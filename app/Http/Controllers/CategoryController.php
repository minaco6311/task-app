<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** 凡例（色とカテゴリ）の追加・変更・削除 */
class CategoryController extends Controller
{
    public function store(): RedirectResponse
    {
        $n = Category::query()->count();
        Category::create([
            'name' => '新しいカテゴリ',
            'color' => Category::PALETTE[$n % count(Category::PALETTE)],
            'sort_order' => (int) Category::query()->max('sort_order') + 1,
        ]);

        return redirect()->to(route('settings.edit').'#legend')->with('status', 'カテゴリを追加しました。名前と色を変えて「保存」してください');
    }

    /** すべてのカテゴリの名前と色をまとめて保存 */
    public function updateAll(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'categories' => ['required', 'array'],
            'categories.*.name' => ['required', 'string', 'max:20'],
            'categories.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'categories.*.name.required' => 'カテゴリ名を入力してください',
            'categories.*.name.max' => 'カテゴリ名は20文字までです',
            'categories.*.color.regex' => '色の形式が正しくありません',
        ]);

        foreach ($v['categories'] as $id => $c) {
            Category::query()->whereKey($id)->update(['name' => trim($c['name']), 'color' => strtoupper($c['color'])]);
        }

        return redirect()->to(route('settings.edit').'#legend')->with('status', '凡例を保存しました');
    }

    /** 削除。その色の予定は、残ったカテゴリの先頭へ移す */
    public function destroy(Category $category): RedirectResponse
    {
        $rest = Category::query()->whereKeyNot($category->id)->orderBy('sort_order')->orderBy('id')->first();
        if (! $rest) {
            return back()->with('status', 'カテゴリは1つ以上必要です');
        }

        $moved = DB::transaction(function () use ($category, $rest) {
            $n = $category->tasks()->update(['category_id' => $rest->id]);
            $category->delete();

            return $n;
        });

        return redirect()->to(route('settings.edit').'#legend')->with('status',
            $moved ? "削除しました。{$moved}件の予定は「{$rest->name}」に移りました" : '削除しました');
    }
}
