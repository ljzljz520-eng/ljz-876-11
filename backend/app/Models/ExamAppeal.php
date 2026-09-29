<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamAppeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_record_id',
        'proctoring_event_id',
        'user_id',
        'reason',
        'evidence_screenshot',
        'status',
        'reviewer_id',
        'review_note',
        'reviewed_at',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'proctoring_event_id' => 'integer',
        'user_id' => 'integer',
        'reviewer_id' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING  => '待复核',
        self::STATUS_APPROVED => '申诉成立',
        self::STATUS_REJECTED => '申诉驳回',
    ];

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function proctoringEvent()
    {
        return $this->belongsTo(ProctoringEvent::class, 'proctoring_event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
