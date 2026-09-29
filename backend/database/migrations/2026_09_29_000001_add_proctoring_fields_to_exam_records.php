<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_records', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_records', 'base_score')) {
                $table->decimal('base_score', 5, 2)->default(0)->after('score')->comment('自动评分原始得分');
            }
            if (!Schema::hasColumn('exam_records', 'deduction')) {
                $table->decimal('deduction', 5, 2)->default(0)->after('base_score')->comment('监考违规累计扣分');
            }
            if (!Schema::hasColumn('exam_records', 'anomaly_count')) {
                $table->unsignedInteger('anomaly_count')->default(0)->after('deduction')->comment('已确认违规异常数');
            }
            if (!Schema::hasColumn('exam_records', 'review_status')) {
                $table->enum('review_status', ['none', 'reviewed'])->default('none')->after('anomaly_count')->comment('是否经教师复核改判');
            }
            if (!Schema::hasColumn('exam_records', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_status')->comment('改判教师ID');
            }
            if (!Schema::hasColumn('exam_records', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by')->comment('最近改判时间');
            }
            if (!Schema::hasColumn('exam_records', 'review_note')) {
                $table->string('review_note', 500)->nullable()->after('reviewed_at')->comment('改判说明');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_records', function (Blueprint $table) {
            $table->dropColumn(['base_score', 'deduction', 'anomaly_count', 'review_status', 'reviewed_by', 'reviewed_at', 'review_note']);
        });
    }
};
