<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAppeal;
use App\Models\ExamRecord;
use App\Models\ProctoringEvent;
use App\Services\ProctoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProctoringController extends Controller
{
    public function __construct(private readonly ProctoringService $proctoring)
    {
    }

    /* ===================== 学生端 ===================== */

    /**
     * 考试过程中实时上报监考事件（切屏、摄像头断开、长时间不操作、网络恢复等）。
     * 网络中断期间由客户端本地缓冲，恢复后以 batch 方式补发。
     */
    public function reportEvents(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'events' => 'required|array|min:1',
            'events.*.event_type' => 'required|string|in:' . implode(',', array_keys(ProctoringEvent::TYPES)),
            'events.*.severity' => 'sometimes|string|in:info,warning,critical',
            'events.*.occurred_at' => 'required|date',
            'events.*.duration' => 'sometimes|integer|min:0',
            'events.*.detail' => 'sometimes|nullable|string|max:500',
            'events.*.evidence_screenshot' => 'sometimes|nullable|string|max:2000000',
            'events.*.penalty' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $saved = $this->proctoring->storeEvents($record, $request->events);

        // 已交卷的记录若补发事件（例如提交瞬间断网），同样自动确认并重算
        if ($record->fresh()->status === ExamRecord::STATUS_GRADED) {
            DB::transaction(function () use ($record) {
                $locked = ExamRecord::lockForUpdate()->findOrFail($record->id);
                ProctoringEvent::where('exam_record_id', $locked->id)
                    ->where('status', ProctoringEvent::STATUS_PENDING)
                    ->whereIn('severity', ['warning', 'critical'])
                    ->update([
                        'status' => ProctoringEvent::STATUS_CONFIRMED,
                        'reviewed_at' => Carbon::now(),
                        'review_note' => '系统按监考策略自动确认（交卷后补发）',
                    ]);
                ProctoringEvent::where('exam_record_id', $locked->id)
                    ->where('status', ProctoringEvent::STATUS_PENDING)
                    ->where('severity', 'info')
                    ->update([
                        'status' => ProctoringEvent::STATUS_DISMISSED,
                        'reviewed_at' => Carbon::now(),
                        'review_note' => '恢复类事件，不计违规',
                    ]);
                $this->proctoring->recalculate($locked);
            });
        }

        return response()->json([
            'message' => '监考事件已记录',
            'saved' => $saved,
        ]);
    }

    /**
     * 监考回放：按时间顺序输出某场考试的全部事件（含申诉与复核状态）。
     */
    public function timeline(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        return response()->json($this->buildTimeline($record));
    }

    /**
     * 学生对某个异常事件提交申诉（说明 + 截图），每件异常只能申诉一次。
     */
    public function storeAppeal(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'proctoring_event_id' => 'required|exists:proctoring_events,id',
            'reason' => 'required|string|min:5|max:1000',
            'evidence_screenshot' => 'sometimes|nullable|string|max:2000000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event = ProctoringEvent::findOrFail($request->proctoring_event_id);

        if ($event->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权对该异常提交申诉'], 403);
        }

        $record = $event->examRecord;
        if ($record->status !== ExamRecord::STATUS_GRADED) {
            return response()->json(['message' => '考试结束后才能提交申诉'], 422);
        }

        if ($event->status === ProctoringEvent::STATUS_DISMISSED) {
            return response()->json(['message' => '该异常已被撤销，无需申诉'], 422);
        }

        if ($event->appeal) {
            return response()->json(['message' => '该异常已提交过申诉，请勿重复提交'], 422);
        }

        $appeal = ExamAppeal::create([
            'exam_record_id' => $event->exam_record_id,
            'proctoring_event_id' => $event->id,
            'user_id' => $request->user()->id,
            'reason' => $request->reason,
            'evidence_screenshot' => $this->proctoring->normalizeScreenshot($request->evidence_screenshot),
            'status' => ExamAppeal::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => '申诉已提交，等待教师复核',
            'appeal' => $appeal,
        ], 201);
    }

    /**
     * 我的申诉列表。
     */
    public function myAppeals(Request $request)
    {
        $appeals = ExamAppeal::with(['proctoringEvent', 'examRecord.examPaper', 'reviewer'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate($request->input('per_page', 15));

        return response()->json(['appeals' => $appeals]);
    }

    /* ===================== 教师端 ===================== */

    /**
     * 监考复核台：筛选有异常的考试记录。
     */
    public function records(Request $request)
    {
        $query = ExamRecord::with(['user', 'examPaper'])
            ->where('status', ExamRecord::STATUS_GRADED)
            ->whereHas('proctoringEvents', function ($q) {
                $q->whereIn('severity', ['warning', 'critical']);
            });

        if ($request->filled('appeal_status')) {
            $query->whereHas('appeals', function ($q) use ($request) {
                $q->where('status', $request->appeal_status);
            });
        }

        if ($request->filled('exam_paper_id')) {
            $query->where('exam_paper_id', $request->exam_paper_id);
        }

        if ($request->filled('keyword')) {
            $kw = '%' . $request->keyword . '%';
            $query->whereHas('user', function ($q) use ($kw) {
                $q->where('username', 'like', $kw)->orWhere('real_name', 'like', $kw);
            });
        }

        $records = $query->orderByDesc('id')->paginate($request->input('per_page', 15));

        $records->getCollection()->transform(function ($record) {
            $record->setAttribute('pending_counts', [
                'pending_appeals' => $record->appeals()->where('status', ExamAppeal::STATUS_PENDING)->count(),
                'pending_events' => $record->proctoringEvents()->where('status', ProctoringEvent::STATUS_PENDING)
                    ->whereIn('severity', ['warning', 'critical'])->count(),
                'confirmed_events' => (int) $record->anomaly_count,
            ]);
            return $record;
        });

        return response()->json(['records' => $records]);
    }

    /**
     * 复核详情：时间线 + 申诉 + 答卷得分构成。
     */
    public function adminTimeline(Request $request, ExamRecord $record)
    {
        $data = $this->buildTimeline($record, true);
        $record->load(['answers.question', 'reviewer']);

        $data['record'] = [
            'id' => $record->id,
            'score' => $record->score,
            'base_score' => $record->base_score,
            'deduction' => $record->deduction,
            'anomaly_count' => $record->anomaly_count,
            'review_status' => $record->review_status,
            'review_note' => $record->review_note,
            'reviewed_at' => $record->reviewed_at,
            'reviewer' => $record->reviewer ? ['id' => $record->reviewer->id, 'username' => $record->reviewer->username] : null,
            'answers' => $record->answers->map(fn ($a) => [
                'question_id' => $a->question_id,
                'title' => $a->question->title ?? null,
                'answer' => $a->answer,
                'is_correct' => $a->is_correct,
                'score' => $a->score,
            ]),
        ];

        return response()->json($data);
    }

    /**
     * 教师对单个异常事件复核：confirm=违规成立 / dismiss=误报撤销。
     * 事务内联动重算 扣分、异常数、最终分（成绩统计实时生效）。
     */
    public function reviewEvent(Request $request, ProctoringEvent $event)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:confirm,dismiss',
            'penalty' => 'sometimes|numeric|min:0|max:100',
            'review_note' => 'sometimes|nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = DB::transaction(function () use ($request, $event) {
            $record = ExamRecord::lockForUpdate()->findOrFail($event->exam_record_id);
            $event->refresh();

            $newStatus = $request->action === 'confirm'
                ? ProctoringEvent::STATUS_CONFIRMED
                : ProctoringEvent::STATUS_DISMISSED;

            $update = [
                'status' => $newStatus,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $request->review_note,
            ];

            if ($request->action === 'confirm' && $request->filled('penalty')) {
                $update['penalty'] = (float) $request->penalty;
            }
            if ($request->action === 'dismiss') {
                $update['penalty'] = 0;
            }

            $event->update($update);

            // 若该事件存在待复核申诉：撤销异常 => 申诉成立；确认违规 => 申诉驳回
            $appeal = $event->appeal;
            if ($appeal && $appeal->status === ExamAppeal::STATUS_PENDING) {
                $appeal->update([
                    'status' => $request->action === 'dismiss'
                        ? ExamAppeal::STATUS_APPROVED
                        : ExamAppeal::STATUS_REJECTED,
                    'reviewer_id' => $request->user()->id,
                    'reviewed_at' => Carbon::now(),
                    'review_note' => $request->review_note ?: ($request->action === 'dismiss' ? '异常撤销，申诉自动成立' : '违规成立，申诉驳回'),
                ]);
            }

            $this->proctoring->recalculate($record);
            $this->stampReviewed($record, $request, $request->review_note);

            return $record->fresh();
        });

        return response()->json([
            'message' => $request->action === 'confirm' ? '已确认违规，成绩已重新计算' : '已撤销异常，成绩已恢复',
            'record' => $record,
        ]);
    }

    /**
     * 教师直接复核申诉：approved=申诉成立(撤销异常并恢复分数) / rejected=驳回(维持判罚)。
     */
    public function reviewAppeal(Request $request, ExamAppeal $appeal)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approved,rejected',
            'review_note' => 'required|string|min:2|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($appeal->status !== ExamAppeal::STATUS_PENDING) {
            return response()->json(['message' => '该申诉已完成复核'], 422);
        }

        $record = DB::transaction(function () use ($request, $appeal) {
            $record = ExamRecord::lockForUpdate()->findOrFail($appeal->exam_record_id);
            $event = ProctoringEvent::lockForUpdate()->findOrFail($appeal->proctoring_event_id);

            $appeal->update([
                'status' => $request->action === 'approved'
                    ? ExamAppeal::STATUS_APPROVED
                    : ExamAppeal::STATUS_REJECTED,
                'reviewer_id' => $request->user()->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $request->review_note,
            ]);

            if ($request->action === 'approved') {
                // 申诉成立：异常标记撤销、扣分清零
                $event->update([
                    'status' => ProctoringEvent::STATUS_DISMISSED,
                    'penalty' => 0,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => Carbon::now(),
                    'review_note' => '申诉成立：' . $request->review_note,
                ]);
            } else {
                // 申诉驳回：若事件此前尚未确认，则确认违规维持扣分
                if ($event->status !== ProctoringEvent::STATUS_CONFIRMED) {
                    $event->update([
                        'status' => ProctoringEvent::STATUS_CONFIRMED,
                        'reviewed_by' => $request->user()->id,
                        'reviewed_at' => Carbon::now(),
                        'review_note' => '申诉驳回，违规成立：' . $request->review_note,
                    ]);
                }
            }

            $this->proctoring->recalculate($record);
            $this->stampReviewed($record, $request, $request->review_note);

            return $record->fresh();
        });

        return response()->json([
            'message' => $request->action === 'approved' ? '申诉成立，异常已撤销、成绩已恢复' : '申诉已驳回，维持原判罚',
            'record' => $record,
        ]);
    }

    /**
     * 教师手动调整最终成绩（兜底改判），异常与扣分仍由事件驱动，此处仅覆盖原始分基准。
     */
    public function adjustScore(Request $request, ExamRecord $record)
    {
        $validator = Validator::make($request->all(), [
            'base_score' => 'required|numeric|min:0|max:1000',
            'review_note' => 'required|string|min:2|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = DB::transaction(function () use ($request, $record) {
            $locked = ExamRecord::lockForUpdate()->findOrFail($record->id);
            $this->proctoring->recalculate($locked, (float) $request->base_score);
            $this->stampReviewed($locked, $request, $request->review_note);
            return $locked->fresh();
        });

        return response()->json([
            'message' => '成绩已改判，统计数据已同步更新',
            'record' => $record,
        ]);
    }

    /**
     * 待办计数（导航角标用）。
     */
    public function pendingCounts()
    {
        return response()->json([
            'pending_appeals' => ExamAppeal::where('status', ExamAppeal::STATUS_PENDING)->count(),
            'records_with_pending' => ExamRecord::where('status', ExamRecord::STATUS_GRADED)
                ->whereHas('proctoringEvents', function ($q) {
                    $q->where('status', ProctoringEvent::STATUS_PENDING)
                        ->whereIn('severity', ['warning', 'critical']);
                })->count(),
        ]);
    }

    /* ===================== 私有辅助 ===================== */

    private function buildTimeline(ExamRecord $record, bool $forReviewer = false): array
    {
        $events = ProctoringEvent::with(['appeal' => function ($q) use ($forReviewer) {
            $q->with($forReviewer ? ['user', 'reviewer'] : ['reviewer']);
        }, 'reviewer'])
            ->where('exam_record_id', $record->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $timeline = $events->map(function (ProctoringEvent $event) {
            [$label, , ] = ProctoringEvent::TYPES[$event->event_type] ?? [$event->event_type, 'warning', 0];

            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'type_label' => $label,
                'severity' => $event->severity,
                'occurred_at' => $event->occurred_at,
                'duration' => $event->duration,
                'detail' => $event->detail,
                'evidence_screenshot' => $event->evidence_screenshot,
                'penalty' => $event->penalty,
                'status' => $event->status,
                'status_label' => [
                    ProctoringEvent::STATUS_PENDING => '待复核',
                    ProctoringEvent::STATUS_CONFIRMED => '违规成立',
                    ProctoringEvent::STATUS_DISMISSED => '已撤销',
                ][$event->status],
                'review_note' => $event->review_note,
                'reviewed_at' => $event->reviewed_at,
                'appeal' => $event->appeal ? [
                    'id' => $event->appeal->id,
                    'reason' => $event->appeal->reason,
                    'evidence_screenshot' => $event->appeal->evidence_screenshot,
                    'status' => $event->appeal->status,
                    'status_label' => ExamAppeal::STATUS_LABELS[$event->appeal->status] ?? $event->appeal->status,
                    'review_note' => $event->appeal->review_note,
                    'reviewed_at' => $event->appeal->reviewed_at,
                ] : null,
            ];
        });

        return [
            'exam_record' => [
                'id' => $record->id,
                'score' => $record->score,
                'base_score' => $record->base_score,
                'deduction' => $record->deduction,
                'anomaly_count' => $record->anomaly_count,
                'review_status' => $record->review_status,
                'start_time' => $record->start_time,
                'end_time' => $record->end_time,
            ],
            'exam_paper' => $record->examPaper()->select('id', 'title')->first(),
            'student' => ['id' => $record->user_id, 'username' => $record->user?->username, 'real_name' => $record->user?->real_name],
            'timeline' => $timeline,
        ];
    }

    private function stampReviewed(ExamRecord $record, Request $request, ?string $note): void
    {
        $record->update([
            'review_status' => ExamRecord::REVIEW_REVIEWED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $note,
        ]);
    }
}
