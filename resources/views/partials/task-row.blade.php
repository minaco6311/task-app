{{--
    予定の1行（月表示の予定一覧・今日やること・期限切れ・一覧表示で共通）
    ・左の丸（カテゴリの色）を押す → 完了／未完了を切り替える（編集画面には移らない）
    ・それ以外を押す → 編集画面へ
    ・優先度「高」は小さな「高」の印
    ・サブタスクがあるときは右に「☑ 2/3」。押すとチェック項目が開く
    引数：$t（予定）、$showDate（日付も出す）、$showCategory（カテゴリ名を出す。出さないときは通知・Googleの印）
--}}
@php($showDate ??= false)
@php($showCategory ??= false)
@php($subs = $t->relationLoaded('subtasks') ? $t->subtasks : collect())
@php($subDone = $subs->where('done', true)->count())
@php($ask = (int) session('ask_complete') === $t->id)
@php($uid = 'st-'.$t->id.'-'.\Illuminate\Support\Str::random(6))
<div class="task-item" data-task-item="{{ $t->id }}">
    <div @class(['task-row', 'done' => $t->done]) style="--c: {{ $t->color }}" data-task-row="{{ $t->id }}">
        <form method="POST" action="{{ route('tasks.done', $t) }}" class="shrink-0" data-done-form>
            @csrf
            @method('PATCH')
            <input type="hidden" name="done" value="{{ $t->done ? 0 : 1 }}">
            <button type="submit" class="task-check" aria-pressed="{{ $t->done ? 'true' : 'false' }}"
                    aria-label="「{{ $t->title }}」を{{ $t->done ? '未完了に戻す' : '完了にする' }}">
                <span aria-hidden="true">✓</span>
            </button>
        </form>
        <a href="{{ route('tasks.edit', $t) }}" class="task-link">
            <span class="min-w-[74px] shrink-0 text-xs text-muted tabular-nums">
                @if ($showDate)<span class="block">{{ \App\Support\Jp::dateLabel($t->date) }}</span>@endif{{ $t->time_label }}
            </span>
            @if ($showCategory)
                {{-- 一覧表示：カテゴリ名は予定名の下に（スマホでも予定名の幅を広くとるため） --}}
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="flex min-w-0 items-center gap-1.5">
                        @if ($t->isHighPriority())<span class="prio-high" title="優先度：高">高</span>@endif
                        <span class="title truncate font-medium">{{ $t->title }}</span>
                    </span>
                    @if ($t->category)<span class="truncate text-[11px] text-muted">{{ $t->category->name }}</span>@endif
                </span>
            @else
                @if ($t->isHighPriority())<span class="prio-high" title="優先度：高">高</span>@endif
                <span class="title min-w-0 flex-1 truncate font-medium">{{ $t->title }}</span>
                {{-- 通知・Googleの印は、スマホでは予定名の幅を優先して隠す --}}
                <span class="hidden shrink-0 gap-1 sm:flex">
                    @if (! $t->all_day && $t->notify_before_minutes > 0)
                        <span class="badge">🔔{{ \App\Support\Jp::beforeLabel($t->notify_before_minutes) }}</span>
                    @endif
                    @if ($t->gcal_added)
                        <span class="badge">Google登録済</span>
                    @endif
                </span>
            @endif
        </a>
        @if ($subs->isNotEmpty())
            <button type="button" class="subtask-toggle" data-subtask-toggle aria-controls="{{ $uid }}" aria-expanded="{{ $ask ? 'true' : 'false' }}"
                    aria-label="チェック項目（{{ $subDone }}/{{ $subs->count() }}件完了）を開く">
                <span aria-hidden="true">☑</span><span data-subtask-count="{{ $t->id }}">{{ $subDone }}/{{ $subs->count() }}</span>
            </button>
        @endif
    </div>

    @if ($subs->isNotEmpty())
        <div id="{{ $uid }}" class="subtask-list" @unless ($ask) hidden @endunless>
            @foreach ($subs as $s)
                <form method="POST" action="{{ route('subtasks.done', $s) }}" @class(['subtask-item', 'done' => $s->done]) data-subtask-form data-subtask="{{ $s->id }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="done" value="{{ $s->done ? 0 : 1 }}">
                    <button type="submit" class="subtask-check" aria-pressed="{{ $s->done ? 'true' : 'false' }}"
                            aria-label="「{{ $s->title }}」を{{ $s->done ? '未完了に戻す' : '完了にする' }}"><span aria-hidden="true">✓</span></button>
                    <span class="min-w-0 flex-1 truncate">{{ $s->title }}</span>
                </form>
            @endforeach
            {{-- すべてのサブタスクが完了したとき（予定は自動では完了にしない） --}}
            <div class="subtask-ask" data-subtask-ask="{{ $t->id }}" @unless ($ask && ! $t->done) hidden @endunless role="status">
                <p class="m-0 text-[13px]">すべてのサブタスクが完了しました。このタスクも完了にしますか？</p>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('tasks.done', $t) }}" data-done-form>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="done" value="1">
                        <button type="submit" class="btn btn-small btn-primary">完了する</button>
                    </form>
                    <a href="{{ url()->full() }}" class="btn btn-small bg-panel-2" data-ask-close>まだ完了しない</a>
                </div>
            </div>
        </div>
    @endif
</div>
