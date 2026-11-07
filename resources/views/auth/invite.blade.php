@extends('layouts.app')

@section('title', '招待リンクの確認')

@section('content')
    {{--
        招待リンクは https://…/invite#トークン の形。# の後ろはサーバーに送られないので、アクセスログに残らない
        resources/js/app.js が URL からトークンを消してから、下のフォームで POST する（JavaScript が動かないときは貼り付けて送る）
    --}}
    <div class="mx-auto max-w-[420px] px-4 pt-6">
        <form method="POST" action="{{ route('invite.redeem') }}" class="panel flex flex-col gap-4 p-5" data-invite-form>
            @csrf
            <h2 class="panel-title m-0">招待リンクの確認</h2>
            <p class="hint" data-invite-checking hidden>招待リンクを確認しています…</p>
            <div class="flex flex-col gap-4" data-invite-manual>
                <p class="hint">招待リンクを開いた方は、そのまま少しお待ちください。画面が変わらないときは、招待リンクの「#」より後ろの英数字（64文字）を貼り付けて「確認する」を押してください。</p>
                <label class="field"><span>招待コード</span>
                    <input class="input bg-panel-2 font-sans" type="text" name="token" autocomplete="off" spellcheck="false" inputmode="latin" maxlength="80" required>
                </label>
                <button class="btn btn-primary">確認する</button>
            </div>
        </form>
        <p class="hint mt-3">このアプリは招待制です。招待リンクは、管理者から直接受け取ったものだけを使ってください。<a href="{{ route('login') }}">ログイン画面へ</a></p>
    </div>
@endsection
