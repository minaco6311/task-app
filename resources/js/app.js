/* =========================================================
   いろどり手帳 — 画面の小さな動き
   データの保存・計算はサーバー（Laravel）で行い、ここでは見た目の操作だけを扱う
   ========================================================= */

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const pad = (n) => String(n).padStart(2, '0');
const WEEK = ['日', '月', '火', '水', '木', '金', '土'];
const toMin = (t) => {
    if (!t) return null;
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
};
const fromMin = (m) => {
    m = Math.max(0, Math.min(m, 23 * 60 + 59));
    return `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
};

/* ----- お知らせ（数秒で消える） ----- */
let toastTimer;
function toast(msg) {
    const el = $('#toast');
    if (!el) return;
    if (msg) el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 2800);
}

/* ----- 削除などは2回押しで確定（誤操作防止） ----- */
function setupConfirm() {
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-confirm]');
        if (!btn || btn.dataset.armed) return;
        e.preventDefault();
        const orig = btn.textContent;
        btn.dataset.armed = '1';
        btn.textContent = btn.dataset.confirm;
        if (btn.dataset.confirmToast) toast(btn.dataset.confirmToast);
        setTimeout(() => {
            if (btn.isConnected) {
                delete btn.dataset.armed;
                btn.textContent = orig;
            }
        }, 3000);
    });
}

/* ----- タイムライン：今の時刻あたりまでスクロール／空いているところを押すと予定を追加 ----- */
function setupTimeline() {
    const scroll = $('[data-tl-scroll]');
    if (!scroll) return;
    const hourPx = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--hour')) || 48;
    scroll.scrollTop = Number(scroll.dataset.scrollHour || 7) * hourPx;

    $$('.tl-col', scroll).forEach((col) => {
        col.addEventListener('click', (e) => {
            if (e.target !== col) return; // 予定ブロックを押したときは編集へ
            const y = e.clientY - col.getBoundingClientRect().top;
            const hour = Math.max(0, Math.min(23, Math.floor(y / hourPx)));
            const url = new URL(col.dataset.createUrl, location.href);
            url.searchParams.set('start', `${pad(hour)}:00`);
            location.href = url.toString();
        });
    });
}

/* ----- 予定のフォーム：終日の切り替え、時間の長さを保つ、LINE通知の届く時刻を表示 ----- */
function setupTaskForm() {
    const f = $('#task-form');
    if (!f) return;
    const allDay = f.elements.all_day;
    const start = f.elements.start_time;
    const end = f.elements.end_time;
    const preview = $('[data-notify-preview]', f);
    let duration = (toMin(end.value) ?? 0) - (toMin(start.value) ?? 0);
    if (duration <= 0) duration = 60;

    const atTime = (dateStr, hm, addDays = 0) => {
        const [y, m, d] = dateStr.split('-').map(Number);
        const dt = new Date(y, m - 1, d + addDays);
        const min = toMin(hm) ?? 0;
        dt.setHours(Math.floor(min / 60), min % 60, 0, 0);
        return dt;
    };
    const label = (d) => `${d.getMonth() + 1}/${d.getDate()}(${WEEK[d.getDay()]}) ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const beforeLabel = () => f.elements.notify_before_minutes.selectedOptions[0]?.textContent.replace('（標準）', '').trim();

    const update = () => {
        $$('[data-timed]', f).forEach((el) => { el.hidden = allDay.checked; });
        const date = f.elements.date.value;
        if (!date) return;
        const list = [];
        if (f.elements.notify_day_before.checked) list.push([atTime(date, f.dataset.dayBeforeTime, -1), '前日']);
        if (f.elements.notify_morning.checked) list.push([atTime(date, f.dataset.morningTime), '当日朝']);
        const before = Number(f.elements.notify_before_minutes.value);
        if (!allDay.checked && start.value && before > 0) {
            list.push([new Date(atTime(date, start.value) - before * 60000), beforeLabel()]);
        }
        list.sort((a, b) => a[0] - b[0]);
        preview.textContent = list.length
            ? '届く予定：' + list.map(([at, kind]) => `${label(at)}（${kind}）`).join(' ／ ')
            : 'この予定ではLINE通知は届きません';
    };

    start.addEventListener('change', () => {
        if (start.value) end.value = fromMin(toMin(start.value) + duration);
    });
    end.addEventListener('change', () => {
        const d = toMin(end.value) - toMin(start.value);
        if (d > 0) duration = d;
    });
    f.addEventListener('input', update);
    f.addEventListener('change', update);
    update();
}

/* ----- 手帳から取り込み：画像を画面で確認（サーバーには送らない） ----- */
function setupImport() {
    const input = $('[data-image-input]');
    const img = $('[data-image-preview]');
    if (input && img) {
        let url = null;
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) return;
            if (url) URL.revokeObjectURL(url);
            url = URL.createObjectURL(file);
            img.src = url;
            img.hidden = false;
        });
        img.addEventListener('click', () => img.classList.toggle('max-h-[220px]'));
    }

    // 候補の確認：チェックの数、カテゴリの色
    const form = $('[data-cands]');
    if (form) {
        const colors = JSON.parse(form.dataset.colors || '{}');
        const btn = $('[data-cand-submit]', form);
        const refresh = () => {
            const n = $$('[data-cand-on]', form).filter((c) => c.checked).length;
            btn.textContent = `選んだ ${n}件を登録`;
            btn.disabled = !n;
        };
        form.addEventListener('change', (e) => {
            const box = e.target.closest('[data-cand]');
            if (!box) return;
            if (e.target.matches('[data-cand-on]')) {
                box.classList.toggle('off', !e.target.checked);
                refresh();
            }
            if (e.target.matches('[data-cand-cat]')) box.style.setProperty('--c', colors[e.target.value] || '#7C8A94');
        });
    }
}

/* ----- Googleカレンダーへの登録ボタン（別タブで開く） ----- */
function setupGcalForms() {
    $$('[data-gcal-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = $('button', form);
            setTimeout(() => { btn.textContent = '登録画面を開きました'; }, 50);
            toast('Googleカレンダーの画面で「保存」を押すと登録されます');
        });
    });
}

/* ----- フォームを画面の読み込み直しなしで送る（失敗したら例外） ----- */
async function sendForm(form) {
    const res = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]')?.content },
        body: new FormData(form),
    });
    if (!res.ok) throw new Error(String(res.status));
    return res.json();
}

/* ----- 今日の達成状況（進捗バー）を書き換える ----- */
function updateProgress(p) {
    if (!p) return;
    $$('[data-progress]').forEach((box) => {
        const count = $('[data-progress-count]', box);
        const percent = $('[data-progress-percent]', box);
        const bar = $('[data-progress-bar]', box);
        if (count) count.textContent = `${p.done}/${p.total}件`;
        if (percent) percent.textContent = String(p.percent);
        if (bar) {
            bar.setAttribute('aria-valuenow', String(p.percent));
            $('i', bar).style.width = `${p.percent}%`;
        }
    });
}

/* ----- 完了チェック：画面を読み込み直さずに切り替える（JavaScript がなくても普通の送信で動く） ----- */
function setupDoneToggle() {
    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('[data-done-form]');
        if (!form || e.defaultPrevented) return;
        e.preventDefault();
        const btn = $('button', form);
        btn.disabled = true;
        try {
            const { id, done, progress } = await sendForm(form);
            // 同じ予定が複数の一覧に出ていることがあるので、まとめて直す
            $$(`[data-task-row="${id}"]`).forEach((row) => {
                row.classList.toggle('done', done);
                const b = $('.task-check', row);
                const title = $('.title', row)?.textContent ?? '';
                b.setAttribute('aria-pressed', done ? 'true' : 'false');
                b.setAttribute('aria-label', `「${title}」を${done ? '未完了に戻す' : '完了にする'}`);
                $('input[name="done"]', row).value = done ? '0' : '1';
            });
            $$(`[data-task-bar="${id}"]`).forEach((el) => el.classList.toggle('done', done));
            if (done) $$(`[data-subtask-ask="${id}"]`).forEach((el) => { el.hidden = true; });
            updateProgress(progress);
            toast(done ? '完了にしました' : '未完了に戻しました');
        } catch {
            toast('保存できませんでした。もう一度押してください');
        } finally {
            btn.disabled = false;
        }
    });
}

/* ----- サブタスク：「☑ 2/3」で開く、チェック項目を押す、全部終わったら親を完了にするか聞く ----- */
function setupSubtasks() {
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('[data-subtask-toggle]');
        if (toggle) {
            const list = document.getElementById(toggle.getAttribute('aria-controls'));
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (list) list.hidden = !open;
            return;
        }
        const close = e.target.closest('[data-ask-close]');
        if (close) {
            e.preventDefault();
            close.closest('[data-subtask-ask]').hidden = true; // 「まだ完了しない」：親は未完了のまま
        }
    });

    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('[data-subtask-form]');
        if (!form || e.defaultPrevented) return;
        e.preventDefault();
        const btn = $('button', form);
        btn.disabled = true;
        try {
            const r = await sendForm(form);
            // 同じサブタスクが複数の一覧に出ていることがあるので、まとめて直す
            $$(`[data-subtask="${r.id}"]`).forEach((f) => {
                f.classList.toggle('done', r.done);
                const b = $('button', f);
                const title = $('span.flex-1', f)?.textContent ?? '';
                b.setAttribute('aria-pressed', r.done ? 'true' : 'false');
                b.setAttribute('aria-label', `「${title}」を${r.done ? '未完了に戻す' : '完了にする'}`);
                $('input[name="done"]', f).value = r.done ? '0' : '1';
            });
            $$(`[data-subtask-count="${r.task_id}"]`).forEach((el) => { el.textContent = `${r.done_count}/${r.total}`; });
            // 親は自動では完了にしない。押した一覧の中でだけ聞く
            const ask = $(`[data-subtask-ask="${r.task_id}"]`, form.closest('.subtask-list'));
            if (ask) ask.hidden = !r.ask_complete;
            if (!r.ask_complete) toast(r.done ? 'チェックしました' : 'チェックを外しました');
        } catch {
            toast('保存できませんでした。もう一度押してください');
        } finally {
            btn.disabled = false;
        }
    });
}

/* ----- 予定のフォーム：繰り返し（選んだ種類に合わせて、曜日・毎月の説明・終わりの日を出す） ----- */
function setupRepeat() {
    const box = $('[data-repeat]');
    if (!box) return;
    const freq = $('[data-repeat-freq]', box);
    const until = $('input[name="repeat_until"]', box);
    const update = () => {
        const v = freq.value;
        $('[data-repeat-weekly]', box).hidden = v !== 'weekly';
        $('[data-repeat-monthly]', box).hidden = v !== 'monthly';
        $('[data-repeat-end]', box).hidden = v === 'none';
        until.disabled = $('input[name="repeat_end"]:checked', box)?.value !== 'until';
    };
    box.addEventListener('change', update);
    update();
}

/* ----- 予定のフォーム：チェック項目（サブタスク）の追加・削除・並び替え ----- */
function setupSubtaskEditor() {
    const box = $('[data-subtask-editor]');
    if (!box) return;
    const list = $('[data-subtask-rows]', box);
    const tpl = $('template', box);
    const addBtn = $('[data-subtask-add]', box);
    const counter = $('[data-subtask-counter]', box);
    const ask = $('[data-form-ask]', box);
    const doneBox = $('#task-form input[name="done"][type="checkbox"]');
    const max = Number(box.dataset.max || 20);
    let seq = 0;

    const refresh = () => {
        const rows = $$('.subtask-edit', list);
        counter.textContent = `${rows.length}/${max}`;
        addBtn.disabled = rows.length >= max;
        // すべてチェックしたら「このタスクも完了にしますか？」（自動では完了にしない）
        const checks = rows.map((r) => $('input[type="checkbox"]', r));
        const allDone = checks.length > 0 && checks.every((c) => c.checked);
        if (ask) ask.hidden = !(allDone && doneBox && !doneBox.checked);
    };

    addBtn.addEventListener('click', () => {
        if ($$('.subtask-edit', list).length >= max) return;
        const node = tpl.content.firstElementChild.cloneNode(true);
        const key = `new${++seq}`;
        $$('[name]', node).forEach((el) => { el.name = el.name.replace('__KEY__', key); });
        list.appendChild(node);
        $('input[type="text"]', node).focus();
        refresh();
    });

    list.addEventListener('click', (e) => {
        const row = e.target.closest('.subtask-edit');
        if (!row) return;
        if (e.target.closest('[data-subtask-remove]')) { row.remove(); refresh(); }
        if (e.target.closest('[data-subtask-up]') && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
        if (e.target.closest('[data-subtask-down]') && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
    });
    list.addEventListener('change', refresh);
    doneBox?.addEventListener('change', refresh);
    $('[data-form-ask-yes]', box)?.addEventListener('click', () => { doneBox.checked = true; refresh(); toast('「完了にする」にチェックしました。保存すると完了になります'); });
    $('[data-form-ask-no]', box)?.addEventListener('click', () => { ask.hidden = true; });
    refresh();
}

/* ----- ファイルを選んだらそのまま送信（バックアップから戻す） ----- */
function setupAutoSubmit() {
    $$('[data-auto-submit]').forEach((input) => {
        input.addEventListener('change', () => {
            if (input.files && input.files.length) input.form.submit();
        });
    });
}

/* ----- 招待リンク（/invite#トークン）：URL からトークンを消してから、POST で確認に送る -----
   # の後ろはサーバーに送られないので、アクセスログには残らない。履歴からも消すために replaceState で書き換える */
function setupInvite() {
    const form = $('[data-invite-form]');
    if (!form) return;
    let token = '';
    try {
        token = decodeURIComponent(location.hash.replace(/^#/, '')).trim().toLowerCase();
    } catch {
        token = ''; // 壊れた URL（% の使い方が正しくない）
    }
    if (location.hash) history.replaceState(null, '', location.pathname + location.search);
    if (!/^[0-9a-f]{64}$/.test(token)) return; // 形が違う → 貼り付けの欄をそのまま出す
    $('[name="token"]', form).value = token;
    $('[data-invite-manual]', form).hidden = true;
    $('[data-invite-checking]', form).hidden = false;
    form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    setupInvite();
    if ($('#toast.show')) toast();
    setupConfirm();
    setupTimeline();
    setupTaskForm();
    setupImport();
    setupGcalForms();
    setupAutoSubmit();
    setupDoneToggle();
    setupSubtasks();
    setupSubtaskEditor();
    setupRepeat();
});
