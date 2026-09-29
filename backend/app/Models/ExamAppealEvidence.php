<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamAppealEvidence extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'appeal_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'appeal_id' => 'integer',
        'file_size' => 'integer',
    ];

    public function appeal()
    {
        return $this->belongsTo(ExamAppeal::class, 'appeal_id');
    }
}
