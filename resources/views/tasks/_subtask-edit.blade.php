{{-- 予定のフォームのチェック項目1行（$key = 行の番号。新しい行は JavaScript が "new1" などに置きかえる） --}}
<div class="subtask-edit">
    <input type="hidden" name="subtasks[{{ $key }}][id]" value="{{ $s['id'] }}">
    <input type="hidden" name="subtasks[{{ $key }}][done]" value="0">
    <label class="grid size-9 shrink-0 place-items-center" title="完了">
        <input type="checkbox" name="subtasks[{{ $key }}][done]" value="1" class="size-[18px] accent-accent" @checked($s['done']) aria-label="完了">
    </label>
    <input class="input min-w-0 flex-1" type="text" name="subtasks[{{ $key }}][title]" value="{{ $s['title'] }}" maxlength="80" placeholder="例：荷物をまとめる" aria-label="チェック項目">
    <button type="button" class="icon-btn size-8 text-base" data-subtask-up aria-label="上へ">↑</button>
    <button type="button" class="icon-btn size-8 text-base" data-subtask-down aria-label="下へ">↓</button>
    <button type="button" class="icon-btn size-8 text-base text-sun" data-subtask-remove aria-label="この項目を削除">✕</button>
</div>
