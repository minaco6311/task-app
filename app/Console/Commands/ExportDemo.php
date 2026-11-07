<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskTextParser;
use App\Support\Jp;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

/**
 * デザイン確認用の「器」だけのデモを書き出す（Vercel などに静的サイトとして置ける）
 * ・画面はいまの Blade そのまま。データはサンプルの予定
 * ・リンクはデモの各ページにつなぎ直し、保存などの操作は「デモ版のため保存されません」と出す
 * ・データベースは書き出しのあと元に戻す
 *
 * 使い方：npm run build のあと php artisan demo:export
 */
#[Signature('demo:export {--out=irodori-techo-demo : 書き出し先のフォルダ}')]
#[Description('デザイン確認用の静的デモ（HTML）を書き出す')]
class ExportDemo extends Command
{
    private string $base;

    public function handle(Kernel $kernel): int
    {
        if (! File::exists(public_path('build/manifest.json'))) {
            $this->error('先に npm run build を実行してください');

            return self::FAILURE;
        }
        if (File::exists(public_path('hot'))) {
            $this->error('Vite の開発サーバー（public/hot）を止めてから実行してください');

            return self::FAILURE;
        }

        $out = base_path($this->option('out'));
        $this->base = rtrim(config('app.url'), '/');
        $today = Jp::today()->format('Y-m-d');

        DB::beginTransaction();
        try {
            // 画面はログインが必要なので、書き出しの間だけ仮のユーザーでログインした扱いにする（このユーザーも最後に消える）
            // データはユーザーごとなので、仮のユーザーにはサンプルの予定だけが入る（ほかの人の予定は画面に出ない）
            Auth::setUser(User::query()->create([
                'name' => 'デモ', 'email' => 'demo-'.Str::random(12).'@example.invalid', 'password' => Str::random(40),
            ]));
            (new DatabaseSeeder)->run();

            $edit = Task::query()->where('title', 'チーム定例')->value('id');
            $done = Task::query()->whereIn('title', ['歯医者', '資料の提出', '燃えないゴミ'])->pluck('id')->implode(',');

            $pages = [
                'index.html' => "/?view=month&date={$today}",
                'week.html' => "/?view=week&date={$today}",
                'day.html' => "/?view=day&date={$today}",
                'list.html' => "/?view=list&date={$today}",
                'task-new.html' => "/tasks/create?date={$today}",
                'task-edit.html' => "/tasks/{$edit}/edit",
                'import.html' => "/import?date={$today}",
                'import-done.html' => "/import/done?ids={$done}",
                'settings.html' => '/settings',
                'line.html' => '/settings/line',
            ];

            $html = [];
            foreach ($pages as $file => $uri) {
                $res = $kernel->handle(Request::create($this->base.$uri, 'GET'));
                if ($res->getStatusCode() !== 200) {
                    throw new \RuntimeException("{$uri} が表示できませんでした（{$res->getStatusCode()}）");
                }
                $html[$file] = $res->getContent();
            }

            // 候補の確認画面（本来はフォーム送信のあとに出る画面）
            view()->share('errors', new ViewErrorBag);
            $categories = Category::ordered();
            $sample = "10/27(火)\n14:00 歯医者\n19:00-20:00 ジム\n10/28 資料の提出\n10/29 午後3時半 打ち合わせ";
            $html['import-review.html'] = view('import.review', [
                'cands' => (new TaskTextParser($categories))->parse($sample, Jp::today()),
                'categories' => $categories,
                'base' => Jp::today(),
            ])->render();
        } finally {
            DB::rollBack();
            Auth::forgetUser();
        }

        File::deleteDirectory($out);
        File::ensureDirectoryExists($out);
        File::copyDirectory(public_path('build/assets'), "{$out}/build/assets");
        if (File::exists(public_path('favicon.ico'))) {
            File::copy(public_path('favicon.ico'), "{$out}/favicon.ico");
        }
        foreach ($html as $file => $content) {
            File::put("{$out}/{$file}", $this->rewrite($content));
        }

        $this->info('書き出しました：'.$this->option('out').'/ （'.count($html).'ページ）');

        return self::SUCCESS;
    }

    /** アプリのURL → デモのファイル名。保存などの操作は "#demo" */
    private function rewrite(string $html): string
    {
        $base = preg_quote($this->base, '#');
        $html = preg_replace_callback("#(href|action|src|data-create-url)=\"{$base}([^\"]*)\"#", function ($m) {
            return $m[1].'="'.$this->map(html_entity_decode($m[2]), $m[1]).'"';
        }, $html);

        // セッションの値（CSRFトークン）は不要なので消す
        $html = preg_replace('#\s*<meta name="csrf-token"[^>]*>#', '', $html);
        $html = preg_replace('#\s*<input type="hidden" name="_token"[^>]*>#', '', $html);

        $html = str_replace('</head>', "    <meta name=\"robots\" content=\"noindex\">\n</head>", $html);

        return str_replace('</body>', $this->demoScript()."\n</body>", $html);
    }

    private function map(string $path, string $attr): string
    {
        $p = parse_url($path);
        $route = ($p['path'] ?? '') === '' ? '/' : $p['path'];
        $hash = isset($p['fragment']) ? '#'.$p['fragment'] : '';
        parse_str($p['query'] ?? '', $q);

        if (str_starts_with($route, '/build/')) {
            return ltrim($route, '/');
        }
        if ($route === '/favicon.ico') {
            return 'favicon.ico';
        }

        $file = match (true) {
            $route === '/' => ['month' => 'index.html', 'week' => 'week.html', 'day' => 'day.html', 'list' => 'list.html'][$q['view'] ?? 'month'] ?? 'index.html',
            $route === '/tasks/create' => 'task-new.html',
            (bool) preg_match('#^/tasks/\d+/edit$#', $route) => 'task-edit.html',
            (bool) preg_match('#^/tasks(/\d+)?$#', $route) => 'index.html',   // 予定の保存・削除のあと
            $route === '/import/preview' => 'import-review.html',
            $route === '/import' => $attr === 'action' ? 'import-done.html' : 'import.html',
            $route === '/import/done' => 'import-done.html',
            $route === '/settings' && $attr !== 'action' => 'settings.html',
            $route === '/settings/line' && $attr !== 'action' => 'line.html',
            default => '#demo',                                                // 保存・書き出し・Google登録など
        };

        return $file === '#demo' ? '#demo' : $file.$hash;
    }

    /** デモ用の案内と、フォーム送信の置きかえ */
    private function demoScript(): string
    {
        return <<<'HTML'
<div id="demo-banner" style="position:fixed;left:0;right:0;top:0;z-index:70;background:rgba(58,102,128,.92);color:#fff;font:12px/1.6 'Hiragino Sans',system-ui,sans-serif;text-align:center;padding:4px 12px;letter-spacing:.05em">デザイン確認用のデモです（入力しても保存されません）</div>
<script>
(() => {
  document.body.style.paddingTop = '26px';
  const say = (msg) => {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(say.t);
    say.t = setTimeout(() => el.classList.remove('show'), 2800);
  };
  const go = (form) => {
    const action = form.getAttribute('action') || '';
    if (form.target === '_blank') return say('本番では、Googleカレンダーの登録画面が別タブで開きます');
    if (action.endsWith('.html')) {
      sessionStorage.setItem('demo-toast', 'デモ版のため保存はされません（画面の流れだけ確認できます）');
      location.href = action;
    } else {
      say('デモ版のため保存はされません');
    }
  };
  // フォームの送信をすべて止めて、次の画面へ進むだけにする
  document.addEventListener('submit', (e) => { e.preventDefault(); go(e.target); }, true);
  HTMLFormElement.prototype.submit = function () { go(this); };
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href="#demo"]');
    if (a) { e.preventDefault(); say('デモ版では書き出し・取り込みは使えません'); }
  });
  const msg = sessionStorage.getItem('demo-toast');
  if (msg) { sessionStorage.removeItem('demo-toast'); setTimeout(() => say(msg), 100); }
})();
</script>
HTML;
    }
}
