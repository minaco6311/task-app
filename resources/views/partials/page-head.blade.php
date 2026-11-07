{{-- カレンダー以外のページの見出し（戻るボタン ＋ タイトル） --}}
<div class="flex items-center justify-between gap-2 border-b-[1.5px] border-dashed border-accent px-4 pt-2 pb-2.5 mb-4 mx-0">
    <h2 class="m-0 text-[17px] font-medium tracking-[.12em]">{{ $title }}</h2>
    <a href="{{ $back ?? route('calendar') }}" class="icon-btn no-underline" aria-label="閉じる">✕</a>
</div>
