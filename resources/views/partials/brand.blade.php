{{-- タイトル「Daily いろどり手帳」と、右上の Date / Day 欄（今日の曜日の丸だけ濃く塗る） --}}
@php($now = \App\Support\Jp::today())
<div class="flex flex-wrap items-end justify-between gap-2.5 px-4 pt-[calc(12px+env(safe-area-inset-top))] pb-2">
    <a href="{{ route('calendar') }}" class="relative block pt-[22px] leading-none text-accent-ink no-underline" aria-label="いろどり手帳（カレンダーへ）">
        <span class="brand-script" aria-hidden="true">Daily</span>
        <span class="brand-main relative text-[26px] font-medium tracking-[.06em] sm:text-[32px]">いろどり手帳</span>
    </a>
    <div class="flex flex-col gap-[5px] bg-panel px-2.5 py-1.5 text-xs" aria-label="今日">
        <div class="flex items-center gap-2">
            <span class="min-w-10 tracking-[.1em] text-muted">Date :</span>
            <span class="tracking-[.12em] tabular-nums">{{ $now->year }} / {{ $now->month }} / {{ $now->day }}</span>
        </div>
        <div class="flex items-center gap-2">
            <span class="min-w-10 tracking-[.1em] text-muted">Day :</span>
            <span class="flex gap-[3px]">
                @foreach (\App\Support\Jp::WEEK as $i => $w)
                    <i @class(['day-dot', 'on' => $i === $now->dayOfWeek])>{{ $w }}</i>
                @endforeach
            </span>
        </div>
    </div>
</div>
<div class="rule" aria-hidden="true"></div>
