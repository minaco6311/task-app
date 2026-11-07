<?php

namespace App\Http\Controllers;

use App\Models\LineConnection;
use App\Models\NotificationLog;
use App\Services\Line\Messenger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * LINE連携（アプリを受け取った人が、自分のLINE公式アカウントをつなぐ画面）
 * トークンは暗号化して保存し、画面には末尾4文字しか出さない
 */
class LineController extends Controller
{
    public function edit(Messenger $messenger): View
    {
        return view('settings.line', [
            'conn' => LineConnection::current(),
            'live' => $messenger->isLive(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $conn = LineConnection::current();
        $request->merge([
            'channel_access_token' => preg_replace('/\s+/', '', (string) $request->input('channel_access_token')),
            'line_user_id' => trim((string) $request->input('line_user_id')),
        ]);
        $v = $request->validate([
            // すでに登録済みなら、空欄のままでよい（いまのトークンを使い続ける）
            'channel_access_token' => [$conn ? 'nullable' : 'required', 'string', 'min:20', 'max:1000'],
            'line_user_id' => [$conn ? 'nullable' : 'required', 'string', 'regex:'.LineConnection::USER_ID_PATTERN],
        ], [
            'channel_access_token.required' => 'チャネルアクセストークンを貼り付けてください',
            'channel_access_token.min' => 'チャネルアクセストークンが短すぎます。「チャネルアクセストークン（長期）」の文字列をすべてコピーしてください',
            'line_user_id.required' => 'ユーザーIDを貼り付けてください',
            'line_user_id.regex' => 'ユーザーIDは「U」で始まる33文字です。「チャネル基本設定」タブの「あなたのユーザーID」をコピーしてください',
        ]);

        if ($conn && empty($v['channel_access_token']) && empty($v['line_user_id'])) {
            return back()->with('status', '変更はありません（空欄のときは、いまの登録内容を使います）');
        }

        $data = ['status' => 'unverified', 'last_error' => null, 'verified_at' => null];
        foreach (['channel_access_token', 'line_user_id'] as $k) {
            if (! empty($v[$k])) {
                $data[$k] = $v[$k];
            }
        }
        $conn ? $conn->update($data) : LineConnection::query()->create($data);

        return redirect()->route('line.edit')->with('status', '保存しました。次に「テスト送信」を押して、LINEに届くか確かめてください');
    }

    public function test(Messenger $messenger): RedirectResponse
    {
        $conn = LineConnection::current();
        if (! $conn) {
            return back()->with('status', '先にトークンとユーザーIDを保存してください');
        }

        $text = "✅ いろどり手帳とLINEがつながりました\nこれから予定の通知がここに届きます";
        $log = NotificationLog::query()->create([
            'dedupe_key' => 'test:'.Str::uuid(),
            'type' => 'test',
            'scheduled_at' => now(),
            'status' => 'pending',
            'attempts' => 1,
            'retry_key' => (string) Str::uuid(),
            'message' => $text,
        ]);
        $res = $messenger->send($text, $log->retry_key);
        $log->forceFill([
            'status' => $res->status,
            'sent_at' => $res->ok() ? now() : null,
            'last_error' => $res->error,
        ])->save();

        if (! $messenger->isLive()) {
            return back()->with('status', 'シミュレーションで送りました（LINE_DRIVER=log のため、LINEには届きません）');
        }
        if ($res->ok()) {
            $conn->markOk();

            return back()->with('status', 'テスト送信しました。LINEに届いていれば連携は完了です');
        }
        $conn->markError($res->error);

        return back()->with('status', 'テスト送信に失敗しました：'.$res->error);
    }

    public function destroy(): RedirectResponse
    {
        LineConnection::query()->delete();

        return redirect()->route('line.edit')->with('status', 'LINE連携を解除しました（トークンとユーザーIDを消しました）');
    }
}
