@extends('layouts.app')

@section('title', '個別に変更した予定の上書き')

@php
    $newTime = ! empty($data['all_day']) ? '終日' : substr((string) $data['start_time'], 0, 5).'–'.substr((string) $data['end_time'], 0, 5);
    $newRule = $rule
        ? (new \App\Models\TaskSeries([
            'freq' => $rule['freq'], 'weekdays' => implode(',', $rule['weekdays']),
            'month_day' => \Carbon\CarbonImmutable::parse($data['date'])->day, 'until' => $rule['until'],
        ]))->label()
        : '繰り返さない（この予定だけ残す）';
@endphp

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', ['title' => '個別に変更した予定の上書き', 'back' => route('tasks.edit', $task)])

        <div class="panel flex flex-col gap-1.5 p-3.5">
            <p class="m-0 text-[15px]">「この予定だけ」で個別に変更した予定が <b class="text-lg tabular-nums">{{ $mods->count() }}件</b> あります。</p>
            <p class="m-0 text-sm">上書きすると、これらも次の内容に変わります。<b>まだ保存していません。</b></p>
            <dl class="m-0 mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-muted">予定名</dt><dd class="m-0">{{ $data['title'] }}</dd>
                <dt class="text-muted">時間</dt><dd class="m-0 tabular-nums">{{ $newTime }}</dd>
                <dt class="text-muted">繰り返し</dt><dd class="m-0">{{ $newRule }}</dd>
            </dl>
            <p class="hint">新しい繰り返しに合わない日の予定は、上書きしても消えます。完了の状態はそのまま残ります。</p>
        </div>

        <h3 class="list-head">上書きされる予定（いまの内容）</h3>
        <ul class="m-0 mb-4 list-none p-0">
            @foreach ($mods as $m)
                <li class="flex items-center gap-2 border-b border-dashed border-dash py-2 text-sm">
                    <span class="shrink-0 text-xs text-muted tabular-nums">{{ \App\Support\Jp::dateLabel($m->date) }} {{ $m->time_label }}</span>
                    <span class="min-w-0 truncate">{{ $m->title }}</span>
                </li>
            @endforeach
        </ul>

        <form method="POST" action="{{ route('tasks.update', $task) }}" class="flex flex-col gap-2">
            @csrf
            @method('PUT')
            @foreach ($hidden as [$name, $value])
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="hidden" name="confirmed" value="1">
            <button type="submit" name="overwrite_modified" value="0" class="btn btn-primary">個別の変更は残して保存（おすすめ）</button>
            <button type="submit" name="overwrite_modified" value="1" class="btn btn-ghost">{{ $mods->count() }}件も上書きして保存</button>
            <a class="btn btn-ghost" href="{{ route('tasks.edit', $task) }}">保存せずに戻る</a>
        </form>
    </div>
@endsection
