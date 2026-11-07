{{--
    月表示
    ・予定名を出さない（標準）：カテゴリの色の線だけ（1日あたり最大4本＋「+N」）
    ・予定名を出す（設定で切り替え）：色の付いた予定名を1日3件まで＋「＋N件」
--}}
@php($max = $showTitles ? 3 : 4)
<div class="panel px-2 pt-2.5 pb-1.5">
    <div class="grid grid-cols-7 justify-items-center pb-2">
        @foreach (\App\Support\Jp::WEEK as $i => $w)
            <span class="wk-dot w{{ $i }}">{{ $w }}</span>
        @endforeach
    </div>
    <div class="grid grid-cols-7">
        @foreach ($days as $d)
            @php($k = $d['date']->format('Y-m-d'))
            <a href="{{ route('calendar', ['view' => 'month', 'date' => $k]) }}"
               @class([
                   'day-cell no-underline text-inherit',
                   'w'.$d['date']->dayOfWeek,
                   'other' => ! $d['date']->isSameMonth($date),
                   'today' => $d['date']->isSameDay($today),
                   'sel' => $d['date']->isSameDay($date),
               ])
               aria-label="{{ \App\Support\Jp::dateLabel($d['date']) }} 予定{{ $d['tasks']->count() }}件">
                <span class="num">{{ $d['date']->day }}</span>
                <span class="flex w-full min-w-0 flex-col gap-[3px]">
                    @foreach ($d['tasks']->take($max) as $t)
                        @if ($showTitles)
                            <span @class(['mcell-title', 'done' => $t->done]) style="--c: {{ $t->color }}" data-task-bar="{{ $t->id }}">{{ mb_strimwidth($t->title, 0, 16, '…') }}</span>
                        @else
                            <i @class(['bar', 'done' => $t->done]) style="--c: {{ $t->color }}" data-task-bar="{{ $t->id }}"></i>
                        @endif
                    @endforeach
                    @if ($d['tasks']->count() > $max)
                        <b class="text-center text-[10px] leading-none font-normal whitespace-nowrap text-muted">{{ $showTitles ? '＋'.($d['tasks']->count() - $max).'件' : '+'.($d['tasks']->count() - $max) }}</b>
                    @endif
                </span>
            </a>
        @endforeach
    </div>
</div>

{{-- 選んだ日の予定一覧 --}}
<section class="panel mt-3 px-3.5 pt-3 pb-2">
    <div class="flex flex-col items-center gap-1 pb-1.5">
        <h2 class="panel-title m-0">{{ \App\Support\Jp::dateLabel($date) }}</h2>
        <a href="{{ route('calendar', ['view' => 'day', 'date' => $date->format('Y-m-d')]) }}" class="text-xs tracking-[.05em] text-accent-ink">タイムラインで見る ›</a>
    </div>
    @forelse ($agenda as $t)
        @include('partials.task-row', ['t' => $t])
    @empty
        <p class="m-0 border-b border-dashed border-dash px-1 py-[18px] text-center text-muted">
            予定はありません
            <a href="{{ route('tasks.create', ['date' => $date->format('Y-m-d')]) }}" class="ml-1.5 text-accent-ink">＋ 追加する</a>
        </p>
    @endforelse
</section>

{{--
    今日の達成状況・今日やること・期限切れ
    （選んだ日が今日のときは、今日の予定は上の一覧にあるので、達成状況と期限切れだけ）
--}}
@php($showToday = ! $date->isSameDay($today))
@php($od = $overdue)
@php($pg = $progress)
<section class="panel mt-3 px-3.5 pt-1 pb-2" id="todo">
    {{-- 今日の達成状況（今日の日付の予定だけを数える。期限切れ・サブタスク・明日以降は数えない） --}}
    <div class="flex flex-col gap-1.5 pt-3 pb-1" data-progress>
        <p @class(['m-0 text-[13px]', 'hidden' => ! $pg['total']]) data-progress-text>
            今日の達成状況：<b class="tabular-nums" data-progress-count>{{ $pg['done'] }}/{{ $pg['total'] }}件</b> 完了（<span class="tabular-nums" data-progress-percent>{{ $pg['percent'] }}</span>%）
        </p>
        <div @class(['progress-bar', 'hidden' => ! $pg['total']]) role="progressbar" aria-label="今日の達成状況"
             aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pg['percent'] }}" data-progress-bar>
            <i style="width: {{ $pg['percent'] }}%"></i>
        </div>
        @unless ($pg['total'])
            <p class="m-0 text-[13px] text-muted">今日の予定はありません</p>
        @endunless
    </div>

    {{-- 今日やること（優先度の高い順。0件のときは上の「今日の予定はありません」だけ） --}}
    @if ($showToday && $todayTasks->isNotEmpty())
        <h3 class="list-head m-0">今日やること <span class="font-normal text-muted">{{ \App\Support\Jp::dateLabel($today) }}</span>
            <a href="{{ route('calendar', ['view' => 'month', 'date' => $today->format('Y-m-d')]) }}" class="ml-auto text-xs font-normal tracking-normal text-accent-ink">今日を選ぶ ›</a></h3>
        @foreach ($todayTasks as $t)
            @include('partials.task-row', ['t' => $t])
        @endforeach
    @endif

    @if ($od['count'] > 0 || $od['older'] > 0 || $od['all'])
        <h3 class="list-head m-0">期限切れ（未完了）
            <span class="font-normal text-muted">{{ $od['all'] ? 'すべて' : '過去'.\App\Services\TaskLists::OVERDUE_DAYS.'日間' }}・{{ $od['count'] }}件</span></h3>
        @forelse ($od['tasks'] as $t)
            @include('partials.task-row', ['t' => $t, 'showDate' => true])
        @empty
            <p class="m-0 border-b border-dashed border-dash px-1 py-3 text-center text-sm text-muted">
                {{ $od['all'] ? '期限切れの未完了はありません' : '過去'.\App\Services\TaskLists::OVERDUE_DAYS.'日間の期限切れはありません' }}</p>
        @endforelse
        @if ($od['count'] > $od['tasks']->count())
            <p class="hint pt-2">優先度の高い順・日付の古い順に{{ $od['limit'] }}件まで表示しています（ほか{{ $od['count'] - $od['tasks']->count() }}件）。</p>
        @endif

        <div class="flex flex-wrap items-center gap-2 pt-2.5">
            @if ($od['all'])
                <a class="btn btn-small bg-panel-2" href="{{ route('calendar', ['view' => 'month', 'date' => $date->format('Y-m-d')]) }}#todo">{{ \App\Services\TaskLists::OVERDUE_DAYS }}日間の表示に戻す</a>
            @elseif ($od['older'] > 0)
                <a class="btn btn-small bg-panel-2" href="{{ route('calendar', ['view' => 'month', 'date' => $date->format('Y-m-d'), 'overdue' => 'all']) }}#todo">すべて表示（{{ \App\Services\TaskLists::OVERDUE_DAYS }}日より前に{{ $od['older'] }}件）</a>
            @endif
            @if ($od['count'] > 0)
                <a class="btn btn-small bg-panel-2 ml-auto" href="{{ route('overdue.confirm', ['range' => $od['all'] ? 'all' : '14', 'date' => $date->format('Y-m-d')]) }}">まとめて完了…</a>
            @endif
        </div>
    @endif
</section>
