<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamAppeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_record_id',
        'user_id',
        'exam_paper_id',
        'proctoring_event_id',
        'reason',
        'status',
        'review_comment',
        'reviewer_id',
        'reviewed_at',
        'score_adjustment',
        'score_before',
        'score_after',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'proctoring_event_id' => 'integer',
        'reviewer_id' => 'integer',
        'reviewed_at' => 'datetime',
        'score_adjustment' => 'decimal:2',
        'score_before' => 'decimal:2',
        'score_after' => 'decimal:2',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => '待复核',
        self::STATUS_APPROVED => '申诉成立(已改判)',
        self::STATUS_REJECTED => '申诉驳回',
    ];

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

    public function proctoringEvent()
    {
        return $this->belongsTo(ProctoringEvent::class, 'proctoring_event_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function evidence()
    {
        return $this->hasMany(ExamAppealEvidence::class, 'appeal_id');
    }
}
