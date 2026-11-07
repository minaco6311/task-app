@extends('layouts.app')

@section('title', 'タスク候補')

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', ['title' => 'タスク候補 '.count($cands).'件', 'back' => route('import.create', ['date' => $base->format('Y-m-d')])])

        <p class="hint mb-3">直してから登録できます。チェックを外した候補は登録しません。時刻が空の候補は「終日」の予定になります。</p>

        <form method="POST" action="{{ route('import.store') }}" class="flex flex-col gap-2" data-cands
              data-colors='@json($categories->pluck('color', 'id'))'>
            @csrf
            @foreach ($cands as $i => $c)
                @php($color = $categories->firstWhere('id', $c['category_id'])?->color ?? '#7C8A94')
                <div class="cand" style="--c: {{ $color }}" data-cand>
                    <div class="flex items-center gap-2.5">
                        <label class="check"><input type="checkbox" name="items[{{ $i }}][on]" value="1" checked aria-label="登録する" data-cand-on></label>
                        <input class="input" name="items[{{ $i }}][title]" value="{{ $c['title'] }}" maxlength="80" aria-label="タイトル">
                    </div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <input class="input" type="date" name="items[{{ $i }}][date]" value="{{ $c['date'] }}" aria-label="日付">
                        <select class="input" name="items[{{ $i }}][category_id]" aria-label="カテゴリ" data-cand-cat>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}" @selected($cat->id === $c['category_id'])>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        <input class="input" type="time" name="items[{{ $i }}][start]" value="{{ $c['start'] }}" aria-label="開始（空なら終日）">
                        <input class="input" type="time" name="items[{{ $i }}][end]" value="{{ $c['end'] }}" aria-label="終了">
                    </div>
                </div>
            @endforeach
            <button type="submit" class="btn btn-primary mt-2" data-cand-submit>選んだ {{ count($cands) }}件を登録</button>
            <a href="javascript:history.back()" class="btn btn-ghost">文字を直す</a>
        </form>
    </div>
@endsection
