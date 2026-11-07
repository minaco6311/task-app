@extends('layouts.app')

@section('title', '招待リンクが使えません')

@section('content')
    <div class="mx-auto max-w-[420px] px-4 pt-6">
        <div class="panel flex flex-col gap-3 p-5">
            <h2 class="panel-title m-0">招待リンクが使えません</h2>
            <p>{{ $reason }}</p>
            <p class="hint">招待リンクは1回だけ使えます。すでに登録した方は、ログイン画面からログインしてください。まだ登録していない方は、招待してくれた人に新しい招待リンクをお願いしてください。</p>
            <a class="btn btn-ghost" href="{{ route('login') }}">ログイン画面へ</a>
        </div>
    </div>
@endsection
