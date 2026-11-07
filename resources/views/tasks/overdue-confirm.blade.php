@extends('layouts.app')

@section('title', '期限切れをまとめて完了')

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', ['title' => '期限切れをまとめて完了', 'back' => $back])

        @if ($count === 0)
            <p class="hint py-4 text-center">完了にする期限切れの予定はありません。</p>
            <a class="btn btn-ghost" href="{{ $back }}">戻る</a>
        @else
            <div class="panel flex flex-col gap-1.5 p-3.5">
                <p class="m-0 text-[15px]"><b class="text-lg tabular-nums">{{ $count }}件</b> を完了にします。</p>
                <p class="hint">対象：{{ $all ? '今日より前の、すべての未完了の予定' : \App\Support\Jp::dateLabel($from).'から昨日までの未完了の予定（過去'.\App\Services\TaskLists::OVERDUE_DAYS.'日間）' }}</p>
                <p class="hint">完了にした予定は、LINE通知が送られなくなります。あとから1件ずつ未完了に戻せます。</p>
            </div>

            <h3 class="list-head">対象の例（新しい順に{{ $samples->count() }}件{{ $count > $samples->count() ? '・ほか'.($count - $samples->count()).'件' : '' }}）</h3>
            <ul class="m-0 mb-4 list-none p-0">
                @foreach ($samples as $t)
                    <li class="flex items-center gap-2 border-b border-dashed border-dash py-2 text-sm" style="--c: {{ $t->color }}">
                        <i class="size-3 shrink-0 rounded-full bg-(--c)"></i>
                        <span class="shrink-0 text-xs text-muted tabular-nums">{{ \App\Support\Jp::dateLabel($t->date) }} {{ $t->time_label }}</span>
                        <span class="min-w-0 truncate">{{ $t->title }}</span>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('overdue.complete') }}" class="flex flex-col gap-2">
                @csrf
                <input type="hidden" name="range" value="{{ $all ? 'all' : '14' }}">
                <input type="hidden" name="count" value="{{ $count }}">
                <button class="btn btn-primary">{{ $count }}件を完了にする</button>
                <a class="btn btn-ghost" href="{{ $back }}">やめる</a>
            </form>
        @endif
    </div>
@endsection
