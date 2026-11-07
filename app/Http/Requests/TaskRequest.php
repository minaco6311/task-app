<?php

namespace App\Http\Requests;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskSeries;
use App\Support\Jp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** 予定の追加・編集フォームの入力チェック */
class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ログインは routes/web.php の auth、予定の持ち主は can:own,task と OwnerScope で確かめている
    }

    protected function prepareForValidation(): void
    {
        // チェックボックスは、チェックなしだと送られてこないので false にそろえる
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'all_day' => $this->boolean('all_day'),
            'done' => $this->boolean('done'),
            'notify_day_before' => $this->boolean('notify_day_before'),
            'notify_morning' => $this->boolean('notify_morning'),
            'remind_until_done' => $this->boolean('remind_until_done'),
            'repeat' => $this->input('repeat') ?: 'none',
            'repeat_end' => $this->input('repeat_end') ?: 'none',
        ]);
    }

    /** フォームの項目のうち、予定そのものには保存しないもの */
    private const NOT_TASK = ['subtasks', 'repeat', 'repeat_weekdays', 'repeat_end', 'repeat_until', 'scope'];

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'date' => ['required', 'date_format:Y-m-d'],
            'all_day' => ['boolean'],
            'start_time' => ['nullable', 'required_if:all_day,false', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            // 自分のカテゴリだけ（ほかの人のカテゴリの ID を送っても通らない）
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)],
            'memo' => ['nullable', 'string', 'max:500'],
            'done' => ['boolean'],
            'notify_day_before' => ['boolean'],
            'notify_morning' => ['boolean'],
            'notify_before_minutes' => ['required', 'integer', Rule::in(array_keys(Jp::BEFORE_OPTIONS))],
            'priority' => ['nullable', 'integer', Rule::in(array_keys(Task::PRIORITIES))],
            // サブタスク（空欄の行は保存しない）
            'subtasks' => ['nullable', 'array'],
            'subtasks.*.id' => ['nullable', 'integer'],
            'subtasks.*.title' => ['nullable', 'string', 'max:80'],
            'subtasks.*.done' => ['nullable', 'boolean'],
            'remind_until_done' => ['boolean'],
            // 繰り返し
            'repeat' => ['required', Rule::in(['none', ...array_keys(TaskSeries::FREQS)])],
            'repeat_weekdays' => ['nullable', 'array', 'required_if:repeat,weekly'],
            'repeat_weekdays.*' => ['integer', 'between:0,6'],
            'repeat_end' => ['required', 'in:none,until'],
            'repeat_until' => ['nullable', 'required_if:repeat_end,until', 'date_format:Y-m-d', 'after_or_equal:date'],
            // 繰り返しの回を保存・削除するとき：この予定だけ / この予定以後すべて
            'scope' => ['nullable', 'in:this,following'],
        ];
    }

    /** サブタスクは、空欄でない行が20個まで */
    public function after(): array
    {
        return [function (Validator $v) {
            if (count($this->subtaskData()) > Subtask::MAX_PER_TASK) {
                $v->errors()->add('subtasks', 'チェック項目は'.Subtask::MAX_PER_TASK.'個までです');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'title' => 'タイトル', 'date' => '日付', 'start_time' => '開始', 'end_time' => '終了',
            'category_id' => 'カテゴリ', 'memo' => 'メモ', 'notify_before_minutes' => '開始前の通知', 'priority' => '優先度',
            'subtasks.*.title' => 'チェック項目', 'repeat_until' => '繰り返しの終わりの日',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'タイトルを入力してください',
            'start_time.required_if' => '開始の時刻を入れるか、「終日」にしてください',
            'repeat_weekdays.required_if' => '毎週の繰り返しは、曜日を1つ以上選んでください',
            'repeat_until.required_if' => '繰り返しの終わりの日を選んでください',
            'repeat_until.after_or_equal' => '繰り返しの終わりの日は、予定の日付以降にしてください',
        ];
    }

    /** 保存する予定の値（時刻をそろえたもの。サブタスク・繰り返しは含めない） */
    public function taskData(): array
    {
        $data = collect($this->validated())->except(self::NOT_TASK)->all();
        $data['priority'] ??= Task::PRIORITY_NORMAL;

        return Task::normalizeTimes($data);
    }

    /**
     * 繰り返しのルール（繰り返さないなら null）
     *
     * @return array{freq: string, weekdays: list<int>, until: ?string}|null
     */
    public function ruleData(): ?array
    {
        $v = $this->validated();
        if ($v['repeat'] === 'none') {
            return null;
        }
        $days = array_values(array_unique(array_map('intval', $v['repeat_weekdays'] ?? [])));
        sort($days);

        return [
            'freq' => $v['repeat'],
            'weekdays' => $days,
            'until' => $v['repeat_end'] === 'until' ? $v['repeat_until'] : null,
        ];
    }

    /** 繰り返しの回を「この予定以後すべて」で保存するか（それ以外は「この予定だけ」） */
    public function following(): bool
    {
        return $this->input('scope') === 'following';
    }

    /**
     * サブタスク（画面の並び順のまま。空欄の行は除く）
     *
     * @return list<array{id: ?int, title: string, done: bool}>
     */
    public function subtaskData(): array
    {
        $out = [];
        foreach ((array) $this->input('subtasks', []) as $s) {
            $title = mb_substr(trim((string) ($s['title'] ?? '')), 0, 80);
            if (is_array($s) && $title !== '') {
                $out[] = ['id' => isset($s['id']) && is_numeric($s['id']) ? (int) $s['id'] : null, 'title' => $title, 'done' => filter_var($s['done'] ?? false, FILTER_VALIDATE_BOOL)];
            }
        }

        return $out;
    }
}
