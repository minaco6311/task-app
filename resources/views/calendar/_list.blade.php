{{-- 一覧：7日間の予定を日付ごと・時刻順に（終日 → 時刻の早い順）。丸を押すと完了、行を押すと編集 --}}
<div class="flex flex-col gap-3">
    @foreach ($listDays as $d)
        <section @class(['panel px-3.5 pt-1 pb-1.5', 'ring-1 ring-accent' => $d['date']->isSameDay($today)])
                 aria-label="{{ \App\Support\Jp::dateLabel($d['date']) }}">
            <h2 @class(['list-head m-0', 'text-sun' => $d['date']->dayOfWeek === 0, 'text-sat' => $d['date']->dayOfWeek === 6])>
                {{ \App\Support\Jp::dateLabel($d['date']) }}
                @if ($d['date']->isSameDay($today))<span class="pill py-0.5 text-[10px]">今日</span>@endif
                <span class="ml-auto text-xs font-normal tracking-normal text-muted">{{ $d['tasks']->count() ? $d['tasks']->count().'件' : '' }}</span>
                <a href="{{ route('tasks.create', ['date' => $d['date']->format('Y-m-d')]) }}" class="text-xs font-normal tracking-normal text-accent-ink"
                   aria-label="{{ \App\Support\Jp::dateLabel($d['date']) }}に予定を追加">＋</a>
            </h2>
            @forelse ($d['tasks'] as $t)
                @include('partials.task-row', ['t' => $t, 'showCategory' => true])
            @empty
                <p class="m-0 px-1 py-2.5 text-sm text-muted">予定なし</p>
            @endforelse
        </section>
    @endforeach
</div>
