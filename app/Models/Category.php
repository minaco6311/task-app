<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'color', 'key', 'sort_order'])]
/** 凡例（カテゴリ）。ユーザーごと */
class Category extends Model
{
    use BelongsToOwner;

    // 手帳の雰囲気に合わせた、明るさをそろえた淡いくすみパステル（予定の文字は CSS で自動的に紺色になる）
    public const PALETTE = ['#A3C1DE', '#E8D78C', '#E8BAC5', '#EDBC9F', '#C4B7E0', '#AACFB4', '#A8D3D1', '#E2B8D8', '#C3CAD1'];

    public const DEFAULTS = [
        ['key' => 'work', 'name' => '仕事', 'color' => '#A3C1DE'],
        ['key' => 'private', 'name' => 'プライベート', 'color' => '#E8D78C'],
        ['key' => 'family', 'name' => '家族', 'color' => '#E8BAC5'],
        ['key' => 'errand', 'name' => '用事・手続き', 'color' => '#EDBC9F'],
        ['key' => 'study', 'name' => '勉強', 'color' => '#C4B7E0'],
        ['key' => 'health', 'name' => '病院・健康', 'color' => '#AACFB4'],
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** 並び順どおりのカテゴリ一覧（いまのユーザーの分）。1つもなければ初期カテゴリを作る */
    public static function ordered(): Collection
    {
        if (! static::query()->exists()) {
            static::createDefaults();
        }

        return static::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public static function createDefaults(): void
    {
        foreach (self::DEFAULTS as $i => $c) {
            static::create([...$c, 'sort_order' => $i]);
        }
    }
}
