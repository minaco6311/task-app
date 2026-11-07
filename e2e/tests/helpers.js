// ブラウザテストの共通の操作
import { expect, test } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export const BASE = {
    day: 'http://app-day:8000',     // 11/5(木) 10:00・シミュレーション
    night: 'http://app-night:8000', // 11/5(木) 21:55・シミュレーション
    limit: 'http://app-limit:8000', // 今月200通送信済み・上限200通
};

/** e2e/setup.php が作ったログイン情報・招待のトークンなど（毎回ランダム。Git 対象外） */
export function runtimeData(scenario) {
    return credentials(scenario);
}

function credentials(scenario) {
    return JSON.parse(readFileSync(fileURLToPath(new URL(`../.runtime/${scenario}.json`, import.meta.url)), 'utf8'));
}

/** ログイン画面から入る（入れた状態は e2e/.runtime/ に保存して、次のテストで使い回す） */
export async function loginWithForm(page, scenario) {
    const { email, password } = credentials(scenario);
    await page.goto(`${BASE[scenario]}/login`);
    await page.getByLabel('メールアドレス').fill(email);
    await page.getByLabel('パスワード').fill(password);
    await page.getByRole('button', { name: 'ログイン' }).click();
    await expect(page).toHaveURL(`${BASE[scenario]}/`);
    await page.context().storageState({ path: stateFile(scenario) });
}

/**
 * ログインした状態にする。アプリはログインの試行を1分に5回までにしているので、
 * 保存したログイン状態があればそれを使い、毎回はログインしない
 */
export async function login(page, scenario) {
    const file = stateFile(scenario);
    if (existsSync(file)) {
        await page.context().addCookies(JSON.parse(readFileSync(file, 'utf8')).cookies);
        await page.goto(`${BASE[scenario]}/`);
        if (page.url() === `${BASE[scenario]}/`) return;
    }
    await loginWithForm(page, scenario);
}

function stateFile(scenario) {
    return fileURLToPath(new URL(`../.runtime/state-${scenario}-${test.info().project.name}.json`, import.meta.url));
}

/** 画面下のお知らせ（保存しました など） */
export async function expectStatus(page, text) {
    await expect(page.locator('#toast')).toContainText(text);
}

/** 月表示でその日を選び、予定の行を押して編集画面を開く */
export async function openTask(page, scenario, date, title) {
    await page.goto(`${BASE[scenario]}/?view=month&date=${date}`);
    await page.locator('section.panel').first().getByRole('link', { name: new RegExp(escape(title)) }).first().click();
    await expect(page.getByRole('heading', { name: '予定を編集' })).toBeVisible();
}

export function escape(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** 予定を1件作る（繰り返しなし） */
export async function createTask(page, scenario, { title, date, start = '14:00', end = '15:00' }) {
    await page.goto(`${BASE[scenario]}/tasks/create?date=${date}`);
    await page.getByPlaceholder('タイトル（例：歯医者）').fill(title);
    await page.locator('input[name="start_time"]').fill(start);
    await page.locator('input[name="end_time"]').fill(end);
    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expectStatus(page, '予定を追加しました');
}
