{{-- 週・日のタイムライン。空いているところを押すと、その時刻で予定を追加 --}}
@php($n = count($cols))
<div @class(['panel flex h-full flex-col overflow-hidden rounded-b-none', 'is-day' => $n === 1, 'is-week' => $n > 1]) style="--n: {{ $n }}">
    <div class="tl-row border-b-[1.5px] border-rule">
        <div></div>
        @foreach ($cols as $c)
            <a href="{{ route('calendar', ['view' => 'day', 'date' => $c['date']->format('Y-m-d')]) }}"
               @class(['tl-dh no-underline', 'w'.$c['date']->dayOfWeek, 'today' => $c['date']->isSameDay($today)])>
                <span>{{ \App\Support\Jp::WEEK[$c['date']->dayOfWeek] }}</span><b>{{ $c['date']->day }}</b>
            </a>
        @endforeach
    </div>

    @if ($hasAllDay)
        <div class="tl-row border-b border-dashed border-dash">
            <div class="pt-1.5 pr-1.5 text-right text-[10px] text-muted">終日</div>
            @foreach ($cols as $c)
                <div class="flex min-w-0 flex-col gap-0.5 border-l border-dashed border-dash px-0.5 py-[3px]">
                    @foreach ($c['allDay'] as $t)
                        <a href="{{ route('tasks.edit', $t) }}" @class(['chip-ev no-underline', 'done' => $t->done]) style="--c: {{ $t->color }}">{{ $t->title }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    {{-- 下の余白：いちばん下（23時台）の予定が右下のボタン（＋・手帳から）に隠れないように --}}
    <div class="min-h-0 flex-1 overflow-y-auto pb-[calc(84px+env(safe-area-inset-bottom))]" data-tl-scroll data-scroll-hour="{{ $scrollHour }}">
        <div class="tl-row h-[calc(var(--hour)*24)]">
            <div class="relative">
                @for ($h = 1; $h < 24; $h++)
                    <span class="absolute right-1.5 -translate-y-1/2 text-[10px] text-muted tabular-nums" style="top: calc(var(--hour) * {{ $h }})">{{ $h }}:00</span>
                @endfor
            </div>
            @foreach ($cols as $c)
                <div class="tl-col" data-create-url="{{ route('tasks.create', ['date' => $c['date']->format('Y-m-d')]) }}">
                    @foreach ($c['events'] as $ev)
                        @php($t = $ev['task'])
                        <a href="{{ route('tasks.edit', $t) }}" @class(['ev no-underline', 'done' => $t->done])
                           style="--c: {{ $t->color }}; top: calc(var(--hour) * {{ $ev['s'] / 60 }}); height: max(calc(var(--hour) * {{ ($ev['e'] - $ev['s']) / 60 }} - 2px), 18px); left: calc({{ $ev['lane'] / $ev['lanes'] * 100 }}% + 1px); width: calc({{ 100 / $ev['lanes'] }}% - 3px)">
                            <b>{{ $t->title }}</b><span>{{ $t->start }}–{{ $t->end }}</span>
                        </a>
                    @endforeach
                    @if ($c['date']->isSameDay($today))
                        <div class="now-line" style="top: calc(var(--hour) * {{ $nowMin / 60 }})"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
