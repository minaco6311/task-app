<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// LINE連携（アプリを受け取った人が設定画面から登録する。1行だけ使う）
// トークンとユーザーIDは APP_KEY で暗号化して保存する
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_connections', function (Blueprint $table) {
            $table->id();
            $table->text('channel_access_token');
            $table->text('user_id');
            $table->string('status', 20)->default('unverified'); // unverified / ok / error
            $table->string('last_error', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_connections');
    }
};
