<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProctoringEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_record_id',
        'user_id',
        'event_type',
        'severity',
        'occurred_at',
        'duration',
        'detail',
        'evidence_screenshot',
        'penalty',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'user_id' => 'integer',
        'occurred_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'duration' => 'integer',
        'penalty' => 'decimal:2',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISMISSED = 'dismissed';

    // 事件类型 => [中文标签, 默认严重级别, 默认扣分]
    public const TYPES = [
        'tab_switch'      => ['切屏/窗口失焦', 'warning', 2],
        'fullscreen_exit' => ['退出全屏', 'warning', 2],
        'camera_off'      => ['摄像头断开', 'critical', 3],
        'camera_on'       => ['摄像头恢复', 'info', 0],
        'idle'            => ['长时间无操作', 'warning', 2],
        'network_offline' => ['网络中断', 'warning', 0],
        'network_online'  => ['网络恢复', 'info', 0],
        'manual_flag'     => ['监考员标记', 'critical', 5],
    ];

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function appeal()
    {
        return $this->hasOne(ExamAppeal::class, 'proctoring_event_id');
    }
}
