<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ユーザーごとの設定（通知のタイミング・表示など）。テーブルは user_settings（user_id と key の組み合わせで1行）
 * 1人用だったころの settings テーブルは、元に戻すときのために残している（いまは読み書きしない）
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use BelongsToOwner;

    protected $table = 'user_settings';

    public const DEFAULTS = [
        'default_before' => 60,         // 開始前の通知（標準・分）
        'day_before_time' => '23:00',   // 前日の通知
        'morning_time' => '08:00',      // 当日朝の通知
        'month_show_titles' => 0,       // 月表示に予定名を出す（0 = 色の線だけ）
    ];

    /** いまのユーザーのすべての設定（未保存のものは初期値） */
    public static function values(): array
    {
        $saved = static::query()->pluck('value', 'key')->all();
        $v = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $v['default_before'] = (int) $v['default_before'];
        $v['month_show_titles'] = (int) (bool) $v['month_show_titles'];

        return $v;
    }

    public static function put(string $key, string|int $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /**
     * この時刻より前に送るはずだった通知は送らない
     * （データの全削除やバックアップから戻したあとに、古い通知が送られないようにする）
     */
    public static function markNotificationsResumed(): void
    {
        static::put('notify_resume_after', now()->format('Y-m-d H:i:s'));
    }

    public static function notificationsResumedAt(): ?string
    {
        return static::query()->where('key', 'notify_resume_after')->value('value');
    }
}
