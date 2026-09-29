<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'start_time',
        'end_time',
        'score',
        'base_score',
        'deduction',
        'anomaly_count',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'score' => 'decimal:2',
        'base_score' => 'decimal:2',
        'deduction' => 'decimal:2',
        'anomaly_count' => 'integer',
        'reviewed_at' => 'datetime',
        'status' => 'string',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';

    public const REVIEW_NONE = 'none';
    public const REVIEW_REVIEWED = 'reviewed';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS => '进行中',
        self::STATUS_SUBMITTED => '已提交',
        self::STATUS_GRADED => '已评分',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function answers()
    {
        return $this->hasMany(ExamRecordAnswer::class, 'exam_record_id');
    }

    public function proctoringEvents()
    {
        return $this->hasMany(ProctoringEvent::class, 'exam_record_id');
    }

    public function appeals()
    {
        return $this->hasMany(ExamAppeal::class, 'exam_record_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
