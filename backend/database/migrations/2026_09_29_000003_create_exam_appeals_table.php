<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_appeals')) {
            return;
        }

        Schema::create('exam_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_record_id')->comment('考试记录ID');
            $table->unsignedBigInteger('proctoring_event_id')->comment('被申诉的监考事件ID');
            $table->unsignedBigInteger('user_id')->comment('申诉学生ID');
            $table->text('reason')->comment('申诉说明');
            $table->mediumText('evidence_screenshot')->nullable()->comment('申诉截图(data URL)');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->comment('申诉状态');
            $table->unsignedBigInteger('reviewer_id')->nullable()->comment('复核教师ID');
            $table->text('review_note')->nullable()->comment('复核意见');
            $table->dateTime('reviewed_at')->nullable()->comment('复核时间');
            $table->timestamps();

            $table->unique('proctoring_event_id');
            $table->index('exam_record_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_appeals');
    }
};
