<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// スヌーズ（10分後・1時間後・翌朝8時に再通知）の予約。予定を消すと一緒に消える
// 送ったかどうかは notification_logs（dedupe_key = snooze:{id}）に残す
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->dateTime('remind_at')->index();
            $table->timestamps();
            $table->unique(['task_id', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
    }
};
