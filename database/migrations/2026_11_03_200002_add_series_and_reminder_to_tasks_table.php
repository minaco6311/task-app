<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 予定に、繰り返しの情報と「完了まで再通知」を足す（既にある予定は、繰り返しなし・再通知オフ）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('series_id')->nullable()->after('gcal_added');  // 繰り返し（task_series.id）
            $table->date('series_date')->nullable()->after('series_id');                // 繰り返しの何日の回か（日付を動かしても変えない）
            $table->boolean('series_modified')->default(false)->after('series_date');   // 「この予定だけ」変更した
            $table->boolean('remind_until_done')->default(false)->after('notify_before_minutes');
            // 同じ繰り返しの同じ日の回は1つだけ（null は重ならない扱い）
            $table->unique(['series_id', 'series_date']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['series_id', 'series_date']);
            $table->dropColumn(['series_id', 'series_date', 'series_modified', 'remind_until_done']);
        });
    }
};
