@extends('layouts.app')

@php($isNew = ! $task->exists)
@section('title', $isNew ? '予定を追加' : '予定を編集')

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', [
            'title' => $isNew ? '予定を追加' : '予定を編集',
            'back' => \App\Http\Controllers\TaskController::calendarUrl($task->date ?? \App\Support\Jp::today()),
        ])

        {{-- クイック登録から来たとき：読み取った内容の確認（まだ保存していない） --}}
        @isset($quick)
            <div class="panel mb-4 flex flex-col gap-1.5 p-3" role="status">
                <p class="m-0 text-sm">「{{ $quick['text'] }}」から読み取りました。<b>まだ保存していません。</b>内容を確かめて、直してから「保存」を押してください。</p>
                @foreach ($quick['warnings'] as $w)
                    <p class="error m-0">⚠ {{ $w }}</p>
                @endforeach
            </div>
        @endisset
        @php($noDefaultTime = isset($quick) && ! $task->start_time)

        <form method="POST" action="{{ $isNew ? route('tasks.store') : route('tasks.update', $task) }}" class="flex flex-col gap-4" id="task-form"
              data-day-before-time="{{ $settings['day_before_time'] }}" data-morning-time="{{ $settings['morning_time'] }}">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            <div>
                <input class="title-input" name="title" placeholder="タイトル（例：歯医者）" value="{{ old('title', $task->title) }}" maxlength="80" autocomplete="off" @if ($isNew) autofocus @endif>
                @error('title') <p class="error mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-end gap-2.5">
                <label class="field"><span>日付</span>
                    <input class="input" type="date" name="date" value="{{ old('date', $task->date?->format('Y-m-d')) }}" required>
                </label>
                <label class="switch"><input type="checkbox" name="all_day" value="1" @checked(old('all_day', $task->all_day))><span>終日</span></label>
            </div>
            @error('date') <p class="error -mt-2">{{ $message }}</p> @enderror

            <div class="flex items-end gap-2.5" data-timed>
                <label class="field"><span>開始</span>
                    <input class="input" type="time" name="start_time" value="{{ old('start_time', $task->start ?: ($noDefaultTime ? '' : '10:00')) }}" step="300">
                </label>
                <label class="field"><span>終了</span>
                    <input class="input" type="time" name="end_time" value="{{ old('end_time', $task->end ?: ($noDefaultTime ? '' : '11:00')) }}" step="300">
                </label>
            </div>
            @error('start_time') <p class="error -mt-2">{{ $message }}</p> @enderror

            <div class="flex flex-col gap-2.5">
                <div class="text-xs tracking-[.1em] text-muted">色（カテゴリ）</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($categories as $c)
                        <label class="chip">
                            <input type="radio" name="category_id" value="{{ $c->id }}" @checked((int) old('category_id', $task->category_id) === $c->id)>
                            <span style="--c: {{ $c->color }}">{{ $c->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- 優先度（高・中・低。初期値は中）。並び順だけに使い、LINE通知の時刻は変わらない --}}
            <div class="flex flex-col gap-2.5">
                <div class="text-xs tracking-[.1em] text-muted">優先度</div>
                <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="優先度">
                    @foreach (\App\Models\Task::PRIORITIES as $p => $label)
                        <label class="chip">
                            <input type="radio" name="priority" value="{{ $p }}" @checked((int) old('priority', $task->priority) === $p)>
                            <span style="--c: {{ $p === \App\Models\Task::PRIORITY_HIGH ? 'var(--color-sun)' : ($p === \App\Models\Task::PRIORITY_NORMAL ? 'var(--color-accent)' : 'var(--color-muted)') }}">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('priority') <p class="error">{{ $message }}</p> @enderror
            </div>

            {{-- チェック項目（サブタスク）。20個まで。すべてチェックしても、予定は自動では完了にしない --}}
            @php($subRows = old('subtasks') !== null
                ? collect(old('subtasks'))->map(fn ($s) => ['id' => $s['id'] ?? null, 'title' => $s['title'] ?? '', 'done' => (bool) ($s['done'] ?? false)])->values()
                : ($task->exists ? $task->subtasks->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'done' => $s->done]) : collect()))
            <div class="flex flex-col gap-2" data-subtask-editor data-max="{{ \App\Models\Subtask::MAX_PER_TASK }}">
                <div class="flex items-center text-xs tracking-[.1em] text-muted">チェック項目（サブタスク）
                    <span class="ml-auto tabular-nums" data-subtask-counter>{{ $subRows->count() }}/{{ \App\Models\Subtask::MAX_PER_TASK }}</span></div>
                <div class="flex flex-col gap-1.5" data-subtask-rows>
                    @foreach ($subRows as $i => $s)
                        @include('tasks._subtask-edit', ['key' => $i, 's' => $s])
                    @endforeach
                </div>
                @error('subtasks') <p class="error">{{ $message }}</p> @enderror
                @error('subtasks.*.title') <p class="error">{{ $message }}</p> @enderror
                <button type="button" class="btn btn-small bg-panel self-start" data-subtask-add>＋ 項目を追加</button>
                <template>@include('tasks._subtask-edit', ['key' => '__KEY__', 's' => ['id' => null, 'title' => '', 'done' => false]])</template>
                @unless ($isNew)
                    <div class="subtask-ask" data-form-ask hidden role="status">
                        <p class="m-0 text-[13px]">すべてのサブタスクが完了しました。このタスクも完了にしますか？</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="btn btn-small btn-primary" data-form-ask-yes>完了する</button>
                            <button type="button" class="btn btn-small bg-panel-2" data-form-ask-no>まだ完了しない</button>
                        </div>
                    </div>
                @endunless
            </div>

            {{-- 繰り返し（毎日・毎週（曜日を複数）・平日・毎月。終わりなし／日付まで） --}}
            @php($series = $task->exists ? $task->series : null)
            @php($repeat = old('repeat', $series?->freq ?? 'none'))
            @php($repeatDays = array_map('intval', (array) old('repeat_weekdays', $series ? $series->weekdayList() : [($task->date ?? \App\Support\Jp::today())->dayOfWeek])))
            @php($repeatEnd = old('repeat_end', $series?->until ? 'until' : 'none'))
            <div class="panel flex flex-col gap-2.5 px-3.5 py-3" data-repeat>
                <div class="text-[13px] font-medium">🔁 繰り返し</div>
                @if ($series)
                    <p class="hint">いまの繰り返し：{{ $series->label() }}@if ($task->series_modified)（この予定は個別に変更済み）@endif</p>
                @endif
                <select class="input bg-panel-2" name="repeat" data-repeat-freq aria-label="繰り返し">
                    <option value="none" @selected($repeat === 'none')>繰り返さない</option>
                    @foreach (\App\Models\TaskSeries::FREQS as $f => $label)
                        <option value="{{ $f }}" @selected($repeat === $f)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="繰り返す曜日" data-repeat-weekly>
                    @foreach (\App\Support\Jp::WEEK as $i => $w)
                        <label class="chip">
                            <input type="checkbox" name="repeat_weekdays[]" value="{{ $i }}" @checked(in_array($i, $repeatDays, true))>
                            <span style="--c: {{ $i === 0 ? 'var(--color-sun)' : ($i === 6 ? 'var(--color-sat)' : 'var(--color-accent)') }}">{{ $w }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="hint" data-repeat-monthly>毎月、予定の日付と同じ日に繰り返します。31日のようにその日がない月は、月末に入れます。</p>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2" data-repeat-end>
                    <label class="check"><input type="radio" name="repeat_end" value="none" @checked($repeatEnd === 'none')><span>終わりなし</span></label>
                    <label class="check"><input type="radio" name="repeat_end" value="until" @checked($repeatEnd === 'until')><span>この日まで</span></label>
                    <input class="input w-auto bg-panel-2" type="date" name="repeat_until" value="{{ old('repeat_until', $series?->until?->format('Y-m-d')) }}" aria-label="繰り返しの終わりの日">
                </div>
                @error('repeat_weekdays') <p class="error">{{ $message }}</p> @enderror
                @error('repeat_until') <p class="error">{{ $message }}</p> @enderror
                @php($mods = $series ? \App\Services\Recurrence::modifiedAfter($task) : collect())
                @if ($mods->isNotEmpty())
                    <div class="rounded-[3px] border border-dashed border-rule p-2.5">
                        <p class="hint">この後の予定のうち <b>{{ $mods->count() }}件</b> は「この予定だけ」で個別に変更されています（{{ $mods->take(3)->map(fn ($m) => \App\Support\Jp::dateLabel($m->date).' '.$m->title)->implode('、') }}{{ $mods->count() > 3 ? ' ほか' : '' }}）。
                            「以後すべて」で保存しても、標準ではこれらの変更を残します。</p>
                        <label class="check mt-1.5"><input type="checkbox" name="overwrite_modified" value="1" @checked(old('overwrite_modified'))>
                            <span class="text-[13px]">個別に変更した{{ $mods->count() }}件も上書きする（保存の前に確認します）</span></label>
                    </div>
                @endif
                <p class="hint">
                    @if ($series)
                        繰り返しの変更は「この予定以後すべてに保存」で反映されます。「この予定だけ保存」では、この予定だけが変わります。
                    @else
                        90日先までの予定を作り、毎日自動で先へ足していきます。チェック項目は各回にコピーされ、完了は回ごとです。
                    @endif
                </p>
            </div>

            <div class="panel flex flex-col gap-2.5 px-3.5 py-3">
                <div class="flex items-center gap-1.5 text-[13px] font-medium"><span class="mini-badge">LINE</span>通知</div>
                <label class="check"><input type="checkbox" name="notify_day_before" value="1" @checked(old('notify_day_before', $task->notify_day_before))>
                    <span>前日 {{ $settings['day_before_time'] }} の「明日の予定」に入れる</span></label>
                <label class="check"><input type="checkbox" name="notify_morning" value="1" @checked(old('notify_morning', $task->notify_morning))>
                    <span>当日 {{ $settings['morning_time'] }} の「今日の予定」に入れる</span></label>
                <label class="field" data-timed><span>開始前の通知</span>
                    <select class="input bg-panel-2" name="notify_before_minutes">
                        @foreach (\App\Support\Jp::BEFORE_OPTIONS as $min => $label)
                            <option value="{{ $min }}" @selected((int) old('notify_before_minutes', $task->notify_before_minutes) === $min)>
                                {{ $label }}{{ $min === $settings['default_before'] ? '（標準）' : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="hint" data-notify-preview></p>
                <label class="check"><input type="checkbox" name="remind_until_done" value="1" @checked(old('remind_until_done', $task->remind_until_done))>
                    <span>完了まで再通知する</span></label>
                <p class="hint -mt-1.5 pl-[30px]">開始時刻（終日なら当日朝の通知の時刻）から1時間おき、1日3回・合計5回まで。22時〜7時は送りません。完了にすると止まります。</p>
            </div>

            {{-- あとで再通知（スヌーズ）。ボタンはフォームの外の小さなフォームを送る --}}
            @if (! $isNew && ! $task->done)
                @php($pending = $task->reminders->filter(fn ($r) => $r->remind_at->isFuture()))
                <div class="panel flex flex-col gap-2 px-3.5 py-3" id="snooze">
                    <div class="text-[13px] font-medium">⏰ あとで再通知（スヌーズ）</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Http\Controllers\ReminderController::OPTIONS as $when => $label)
                            @php($at = $snoozeTimes[$when])
                            @php($night = \App\Http\Controllers\ReminderController::isNight($at))
                            <button type="submit" form="snooze-form" name="when" value="{{ $when }}" class="btn btn-small bg-panel-2" @disabled($snoozeBlocked)
                                    aria-label="{{ $label }}（{{ \App\Support\Jp::dateTimeLabel($at) }}{{ $night ? '・夜間' : '' }}）に再通知">
                                {{ $label }} <span class="text-xs text-muted tabular-nums">{{ $when === 'tomorrow' ? $at->format('n/j H:i') : $at->format('H:i') }}</span>@if ($night)<span title="夜間（22時〜7時）"> 🌙</span>@endif
                            </button>
                        @endforeach
                    </div>
                    @if ($snoozeBlocked)
                        <p class="error" role="alert">{{ $snoozeBlocked }}</p>
                    @endif
                    @if (session('snooze_night'))
                        {{-- 夜間の再通知：まだ予約していない。確認したら予約する --}}
                        <div class="subtask-ask" role="alert" data-subtask-ask="night">
                            <p class="m-0 text-[13px]">🌙 {{ session('snooze_night')['at'] }} は夜間（22時〜7時）です。夜間でもLINEに再通知しますか？（まだ予約していません）</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" form="snooze-night-form" class="btn btn-small btn-primary">夜間でも予約する</button>
                                {{-- やめる：JavaScript で閉じる（動かないときは、読み込み直して閉じる。#付きの同じ URL だと読み込み直されないので付けない） --}}
                                <a href="{{ route('tasks.edit', $task) }}" class="btn btn-small bg-panel-2" data-ask-close>やめる</a>
                            </div>
                        </div>
                    @endif
                    <p class="hint">「翌日」は翌朝 {{ config('line.snooze_tomorrow_at') }} に送ります。🌙 は夜間（22時〜7時）に届く時刻で、予約の前に確認します。予定の日付や時刻を変えると、予約は取り消されます。</p>
                    @foreach ($pending as $r)
                        <div class="flex items-center gap-2 border-t border-dashed border-dash pt-1.5 text-sm">
                            <span class="tabular-nums">{{ \App\Support\Jp::dateTimeLabel($r->remind_at) }}</span><span class="text-muted">に再通知</span>
                            <button type="submit" form="reminder-del-{{ $r->id }}" class="ml-auto text-xs text-sun">取り消す</button>
                        </div>
                    @endforeach
                </div>
            @endif

            <label class="field"><span>メモ</span>
                <textarea class="input resize-y leading-[1.7]" name="memo" rows="2" maxlength="500">{{ old('memo', $task->memo) }}</textarea>
            </label>

            @unless ($isNew)
                <label class="check"><input type="checkbox" name="done" value="1" @checked(old('done', $task->done))><span>完了にする</span></label>
            @endunless

            <div class="mt-1 flex flex-col gap-2">
                @if ($series)
                    {{-- 繰り返しの回：保存する範囲を選ぶ（Enter キーでは「この予定だけ」） --}}
                    <button type="submit" name="scope" value="this" class="btn btn-primary">この予定だけ保存</button>
                    <button type="submit" name="scope" value="following" class="btn btn-ghost">この予定以後すべてに保存</button>
                @else
                    <button type="submit" class="btn btn-primary">保存</button>
                @endif
                <button type="submit" name="action" value="gcal" class="btn btn-ghost">
                    保存して{{ $task->gcal_added ? 'もう一度 ' : '' }}Googleカレンダーに登録
                </button>
                @if ($series)
                    <button type="submit" form="delete-form" name="scope" value="this" class="btn btn-danger" data-confirm="もう一度押すと、この予定だけ削除します">この予定だけ削除</button>
                    <button type="submit" form="delete-form" name="scope" value="following" class="btn btn-danger"
                            data-confirm="もう一度押すと、この予定以後すべてを削除します{{ $mods->isNotEmpty() ? '（個別に変更した'.$mods->count().'件も含む）' : '' }}">この予定以後すべて削除</button>
                @elseif (! $isNew)
                    <button type="submit" form="delete-form" class="btn btn-danger" data-confirm="もう一度押すと削除します">削除</button>
                @endif
            </div>
        </form>

        @unless ($isNew)
            <form method="POST" action="{{ route('tasks.destroy', $task) }}" id="delete-form" hidden>
                @csrf
                @method('DELETE')
            </form>
            <form method="POST" action="{{ route('reminders.store', $task) }}" id="snooze-form" hidden>
                @csrf
            </form>
            @if (session('snooze_night'))
                <form method="POST" action="{{ route('reminders.store', $task) }}" id="snooze-night-form" hidden>
                    @csrf
                    <input type="hidden" name="when" value="{{ session('snooze_night')['when'] }}">
                    <input type="hidden" name="night_ok" value="1">
                </form>
            @endif
            @foreach ($task->reminders as $r)
                <form method="POST" action="{{ route('reminders.destroy', $r) }}" id="reminder-del-{{ $r->id }}" hidden>
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach
        @endunless
    </div>
@endsection
