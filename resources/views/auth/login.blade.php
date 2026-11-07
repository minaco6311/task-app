@extends('layouts.app')

@section('title', 'ログイン')

@section('content')
    <div class="mx-auto max-w-[420px] px-4 pt-6">
        <form method="POST" action="{{ route('login') }}" class="panel flex flex-col gap-4 p-5">
            @csrf
            <h2 class="panel-title m-0">ログイン</h2>

            <label class="field"><span>メールアドレス</span>
                <input class="input bg-panel-2" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            </label>
            <label class="field"><span>パスワード</span>
                <input class="input bg-panel-2" type="password" name="password" autocomplete="current-password" required>
            </label>
            @error('email') <p class="error">{{ $message }}</p> @enderror
            @error('password') <p class="error">{{ $message }}</p> @enderror

            <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))><span>ログインしたままにする</span></label>

            <button class="btn btn-primary">ログイン</button>
        </form>
        <p class="hint mt-3">このアプリは招待制です。はじめての方は、管理者から受け取った招待リンクを開いて登録してください。パスワードを忘れたときは、管理者に連絡してください。</p>
    </div>
@endsection
