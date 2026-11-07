@extends('layouts.app')

@section('title', '設定')

@section('content')
    <div class="mx-auto max-w-[640px] px-4">
        @include('partials.page-head', ['title' => '設定'])

        {{-- 凡例 --}}
        <section class="flex flex-col gap-2.5 pb-4" id="legend">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">凡例（色とカテゴリ）</h3>
            <p class="hint">月のカレンダーには文字を出さず、この色だけで予定を表します。</p>
            <form method="POST" action="{{ route('categories.update') }}" class="flex flex-col gap-2.5">
                @csrf
                @method('PUT')
                @foreach ($categories as $c)
                    <div class="flex items-center gap-2">
                        <input type="color" name="categories[{{ $c->id }}][color]" value="{{ old("categories.{$c->id}.color", $c->color) }}"
                               class="h-[42px] w-11 shrink-0 rounded-[3px] border border-rule-soft bg-panel-2 p-0.5" aria-label="{{ $c->name }}の色">
                        <input class="input flex-1" type="text" name="categories[{{ $c->id }}][name]" value="{{ old("categories.{$c->id}.name", $c->name) }}" maxlength="20" aria-label="カテゴリ名">
                        <button type="submit" form="del-cat-{{ $c->id }}" class="icon-btn text-lg" aria-label="{{ $c->name }}を削除"
                                @if ($c->tasks_count) data-confirm="⚠️" data-confirm-toast="{{ $c->tasks_count }}件の予定が別のカテゴリに移ります。もう一度押すと削除します" @endif>🗑</button>
                    </div>
                @endforeach
                @error('categories.*') <p class="error">{{ $message }}</p> @enderror
                <button class="btn btn-primary">凡例を保存</button>
            </form>
            <form method="POST" action="{{ route('categories.store') }}">
                @csrf
                <button class="btn btn-ghost">＋ カテゴリを追加</button>
            </form>
            @foreach ($categories as $c)
                <form method="POST" action="{{ route('categories.destroy', $c) }}" id="del-cat-{{ $c->id }}" hidden>
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach
        </section>

        {{-- カレンダーの表示 --}}
        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4" id="display">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">カレンダーの表示</h3>
            <form method="POST" action="{{ route('settings.display') }}" class="flex flex-col gap-2.5">
                @csrf
                @method('PUT')
                <label class="check"><input type="checkbox" name="month_show_titles" value="1" @checked($settings['month_show_titles'])>
                    <span>月表示に予定名を出す</span></label>
                <p class="hint">出さないときは、今までどおりカテゴリの色の線だけで表します。出すときは1日3件までで、入りきらない分は「＋○件」と表示します。</p>
                <button class="btn btn-primary">保存</button>
            </form>
        </section>

        {{-- 通知のタイミング --}}
        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">通知のタイミング</h3>
            <form method="POST" action="{{ route('settings.update') }}" class="flex flex-col gap-2.5">
                @csrf
                @method('PUT')
                <label class="flex items-center justify-between gap-2.5 text-sm"><span>前日の通知（明日の予定）</span>
                    <input class="input w-auto" type="time" name="day_before_time" value="{{ old('day_before_time', $settings['day_before_time']) }}"></label>
                <label class="flex items-center justify-between gap-2.5 text-sm"><span>当日の通知（今日の予定）</span>
                    <input class="input w-auto" type="time" name="morning_time" value="{{ old('morning_time', $settings['morning_time']) }}"></label>
                <label class="flex items-center justify-between gap-2.5 text-sm"><span>開始前の通知（標準）</span>
                    <select class="input w-auto" name="default_before">
                        @foreach (\App\Support\Jp::BEFORE_OPTIONS as $min => $label)
                            <option value="{{ $min }}" @selected((int) old('default_before', $settings['default_before']) === $min)>{{ $label }}</option>
                        @endforeach
                    </select></label>
                @if ($errors->hasAny(['day_before_time', 'morning_time', 'default_before']))
                    <p class="error">時刻の形式が正しくありません</p>
                @endif
                <p class="hint">開始前の通知は予定ごとに変えられます。標準を変えると、これから作る予定に使われます。</p>
                <button class="btn btn-primary">保存</button>
            </form>
        </section>

        {{-- LINE --}}
        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4" id="line">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">LINE連携</h3>
            <div class="panel flex items-start gap-3 p-3">
                <span class="shrink-0 rounded-[10px] bg-line px-2 py-[7px] font-sans text-[11px] font-extrabold text-white">LINE</span>
                <div class="flex min-w-0 flex-col gap-1">
                    @if (! $line)
                        <b>未連携</b>
                        <p class="hint">自分のLINEに通知を届けるには、LINE公式アカウントを作って連携します。連携しなくても、予定の管理はそのまま使えます。</p>
                    @elseif ($line->status === 'ok')
                        <b>連携済み <span class="status-tag ok">テスト送信OK</span></b>
                    @elseif ($line->status === 'error')
                        <b>エラー <span class="status-tag ng">再設定が必要</span></b>
                        <p class="hint">{{ $line->last_error }}</p>
                    @else
                        <b>登録済み <span class="status-tag">テスト送信がまだ</span></b>
                    @endif
                    <p class="hint">送り方：{{ $live ? 'LINEに送る（LINE_DRIVER=line）' : 'シミュレーション（LINE_DRIVER=log・LINEには届きません）' }}<br>
                        通知の確認：{{ $lastRun ? \App\Support\Jp::dateTimeLabel($lastRun).'（スケジューラーが動いていれば毎分更新）' : 'まだ動いていません（スケジューラーを起動してください）' }}</p>
                </div>
            </div>
            {{-- 今月の送信数（無料メッセージの上限に近づいたら、完了までの再通知を止める） --}}
            <div class="flex flex-col gap-1 rounded-[3px] border border-dashed border-rule p-2.5 text-sm" id="sends">
                <p class="m-0">今月のLINE送信数：<b class="tabular-nums">{{ max($sends['app'], $sends['official'] ?? 0) }}通</b>
                    <span class="text-muted">／ {{ $sends['limit'] === null ? '上限なし' : '上限 '.$sends['limit'].'通' }}</span>
                    @if ($sends['reached'])<span class="status-tag ng">上限に達しました</span>@elseif ($sends['stopped'])<span class="status-tag ng">再通知を停止中</span>@endif</p>
                <p class="hint">
                    アプリで数えた送信：{{ $sends['app'] }}通@if ($sends['official'] !== null)・LINE側の集計（おおよそ）：{{ $sends['official'] }}通@endif
                    @if ($sends['unknown'])<br><span class="text-sun">LINE側の送信数を確認できませんでした。アプリで数えた数で判断し、スヌーズの予約は余裕をもって {{ $sends['stopAt'] }}通までにしています。</span>@endif
                    <br>
                    @if ($sends['stopAt'] !== null)
                        {{ $sends['stopAt'] }}通に達したら、完了までの再通知を止めます（前日・当日朝・開始前の通知は続けます）。スヌーズは、上限を超えるおそれがあると新しく予約できません。
                    @else
                        上限なしの設定なので、再通知は止めません。
                    @endif
                    月が替わると0から数えます。上限は料金プランに合わせて .env の LINE_MONTHLY_LIMIT で変えられます（LINE から上限を取れたときは、そちらを使います）。
                    @unless ($live)<br>シミュレーション {{ $sends['simulated'] }}件は、実際の送信ではないので数えません。@endunless
                </p>
            </div>
            <a class="btn btn-ghost" href="{{ route('line.edit') }}">{{ $line ? 'LINE連携の設定' : 'LINEを連携する（設定ガイド）' }}</a>
        </section>

        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">これから届く通知（見本）</h3>
            <div class="flex flex-col gap-3 rounded-[14px] bg-line-chat px-3 py-3.5 font-sans">
                @forelse ($notifications as $n)
                    <div class="flex flex-col items-start gap-[3px]">
                        <time class="pl-1 text-[10px] text-white opacity-90">{{ \App\Support\Jp::dateTimeLabel($n['at']) }}</time>
                        <div class="bubble"><b class="mb-0.5">{{ $n['head'] }}</b>
                            @foreach ($n['lines'] as $line)<span>・{{ $line }}</span>@endforeach
                        </div>
                    </div>
                @empty
                    <div class="bubble">1週間以内に届く通知はありません</div>
                @endforelse
            </div>
        </section>

        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4" id="history">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">通知履歴（新しい順・20件）</h3>
            @forelse ($logs as $log)
                <details class="border-b border-dashed border-dash pb-2">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                        <time class="tabular-nums">{{ \App\Support\Jp::dateTimeLabel($log->scheduled_at) }}</time>
                        <span class="text-muted">{{ $log->typeLabel() }}</span>
                        <span @class(['status-tag', 'ok' => in_array($log->status, ['sent', 'simulated'], true), 'ng' => $log->status === 'failed'])>{{ $log->statusLabel() }}</span>
                    </summary>
                    <div class="mt-1.5 flex flex-col gap-1">
                        <pre class="m-0 whitespace-pre-wrap rounded-[3px] bg-panel-2 p-2 font-sans text-xs leading-relaxed">{{ $log->message }}</pre>
                        @if ($log->last_error)<p class="hint">{{ $log->last_error }}</p>@endif
                        @if ($log->attempts > 1)<p class="hint">送信した回数：{{ $log->attempts }}回</p>@endif
                    </div>
                </details>
            @empty
                <p class="hint">まだ通知はありません。スケジューラーが動いていると、時刻になった通知がここに記録されます（シミュレーションのときも記録されます）。</p>
            @endforelse
        </section>

        {{-- データ --}}
        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">データ</h3>
            <p class="hint">予定はデータベース（MySQL）に保存されています。試作版（ブラウザだけで動く版）で書き出したバックアップも読み込めます。</p>
            <a class="btn btn-ghost" href="{{ route('export.ics') }}">すべての予定を .ics で書き出す</a>
            <a class="btn btn-ghost" href="{{ route('export.json') }}">バックアップを書き出す</a>
            <form method="POST" action="{{ route('data.restore') }}" enctype="multipart/form-data" class="flex flex-col gap-2">
                @csrf
                <label class="btn btn-ghost cursor-pointer">バックアップから戻す（いまのデータは置き換わります）
                    <input type="file" name="backup" accept="application/json,.json" class="hidden" data-auto-submit>
                </label>
                @error('backup') <p class="error">{{ $message }}</p> @enderror
            </form>
            <div class="panel flex flex-col gap-1 p-3 text-sm">
                <b>「予定などのデータを削除」で消えるもの</b>
                <p class="hint">予定（チェック項目・繰り返しの設定・再通知の予約を含む）、凡例（カテゴリは最初の6つに戻ります）、通知のタイミング（23:00・8:00・60分前に戻ります）、通知履歴</p>
                <b class="mt-1">消えずに残るもの</b>
                <p class="hint">ログイン情報（メールアドレス・パスワード）、LINE連携（トークン・ユーザーID）。消えるのはあなたのデータだけで、ほかの人のデータには影響しません</p>
            </div>
            <form method="POST" action="{{ route('data.reset') }}">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger" data-confirm="もう一度押すと、予定・凡例・通知の設定・通知履歴を削除します">予定などのデータを削除</button>
            </form>
        </section>

        {{-- アカウント --}}
        <section class="flex flex-col gap-2.5 border-t border-dashed border-dash py-4">
            <h3 class="m-0 text-sm font-medium tracking-[.12em]">アカウント</h3>
            <p class="hint">ログイン中：{{ auth()->user()?->name }}（{{ auth()->user()?->email }}）<br>予定・カテゴリ・設定・通知・LINE連携は、あなた専用です（ほかの人からは見えません）。</p>
            <a class="btn btn-ghost" href="{{ route('password.edit') }}">パスワードを変える</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-ghost">ログアウト</button>
            </form>
        </section>
    </div>
@endsection
