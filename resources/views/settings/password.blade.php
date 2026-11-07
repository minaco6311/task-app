@extends('layouts.app')

@section('title', 'パスワードの変更')

@section('content')
    <div class="px-4">
        @include('partials.page-head', ['title' => 'パスワードの変更', 'back' => route('settings.edit')])

        <form method="POST" action="{{ route('password.update') }}" class="mx-auto flex max-w-[420px] flex-col gap-4">
            @csrf
            @method('PUT')
            <p class="hint">ログイン中：{{ auth()->user()->email }}</p>

            <label class="field"><span>いまのパスワード</span>
                <input class="input bg-panel-2" type="password" name="current_password" autocomplete="current-password" required autofocus>
            </label>
            @error('current_password') <p class="error">{{ $message }}</p> @enderror

            <label class="field"><span>新しいパスワード（{{ \App\Models\User::PASSWORD_MIN }}文字以上）</span>
                <input class="input bg-panel-2" type="password" name="password" minlength="{{ \App\Models\User::PASSWORD_MIN }}" autocomplete="new-password" required>
            </label>
            <label class="field"><span>新しいパスワード（確認）</span>
                <input class="input bg-panel-2" type="password" name="password_confirmation" minlength="{{ \App\Models\User::PASSWORD_MIN }}" autocomplete="new-password" required>
            </label>
            @error('password') <p class="error">{{ $message }}</p> @enderror

            <p class="hint">変えると、ほかの端末（スマホ・別のブラウザ）ではログインし直しが必要になります。</p>
            <button class="btn btn-primary">パスワードを変える</button>
        </form>
    </div>
@endsection
