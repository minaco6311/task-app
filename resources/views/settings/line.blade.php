@extends('layouts.app')

@section('title', 'LINE連携')

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', ['title' => 'LINE連携', 'back' => route('settings.edit')])

        {{-- いまの状態 --}}
        <section class="flex flex-col gap-2.5 pb-4">
            <div class="panel flex items-start gap-3 p-3">
                <span class="shrink-0 rounded-[10px] bg-line px-2 py-[7px] font-sans text-[11px] font-extrabold text-white">LINE</span>
                <div class="flex min-w-0 flex-col gap-1">
                    @if (! $conn)
                        <b>未連携</b>
                        <p class="hint">下の手順でLINE公式アカウントを作り、トークンとユーザーIDを登録してください。連携しなくても、予定の管理はそのまま使えます。</p>
                    @elseif ($conn->status === 'ok')
                        <b>連携済み <span class="status-tag ok">テスト送信OK</span></b>
                        <p class="hint">トークン {{ $conn->maskedToken() }} ／ ユーザーID {{ $conn->maskedUserId() }}<br>テスト送信に成功：{{ \App\Support\Jp::dateTimeLabel($conn->verified_at) }}</p>
                    @elseif ($conn->status === 'error')
                        <b>エラー <span class="status-tag ng">再設定が必要</span></b>
                        <p class="hint">{{ $conn->last_error }}</p>
                        <p class="hint">トークン {{ $conn->maskedToken() }} ／ ユーザーID {{ $conn->maskedUserId() }}</p>
                    @else
                        <b>登録済み <span class="status-tag">テスト送信がまだ</span></b>
                        <p class="hint">トークン {{ $conn->maskedToken() }} ／ ユーザーID {{ $conn->maskedUserId() }}<br>「テスト送信」を押して、LINEに届くか確かめてください。</p>
                    @endif
                </div>
            </div>
            @unless ($live)
                <p class="hint rounded-[3px] border border-dashed border-rule p-2.5">
                    いまは<b>シミュレーション</b>の設定です（<code>.env</code> の <code>LINE_DRIVER=log</code>）。テスト送信や通知を行っても、LINEには届かず、通知履歴とログに記録されるだけです。実際に送るには <code>LINE_DRIVER=line</code> にします（README.md の「LINE通知を本番で使う」）。
                </p>
            @endunless
        </section>

        {{-- 登録フォーム --}}
        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4" id="form">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">トークンとユーザーIDを登録</h3>
            <form method="POST" action="{{ route('line.update') }}" class="flex flex-col gap-3" autocomplete="off">
                @csrf
                @method('PUT')
                <label class="field"><span>チャネルアクセストークン（長期）</span>
                    <input class="input" type="password" name="channel_access_token" autocomplete="off" spellcheck="false"
                           placeholder="{{ $conn ? 'いまのトークンを使う場合は空欄のまま' : '長い英数字の文字列を貼り付け' }}">
                </label>
                @error('channel_access_token') <p class="error">{{ $message }}</p> @enderror
                <label class="field"><span>あなたのユーザーID</span>
                    <input class="input font-sans" type="text" name="line_user_id" value="{{ old('line_user_id') }}" autocomplete="off" spellcheck="false"
                           placeholder="{{ $conn ? 'いまのユーザーIDを使う場合は空欄のまま' : 'U から始まる33文字' }}" maxlength="40">
                </label>
                @error('line_user_id') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">トークンとユーザーIDは暗号化して保存します。保存したあとは画面に表示されません（一部だけ表示）。</p>
                <button class="btn btn-primary">保存</button>
            </form>

            @if ($conn)
                <form method="POST" action="{{ route('line.test') }}">
                    @csrf
                    <button class="btn btn-ghost">テスト送信</button>
                </form>
                <form method="POST" action="{{ route('line.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-danger" data-confirm="もう一度押すと連携を解除します">LINE連携を解除</button>
                </form>
            @endif
        </section>

        {{-- 設定ガイド --}}
        <section class="flex flex-col gap-3 border-t border-dashed border-dash py-4" id="guide">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">設定ガイド（はじめての人向け・10分ほど）</h3>
            <p class="hint">自分専用の「通知用のLINE公式アカウント」を作り、そこからあなたのLINEに通知を送るしくみです。無料プランで使えます（無料プランで送れるのは月200通まで）。LINEの画面は変わることがあるので、言葉が少し違っていたら近いものを選んでください。</p>

            <ol class="guide-steps">
                <li>
                    <b>LINE公式アカウントを作る</b>
                    <p><a href="https://manager.line.biz/" target="_blank" rel="noopener">LINE Official Account Manager</a> を開き、自分のLINEアカウントでログインします。「アカウントを作成」から、名前（例：いろどり手帳 通知）などを入力して作ります。</p>
                </li>
                <li>
                    <b>Messaging API を使えるようにする</b>
                    <p>作ったアカウントの画面で、右上の「設定」→ 左の「Messaging API」→「Messaging APIを利用する」を押します。プロバイダー（まとめ役の名前。自分の名前などで可）を作るか選んで、進めます。</p>
                </li>
                <li>
                    <b>LINE Developers を開く</b>
                    <p><a href="https://developers.line.biz/console/" target="_blank" rel="noopener">LINE Developers コンソール</a> に同じLINEアカウントでログインし、さきほどのプロバイダー → 作ったチャネル（公式アカウントと同じ名前）を開きます。</p>
                </li>
                <li>
                    <b>「あなたのユーザーID」をコピーする</b>
                    <p>「チャネル基本設定」タブのいちばん下にある「あなたのユーザーID」（U から始まる33文字）をコピーし、上の「あなたのユーザーID」に貼り付けます。</p>
                </li>
                <li>
                    <b>チャネルアクセストークン（長期）を発行してコピーする</b>
                    <p>「Messaging API設定」タブのいちばん下にある「チャネルアクセストークン（長期）」で「発行」を押し、出てきた長い文字列をコピーして、上の「チャネルアクセストークン」に貼り付けます。</p>
                </li>
                <li>
                    <b>公式アカウントを友だち追加する</b>
                    <p>同じ「Messaging API設定」タブにある QRコードを、スマホのLINEで読み取って友だち追加します。友だちになっていないと通知は届きません。</p>
                </li>
                <li>
                    <b>保存して「テスト送信」を押す</b>
                    <p>上の「保存」→「テスト送信」を押して、LINEに「いろどり手帳とLINEがつながりました」と届けば完了です。</p>
                </li>
            </ol>

            <div class="panel flex flex-col gap-1.5 p-3">
                <b class="text-sm">うまくいかないとき</b>
                <ul class="hint m-0 list-disc pl-4">
                    <li>「トークンが正しくない（401）」：トークンをもう一度発行して、全部をコピーし直してください。</li>
                    <li>「送信内容が受け付けられない（400）」：ユーザーIDがチャネル基本設定の「あなたのユーザーID」になっているか確かめてください（チャネルIDとは別のものです）。</li>
                    <li>「送信の上限（429）」：無料プランの月の上限に達した可能性があります。翌月まで待つか、プランを見直してください。</li>
                    <li>エラーは出ないのに届かない：公式アカウントを友だち追加しているか、ブロックしていないか確かめてください。</li>
                    <li>公式アカウントに話しかけると自動の返事が来る：LINE Official Account Manager の「応答設定」で応答メッセージをオフにできます（通知には影響しません）。</li>
                </ul>
            </div>
            <p class="hint">トークンは他の人に教えないでください。もし漏れたときは、LINE Developers でトークンを再発行してから、ここで登録し直してください。</p>
        </section>
    </div>
@endsection
