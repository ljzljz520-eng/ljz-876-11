<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAppeal;
use App\Models\ExamAppealEvidence;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ProctoringEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AppealController extends Controller
{
    /**
     * 学生提交申诉：针对某场考试的某个异常，填写说明并上传截图
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'proctoring_event_id' => 'nullable|exists:proctoring_events,id',
            'reason' => 'required|string|min:5|max:1000',
            'screenshots' => 'required|array|min:1|max:3',
            'screenshots.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ], [
            'reason.min' => '申诉说明至少 5 个字符',
            'screenshots.required' => '请至少上传 1 张截图作为证据',
            'screenshots.*.image' => '仅支持图片文件',
            'screenshots.*.max' => '单张截图不能超过 5MB',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->input('exam_record_id'))
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$record) {
            return response()->json(['message' => '考试记录不存在'], 404);
        }

        if ($record->status !== ExamRecord::STATUS_GRADED) {
            return response()->json(['message' => '考试尚未评分结束，暂不能申诉'], 422);
        }

        $event = null;
        if ($eventId = $request->input('proctoring_event_id')) {
            $event = ProctoringEvent::where('id', $eventId)
                ->where('exam_record_id', $record->id)
                ->first();

            if (!$event) {
                return response()->json(['message' => '异常事件不存在'], 404);
            }

            if (!in_array($event->type, ProctoringEvent::ANOMALY_TYPES, true)) {
                return response()->json(['message' => '该事件为信息类记录，无需申诉'], 422);
            }

            $already = ExamAppeal::where('proctoring_event_id', $event->id)
                ->where('status', ExamAppeal::STATUS_PENDING)
                ->exists();
            if ($already) {
                return response()->json(['message' => '该异常已有待复核的申诉，请等待老师处理'], 422);
            }
        }

        $appeal = DB::transaction(function () use ($request, $record, $event) {
            $appeal = ExamAppeal::create([
                'exam_record_id' => $record->id,
                'user_id' => $request->user()->id,
                'exam_paper_id' => $record->exam_paper_id,
                'proctoring_event_id' => $event?->id,
                'reason' => $request->input('reason'),
                'status' => ExamAppeal::STATUS_PENDING,
            ]);

            foreach ($request->file('screenshots', []) as $file) {
                $path = $file->store("appeals/{$appeal->id}", 'public');
                ExamAppealEvidence::create([
                    'appeal_id' => $appeal->id,
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return $appeal->load('evidence');
        });

        return response()->json([
            'message' => '申诉已提交，请等待老师复核',
            'appeal' => $appeal,
        ], 201);
    }

    /**
     * 学生查看本人申诉列表
     */
    public function myAppeals(Request $request)
    {
        $appeals = ExamAppeal::with(['examPaper:id,title', 'proctoringEvent:id,type', 'evidence', 'reviewer:id,username,real_name'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate($request->input('per_page', 15));

        $this->attachEvidenceUrls($appeals, $request->bearerToken());

        return response()->json(['appeals' => $appeals]);
    }

    /**
     * 教师/管理员：申诉处理列表
     */
    public function index(Request $request)
    {
        if (!$request->user()->isAdmin() && !$request->user()->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $query = ExamAppeal::with([
            'user:id,username,real_name',
            'examPaper:id,title',
            'proctoringEvent:id,type,detail,occurred_at,is_waived',
            'examRecord:id,score,status',
            'reviewer:id,username,real_name',
            'evidence',
        ])->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
            ->orderByDesc('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($paperId = $request->input('exam_paper_id')) {
            $query->where('exam_paper_id', $paperId);
        }

        $appeals = $query->paginate($request->input('per_page', 15));
        $this->attachEvidenceUrls($appeals, $request->bearerToken());

        $papers = ExamPaper::orderByDesc('id')->get(['id', 'title']);

        return response()->json([
            'appeals' => $appeals,
            'exam_papers' => $papers,
        ]);
    }

    /**
     * 给分页结果中的证据附加可直接用于 <img> 的鉴权 URL
     */
    protected function attachEvidenceUrls($paginator, ?string $token): void
    {
        $token = $token ?? '';
        $paginator->getCollection()->transform(function (ExamAppeal $appeal) use ($token) {
            $appeal->evidence->each(function ($evidence) use ($token) {
                $evidence->url = url("/api/proctoring/evidence/{$evidence->id}")
                    . '?token=' . urlencode($token);
            });
            return $appeal;
        });
    }

    /**
     * 教师复核：通过(可改判分数 + 撤销异常标记) 或 驳回
     */
    public function review(Request $request, ExamAppeal $appeal)
    {
        if (!$request->user()->isAdmin() && !$request->user()->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected',
            'review_comment' => 'nullable|string|max:1000',
            // 分数调整：正数加分、负数减分（仅在通过时生效）
            'score_adjustment' => 'required_if:decision,approved|numeric|between:-1000,1000',
            // 是否撤销对应异常标记（仅在通过时生效）
            'waive_event' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($appeal->status !== ExamAppeal::STATUS_PENDING) {
            return response()->json(['message' => '该申诉已复核，不能重复处理'], 422);
        }

        $record = ExamRecord::lockForUpdate()->find($appeal->exam_record_id);
        if (!$record || $record->status !== ExamRecord::STATUS_GRADED) {
            return response()->json(['message' => '关联的考试记录状态异常，无法复核'], 422);
        }

        $decision = $request->input('decision');
        $adjustment = $decision === ExamAppeal::STATUS_APPROVED
            ? (float) $request->input('score_adjustment', 0)
            : 0;
        $waiveEvent = $decision === ExamAppeal::STATUS_APPROVED
            && $request->boolean('waive_event', true);

        $paper = ExamPaper::find($record->exam_paper_id);
        $scoreBefore = (float) $record->score;
        $scoreAfter = $scoreBefore + $adjustment;
        if ($scoreAfter < 0) {
            $scoreAfter = 0;
        }
        if ($paper && $paper->total_score !== null && $scoreAfter > (float) $paper->total_score) {
            $scoreAfter = (float) $paper->total_score;
        }

        $result = DB::transaction(function () use ($appeal, $record, $decision, $adjustment, $waiveEvent, $scoreBefore, $scoreAfter, $request) {
            $waivedEvent = null;

            if ($decision === ExamAppeal::STATUS_APPROVED) {
                // 1) 改判：分数跟着改
                if (abs($adjustment) > 0.0001) {
                    $record->score = $scoreAfter;
                    $record->save();
                }

                // 2) 异常标记跟着改：撤销该事件的违规标记
                if ($waiveEvent && $appeal->proctoring_event_id) {
                    $waivedEvent = ProctoringEvent::where('id', $appeal->proctoring_event_id)
                        ->where('exam_record_id', $record->id)
                        ->first();
                    if ($waivedEvent) {
                        $waivedEvent->update([
                            'is_waived' => 1,
                            'waived_appeal_id' => $appeal->id,
                        ]);
                    }
                }
            }

            $appeal->update([
                'status' => $decision,
                'review_comment' => $request->input('review_comment'),
                'reviewer_id' => $request->user()->id,
                'reviewed_at' => Carbon::now(),
                'score_adjustment' => $decision === ExamAppeal::STATUS_APPROVED ? $adjustment : 0,
                'score_before' => $decision === ExamAppeal::STATUS_APPROVED ? $scoreBefore : null,
                'score_after' => $decision === ExamAppeal::STATUS_APPROVED ? $scoreAfter : null,
            ]);

            return [
                'record' => $record->fresh(),
                'waived_event_id' => $waivedEvent?->id,
                'score_before' => $scoreBefore,
                'score_after' => $scoreAfter,
            ];
        });

        // 3) 成绩统计无需手工处理：统计接口实时基于 exam_records / proctoring_events 聚合，自动联动

        return response()->json([
            'message' => $decision === ExamAppeal::STATUS_APPROVED ? '申诉成立，已完成改判' : '申诉已驳回',
            'appeal' => $appeal->fresh()->load(['evidence', 'reviewer:id,username,real_name']),
            'record' => $result['record'],
            'score_before' => $result['score_before'],
            'score_after' => $result['score_after'],
            'waived_event_id' => $result['waived_event_id'],
        ]);
    }

    /**
     * 读取申诉截图（鉴权后输出，避免证据被未授权访问）
     */
    public function evidence(Request $request, ExamAppealEvidence $evidenceItem)
    {
        $appeal = $evidenceItem->appeal;
        $user = $request->user();

        $allowed = $user->id === $appeal->user_id || $user->isAdmin() || $user->isTeacher();
        if (!$allowed) {
            return response()->json(['message' => '无权查看该证据'], 403);
        }

        // 种子演示数据没有真实文件时，动态生成一张占位图，保证演示链路完整
        if (!Storage::disk('public')->exists($evidenceItem->file_path)) {
            return $this->placeholderImage($evidenceItem);
        }

        return Storage::disk('public')->response($evidenceItem->file_path, $evidenceItem->original_name);
    }

    protected function placeholderImage(ExamAppealEvidence $evidence)
    {
        $label = 'Sample evidence (placeholder)';
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(640, 360);
            $bg = imagecolorallocate($img, 238, 242, 255);
            $fg = imagecolorallocate($img, 79, 70, 229);
            imagefilledrectangle($img, 0, 0, 640, 360, $bg);
            imagestring($img, 5, 60, 160, 'Appeal evidence screenshot', $fg);
            imagestring($img, 3, 60, 200, $label . ' #' . $evidence->id, $fg);
            ob_start();
            imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);
            return response($data, 200, ['Content-Type' => 'image/png']);
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360">'
            . '<rect width="100%" height="100%" fill="#eef2ff"/>'
            . '<text x="60" y="170" fill="#4f46e5" font-size="24">Appeal evidence screenshot</text>'
            . '<text x="60" y="210" fill="#4f46e5" font-size="16">' . e($label) . ' #' . $evidence->id . '</text>'
            . '</svg>';

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }
}
