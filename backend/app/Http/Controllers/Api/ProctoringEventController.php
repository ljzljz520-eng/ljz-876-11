<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAppeal;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ProctoringEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProctoringEventController extends Controller
{
    /**
     * 考试过程中客户端批量上报监考事件（切屏/摄像头/闲置/网络等）
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'events' => 'required|array|min:1|max:50',
            'events.*.type' => 'required|string|in:' . implode(',', array_keys(ProctoringEvent::TYPES)),
            'events.*.detail' => 'nullable|string|max:500',
            'events.*.occurred_at' => 'required|date',
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

        // 仅允许考试进行中、或交卷后短时间内（卸载页面时补发）写入事件
        $flushAllowed = $record->status === ExamRecord::STATUS_IN_PROGRESS
            || ($record->end_time && $record->end_time->copy()->addMinutes(30)->isFuture());

        if (!$flushAllowed) {
            return response()->json(['message' => '该场考试已结束，无法再上报监考事件'], 422);
        }

        $saved = 0;
        foreach ($request->input('events') as $eventData) {
            $occurredAt = \Illuminate\Support\Carbon::parse($eventData['occurred_at']);

            // 重试/补发去重：同一记录同类型事件在前后 1 秒内已存在则跳过
            $exists = ProctoringEvent::where('exam_record_id', $record->id)
                ->where('type', $eventData['type'])
                ->whereBetween('occurred_at', [
                    $occurredAt->copy()->subSecond(),
                    $occurredAt->copy()->addSecond(),
                ])
                ->exists();

            if ($exists) {
                continue;
            }

            ProctoringEvent::create([
                'exam_record_id' => $record->id,
                'user_id' => $record->user_id,
                'exam_paper_id' => $record->exam_paper_id,
                'type' => $eventData['type'],
                'detail' => $eventData['detail'] ?? null,
                'occurred_at' => $occurredAt,
            ]);
            $saved++;
        }

        return response()->json([
            'message' => '监考事件已记录',
            'saved' => $saved,
        ]);
    }

    /**
     * 学生查看本人某场考试的监考回放时间线
     */
    public function studentTimeline(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        return response()->json($this->buildTimeline($request, $record, false));
    }

    /**
     * 教师/管理员查看任意一场考试的监考回放时间线
     */
    public function teacherTimeline(Request $request, ExamRecord $record)
    {
        if (!$request->user()->isAdmin() && !$request->user()->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        return response()->json($this->buildTimeline($request, $record, true));
    }

    /**
     * 教师端：有监考记录的考试列表（支持按试卷/待申诉筛选）
     */
    public function records(Request $request)
    {
        if (!$request->user()->isAdmin() && !$request->user()->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $query = ExamRecord::with([
            'user:id,username,real_name',
            'examPaper:id,title,total_score',
            'proctoringEvents:id,exam_record_id,type,is_waived,occurred_at',
            'appeals:id,exam_record_id,status',
        ])
            ->whereIn('status', [ExamRecord::STATUS_SUBMITTED, ExamRecord::STATUS_GRADED])
            ->orderByDesc('id');

        if ($paperId = $request->input('exam_paper_id')) {
            $query->where('exam_paper_id', $paperId);
        }

        if ($request->input('has_anomaly') === '1') {
            $query->whereHas('proctoringEvents', function ($q) {
                $q->where('is_waived', 0)
                    ->whereIn('type', ProctoringEvent::ANOMALY_TYPES);
            });
        }

        if ($request->input('appeal_status') === 'pending') {
            $query->whereHas('appeals', function ($q) {
                $q->where('status', ExamAppeal::STATUS_PENDING);
            });
        }

        if ($keyword = trim((string) $request->input('keyword', ''))) {
            $query->whereHas('user', function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                    ->orWhere('real_name', 'like', "%{$keyword}%");
            });
        }

        $records = $query->paginate($request->input('per_page', 15));

        $records->getCollection()->transform(function ($record) {
            return $this->decorateRecord($record);
        });

        $papers = ExamPaper::where('status', 1)
            ->orderByDesc('id')
            ->get(['id', 'title']);

        return response()->json([
            'records' => $records,
            'exam_papers' => $papers,
        ]);
    }

    protected function decorateRecord(ExamRecord $record): array
    {
        $events = $record->proctoringEvents;

        $anomalyByType = $events
            ->where('is_waived', false)
            ->whereIn('type', ProctoringEvent::ANOMALY_TYPES)
            ->groupBy('type')
            ->map(fn ($group) => $group->count());

        $pendingAppeals = $record->appeals
            ->where('status', ExamAppeal::STATUS_PENDING)
            ->count();

        $data = $record->toArray();
        $data['event_total'] = $events->count();
        $data['active_anomaly_count'] = $events
            ->where('is_waived', false)
            ->whereIn('type', ProctoringEvent::ANOMALY_TYPES)
            ->count();
        $data['waived_anomaly_count'] = $events->where('is_waived', true)->count();
        $data['anomaly_by_type'] = $anomalyByType;
        $data['pending_appeals'] = $pendingAppeals;
        $data['first_event_at'] = optional($events->sortBy('occurred_at')->first())->occurred_at;
        $data['last_event_at'] = optional($events->sortByDesc('occurred_at')->first())->occurred_at;

        return $data;
    }

    protected function buildTimeline(Request $request, ExamRecord $record, bool $asTeacher): array
    {
        $record->load([
            'user:id,username,real_name',
            'examPaper:id,title,total_score,total_time',
            'answers.question:id,title,type',
        ]);

        $events = $record->proctoringEvents()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $appealsQuery = $record->appeals()
            ->with(['evidence', 'proctoringEvent:id,type', 'reviewer:id,username,real_name'])
            ->orderBy('created_at')
            ->orderBy('id');

        $token = $request->bearerToken() ?? '';
        $appeals = $appealsQuery->get()->each(function (ExamAppeal $appeal) use ($token) {
            $appeal->evidence->each(function ($evidence) use ($token) {
                $evidence->url = url("/api/proctoring/evidence/{$evidence->id}")
                    . '?token=' . urlencode($token);
            });
        });

        $start = $record->start_time;

        // 合并事件与申诉，按实际时间排序，形成完整时间线
        $timeline = collect();

        foreach ($events as $event) {
            $eventAppeals = $appeals->where('proctoring_event_id', $event->id)->values();
            $timeline->push([
                'kind' => 'event',
                'id' => $event->id,
                'type' => $event->type,
                'type_label' => ProctoringEvent::typeLabel($event->type),
                'detail' => $event->detail,
                'is_anomaly' => in_array($event->type, ProctoringEvent::ANOMALY_TYPES, true),
                'is_waived' => (bool) $event->is_waived,
                'waived_appeal_id' => $event->waived_appeal_id,
                'occurred_at' => $event->occurred_at,
                'offset_seconds' => $start ? $event->occurred_at->diffInSeconds($start) : null,
                'appeals' => $eventAppeals,
            ]);
        }

        foreach ($appeals->whereNull('proctoring_event_id') as $appeal) {
            $timeline->push([
                'kind' => 'appeal',
                'appeal' => $appeal,
                'at' => $appeal->created_at,
            ]);
        }

        $timeline = $timeline->sortBy(function ($item) {
            return $item['kind'] === 'event'
                ? $item['occurred_at']->timestamp
                : optional($item['at'])->getTimestamp() ?? 0;
        })->values();

        $activeEvents = $events->where('is_waived', false)
            ->whereIn('type', ProctoringEvent::ANOMALY_TYPES);

        $summary = [
            'event_total' => $events->count(),
            'active_anomaly_count' => $activeEvents->count(),
            'waived_anomaly_count' => $events->where('is_waived', true)->count(),
            'anomaly_by_type' => $activeEvents->groupBy('type')->map(fn ($g) => $g->count()),
            'appeal_pending' => $appeals->where('status', ExamAppeal::STATUS_PENDING)->count(),
            'appeal_approved' => $appeals->where('status', ExamAppeal::STATUS_APPROVED)->count(),
            'appeal_rejected' => $appeals->where('status', ExamAppeal::STATUS_REJECTED)->count(),
        ];

        return [
            'record' => $record,
            'timeline' => $timeline,
            'appeals' => $appeals,
            'summary' => $summary,
            'can_appeal' => $record->status === ExamRecord::STATUS_GRADED,
        ];
    }
}
