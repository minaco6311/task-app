@extends('layouts.app')

@section('title', '登録しました')

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', ['title' => '✅ '.$tasks->count().'件を登録しました', 'back' => route('calendar', ['date' => $tasks->first()?->date->format('Y-m-d')])])

        <p class="hint mb-3">Googleカレンダーにも入れる場合は、1件ずつ「Googleに登録」を押して、開いた画面で「保存」を押してください。PCなら .ics でまとめて取り込めます。</p>

        <div class="flex flex-col gap-2">
            @foreach ($tasks as $t)
                <div class="flex items-center gap-2.5 rounded-[3px] border-l-[5px] border-(--c) bg-panel px-3 py-2.5" style="--c: {{ $t->color }}">
                    <span class="min-w-0 flex-1 truncate text-sm">{{ \App\Support\Jp::dateLabel($t->date) }} {{ $t->all_day ? '終日' : $t->start }} {{ $t->title }}</span>
                    <form method="POST" action="{{ route('tasks.google', $t) }}" target="_blank" data-gcal-form>
                        @csrf
                        <button class="btn btn-small bg-panel-2">{{ $t->gcal_added ? '登録画面を開きました' : 'Googleに登録' }}</button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex flex-col gap-2">
            <a class="btn btn-ghost" href="{{ route('export.ics', ['ids' => $tasks->pluck('id')->implode(',')]) }}">まとめて .ics で書き出す</a>
            <a class="btn btn-primary" href="{{ route('calendar', ['date' => $tasks->first()?->date->format('Y-m-d')]) }}">カレンダーを見る</a>
        </div>
    </div>
@endsection
