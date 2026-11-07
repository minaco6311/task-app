<?php

namespace App\Services\Line;

use App\Models\LineConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * LINE Messaging API の push メッセージで送る（LINE_DRIVER=line）
 * 送り先・トークンは、いまのユーザー（App\Support\Owner）の LINE連携だけを使う
 *
 * LINE公式の「APIリクエストを再試行する」に沿って扱う
 * ・X-Line-Retry-Key は最初の送信から付ける（付けずに送ったものは安全に送り直せない）
 * ・500番台とタイムアウト／通信エラーは送り直す（最初と同じ本文・同じキーで）
 * ・409 は「同じキーの送信をすでに受け付けた」＝送れている
 * ・それ以外の 400番台は送り直しても結果は変わらないので、送り直さない（429 も含む）
 */
class MessagingApiMessenger implements Messenger
{
    public function send(string $text, string $retryKey): SendResult
    {
        $conn = LineConnection::current();
        if (! $conn) {
            return SendResult::fail('LINEが連携されていません');
        }

        try {
            $res = Http::baseUrl(config('line.api_base'))
                ->withToken($conn->channel_access_token)
                ->withHeaders(['X-Line-Retry-Key' => $retryKey])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(config('line.timeout'))
                ->post('/v2/bot/message/push', [
                    'to' => $conn->line_user_id,
                    'messages' => [['type' => 'text', 'text' => $text]],
                ]);
        } catch (ConnectionException) {
            // タイムアウトもここに来る。例外の中身（URLやヘッダー）は記録しない
            return SendResult::retry('LINEにつながりませんでした（通信エラーまたはタイムアウト）');
        }

        $code = $res->status();
        $lineMessage = mb_substr((string) ($res->json('message') ?? ''), 0, 200);

        return match (true) {
            $res->successful(), $code === 409 => SendResult::sent(),
            $code >= 500 => SendResult::retry("LINE側で一時的なエラーが起きました（{$code}）"),
            $code === 401 => SendResult::fail('チャネルアクセストークンが正しくないか、無効になっています（401）。LINE連携の画面で登録し直してください', true),
            $code === 403 => SendResult::fail('このチャネルでは送信が許可されていません（403）', true),
            $code === 429 => SendResult::fail("送信の上限に達しました（429）。無料プランの月の上限の可能性があります。{$lineMessage}"),
            $code === 400 => SendResult::fail("送信内容が受け付けられませんでした（400）。ユーザーIDが正しいか確認してください。{$lineMessage}"),
            default => SendResult::fail("送信できませんでした（{$code}）。{$lineMessage}"),
        };
    }

    public function isLive(): bool
    {
        return true;
    }

    /**
     * 今月の送信数（GET /v2/bot/message/quota/consumption）と上限（GET /v2/bot/message/quota）
     * LINE の説明では、送信数は「おおよその数」。LINE Official Account Manager から送った分も含む
     */
    public function quota(): ?array
    {
        $conn = LineConnection::current();
        if (! $conn) {
            return null;
        }
        try {
            $http = Http::baseUrl(config('line.api_base'))->withToken($conn->channel_access_token)->acceptJson()->connectTimeout(5)->timeout(config('line.timeout'));
            $used = $http->get('/v2/bot/message/quota/consumption');
            $limit = $http->get('/v2/bot/message/quota');
        } catch (ConnectionException) {
            return null;
        }
        if (! $used->successful() || ! is_numeric($used->json('totalUsage'))) {
            return null;
        }

        return [
            'used' => (int) $used->json('totalUsage'),
            'limit' => $limit->successful() && $limit->json('type') === 'limited' ? (int) $limit->json('value') : null,
        ];
    }
}
