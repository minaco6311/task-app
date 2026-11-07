@extends('layouts.app')

@section('title', '招待リンクの期限切れ')

@section('content')
    <div class="mx-auto max-w-[420px] px-4 pt-6">
        <div class="panel flex flex-col gap-3 p-5">
            <h2 class="panel-title m-0">招待リンクの期限が切れています</h2>
            <p class="hint">招待リンクは、発行してから{{ \App\Models\Invitation::VALID_DAYS }}日間だけ使えます。お手数ですが、招待してくれた人に新しい招待リンクをお願いしてください。</p>
            <a class="btn btn-ghost" href="{{ route('login') }}">ログイン画面へ</a>
        </div>
    </div>
@endsection
