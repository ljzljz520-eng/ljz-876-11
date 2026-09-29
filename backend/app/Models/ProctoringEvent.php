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
        'exam_paper_id',
        'type',
        'detail',
        'occurred_at',
        'is_waived',
        'waived_appeal_id',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'occurred_at' => 'datetime',
        'is_waived' => 'boolean',
        'waived_appeal_id' => 'integer',
    ];

    // 切屏 / 窗口失焦
    public const TYPE_TAB_SWITCH = 'tab_switch';
    // 摄像头断开
    public const TYPE_CAMERA_DISCONNECTED = 'camera_disconnected';
    // 摄像头恢复（信息类，不算违规）
    public const TYPE_CAMERA_RESTORED = 'camera_restored';
    // 长时间无操作
    public const TYPE_IDLE_LONG = 'idle_long';
    // 网络中断
    public const TYPE_NETWORK_LOST = 'network_lost';
    // 网络恢复
    public const TYPE_NETWORK_RESTORED = 'network_restored';
    // 退出全屏（信息/违规，按策略记录）
    public const TYPE_FULLSCREEN_EXIT = 'fullscreen_exit';
    // 考试开始监控（信息类）
    public const TYPE_MONITOR_START = 'monitor_start';

    public const TYPES = [
        self::TYPE_TAB_SWITCH => '切屏/离开考试窗口',
        self::TYPE_CAMERA_DISCONNECTED => '摄像头断开',
        self::TYPE_CAMERA_RESTORED => '摄像头恢复',
        self::TYPE_IDLE_LONG => '长时间无操作',
        self::TYPE_NETWORK_LOST => '网络中断',
        self::TYPE_NETWORK_RESTORED => '网络恢复',
        self::TYPE_FULLSCREEN_EXIT => '退出全屏',
        self::TYPE_MONITOR_START => '开始监考',
    ];

    // 仅以下类型默认视为"异常标记"，恢复/信息类事件不计违规
    public const ANOMALY_TYPES = [
        self::TYPE_TAB_SWITCH,
        self::TYPE_CAMERA_DISCONNECTED,
        self::TYPE_IDLE_LONG,
        self::TYPE_NETWORK_LOST,
        self::TYPE_FULLSCREEN_EXIT,
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function appeals()
    {
        return $this->hasMany(ExamAppeal::class, 'proctoring_event_id');
    }
}
