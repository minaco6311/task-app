// 招待制・複数ユーザーのブラウザ操作テスト。PC幅（pc）とスマホ幅 390px（phone）の両方で動かす
// 招待は1回しか使えないので、幅ごとに別の招待（e2e/setup.php が作る）を使う
// ログインの試行回数の制限（1分に5回）に当たらないよう、stage3 のログインのあとに動かす（ファイル名の順）
import { test, expect } from '@playwright/test';
import { BASE, login, expectStatus, runtimeData } from './helpers.js';

const PASSWORD = 'invited-person-pass-1';
const NEW_PASSWORD = 'invited-person-pass-2';

/** 横にはみ出していない（スマホ幅で横スクロールが出ない） */
async function expectNoHorizontalScroll(page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
}

test.describe('招待からの登録', () => {
    test('M1. 招待リンクから登録・URL からトークンが消える・自分専用の状態から始まる・パスワード変更', async ({ page }, info) => {
        const data = runtimeData('day');
        const inv = data.invites[info.project.name];

        // ログイン画面に「新規登録」はない
        await page.goto(`${BASE.day}/login`);
        await expect(page.getByText('新規登録')).toHaveCount(0);
        await expect(page.getByText('招待制')).toBeVisible();

        // 招待リンクを開く → 登録画面へ。URL にも履歴にもトークンは残らない
        await page.goto(`${BASE.day}/invite#${inv.token}`);
        await expect(page).toHaveURL(`${BASE.day}/register`);
        expect(page.url()).not.toContain(inv.token);
        await expect(page.getByRole('heading', { name: 'アカウントの登録' })).toBeVisible();
        await expectNoHorizontalScroll(page);
        // 「戻る」でも、トークンの入った URL には戻らない（招待の画面の履歴は、登録画面に置き換わっている）
        await page.goBack();
        expect(page.url()).not.toContain(inv.token);
        expect(page.url()).not.toContain('#');
        await page.goto(`${BASE.day}/register`);
        await expect(page.getByRole('heading', { name: 'アカウントの登録' })).toBeVisible();

        // 招待先と違うメールアドレスでは登録できない
        await page.getByLabel('表示名').fill(`招待された人-${info.project.name}`);
        await page.locator('input[name="email"]').fill('someone-else@example.test');
        await page.locator('input[name="password"]').fill(PASSWORD);
        await page.locator('input[name="password_confirmation"]').fill(PASSWORD);
        await page.getByRole('button', { name: '登録してはじめる' }).click();
        await expect(page.getByText('招待されたメールアドレスと違います')).toBeVisible();

        // 正しいメールアドレスで登録 → カレンダー（ログインした状態）
        await page.locator('input[name="email"]').fill(inv.email);
        await page.locator('input[name="password"]').fill(PASSWORD);
        await page.locator('input[name="password_confirmation"]').fill(PASSWORD);
        await page.getByRole('button', { name: '登録してはじめる' }).click();
        await expect(page).toHaveURL(`${BASE.day}/`);
        await expectStatus(page, 'アカウントを作りました');

        // ほかの人の予定は見えない（自分の予定はまだない）
        await page.goto(`${BASE.day}/?view=day&date=2026-11-05`);
        await expect(page.getByText('もう1人の秘密の予定')).toHaveCount(0);
        await page.goto(`${BASE.day}/?view=list&date=2026-11-05`);
        await expect(page.getByText('もう1人の秘密の予定')).toHaveCount(0);
        await expectNoHorizontalScroll(page);

        // 予定を追加すると、自分の予定として見える
        await page.goto(`${BASE.day}/tasks/create?date=2026-11-06`);
        await page.locator('input[name="title"]').fill(`はじめての予定-${info.project.name}`);
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await expectStatus(page, '予定を追加しました');

        // パスワードを変える（設定 → パスワードを変える）
        await page.goto(`${BASE.day}/settings`);
        await expect(page.getByText(inv.email)).toBeVisible();
        await page.getByRole('link', { name: 'パスワードを変える' }).click();
        await expect(page).toHaveURL(`${BASE.day}/settings/password`);
        await expectNoHorizontalScroll(page);
        await page.getByLabel('いまのパスワード').fill('wrong-password-xyz');
        await page.locator('input[name="password"]').fill(NEW_PASSWORD);
        await page.locator('input[name="password_confirmation"]').fill(NEW_PASSWORD);
        await page.getByRole('button', { name: 'パスワードを変える' }).click();
        await expect(page.getByText('いまのパスワードが違います')).toBeVisible();
        await page.getByLabel('いまのパスワード').fill(PASSWORD);
        await page.locator('input[name="password"]').fill(NEW_PASSWORD);
        await page.locator('input[name="password_confirmation"]').fill(NEW_PASSWORD);
        await page.getByRole('button', { name: 'パスワードを変える' }).click();
        await expectStatus(page, 'パスワードを変えました');

        // ログアウトして、新しいパスワードで入り直す
        await page.getByRole('button', { name: 'ログアウト' }).click();
        await expect(page).toHaveURL(`${BASE.day}/login`);
        await page.getByLabel('メールアドレス').fill(inv.email);
        await page.getByLabel('パスワード').fill(NEW_PASSWORD);
        await page.getByRole('button', { name: 'ログイン' }).click();
        await expect(page).toHaveURL(`${BASE.day}/`);
        await page.goto(`${BASE.day}/?view=day&date=2026-11-06`);
        await expect(page.getByText(`はじめての予定-${info.project.name}`).first()).toBeVisible();

        // 同じ招待リンクはもう使えない
        await page.context().clearCookies();
        await page.goto(`${BASE.day}/invite#${inv.token}`);
        await expect(page.getByRole('heading', { name: '招待リンクが使えません' })).toBeVisible();
        await expect(page.getByText('すでに使われています')).toBeVisible();
    });

    test('M2. 期限切れ・でたらめな招待リンクでは登録できない', async ({ page }) => {
        const data = runtimeData('day');
        await page.goto(`${BASE.day}/invite#${data.expired_token}`);
        await expect(page.getByRole('heading', { name: '招待リンクの期限が切れています' })).toBeVisible();
        await expectNoHorizontalScroll(page);

        await page.goto(`${BASE.day}/login`); // 同じ URL の # だけを変えると、画面が読み込み直されないため
        await page.goto(`${BASE.day}/invite#${'0'.repeat(64)}`);
        await expect(page.getByRole('heading', { name: '招待リンクが使えません' })).toBeVisible();

        // 招待なしで登録画面を開いても入れない
        await page.goto(`${BASE.day}/register`);
        await expect(page.getByRole('heading', { name: '招待リンクが使えません' })).toBeVisible();
    });

    test('M3. 同じ招待リンクで2つのブラウザから同時に登録しても、アカウントは1つだけ', async ({ browser }, info) => {
        test.skip(info.project.name !== 'pc', '同時登録は PC 幅だけで確かめる（招待は1回しか使えない）');
        const inv = runtimeData('day').invites.race;
        const contexts = [await browser.newContext(), await browser.newContext()];
        const pages = await Promise.all(contexts.map((c) => c.newPage()));
        for (const [i, p] of pages.entries()) {
            await p.goto(`${BASE.day}/invite#${inv.token}`);
            await expect(p).toHaveURL(`${BASE.day}/register`);
            await p.getByLabel('表示名').fill(`同時${i}`);
            await p.locator('input[name="email"]').fill(inv.email);
            await p.locator('input[name="password"]').fill(PASSWORD);
            await p.locator('input[name="password_confirmation"]').fill(PASSWORD);
        }
        // 2つ同時に送る
        await Promise.all(pages.map((p) => p.getByRole('button', { name: '登録してはじめる' }).click()));
        await Promise.all(pages.map((p) => p.waitForLoadState('load')));

        const urls = pages.map((p) => p.url());
        expect(urls.filter((u) => u === `${BASE.day}/`)).toHaveLength(1); // 登録できたのは1つだけ
        const loser = pages[urls.indexOf(`${BASE.day}/`) === 0 ? 1 : 0];
        await expect(loser.getByText(/使えなくなりました|すでに登録されています/)).toBeVisible();
        await Promise.all(contexts.map((c) => c.close()));
    });
});

test.describe('ユーザーごとのデータ', () => {
    test('M4. ほかの人の予定は一覧に出ず、ID を指定して開いても見つからない', async ({ page }) => {
        const data = runtimeData('day');
        await login(page, 'day');
        await page.goto(`${BASE.day}/?view=day&date=2026-11-05`);
        await expect(page.getByText('もう1人の秘密の予定')).toHaveCount(0);

        const res = await page.goto(`${BASE.day}/tasks/${data.other_task_id}/edit`);
        expect(res.status()).toBe(404);
        await expect(page.getByText('もう1人の秘密の予定')).toHaveCount(0);

        // 完了の切り替えも、ほかの人の予定にはできない（画面の JavaScript と同じ送り方）
        await page.goto(`${BASE.day}/?view=day&date=2026-11-05`);
        const status = await page.evaluate(async (id) => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const body = new FormData();
            body.append('_method', 'PATCH');
            body.append('done', '1');
            const r = await fetch(`/tasks/${id}/done`, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token }, body });
            return r.status;
        }, data.other_task_id);
        expect(status).toBe(404);
    });

    test('M5. もう1人でログインすると、その人の予定だけが見える', async ({ page }, info) => {
        const data = runtimeData('day');
        await page.goto(`${BASE.day}/login`);
        await page.getByLabel('メールアドレス').fill(data.other.email);
        await page.getByLabel('パスワード').fill(data.other.password);
        await page.getByRole('button', { name: 'ログイン' }).click();
        await expect(page).toHaveURL(`${BASE.day}/`);
        await page.goto(`${BASE.day}/?view=day&date=2026-11-05`);
        await expect(page.getByText('もう1人の秘密の予定').first()).toBeVisible();
        // e2e-day のユーザーが作った予定（ほかのテストの予定）は見えない
        await page.goto(`${BASE.day}/?view=list&date=2026-11-05`);
        await expect(page.getByText(`はじめての予定-${info.project.name}`)).toHaveCount(0);
        await page.goto(`${BASE.day}/settings`);
        await expect(page.getByText(data.other.email)).toBeVisible();
        await expect(page.getByText(data.email)).toHaveCount(0);
    });
});
