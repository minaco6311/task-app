@extends('layouts.app')

@section('title', '手帳から取り込む')

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', ['title' => '手帳から取り込む', 'back' => route('calendar', ['date' => $base->format('Y-m-d')])])

        <ol class="m-0 mb-3.5 flex list-none flex-col gap-4 p-0">
            <li class="flex flex-col gap-2">
                <b class="flex items-center font-medium tracking-[.05em]"><span class="step-num">1</span>手帳のスクショを選ぶ</b>
                {{-- 画像はこの画面で見るだけ。サーバーには送らない --}}
                <label class="relative flex cursor-pointer items-center justify-center gap-2 rounded-[3px] border-[1.5px] border-dashed border-rule bg-panel-2 p-[18px] text-muted">
                    <input type="file" accept="image/*" class="absolute inset-0 cursor-pointer opacity-0" data-image-input>📷 画像を選ぶ
                </label>
                <img class="max-h-[220px] max-w-full cursor-zoom-in self-center rounded-[3px] bg-panel object-contain" alt="選んだ手帳の画像" hidden data-image-preview>
            </li>
            <li class="flex flex-col gap-2">
                <b class="flex items-center font-medium tracking-[.05em]"><span class="step-num">2</span>画像の文字をコピーして貼り付け</b>
                <details class="rounded-[3px] bg-panel px-3 py-2 text-xs leading-[1.8] text-muted">
                    <summary class="cursor-pointer font-medium text-accent-ink">文字のコピーのしかた</summary>
                    <p class="mt-1.5 mb-0"><b>iPhone：</b>写真アプリでスクショを開き、文字を長押し →「すべてを選択」→「コピー」。</p>
                    <p class="mt-1.5 mb-0"><b>Android：</b>Googleフォトでスクショを開き「レンズ」→「テキスト」→「すべて選択」→「コピー」。</p>
                    <p class="mt-1.5 mb-0">1行に1つの予定が理想です。「10/27(火)」のように日付だけの行があれば、その下の行はその日の予定として扱います。</p>
                </details>
                <form method="POST" action="{{ route('import.preview') }}" id="import-form" class="flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="base" value="{{ $base->format('Y-m-d') }}">
                    <textarea class="input" name="text" rows="6" placeholder="例）&#10;10/27(火)&#10;14:00 歯医者&#10;19:00-20:00 ジム&#10;10/28 資料の提出">{{ old('text') }}</textarea>
                    @error('text') <p class="error">{{ $message }}</p> @enderror
                </form>
            </li>
        </ol>
        <button type="submit" form="import-form" class="btn btn-primary">タスク候補を作る</button>
    </div>
@endsection
