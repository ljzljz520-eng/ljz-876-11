<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proctoring_events')) {
            return;
        }

        Schema::create('proctoring_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_record_id')->comment('考试记录ID');
            $table->unsignedBigInteger('user_id')->comment('考生ID');
            $table->string('event_type', 30)->comment('事件类型');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning')->comment('严重级别');
            $table->dateTime('occurred_at')->comment('事件发生时间(客户端)');
            $table->unsignedInteger('duration')->default(0)->comment('持续时长(秒)');
            $table->string('detail', 500)->nullable()->comment('事件详情');
            $table->mediumText('evidence_screenshot')->nullable()->comment('截图(data URL)');
            $table->decimal('penalty', 5, 2)->default(0)->comment('扣分');
            $table->enum('status', ['pending', 'confirmed', 'dismissed'])->default('pending')->comment('复核状态');
            $table->unsignedBigInteger('reviewed_by')->nullable()->comment('复核人ID');
            $table->dateTime('reviewed_at')->nullable()->comment('复核时间');
            $table->string('review_note', 500)->nullable()->comment('复核备注');
            $table->timestamps();

            $table->index('exam_record_id');
            $table->index('user_id');
            $table->index('event_type');
            $table->index('status');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proctoring_events');
    }
};
