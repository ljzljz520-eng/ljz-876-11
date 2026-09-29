<?php

namespace App\Services;

use App\Models\ExamRecord;
use App\Models\ProctoringEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 监考领域服务：
 *  - 事件落库（考试中/提交时批量）
 *  - 依据已确认(confirmed)事件重算考试记录的 扣分 / 异常数 / 最终得分
 * 教师复核改判（确认/撤销异常、申诉成立/驳回、教师直接给分）统一走这里，
 * 保证“分数、异常标记、成绩统计”三者始终一致（统计页实时查询 exam_records.score）。
 */
class ProctoringService
{
    /** 最大允许随单条事件/申诉上传的截图大小（base64 data URL，约 2MB） */
    public const MAX_SCREENSHOT_LENGTH = 2_000_000;

    /**
     * 批量写入客户端采集到的监考事件（按 记录+类型+发生时间 去重，支持离线恢复后补发）。
     */
    public function storeEvents(ExamRecord $record, array $events): int
    {
        $saved = 0;
        $now = Carbon::now();

        foreach ($events as $evt) {
            if (!is_array($evt) || empty($evt['event_type'])) {
                continue;
            }

            $type = $evt['event_type'];
            if (!array_key_exists($type, ProctoringEvent::TYPES)) {
                continue;
            }

            [, $defaultSeverity, $defaultPenalty] = ProctoringEvent::TYPES[$type];

            $severity = in_array($evt['severity'] ?? null, ['info', 'warning', 'critical'], true)
                ? $evt['severity']
                : $defaultSeverity;

            $occurredAt = !empty($evt['occurred_at'])
                ? Carbon::parse($evt['occurred_at'])
                : $now;

            $duration = max(0, (int) ($evt['duration'] ?? 0));
            $detail = isset($evt['detail']) ? mb_substr((string) $evt['detail'], 0, 500) : null;
            $screenshot = $this->normalizeScreenshot($evt['evidence_screenshot'] ?? null);
            $penalty = isset($evt['penalty']) ? max(0, (float) $evt['penalty']) : $defaultPenalty;

            // info 级别的恢复类事件不参与扣分
            if ($severity === 'info') {
                $penalty = 0;
            }

            $exists = ProctoringEvent::where('exam_record_id', $record->id)
                ->where('event_type', $type)
                ->where('occurred_at', $occurredAt)
                ->exists();
            if ($exists) {
                continue;
            }

            ProctoringEvent::create([
                'exam_record_id' => $record->id,
                'user_id' => $record->user_id,
                'event_type' => $type,
                'severity' => $severity,
                'occurred_at' => $occurredAt,
                'duration' => $duration,
                'detail' => $detail,
                'evidence_screenshot' => $screenshot,
                // 事件默认按待复核处理；考试结束自动评分时按策略统一确认
                'penalty' => $penalty,
                'status' => ProctoringEvent::STATUS_PENDING,
            ]);
            $saved++;
        }

        return $saved;
    }

    /**
     * 交卷评分时调用：
     *  - 锁定原始作答得分 base_score
     *  - 按严重级别把违规事件自动确认（info 恢复事件不确认、不扣分）
     *  - 重算扣分与最终得分
     */
    public function applyOnSubmit(ExamRecord $record, float $baseScore): void
    {
        DB::transaction(function () use ($record, $baseScore) {
            $record->refresh();

            ProctoringEvent::where('exam_record_id', $record->id)
                ->where('status', ProctoringEvent::STATUS_PENDING)
                ->whereIn('severity', ['warning', 'critical'])
                ->update([
                    'status' => ProctoringEvent::STATUS_CONFIRMED,
                    'reviewed_at' => Carbon::now(),
                    'review_note' => '系统按监考策略自动确认',
                ]);

            // info 恢复事件（摄像头恢复/网络恢复）直接关闭，不进入异常计数
            ProctoringEvent::where('exam_record_id', $record->id)
                ->where('status', ProctoringEvent::STATUS_PENDING)
                ->where('severity', 'info')
                ->update([
                    'status' => ProctoringEvent::STATUS_DISMISSED,
                    'reviewed_at' => Carbon::now(),
                    'review_note' => '恢复类事件，不计违规',
                ]);

            $record->base_score = $baseScore;
            $this->recalculate($record);
        });
    }

    /**
     * 教师复核改判后重算：分数 = 原始分 - 已确认事件扣分之和（最低 0 分）。
     * 必须在事务中、对 exam_records 行加锁后调用。
     */
    public function recalculate(ExamRecord $record, ?float $manualScore = null): void
    {
        $confirmedPenalty = (float) ProctoringEvent::where('exam_record_id', $record->id)
            ->where('status', ProctoringEvent::STATUS_CONFIRMED)
            ->sum('penalty');

        $anomalyCount = ProctoringEvent::where('exam_record_id', $record->id)
            ->where('status', ProctoringEvent::STATUS_CONFIRMED)
            ->count();

        $base = $manualScore !== null
            ? max(0, $manualScore)
            : (float) ($record->base_score ?? 0);

        $finalScore = max(0, $base - $confirmedPenalty);

        $record->update([
            'base_score' => $base,
            'deduction' => round($confirmedPenalty, 2),
            'anomaly_count' => $anomalyCount,
            'score' => round($finalScore, 2),
        ]);
    }

    public function normalizeScreenshot($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        // 仅接受图片 data URL，防止注入其他内容
        if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#', $value)) {
            return null;
        }

        return mb_strlen($value) <= self::MAX_SCREENSHOT_LENGTH ? $value : null;
    }
}
