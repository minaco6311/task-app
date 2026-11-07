<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DataController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LineController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SubtaskController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// ログイン・招待からの登録（自由な新規登録はない。招待は管理者が php artisan invite:create で発行する）
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');

    // 招待リンク（https://…/invite#トークン）。トークンは # の後ろなので、サーバーのアクセスログには残らない
    // 画面の JavaScript がトークンを POST で送り、URL からトークンを消す
    Route::get('invite', [InvitationController::class, 'show'])->name('invite.show');
    Route::post('invite', [InvitationController::class, 'redeem'])->middleware('throttle:invite')->name('invite.redeem');
    // 登録画面（確認できた招待をセッションに覚えている人だけ。URL にトークンは入らない）
    Route::get('register', [InvitationController::class, 'create'])->name('register');
    Route::post('register', [InvitationController::class, 'store'])->middleware('throttle:invite');
});
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ここから下はログインが必要。データはすべて「ログイン中の人のもの」だけ（App\Models\Concerns\BelongsToOwner）
// {task} などの ID は、ほかの人のものだと見つからない（404）。can:own,… でも持ち主かを確かめる
Route::middleware('auth')->group(function () {
    // カレンダー（?view=month|week|day&date=YYYY-MM-DD）
    Route::get('/', [CalendarController::class, 'index'])->name('calendar');

    // 予定
    Route::get('tasks/create', [TaskController::class, 'create'])->name('tasks.create');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::middleware('can:own,task')->group(function () {
        Route::get('tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
        Route::match(['put', 'patch'], 'tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
        Route::post('tasks/{task}/google', [TaskController::class, 'google'])->name('tasks.google');
        Route::patch('tasks/{task}/done', [TaskController::class, 'done'])->name('tasks.done');
        // 再通知（スヌーズ）
        Route::post('tasks/{task}/reminders', [ReminderController::class, 'store'])->name('reminders.store');
    });
    Route::patch('subtasks/{subtask}/done', [SubtaskController::class, 'done'])->middleware('can:own,subtask')->name('subtasks.done');
    Route::delete('reminders/{reminder}', [ReminderController::class, 'destroy'])->middleware('can:own,reminder')->name('reminders.destroy');

    // 期限切れをまとめて完了（確認画面 → 実行）
    Route::get('overdue/complete', [TaskController::class, 'confirmOverdue'])->name('overdue.confirm');
    Route::post('overdue/complete', [TaskController::class, 'completeOverdue'])->name('overdue.complete');

    // 手帳から取り込み
    Route::get('import', [ImportController::class, 'create'])->name('import.create');
    Route::post('import/preview', [ImportController::class, 'preview'])->name('import.preview');
    Route::post('import', [ImportController::class, 'store'])->name('import.store');
    Route::get('import/done', [ImportController::class, 'done'])->name('import.done');

    // 設定
    Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
    Route::put('settings/display', [SettingController::class, 'updateDisplay'])->name('settings.display');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories', [CategoryController::class, 'updateAll'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('can:own,category')->name('categories.destroy');

    // パスワードの変更（いまのパスワードを確かめてから。ほかの端末のログインは切れる）
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');

    // LINE連携
    Route::get('settings/line', [LineController::class, 'edit'])->name('line.edit');
    Route::put('settings/line', [LineController::class, 'update'])->name('line.update');
    Route::post('settings/line/test', [LineController::class, 'test'])->middleware('throttle:10,1')->name('line.test');
    Route::delete('settings/line', [LineController::class, 'destroy'])->name('line.destroy');

    // データ
    Route::get('export/ics', [DataController::class, 'ics'])->name('export.ics');
    Route::get('export/json', [DataController::class, 'json'])->name('export.json');
    Route::post('restore', [DataController::class, 'restore'])->name('data.restore');
    Route::delete('data', [DataController::class, 'reset'])->name('data.reset');
});
