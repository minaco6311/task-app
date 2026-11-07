<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 予定
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->date('date')->index();
            $table->boolean('all_day')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('memo')->nullable();
            $table->boolean('done')->default(false);
            // LINE通知：前日・当日朝のまとめに入れるか、開始何分前に送るか（0 = 送らない）
            $table->boolean('notify_day_before')->default(true);
            $table->boolean('notify_morning')->default(true);
            $table->unsignedSmallInteger('notify_before_minutes')->default(60);
            $table->boolean('gcal_added')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
