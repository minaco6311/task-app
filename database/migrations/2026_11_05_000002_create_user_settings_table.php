<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ユーザーごとの設定（通知のタイミング・表示など）
 * 1人用の settings テーブルは消さずに残す（中身のコピーは次のマイグレーションで行う）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();   // ユーザーを消そうとしたら止まる（設定ごと消えないように）
            $table->string('key', 50);
            $table->string('value', 255);
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
