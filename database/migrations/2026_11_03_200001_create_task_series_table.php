<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 繰り返し予定のルールと、各回を作るときの元の内容（テンプレート）
// 各回は普通の予定（tasks）として作り、tasks.series_id / series_date で結びつける
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_series', function (Blueprint $table) {
            $table->id();
            $table->string('freq', 10);                          // daily / weekly / weekdays / monthly
            $table->string('weekdays', 20)->nullable();          // 毎週の曜日（0=日 … 6=土 をカンマ区切り。例 "1,3,5"）
            $table->unsignedTinyInteger('month_day')->nullable(); // 毎月の基準日（31 のままにして、ない月は末日にする）
            $table->date('starts_on');
            $table->date('until')->nullable();                   // 終わりの日（null = 終わりなし）
            $table->date('generated_until')->nullable();         // どの日まで作ったか
            // 各回の元の内容
            $table->string('title', 80);
            $table->boolean('all_day')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('memo')->nullable();
            $table->unsignedTinyInteger('priority')->default(2);
            $table->boolean('notify_day_before')->default(true);
            $table->boolean('notify_morning')->default(true);
            $table->unsignedSmallInteger('notify_before_minutes')->default(60);
            $table->boolean('remind_until_done')->default(false);
            $table->json('subtasks')->nullable();                // チェック項目の名前（各回にコピーし、完了は回ごと）
            $table->timestamps();
        });

        // 「この予定だけ削除」した回。自動で作り直さないために残す
        Schema::create('task_series_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('task_series')->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();
            $table->unique(['series_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_series_skips');
        Schema::dropIfExists('task_series');
    }
};
