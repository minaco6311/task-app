// スマホ表示の崩れの再発防止テスト（カレンダー画面の上部・凡例・右下のボタン）
//   1. 期間の表示（2026年11月・11/29 – 12/5 など）が「…」にならず、欄に収まる
//   2. 凡例のカテゴリがすべて画面の中に見える（カテゴリが増えても横にはみ出さない）
//   3. 右下の「＋」「手帳から」が、いちばん下までスクロールした予定に重ならない
// phone は 320px・375px・390px、pc は 1280px で確かめる
import { test, expect } from '@playwright/test';
import { BASE, login, createTask } from './helpers.js';

const WIDTHS = { phone: [320, 375, 390], pc: [1280] };
const DATE = '2026-11-24';

// 期間の表示の、いちばん長くなりやすいもの
const PERIODS = [
    ['月', `/?view=month&date=${DATE}`],
    ['週（月をまたぐ）', '/?view=week&date=2026-11-30'],
    ['日', `/?view=day&date=${DATE}`],
    ['一覧', `/?view=list&date=${DATE}`],
];

/** 2つの四角が重なっているか */
const overlaps = (a, b) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;

/** 右下のボタン（＋・手帳から）の四角 */
async function fabBoxes(page) {
    const fab = page.locator('[data-fab]');
    return [
        await fab.getByRole('link', { name: '予定を追加' }).boundingBox(),
        await fab.getByRole('link', { name: /手帳から/ }).boundingBox(),
    ];
}

test.describe('スマホ表示（カレンダー画面）', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, 'day');
    });

    test('期間の表示が途中で切れない・横にはみ出さない', async ({ page }, info) => {
        for (const w of WIDTHS[info.project.name]) {
            await page.setViewportSize({ width: w, height: 800 });
            for (const [name, url] of PERIODS) {
                await page.goto(`${BASE.day}${url}`);
                const h1 = page.locator('h1');
                const m = await h1.evaluate((el) => {
                    // 文字そのものの幅（欄が広くても正しく測れるよう、Range で測る）
                    const range = document.createRange();
                    range.selectNodeContents(el);
                    return {
                        text: el.textContent.trim(),
                        need: Math.ceil(range.getBoundingClientRect().width),
                        room: el.clientWidth,
                        ellipsis: getComputedStyle(el).textOverflow,
                    };
                });
                const where = `${w}px・${name}（${m.text}）`;
                // 字体の違い（スマホの Noto Serif JP など）でも収まるよう、1割の余裕を見る
                expect(m.need * 1.1, `${where}：文字が欄に収まらない（必要 ${m.need}px・欄 ${m.room}px）`).toBeLessThanOrEqual(m.room);
                expect(m.ellipsis, `${where}：「…」で切る設定になっている`).not.toBe('ellipsis');
                // 前へ・次へ・今日・設定も画面の中にある
                for (const label of ['前へ', '次へ', '設定']) {
                    const box = await page.getByRole('link', { name: label }).boundingBox();
                    expect(box.x + box.width, `${where}：「${label}」が画面からはみ出す`).toBeLessThanOrEqual(w);
                }
                await expect(page.getByRole('link', { name: '今日', exact: true })).toBeInViewport();
                expect(await page.evaluate(() => document.documentElement.scrollWidth), `${where}：横スクロールが出る`).toBeLessThanOrEqual(w);
            }
        }
    });

    test('凡例のカテゴリがすべて見える（増えても折り返す）', async ({ page }, info) => {
        for (const w of WIDTHS[info.project.name]) {
            await page.setViewportSize({ width: w, height: 800 });
            await page.goto(`${BASE.day}/?view=month&date=${DATE}`);
            const legend = page.getByLabel('凡例');
            // カテゴリを追加したときを想定して、凡例の項目を画面の上で4つ増やす（データは変えない）
            await legend.evaluate((el) => {
                const items = el.querySelectorAll(':scope > span');
                for (let i = 0; i < 4; i++) {
                    const copy = items[i % items.length].cloneNode(true);
                    copy.lastChild.textContent = `追加のカテゴリ${i + 1}`;
                    el.insertBefore(copy, el.querySelector('a'));
                }
            });
            const m = await legend.evaluate((el) => ({ need: el.scrollWidth, room: el.clientWidth }));
            expect(m.need, `${w}px：凡例が横にはみ出す`).toBeLessThanOrEqual(m.room);
            const items = legend.locator(':scope > span');
            expect(await items.count()).toBe(10);
            for (const item of await items.all()) {
                const box = await item.boundingBox();
                expect(box.x, `${w}px：凡例の項目が左にはみ出す`).toBeGreaterThanOrEqual(0);
                expect(box.x + box.width, `${w}px：凡例の項目「${await item.textContent()}」が右にはみ出す`).toBeLessThanOrEqual(w);
                await expect(item.locator('i')).toBeVisible(); // 色の丸
            }
            await expect(legend.getByRole('link', { name: '色を編集' })).toBeInViewport();
            expect(await page.evaluate(() => document.documentElement.scrollWidth), `${w}px：横スクロールが出る`).toBeLessThanOrEqual(w);
        }
    });

    test('右下のボタンが、いちばん下の予定に重ならない', async ({ page }, info) => {
        const p = info.project.name;
        // その日の予定を多めに作り、夜遅い予定も入れる（週・日表示のいちばん下）
        for (const [i, start] of ['08:00', '09:00', '10:00', '11:00', '13:00', '15:00', '17:00'].entries()) {
            await createTask(page, 'day', { title: `表示確認${i + 1}-${p}`, date: DATE, start, end: start.replace(/^(\d\d)/, (h) => String(+h + 1).padStart(2, '0')) });
        }
        await createTask(page, 'day', { title: `夜の予定-${p}`, date: DATE, start: '22:40', end: '23:50' });

        for (const w of WIDTHS[p]) {
            await page.setViewportSize({ width: w, height: 700 });

            // 月・一覧：予定の一覧をいちばん下までスクロールして、最後の予定（行全体）がボタンに重ならない
            for (const view of ['month', 'list']) {
                await page.goto(`${BASE.day}/?view=${view}&date=${DATE}`);
                await page.locator('main').evaluate((el) => el.scrollTo(0, el.scrollHeight));
                const last = page.locator('[data-task-row]').last();
                await last.scrollIntoViewIfNeeded();
                await page.locator('main').evaluate((el) => el.scrollTo(0, el.scrollHeight));
                const row = await last.boundingBox();
                for (const fab of await fabBoxes(page)) {
                    expect(overlaps(row, fab), `${w}px・${view}：いちばん下の予定が右下のボタンに隠れる`).toBe(false);
                }
                // 最後の予定の完了チェックが押せる（ボタンに邪魔されない）
                await expect(last.locator('.task-check')).toBeInViewport();
            }

            // 週・日：タイムラインをいちばん下までスクロールして、23時台の予定がボタンに重ならない
            for (const view of ['week', 'day']) {
                await page.goto(`${BASE.day}/?view=${view}&date=${DATE}`);
                await page.locator('[data-tl-scroll]').evaluate((el) => el.scrollTo(0, el.scrollHeight));
                const ev = await page.locator('.ev', { hasText: `夜の予定-${p}` }).boundingBox();
                for (const fab of await fabBoxes(page)) {
                    expect(overlaps(ev, fab), `${w}px・${view}：23時台の予定が右下のボタンに隠れる`).toBe(false);
                }
            }
        }
    });
});
