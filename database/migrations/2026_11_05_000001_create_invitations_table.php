<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 招待（1回だけ使える・7日間）。トークンはハッシュだけを保存する */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');                       // 招待先（小文字）。登録するメールアドレスはこれと同じでなければならない
            $table->char('token_hash', 64)->unique();      // SHA-256（トークンそのものは保存しない）
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();      // 登録に使った時刻（1回だけ）
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();   // 管理者が無効にした時刻
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
