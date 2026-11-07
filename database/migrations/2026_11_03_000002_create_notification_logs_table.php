<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 通知の履歴。dedupe_key を一意にして、同じ通知を二度送らないようにする
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 120)->unique();     // 例）morning:2026-11-05 / before:12:2026-11-05 14:00:60
            $table->string('type', 20);                       // day_before / morning / before / test
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('scheduled_at');                 // 送るはずの時刻（日本時間）
            $table->string('status', 20)->index();            // pending / sent / simulated / failed / skipped / canceled
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('retry_key')->nullable();            // LINE の X-Line-Retry-Key（最初の送信から付ける）
            $table->string('signature', 64)->nullable();      // 通知の中身（予定の日時・タイトル）の指紋。変わったら送り直さない
            $table->text('message');                          // 送る本文。送り直すときもこのまま使う
            $table->dateTime('next_retry_at')->nullable()->index();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
