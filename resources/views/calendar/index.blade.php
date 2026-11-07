@extends('layouts.app')

@section('shell', 'mx-auto flex h-dvh max-w-[760px] flex-col')

@section('content')
    {{-- 前へ・期間・次へ・今日・設定 --}}
    {{-- スマホ（640px 未満）では文字を画面幅に合わせて少し小さくし、ボタンの幅を詰めて、期間を最後まで1行で出す --}}
    <header class="flex items-center gap-1 px-3 py-1">
        <a class="icon-btn no-underline max-sm:w-8" href="{{ route('calendar', ['view' => $view, 'date' => $prev]) }}" aria-label="前へ">‹</a>
        <h1 class="m-0 mx-0.5 min-w-0 flex-1 text-center text-[clamp(15px,4.6vw,19px)] font-medium tracking-[.04em] whitespace-nowrap sm:mx-1 sm:text-[19px] sm:tracking-[.1em]">{{ $period }}</h1>
        <a class="icon-btn no-underline max-sm:w-8" href="{{ route('calendar', ['view' => $view, 'date' => $next]) }}" aria-label="次へ">›</a>
        <a class="pill no-underline max-sm:px-2.5" href="{{ route('calendar', ['view' => $view, 'date' => $today->format('Y-m-d')]) }}">今日</a>
        <a class="icon-btn no-underline max-sm:w-8" href="{{ route('settings.edit') }}" aria-label="設定">⚙︎</a>
    </header>

    {{-- クイック登録：1行の文章を読み取って予定のフォームに入れる（保存はフォームで確認してから） --}}
    <form method="GET" action="{{ route('tasks.create') }}" class="mx-4 mb-1.5 flex gap-1.5" role="search" aria-label="クイック登録">
        <input class="input min-w-0 flex-1 py-[7px] text-[15px]" type="text" name="q" maxlength="200" autocomplete="off" enterkeyhint="go"
               placeholder="例：明日10時 歯医者" aria-label="クイック登録（例：明日10時 歯医者）">
        <button class="btn btn-small btn-primary shrink-0">読み取る</button>
    </form>

    {{-- 月・週・日・一覧の切り替え --}}
    <nav class="mx-4 mt-1 mb-2 flex gap-1.5" aria-label="表示切り替え">
        @foreach (['month' => '月', 'week' => '週', 'day' => '日', 'list' => '一覧'] as $v => $label)
            <a href="{{ route('calendar', ['view' => $v, 'date' => $date->format('Y-m-d')]) }}"
               @class([
                   'flex-1 rounded-[3px] py-1.5 text-center text-sm tracking-[.3em] no-underline',
                   'bg-accent font-bold text-white' => $view === $v,
                   'bg-panel text-muted' => $view !== $v,
               ])
               @if ($view === $v) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    {{-- 凡例（色の意味） --}}
    {{-- 画面に収まらない分は次の行へ折り返す（色の丸と名前は1組のまま） --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 pt-0.5 pb-2 text-xs text-muted" aria-label="凡例">
        @foreach ($categories as $c)
            <span class="inline-flex items-center gap-[5px] whitespace-nowrap"><i class="size-[11px] shrink-0 rounded-full bg-(--c)" style="--c: {{ $c->color }}"></i>{{ $c->name }}</span>
        @endforeach
        <a href="{{ route('settings.edit') }}#legend" class="ml-auto pl-2 whitespace-nowrap text-accent-ink underline decoration-dotted underline-offset-[3px]">色を編集</a>
    </div>

    {{-- 下の余白：右下のボタン（＋・手帳から）の分＋ iPhone のセーフエリア。いちばん下の予定がボタンに隠れない --}}
    @if ($view === 'month')
        <main class="min-h-0 flex-1 overflow-auto px-4 pb-[calc(120px+env(safe-area-inset-bottom))]">
            @include('calendar._month')
        </main>
    @elseif ($view === 'list')
        <main class="min-h-0 flex-1 overflow-auto px-4 pb-[calc(120px+env(safe-area-inset-bottom))]">
            @include('calendar._list')
        </main>
    @else
        <main class="min-h-0 flex-1 overflow-hidden px-4">
            @include('calendar._timeline')
        </main>
    @endif

    {{-- 「保存してGoogleカレンダーに登録」を押したあと --}}
    @if ($gcalTask)
        <div class="fixed inset-x-4 bottom-[calc(90px+env(safe-area-inset-bottom))] z-30 mx-auto max-w-[560px] rounded-[3px] bg-paper p-3 shadow-[0_6px_20px_rgba(40,70,90,.25)] ring-1 ring-accent">
            <p class="hint mb-2">「{{ $gcalTask->title }}」をGoogleカレンダーにも入れます。開いた画面で「保存」を押してください。</p>
            <form method="POST" action="{{ route('tasks.google', $gcalTask) }}" target="_blank" data-gcal-form>
                @csrf
                <button class="btn btn-primary">Googleカレンダーの登録画面を開く</button>
            </form>
        </div>
    @endif

    {{-- 右下のボタン（スマホでは少し小さく、少し下に） --}}
    <div class="fixed right-[max(16px,calc(50%-364px))] bottom-[calc(18px+env(safe-area-inset-bottom))] z-20 flex items-center gap-2.5 max-sm:bottom-[calc(12px+env(safe-area-inset-bottom))] max-sm:gap-2" data-fab>
        <a href="{{ route('import.create', ['date' => $date->format('Y-m-d')]) }}"
           class="flex h-11 items-center gap-1.5 rounded-full border-[1.5px] border-accent bg-paper px-4 text-sm font-medium tracking-[.05em] text-accent-ink no-underline shadow-[0_6px_20px_rgba(40,70,90,.16)] max-sm:h-10 max-sm:px-3 max-sm:text-[13px]">
            <span aria-hidden="true">📷</span>手帳から
        </a>
        <a href="{{ route('tasks.create', ['date' => $date->format('Y-m-d')]) }}"
           class="grid size-[58px] place-items-center rounded-full bg-accent text-[28px] text-white no-underline shadow-[0_6px_20px_rgba(40,70,90,.16)] max-sm:size-12 max-sm:text-2xl" aria-label="予定を追加">＋</a>
    </div>
@endsection
