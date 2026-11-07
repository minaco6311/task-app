@extends('layouts.app')

@section('title', 'アカウントの登録')

@section('content')
    <div class="mx-auto max-w-[420px] px-4 pt-6">
        <form method="POST" action="{{ route('register') }}" class="panel flex flex-col gap-4 p-5">
            @csrf
            <h2 class="panel-title m-0">アカウントの登録</h2>
            <p class="hint">招待されたメールアドレス（{{ $invitation->maskedEmail() }}）で登録します。この招待リンクは1回だけ使えます（{{ \App\Support\Jp::dateTimeLabel($invitation->expires_at) }} まで）。</p>

            <label class="field"><span>表示名</span>
                <input class="input bg-panel-2" type="text" name="name" value="{{ old('name') }}" maxlength="50" autocomplete="nickname" required autofocus>
            </label>
            @error('name') <p class="error">{{ $message }}</p> @enderror

            <label class="field"><span>メールアドレス</span>
                <input class="input bg-panel-2" type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="username" required>
            </label>
            @error('email') <p class="error">{{ $message }}</p> @enderror

            <label class="field"><span>パスワード（{{ \App\Models\User::PASSWORD_MIN }}文字以上）</span>
                <input class="input bg-panel-2" type="password" name="password" minlength="{{ \App\Models\User::PASSWORD_MIN }}" autocomplete="new-password" required>
            </label>
            <label class="field"><span>パスワード（確認）</span>
                <input class="input bg-panel-2" type="password" name="password_confirmation" minlength="{{ \App\Models\User::PASSWORD_MIN }}" autocomplete="new-password" required>
            </label>
            @error('password') <p class="error">{{ $message }}</p> @enderror

            <button class="btn btn-primary">登録してはじめる</button>
        </form>
        <p class="hint mt-3">登録すると、あなた専用のカレンダー・予定・カテゴリ・設定が使えるようになります。ほかの人の予定は見えません（ほかの人からもあなたの予定は見えません）。</p>
    </div>
@endsection
