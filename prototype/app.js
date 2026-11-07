'use strict';

/* ================================================================
   いろどり手帳 — カレンダー形式のタスク管理（試作版）
   データはこのブラウザの localStorage に保存します。
   ================================================================ */

const STORE_KEY = 'irodori-techo-v1';
const HOUR_H = 48; // タイムライン1時間ぶんの高さ(px)。style.css の --hour と合わせる
const WEEK = ['日', '月', '火', '水', '木', '金', '土'];
const BEFORE_OPTIONS = [
  [0, '通知しない'], [5, '5分前'], [10, '10分前'], [15, '15分前'], [30, '30分前'],
  [60, '1時間前'], [120, '2時間前'], [180, '3時間前'],
];
// 手帳の雰囲気に合わせた、くすみ系の色
const PALETTE = ['#4A7FA0', '#6A9C7A', '#D0965A', '#C06A6E', '#8A7DB2', '#4E9EA3', '#C2A24E', '#C47FA2', '#7C8A94'];
const DEFAULT_CATEGORIES = [
  { id: 'work', name: '仕事', color: '#4A7FA0' },
  { id: 'private', name: 'プライベート', color: '#6A9C7A' },
  { id: 'family', name: '家族', color: '#D0965A' },
  { id: 'errand', name: '用事・手続き', color: '#C06A6E' },
  { id: 'study', name: '勉強', color: '#8A7DB2' },
  { id: 'health', name: '病院・健康', color: '#4E9EA3' },
];
const DEFAULT_SETTINGS = { defaultBefore: 60, dayBeforeTime: '23:00', morningTime: '08:00' };

/* ===== 小さな道具 ===== */
const pad = n => String(n).padStart(2, '0');
const toKey = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const fromKey = k => { const [y, m, d] = k.split('-').map(Number); return new Date(y, m - 1, d); };
const addDays = (k, n) => { const d = fromKey(k); d.setDate(d.getDate() + n); return toKey(d); };
const todayKey = () => toKey(new Date());
const toMin = t => { if (!t) return null; const [h, m] = t.split(':').map(Number); return h * 60 + m; };
const fromMin = m => { m = Math.max(0, Math.min(m, 23 * 60 + 59)); return `${pad(Math.floor(m / 60))}:${pad(m % 60)}`; };
const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const dateLabel = k => { const d = fromKey(k); return `${d.getMonth() + 1}月${d.getDate()}日(${WEEK[d.getDay()]})`; };
const fmtDT = d => `${d.getMonth() + 1}/${d.getDate()}(${WEEK[d.getDay()]}) ${pad(d.getHours())}:${pad(d.getMinutes())}`;
const beforeLabel = min => (BEFORE_OPTIONS.find(o => o[0] === min) || [0, `${min}分前`])[1];
const afterLabel = min => (min >= 60 && min % 60 === 0 ? `${min / 60}時間後` : `${min}分後`);
const atTime = (k, hm) => { const d = fromKey(k); const [h, m] = hm.split(':').map(Number); d.setHours(h, m, 0, 0); return d; };

/* ===== データ ===== */
let state;
let ui;

function load() {
  try {
    const raw = localStorage.getItem(STORE_KEY);
    if (raw) {
      const s = JSON.parse(raw);
      if (s && Array.isArray(s.tasks)) return normalize(s);
    }
  } catch (e) { /* 読めないときはサンプルから始める */ }
  return seed();
}

function normalize(s) {
  s.categories = Array.isArray(s.categories) && s.categories.length ? s.categories : DEFAULT_CATEGORIES.map(c => ({ ...c }));
  // 以前の初期色のままのカテゴリは、新しいくすみ色に置き換える（自分で変えた色はそのまま）
  const OLD_PALETTE = ['#3B6FD8', '#2FA36B', '#F08A24', '#E04F5F', '#8B5CF6', '#14A3B8', '#D4A72C', '#E05FA8', '#6B7280'];
  s.categories.forEach(c => {
    const i = OLD_PALETTE.indexOf(String(c.color).toUpperCase());
    if (i >= 0) c.color = PALETTE[i];
  });
  s.settings = { ...DEFAULT_SETTINGS, ...(s.settings || {}) };
  s.tasks = s.tasks.map(t => ({ ...t, notify: { dayBefore: true, morning: true, before: s.settings.defaultBefore, ...(t.notify || {}) } }));
  return s;
}

function save() {
  try {
    localStorage.setItem(STORE_KEY, JSON.stringify({ ...state, view: ui.view }));
  } catch (e) {
    toast('保存できませんでした（ブラウザの設定を確認してください）');
  }
}

// 初回だけ、使い方がわかるようにサンプルの予定を入れる
function seed() {
  const t = todayKey();
  const mk = (off, title, cat, start, end, extra = {}) => ({
    id: uid(), title, date: addDays(t, off), allDay: !start, start: start || '', end: end || '',
    categoryId: cat, memo: '', done: false, gcal: false,
    notify: { dayBefore: true, morning: true, before: 60 }, ...extra,
  });
  return {
    categories: DEFAULT_CATEGORIES.map(c => ({ ...c })),
    settings: { ...DEFAULT_SETTINGS },
    view: 'month',
    tasks: [
      mk(-2, '英語の勉強', 'study', '20:00', '21:00', { done: true }),
      mk(0, 'チーム定例', 'work', '10:00', '11:00'),
      mk(0, 'ジム', 'health', '19:00', '20:00', { notify: { dayBefore: false, morning: true, before: 30 } }),
      mk(1, '歯医者', 'health', '14:00', '15:00'),
      mk(1, '資料の提出', 'work', '', ''),
      mk(2, '燃えないゴミ', 'errand', '', '', { notify: { dayBefore: true, morning: false, before: 60 } }),
      mk(3, '友だちとランチ', 'private', '12:00', '13:30'),
      mk(5, '子どもの参観日', 'family', '10:30', '12:00'),
      mk(5, '区役所で手続き', 'errand', '14:00', '14:30', { notify: { dayBefore: true, morning: true, before: 15 } }),
      mk(8, '企画会議', 'work', '15:00', '16:30'),
    ],
  };
}

const catOf = id => state.categories.find(c => c.id === id) || state.categories[0] || { id: '', name: '未分類', color: '#999' };
const byTime = (a, b) => (b.allDay - a.allDay) || (toMin(a.start) ?? 0) - (toMin(b.start) ?? 0);
const tasksOn = k => state.tasks.filter(t => t.date === k).sort(byTime);

function newTask(partial = {}) {
  const date = partial.date || ui.selected || todayKey();
  let start = '10:00';
  if (date === todayKey()) start = fromMin(Math.min(new Date().getHours() + 1, 23) * 60);
  return {
    id: uid(), title: '', date, allDay: false, start, end: fromMin(toMin(start) + 60),
    categoryId: state.categories[0]?.id || '', memo: '', done: false, gcal: false,
    notify: { dayBefore: true, morning: true, before: state.settings.defaultBefore },
    ...partial,
  };
}

function upsert(t) {
  const i = state.tasks.findIndex(x => x.id === t.id);
  if (i >= 0) state.tasks[i] = t; else state.tasks.push(t);
  save();
}

/* ===== 通知の計算（LINEに送る予定の内容） ===== */
function taskNotifs(t) {
  const s = state.settings;
  const out = [];
  if (t.notify.dayBefore) out.push({ at: atTime(addDays(t.date, -1), s.dayBeforeTime), kind: '前日' });
  if (t.notify.morning) out.push({ at: atTime(t.date, s.morningTime), kind: '当日朝' });
  if (!t.allDay && t.start && t.notify.before > 0) {
    out.push({ at: new Date(atTime(t.date, t.start) - t.notify.before * 60000), kind: beforeLabel(t.notify.before) });
  }
  return out.sort((a, b) => a.at - b.at);
}

// 前日・当日朝は「その日の予定まとめ」、開始前は予定ごとに1通
function upcomingNotifs(days = 7) {
  const now = new Date();
  const s = state.settings;
  const t0 = todayKey();
  const lineOf = x => `${x.allDay ? '終日' : x.start} ${x.title}`;
  const res = [];
  for (let i = 0; i <= days; i++) {
    const k = addDays(t0, i);
    const list = tasksOn(k).filter(x => !x.done);
    const db = list.filter(x => x.notify.dayBefore);
    if (db.length) res.push({ at: atTime(addDays(k, -1), s.dayBeforeTime), head: `🌙 明日の予定（${dateLabel(k)}）`, lines: db.map(lineOf) });
    const mo = list.filter(x => x.notify.morning);
    if (mo.length) res.push({ at: atTime(k, s.morningTime), head: `☀️ 今日の予定（${dateLabel(k)}）`, lines: mo.map(lineOf) });
    list.filter(x => !x.allDay && x.start && x.notify.before > 0).forEach(x => {
      res.push({ at: new Date(atTime(k, x.start) - x.notify.before * 60000), head: `⏰ ${afterLabel(x.notify.before)}に始まります`, lines: [`${x.start}〜 ${x.title}`] });
    });
  }
  return res.filter(r => r.at >= now).sort((a, b) => a.at - b.at).slice(0, 12);
}

/* ===== Googleカレンダー連携（登録画面を開くリンク / .ics） ===== */
function gcalUrl(t) {
  const ymd = k => k.replace(/-/g, '');
  let dates;
  if (t.allDay || !t.start) {
    dates = `${ymd(t.date)}/${ymd(addDays(t.date, 1))}`;
  } else {
    const end = t.end && toMin(t.end) > toMin(t.start) ? t.end : fromMin(toMin(t.start) + 60);
    dates = `${ymd(t.date)}T${t.start.replace(':', '')}00/${ymd(t.date)}T${end.replace(':', '')}00`;
  }
  const p = new URLSearchParams({ action: 'TEMPLATE', text: t.title, dates, ctz: 'Asia/Tokyo' });
  if (t.memo) p.set('details', t.memo);
  return 'https://calendar.google.com/calendar/render?' + p.toString();
}

function icsFor(tasks) {
  const e = s => String(s).replace(/\\/g, '\\\\').replace(/\n/g, '\\n').replace(/([,;])/g, '\\$1');
  const ymd = k => k.replace(/-/g, '');
  const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
  const L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//irodori-techo//JA', 'CALSCALE:GREGORIAN'];
  for (const t of tasks) {
    L.push('BEGIN:VEVENT', `UID:${t.id}@irodori-techo`, `DTSTAMP:${stamp}`);
    if (t.allDay || !t.start) {
      L.push(`DTSTART;VALUE=DATE:${ymd(t.date)}`, `DTEND;VALUE=DATE:${ymd(addDays(t.date, 1))}`);
    } else {
      const end = t.end && toMin(t.end) > toMin(t.start) ? t.end : fromMin(toMin(t.start) + 60);
      L.push(`DTSTART:${ymd(t.date)}T${t.start.replace(':', '')}00`, `DTEND:${ymd(t.date)}T${end.replace(':', '')}00`);
    }
    L.push(`SUMMARY:${e(t.title)}`);
    if (t.memo) L.push(`DESCRIPTION:${e(t.memo)}`);
    L.push(`CATEGORIES:${e(catOf(t.categoryId).name)}`, 'END:VEVENT');
  }
  L.push('END:VCALENDAR');
  return L.join('\r\n');
}

function download(name, text, type) {
  const url = URL.createObjectURL(new Blob([text], { type }));
  const a = document.createElement('a');
  a.href = url; a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/* ===== 手帳の文字 → タスク候補 ===== */
const CAT_HINTS = [
  ['health', /病院|歯医者|歯科|クリニック|通院|健診|検診|ジム|整体|薬/],
  ['work', /会議|打ち?合わ?せ|MTG|ミーティング|締め?切|提出|出張|面談|商談|仕事|資料/i],
  ['study', /勉強|試験|テスト|講座|セミナー|授業|英語|資格/],
  ['family', /子ども|子供|家族|母|父|保育|学校|参観|運動会|誕生日|実家/],
  ['errand', /役所|銀行|支払|振込|郵便|ゴミ|ごみ|手続|更新|申込|返却|買い物/],
];
function guessCat(title) {
  const cats = (state && state.categories) || DEFAULT_CATEGORIES;
  for (const [id, re] of CAT_HINTS) if (re.test(title) && cats.some(c => c.id === id)) return id;
  return cats.some(c => c.id === 'private') ? 'private' : cats[0].id;
}

function parseText(text, baseKey) {
  const base = fromKey(baseKey);
  const makeDate = (mo, d) => {
    let y = base.getFullYear();
    if (mo < base.getMonth() + 1 - 6) y += 1;
    else if (mo > base.getMonth() + 1 + 6) y -= 1;
    return toKey(new Date(y, mo - 1, d));
  };
  // 全角の数字・記号を半角にそろえる
  const norm = s => s.replace(/[０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0))
    .replace(/：/g, ':').replace(/／/g, '/').replace(/[～〜]/g, '~').replace(/時半/g, ':30');

  let curDate = null;
  const out = [];
  for (const raw of text.split(/\r?\n/)) {
    let line = norm(raw).trim();
    if (!line) continue;

    let date = null;
    let m = line.match(/(\d{1,2})\s*[/月.]\s*(\d{1,2})\s*日?/);
    if (m && +m[1] >= 1 && +m[1] <= 12 && +m[2] >= 1 && +m[2] <= 31) {
      date = makeDate(+m[1], +m[2]);
      line = line.replace(m[0], ' ');
    } else if ((m = line.match(/^(\d{1,2})\s*日/)) && +m[1] >= 1 && +m[1] <= 31) {
      date = toKey(new Date(base.getFullYear(), base.getMonth(), +m[1]));
      line = line.replace(m[0], ' ');
    }
    line = line.replace(/[(（]\s*[日月火水木金土祝]\s*[)）]/g, ' ');

    let start = '';
    let end = '';
    const pm = /午後|PM/i.test(line);
    const tm = line.match(/(\d{1,2})\s*(?::|時)\s*(\d{2})?\s*分?(?:\s*[-~ー−–—]\s*(\d{1,2})\s*(?::|時)?\s*(\d{2})?\s*分?)?/);
    if (tm && +tm[1] <= 24) {
      let h = +tm[1];
      if (pm && h < 12) h += 12;
      start = fromMin(h * 60 + (+tm[2] || 0));
      if (tm[3] != null && +tm[3] <= 24) {
        let h2 = +tm[3];
        if (pm && h2 < 12) h2 += 12;
        end = fromMin(h2 * 60 + (+tm[4] || 0));
      }
      line = line.replace(tm[0], ' ').replace(/午後|午前|AM|PM/gi, ' ');
    }

    const title = line.replace(/^[\s・\-–—●○◯□■☐☑✓✔*＊>＞]+/, '').replace(/\s+/g, ' ').trim();
    if (date) curDate = date;
    if (!title) continue; // 「10/27(火)」だけの行は日付の見出しとして使う

    const k = date || curDate || baseKey;
    if (start && (!end || toMin(end) <= toMin(start))) end = fromMin(toMin(start) + 60);
    out.push({ title, date: k, allDay: !start, start, end, categoryId: guessCat(title), on: true });
  }
  return out;
}

/* ===== 画面の描画 ===== */
function render() {
  $$('.viewtabs button').forEach(b => b.setAttribute('aria-selected', String(b.dataset.view === ui.view)));
  $('#periodLabel').textContent = periodLabel();
  renderToday();
  renderLegend();
  const main = $('#view');
  main.classList.toggle('tl-mode', ui.view !== 'month');
  if (ui.view === 'month') renderMonth(main);
  else renderTimeline(main, ui.view === 'week' ? 7 : 1);
}

function weekStart(k) { return addDays(k, -fromKey(k).getDay()); }

function periodLabel() {
  const c = fromKey(ui.cursor);
  if (ui.view === 'month') return `${c.getFullYear()}年${c.getMonth() + 1}月`;
  if (ui.view === 'day') return `${c.getMonth() + 1}月${c.getDate()}日(${WEEK[c.getDay()]})`;
  const a = fromKey(weekStart(ui.cursor));
  const b = fromKey(addDays(weekStart(ui.cursor), 6));
  return a.getMonth() === b.getMonth()
    ? `${a.getMonth() + 1}月${a.getDate()}日 – ${b.getDate()}日`
    : `${a.getMonth() + 1}/${a.getDate()} – ${b.getMonth() + 1}/${b.getDate()}`;
}

// 右上の「Date / Day」欄。今日の曜日の丸だけ塗る
function renderToday() {
  const d = new Date();
  $('#todayDate').textContent = `${d.getFullYear()} / ${d.getMonth() + 1} / ${d.getDate()}`;
  $('#todayDots').innerHTML = WEEK
    .map((w, i) => `<i class="w${i} ${i === d.getDay() ? 'on' : ''}">${w}</i>`).join('');
}

function renderLegend() {
  $('#legend').innerHTML = state.categories
    .map(c => `<span class="lg"><i style="--c:${esc(c.color)}"></i>${esc(c.name)}</span>`).join('')
    + '<button class="lg-edit" data-act="settings">色を編集</button>';
}

function renderMonth(main) {
  const c = fromKey(ui.cursor);
  const y = c.getFullYear();
  const m = c.getMonth();
  const first = new Date(y, m, 1);
  const days = new Date(y, m + 1, 0).getDate();
  const rows = Math.ceil((first.getDay() + days) / 7);
  const start = toKey(new Date(y, m, 1 - first.getDay()));
  const t = todayKey();

  let html = '<div class="month"><div class="month-head">'
    + WEEK.map((w, i) => `<span class="w${i}">${w}</span>`).join('')
    + '</div><div class="month-grid">';
  for (let i = 0; i < rows * 7; i++) {
    const k = addDays(start, i);
    const d = fromKey(k);
    const list = tasksOn(k);
    const bars = list.slice(0, 4)
      .map(x => `<i style="--c:${esc(catOf(x.categoryId).color)}" class="${x.done ? 'done' : ''}"></i>`).join('');
    const more = list.length > 4 ? `<b class="more">+${list.length - 4}</b>` : '';
    const cls = ['day', `w${d.getDay()}`, d.getMonth() !== m && 'other', k === t && 'today', k === ui.selected && 'sel'].filter(Boolean).join(' ');
    html += `<button class="${cls}" data-date="${k}" aria-label="${dateLabel(k)} 予定${list.length}件">`
      + `<span class="num">${d.getDate()}</span><span class="bars">${bars}${more}</span></button>`;
  }
  html += '</div></div>' + agendaHTML(ui.selected);
  main.innerHTML = html;
}

function agendaHTML(k) {
  const list = tasksOn(k);
  const rows = list.map(x => {
    const badges = [];
    if (!x.allDay && x.notify.before > 0) badges.push(`🔔${beforeLabel(x.notify.before)}`);
    if (x.gcal) badges.push('Google登録済');
    return `<button class="task-row ${x.done ? 'done' : ''}" style="--c:${esc(catOf(x.categoryId).color)}" data-act="edit" data-id="${x.id}">`
      + `<span class="time">${x.allDay ? '終日' : `${x.start}–${x.end}`}</span>`
      + `<span class="t">${esc(x.title)}</span>`
      + `<span class="badges">${badges.map(b => `<span class="badge">${esc(b)}</span>`).join('')}</span></button>`;
  }).join('');
  return `<section class="agenda"><div class="agenda-head"><h2>${dateLabel(k)}</h2>`
    + `<button class="link" data-act="open-day" data-date="${k}">タイムラインで見る ›</button></div>`
    + (rows || `<p class="empty">予定はありません<button data-act="new" data-date="${k}">＋ 追加する</button></p>`)
    + '</section>';
}

// 重なった予定を横に並べる
function layoutLanes(evs) {
  evs.sort((a, b) => a.s - b.s || b.e - a.e);
  let cluster = [];
  let clusterEnd = -1;
  const flush = () => {
    const lanes = [];
    for (const ev of cluster) {
      let i = lanes.findIndex(end => end <= ev.s);
      if (i < 0) { i = lanes.length; lanes.push(0); }
      lanes[i] = ev.e;
      ev.lane = i;
    }
    cluster.forEach(ev => { ev.lanes = lanes.length; });
    cluster = [];
  };
  for (const ev of evs) {
    if (cluster.length && ev.s >= clusterEnd) flush();
    if (!cluster.length) clusterEnd = ev.e;
    cluster.push(ev);
    clusterEnd = Math.max(clusterEnd, ev.e);
  }
  if (cluster.length) flush();
  return evs;
}

function nowTop() { const n = new Date(); return (n.getHours() * 60 + n.getMinutes()) / 60 * HOUR_H; }

function renderTimeline(main, n) {
  const startK = n === 7 ? weekStart(ui.cursor) : ui.cursor;
  const keys = Array.from({ length: n }, (_, i) => addDays(startK, i));
  const t = todayKey();

  const head = keys.map(k => {
    const d = fromKey(k);
    return `<button class="tl-dh w${d.getDay()} ${k === t ? 'today' : ''}" data-act="open-day" data-date="${k}">`
      + `<span>${WEEK[d.getDay()]}</span><b>${d.getDate()}</b></button>`;
  }).join('');

  const hasAllDay = keys.some(k => tasksOn(k).some(x => x.allDay));
  const allday = hasAllDay
    ? `<div class="tl-row tl-allday"><div class="tl-ad-label">終日</div>${keys.map(k => `<div class="tl-ad-col">${
      tasksOn(k).filter(x => x.allDay).map(x => `<button class="chip-ev ${x.done ? 'done' : ''}" style="--c:${esc(catOf(x.categoryId).color)}" data-act="edit" data-id="${x.id}">${esc(x.title)}</button>`).join('')
    }</div>`).join('')}</div>`
    : '';

  const hours = Array.from({ length: 23 }, (_, i) => `<span style="top:${(i + 1) * HOUR_H}px">${i + 1}:00</span>`).join('');

  const cols = keys.map(k => {
    const evs = layoutLanes(tasksOn(k).filter(x => !x.allDay && x.start).map(x => {
      const s = toMin(x.start);
      let e = toMin(x.end);
      if (e == null || e <= s) e = s + 60;
      return { x, s, e: Math.min(e, 1440) };
    }));
    const blocks = evs.map(({ x, s, e, lane, lanes }) => {
      const top = s / 60 * HOUR_H;
      const h = Math.max((e - s) / 60 * HOUR_H - 2, 18);
      return `<button class="ev ${x.done ? 'done' : ''}" data-act="edit" data-id="${x.id}" `
        + `style="--c:${esc(catOf(x.categoryId).color)};top:${top}px;height:${h}px;left:calc(${lane / lanes * 100}% + 1px);width:calc(${100 / lanes}% - 3px)">`
        + `<b>${esc(x.title)}</b><span>${x.start}–${x.end}</span></button>`;
    }).join('');
    const now = k === t ? `<div class="now" style="top:${nowTop()}px"></div>` : '';
    return `<div class="tl-col" data-date="${k}">${blocks}${now}</div>`;
  }).join('');

  main.innerHTML = `<div class="tl ${n === 1 ? 'is-day' : 'is-week'}" style="--n:${n}">`
    + `<div class="tl-row tl-head"><div></div>${head}</div>${allday}`
    + `<div class="tl-scroll"><div class="tl-row tl-body"><div class="tl-gutter">${hours}</div>${cols}</div></div></div>`;

  const startHour = keys.includes(t) ? Math.max(0, new Date().getHours() - 1) : 7;
  $('.tl-scroll', main).scrollTop = startHour * HOUR_H;
}

/* ===== 下から出るシート ===== */
let sheetToken = 0;
function openSheet(html, onMount) {
  const bd = $('#sheet');
  const body = $('#sheetBody');
  sheetToken++;
  body.innerHTML = html;
  body.scrollTop = 0;
  bd.hidden = false;
  requestAnimationFrame(() => bd.classList.add('open'));
  if (onMount) onMount(body);
}
function closeSheet() {
  const bd = $('#sheet');
  const my = ++sheetToken;
  bd.classList.remove('open');
  setTimeout(() => { if (my === sheetToken) { bd.hidden = true; $('#sheetBody').innerHTML = ''; } }, 220);
}
const sheetIsOpen = () => !$('#sheet').hidden;

// 削除などは2回押しで確定（誤操作防止）
function confirmTap(btn, msg) {
  if (btn.dataset.armed) return true;
  const orig = btn.textContent;
  btn.dataset.armed = '1';
  btn.textContent = msg;
  setTimeout(() => { if (btn.isConnected) { delete btn.dataset.armed; btn.textContent = orig; } }, 3000);
  return false;
}

let toastTimer;
function toast(msg) {
  const el = $('#toast');
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2800);
}

/* ===== 予定の追加・編集 ===== */
function catChips(selectedId) {
  return state.categories.map(c => `<label class="chip"><input type="radio" name="cat" value="${esc(c.id)}" ${c.id === selectedId ? 'checked' : ''}>`
    + `<span style="--c:${esc(c.color)}">${esc(c.name)}</span></label>`).join('');
}

function openEditor(task, isNew = false) {
  if (!task) return;
  const t = structuredClone(task);
  const s = state.settings;
  const beforeOpts = BEFORE_OPTIONS.map(([v, l]) => `<option value="${v}" ${v === t.notify.before ? 'selected' : ''}>${l}${v === s.defaultBefore ? '（標準）' : ''}</option>`).join('');

  openSheet(`
    <div class="sheet-head"><h2>${isNew ? '予定を追加' : '予定を編集'}</h2><button class="icon-btn" data-close aria-label="閉じる">✕</button></div>
    <form class="form" id="taskForm" novalidate>
      <input class="title-input" name="title" placeholder="タイトル（例：歯医者）" value="${esc(t.title)}" maxlength="80" autocomplete="off">
      <div class="field-row">
        <label class="field"><span>日付</span><input type="date" name="date" value="${t.date}" required></label>
        <label class="switch"><input type="checkbox" name="allDay" ${t.allDay ? 'checked' : ''}><span>終日</span></label>
      </div>
      <div class="field-row" data-timed ${t.allDay ? 'hidden' : ''}>
        <label class="field"><span>開始</span><input type="time" name="start" value="${t.start || '10:00'}" step="300"></label>
        <label class="field"><span>終了</span><input type="time" name="end" value="${t.end || '11:00'}" step="300"></label>
      </div>
      <div class="group"><div class="group-title">色（カテゴリ）</div><div class="chips">${catChips(t.categoryId)}</div></div>
      <div class="group notify">
        <div class="group-title"><span class="mini-badge">LINE</span>通知</div>
        <label class="check"><input type="checkbox" name="dayBefore" ${t.notify.dayBefore ? 'checked' : ''}><span>前日 ${s.dayBeforeTime} の「明日の予定」に入れる</span></label>
        <label class="check"><input type="checkbox" name="morning" ${t.notify.morning ? 'checked' : ''}><span>当日 ${s.morningTime} の「今日の予定」に入れる</span></label>
        <label class="field" data-timed ${t.allDay ? 'hidden' : ''}><span>開始前の通知</span><select name="before">${beforeOpts}</select></label>
        <p class="hint" id="notifyPreview"></p>
      </div>
      <label class="field"><span>メモ</span><textarea name="memo" rows="2" maxlength="500">${esc(t.memo)}</textarea></label>
      ${isNew ? '' : `<label class="check"><input type="checkbox" name="done" ${t.done ? 'checked' : ''}><span>完了にする</span></label>`}
      <div class="actions">
        <button type="submit" class="btn primary">保存</button>
        <button type="button" class="btn ghost" data-act="gcal">${t.gcal ? 'もう一度 ' : ''}Googleカレンダーに登録</button>
        ${isNew ? '' : '<button type="button" class="btn danger" data-act="delete">削除</button>'}
      </div>
    </form>`, body => {
    const f = $('#taskForm', body);
    let duration = (toMin(t.end) ?? 0) - (toMin(t.start) ?? 0);
    if (duration <= 0) duration = 60;

    const read = () => {
      const fd = new FormData(f);
      const allDay = f.allDay.checked;
      const start = allDay ? '' : (fd.get('start') || '10:00');
      let end = allDay ? '' : (fd.get('end') || '');
      if (!allDay && (!end || toMin(end) <= toMin(start))) end = fromMin(toMin(start) + 60);
      return {
        ...t,
        title: String(fd.get('title') || '').trim(),
        date: fd.get('date') || t.date,
        allDay, start, end,
        categoryId: fd.get('cat') || t.categoryId,
        memo: String(fd.get('memo') || '').trim(),
        done: f.done ? f.done.checked : t.done,
        notify: { dayBefore: f.dayBefore.checked, morning: f.morning.checked, before: Number(fd.get('before')) || 0 },
      };
    };
    const updatePreview = () => {
      const list = taskNotifs(read());
      $('#notifyPreview', body).textContent = list.length
        ? '届く予定：' + list.map(n => `${fmtDT(n.at)}（${n.kind}）`).join(' ／ ')
        : 'この予定ではLINE通知は届きません';
    };
    const commit = () => {
      const v = read();
      if (!v.title) { f.title.focus(); toast('タイトルを入力してください'); return null; }
      upsert(v);
      ui.selected = v.date;
      if (ui.view === 'month') ui.cursor = v.date;
      return v;
    };

    f.allDay.addEventListener('change', () => {
      $$('[data-timed]', f).forEach(el => { el.hidden = f.allDay.checked; });
      updatePreview();
    });
    f.start.addEventListener('change', () => {
      if (f.start.value) f.end.value = fromMin(toMin(f.start.value) + duration);
    });
    f.end.addEventListener('change', () => {
      const d = toMin(f.end.value) - toMin(f.start.value);
      if (d > 0) duration = d;
    });
    f.addEventListener('input', updatePreview);
    f.addEventListener('change', updatePreview);
    updatePreview();

    f.addEventListener('submit', e => {
      e.preventDefault();
      if (!commit()) return;
      closeSheet(); render();
      toast(isNew ? '予定を追加しました' : '保存しました');
    });
    $('[data-act="gcal"]', f).addEventListener('click', () => {
      const v = read();
      if (!v.title) { f.title.focus(); toast('タイトルを入力してください'); return; }
      window.open(gcalUrl(v), '_blank', 'noopener');
      t.gcal = true;
      commit();
      closeSheet(); render();
      toast('Googleカレンダーの画面で「保存」を押すと登録されます');
    });
    const del = $('[data-act="delete"]', f);
    if (del) del.addEventListener('click', () => {
      if (!confirmTap(del, 'もう一度押すと削除します')) return;
      state.tasks = state.tasks.filter(x => x.id !== t.id);
      save(); closeSheet(); render();
      toast('削除しました');
    });
    if (isNew) setTimeout(() => f.title.focus(), 250);
  });
}

/* ===== 手帳から取り込み ===== */
function openImport() {
  let imgUrl = null;
  let cands = [];
  const base = ui.selected || todayKey();
  const catOptions = id => state.categories.map(c => `<option value="${esc(c.id)}" ${c.id === id ? 'selected' : ''}>${esc(c.name)}</option>`).join('');

  openSheet(`
    <div class="sheet-head"><h2>手帳から取り込む</h2><button class="icon-btn" data-close aria-label="閉じる">✕</button></div>
    <ol class="steps">
      <li><b>手帳のスクショを選ぶ</b>
        <label class="upload"><input type="file" accept="image/*" id="imgInput">📷 画像を選ぶ</label>
        <img class="preview-img" id="imgPreview" alt="選んだ手帳の画像" hidden>
      </li>
      <li><b>画像の文字をコピーして貼り付け</b>
        <details class="howto"><summary>文字のコピーのしかた</summary>
          <p><b>iPhone：</b>写真アプリでスクショを開き、文字を長押し →「すべてを選択」→「コピー」。</p>
          <p><b>Android：</b>Googleフォトでスクショを開き「レンズ」→「テキスト」→「すべて選択」→「コピー」。</p>
          <p>1行に1つの予定が理想です。「10/27(火)」のように日付だけの行があれば、その下の行はその日の予定として扱います。</p>
        </details>
        <textarea class="input" id="ocrText" rows="6" placeholder="例）&#10;10/27(火)&#10;14:00 歯医者&#10;19:00-20:00 ジム&#10;10/28 資料の提出"></textarea>
      </li>
    </ol>
    <button class="btn primary" id="parseBtn">タスク候補を作る</button>
    <div id="candArea"></div>`, body => {
    const imgInput = $('#imgInput', body);
    const img = $('#imgPreview', body);
    imgInput.addEventListener('change', () => {
      const file = imgInput.files && imgInput.files[0];
      if (!file) return;
      if (imgUrl) URL.revokeObjectURL(imgUrl);
      imgUrl = URL.createObjectURL(file);
      img.src = imgUrl;
      img.hidden = false;
    });
    img.addEventListener('click', () => img.classList.toggle('big'));

    const area = $('#candArea', body);
    const renderCands = () => {
      const n = cands.filter(c => c.on).length;
      area.innerHTML = `<div class="cands"><div class="cands-head"><h3>タスク候補 ${cands.length}件</h3><span class="hint">直してから登録できます</span></div>`
        + cands.map((c, i) => `
          <div class="cand ${c.on ? '' : 'off'}" data-i="${i}" style="--c:${esc(catOf(c.categoryId).color)}">
            <div class="cand-top">
              <label class="check"><input type="checkbox" data-k="on" ${c.on ? 'checked' : ''} aria-label="登録する"></label>
              <input class="input" data-k="title" value="${esc(c.title)}" aria-label="タイトル">
            </div>
            <div class="cand-grid">
              <input class="input" type="date" data-k="date" value="${c.date}" aria-label="日付">
              <select class="input" data-k="categoryId" aria-label="カテゴリ">${catOptions(c.categoryId)}</select>
              <input class="input" type="time" data-k="start" value="${c.start}" aria-label="開始（空なら終日）">
              <input class="input" type="time" data-k="end" value="${c.end}" aria-label="終了">
            </div>
          </div>`).join('')
        + `<button class="btn primary" id="registerBtn" ${n ? '' : 'disabled'}>選んだ ${n}件を登録</button>`
        + '<p class="hint">時刻が空の候補は「終日」の予定になります。</p></div>';
    };

    area.addEventListener('input', e => {
      const box = e.target.closest('.cand');
      if (!box) return;
      const c = cands[+box.dataset.i];
      const k = e.target.dataset.k;
      c[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
      if (k === 'on') { box.classList.toggle('off', !c.on); const n = cands.filter(x => x.on).length; const b = $('#registerBtn', area); b.textContent = `選んだ ${n}件を登録`; b.disabled = !n; }
      if (k === 'categoryId') box.style.setProperty('--c', catOf(c.categoryId).color);
    });

    $('#parseBtn', body).addEventListener('click', () => {
      const text = $('#ocrText', body).value;
      cands = parseText(text, base);
      if (!cands.length) {
        area.innerHTML = '<p class="hint" style="margin-top:12px">予定が見つかりませんでした。1行に1つの予定になるように貼り付けてみてください。</p>';
        return;
      }
      renderCands();
      area.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    area.addEventListener('click', e => {
      if (e.target.id === 'registerBtn') {
        const picked = cands.filter(c => c.on && c.title.trim());
        if (!picked.length) return;
        const added = picked.map(c => {
          const allDay = !c.start;
          const start = allDay ? '' : c.start;
          const end = allDay ? '' : (c.end && toMin(c.end) > toMin(c.start) ? c.end : fromMin(toMin(c.start) + 60));
          const task = newTask({ title: c.title.trim(), date: c.date || base, allDay, start, end, categoryId: c.categoryId });
          state.tasks.push(task);
          return task;
        });
        save(); render();
        area.innerHTML = `<div class="cands"><div class="cands-head"><h3>✅ ${added.length}件を登録しました</h3></div>
          <p class="hint">Googleカレンダーにも入れる場合は、1件ずつ「Googleに登録」を押して、開いた画面で「保存」を押してください。PCなら .ics でまとめて取り込めます。</p>
          <div class="reg-list">${added.map(t => `<div class="reg-item" style="--c:${esc(catOf(t.categoryId).color)}">
            <span>${dateLabel(t.date)} ${t.allDay ? '終日' : t.start} ${esc(t.title)}</span>
            <a class="btn small" href="${esc(gcalUrl(t))}" target="_blank" rel="noopener" data-gid="${t.id}">Googleに登録</a></div>`).join('')}</div>
          <button class="btn ghost" id="icsBtn">まとめて .ics で書き出す</button>
          <button class="btn primary" data-close>カレンダーを見る</button></div>`;
        $('#icsBtn', area).addEventListener('click', () => download(`techo-${todayKey()}.ics`, icsFor(added), 'text/calendar'));
        ui.selected = added[0].date;
        ui.cursor = added[0].date;
        return;
      }
      const a = e.target.closest('[data-gid]');
      if (a) {
        const t = state.tasks.find(x => x.id === a.dataset.gid);
        if (t) { t.gcal = true; save(); render(); }
        a.textContent = '登録画面を開きました';
        a.classList.add('added');
      }
    });
  });
}

/* ===== 設定 ===== */
function catRows() {
  return state.categories.map(c => `<div class="cat-row" data-id="${esc(c.id)}">
    <input type="color" value="${esc(c.color)}" aria-label="${esc(c.name)}の色">
    <input class="input" type="text" value="${esc(c.name)}" maxlength="12" aria-label="カテゴリ名">
    <button class="icon-btn" data-act="del-cat" aria-label="${esc(c.name)}を削除">🗑</button></div>`).join('');
}

function linePreviewHTML() {
  const list = upcomingNotifs(7);
  if (!list.length) return '<div class="bubble-wrap"><div class="bubble">1週間以内に届く通知はありません</div></div>';
  return list.map(n => `<div class="bubble-wrap"><time>${fmtDT(n.at)}</time><div class="bubble"><b>${esc(n.head)}</b>${n.lines.map(l => `<span>・${esc(l)}</span>`).join('')}</div></div>`).join('');
}

function openSettings() {
  const s = state.settings;
  openSheet(`
    <div class="sheet-head"><h2>設定</h2><button class="icon-btn" data-close aria-label="閉じる">✕</button></div>
    <section class="set">
      <h3>凡例（色とカテゴリ）</h3>
      <p class="hint">月のカレンダーには文字を出さず、この色だけで予定を表します。</p>
      <div id="catList" class="group">${catRows()}</div>
      <button class="btn ghost" data-act="add-cat">＋ カテゴリを追加</button>
    </section>
    <section class="set">
      <h3>通知のタイミング</h3>
      <label class="field inline"><span>前日の通知（明日の予定）</span><input type="time" id="setDayBefore" value="${s.dayBeforeTime}"></label>
      <label class="field inline"><span>当日の通知（今日の予定）</span><input type="time" id="setMorning" value="${s.morningTime}"></label>
      <label class="field inline"><span>開始前の通知（標準）</span><select id="setBefore">${BEFORE_OPTIONS.map(([v, l]) => `<option value="${v}" ${v === s.defaultBefore ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
      <p class="hint">開始前の通知は予定ごとに変えられます。標準を変えると、これから作る予定に使われます。</p>
    </section>
    <section class="set">
      <h3>LINE連携</h3>
      <div class="line-card"><span class="line-badge">LINE</span><div><b>未接続（準備中）</b>
        <p>LINEに通知を届けるには、LINE公式アカウントと、決まった時刻に送信するサーバーが必要です。いまは「いつ・どんな通知が届くか」を下で確認できます。</p></div></div>
    </section>
    <section class="set">
      <h3>これから届く通知（見本）</h3>
      <div class="line-preview" id="linePreview">${linePreviewHTML()}</div>
    </section>
    <section class="set">
      <h3>データ</h3>
      <p class="hint">予定はこのブラウザの中に保存されています。機種変更の前などはバックアップを書き出してください。</p>
      <div class="btn-col">
        <button class="btn ghost" data-act="export-ics">すべての予定を .ics で書き出す</button>
        <button class="btn ghost" data-act="export-json">バックアップを書き出す</button>
        <label class="btn ghost">バックアップから戻す<input type="file" accept="application/json,.json" id="restoreInput" hidden></label>
        <button class="btn danger" data-act="reset">すべてのデータを削除</button>
      </div>
    </section>`, body => {
    const refreshPreview = () => { $('#linePreview', body).innerHTML = linePreviewHTML(); };

    $('#catList', body).addEventListener('input', e => {
      const row = e.target.closest('.cat-row');
      const c = row && state.categories.find(x => x.id === row.dataset.id);
      if (!c) return;
      if (e.target.type === 'color') c.color = e.target.value;
      else c.name = e.target.value.trim() || '無題';
      save(); render();
    });

    $('#setDayBefore', body).addEventListener('change', e => { if (e.target.value) { s.dayBeforeTime = e.target.value; save(); refreshPreview(); } });
    $('#setMorning', body).addEventListener('change', e => { if (e.target.value) { s.morningTime = e.target.value; save(); refreshPreview(); } });
    $('#setBefore', body).addEventListener('change', e => { s.defaultBefore = Number(e.target.value); save(); });

    $('#restoreInput', body).addEventListener('change', e => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      const r = new FileReader();
      r.onload = () => {
        try {
          const obj = JSON.parse(String(r.result));
          if (!obj || !Array.isArray(obj.tasks)) throw new Error('bad');
          state = normalize(obj);
          save(); closeSheet(); render();
          toast(`バックアップから ${state.tasks.length}件を戻しました`);
        } catch (err) {
          toast('このファイルは読み込めませんでした');
        }
      };
      r.readAsText(file);
    });

    body.addEventListener('click', e => {
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const act = btn.dataset.act;
      if (act === 'add-cat') {
        state.categories.push({ id: uid(), name: '新しいカテゴリ', color: PALETTE[state.categories.length % PALETTE.length] });
        save(); render();
        $('#catList', body).innerHTML = catRows();
        const inputs = $$('#catList .input', body);
        inputs[inputs.length - 1].select();
      } else if (act === 'del-cat') {
        const id = btn.closest('.cat-row').dataset.id;
        if (state.categories.length <= 1) { toast('カテゴリは1つ以上必要です'); return; }
        const rest = state.categories.filter(c => c.id !== id);
        const used = state.tasks.filter(t => t.categoryId === id).length;
        if (used && !confirmTap(btn, '⚠️')) { toast(`${used}件の予定が「${rest[0].name}」に移ります。もう一度押すと削除します`); return; }
        state.tasks.forEach(t => { if (t.categoryId === id) t.categoryId = rest[0].id; });
        state.categories = rest;
        save(); render();
        $('#catList', body).innerHTML = catRows();
      } else if (act === 'export-ics') {
        download(`irodori-techo-${todayKey()}.ics`, icsFor(state.tasks), 'text/calendar');
      } else if (act === 'export-json') {
        download(`irodori-techo-backup-${todayKey()}.json`, JSON.stringify(state, null, 2), 'application/json');
      } else if (act === 'reset') {
        if (!confirmTap(btn, 'もう一度押すと、すべての予定とカテゴリを削除します')) return;
        state = { tasks: [], categories: DEFAULT_CATEGORIES.map(c => ({ ...c })), settings: { ...DEFAULT_SETTINGS } };
        save(); closeSheet(); render();
        toast('すべてのデータを削除しました');
      }
    });
  });
}

/* ===== 操作の受け付け ===== */
function shift(dir) {
  if (ui.view === 'month') {
    const d = fromKey(ui.cursor);
    d.setDate(1);
    d.setMonth(d.getMonth() + dir);
    ui.cursor = toKey(d);
    const t = todayKey();
    ui.selected = t.slice(0, 7) === ui.cursor.slice(0, 7) ? t : ui.cursor;
  } else {
    ui.cursor = addDays(ui.cursor, dir * (ui.view === 'week' ? 7 : 1));
    ui.selected = ui.cursor;
  }
  render();
}

function init() {
  state = load();
  ui = { view: ['month', 'week', 'day'].includes(state.view) ? state.view : 'month', cursor: todayKey(), selected: todayKey() };
  delete state.view;
  save();

  $('#prevBtn').addEventListener('click', () => shift(-1));
  $('#nextBtn').addEventListener('click', () => shift(1));
  $('#todayBtn').addEventListener('click', () => { ui.cursor = ui.selected = todayKey(); render(); });
  $('#settingsBtn').addEventListener('click', openSettings);
  $('#legend').addEventListener('click', e => { if (e.target.closest('[data-act="settings"]')) openSettings(); });
  $('#addBtn').addEventListener('click', () => openEditor(newTask({ date: ui.selected }), true));
  $('#importBtn').addEventListener('click', openImport);
  $$('.viewtabs button').forEach(b => b.addEventListener('click', () => {
    ui.view = b.dataset.view;
    ui.cursor = ui.selected;
    save(); render();
  }));

  const view = $('#view');
  view.addEventListener('click', e => {
    const act = e.target.closest('[data-act]');
    if (act) {
      const a = act.dataset.act;
      if (a === 'edit') return openEditor(state.tasks.find(t => t.id === act.dataset.id));
      if (a === 'new') return openEditor(newTask({ date: act.dataset.date }), true);
      if (a === 'open-day') { ui.view = 'day'; ui.cursor = ui.selected = act.dataset.date; save(); return render(); }
    }
    const day = e.target.closest('.day');
    if (day) {
      ui.selected = day.dataset.date;
      if (day.classList.contains('other')) ui.cursor = ui.selected;
      return render();
    }
    // タイムラインの空いている所を押すと、その時刻で新しい予定
    const col = e.target.closest('.tl-col');
    if (col && e.target === col) {
      const min = Math.floor(e.offsetY / HOUR_H * 2) * 30;
      openEditor(newTask({ date: col.dataset.date, start: fromMin(min), end: fromMin(min + 60) }), true);
    }
  });

  // 左右スワイプで前後の月・週・日へ
  let sx = 0;
  let sy = 0;
  view.addEventListener('touchstart', e => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
  view.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - sx;
    const dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 2) shift(dx < 0 ? 1 : -1);
  }, { passive: true });

  const bd = $('#sheet');
  bd.addEventListener('click', e => { if (e.target === bd || e.target.closest('[data-close]')) closeSheet(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && sheetIsOpen()) closeSheet(); });

  // 現在時刻の赤い線を1分ごとに動かす
  setInterval(() => { const n = $('.now'); if (n) n.style.top = `${nowTop()}px`; }, 60000);

  render();
}

if (typeof document !== 'undefined') init();
if (typeof module !== 'undefined') module.exports = { parseText, gcalUrl, icsFor, layoutLanes, _setState: s => { state = s; } };
