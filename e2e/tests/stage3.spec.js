// 第3段階（繰り返し予定・再通知）のブラウザ操作テスト。PC幅（pc）とスマホ幅 390px（phone）の両方で動かす
// 予定名に幅の名前を付けて、2つの幅のテストのデータが混ざらないようにしている
import { test, expect } from '@playwright/test';
import { BASE, login, loginWithForm, expectStatus, openTask, createTask } from './helpers.js';

test.describe('ログイン', () => {
    test('1. ログインできる・違うパスワードでは入れない', async ({ page }) => {
        await page.goto(`${BASE.day}/`);
        await expect(page).toHaveURL(`${BASE.day}/login`); // ログインしていなければログイン画面へ
        await page.getByLabel('メールアドレス').fill('e2e-day@example.test');
        await page.getByLabel('パスワード').fill('wrong-password-123');
        await page.getByRole('button', { name: 'ログイン' }).click();
        await expect(page.getByText('メールアドレスかパスワードが違います')).toBeVisible();

        await loginWithForm(page, 'day');
        await expect(page.getByRole('link', { name: '一覧' })).toBeVisible();
    });
});

test.describe('繰り返し予定', () => {
    test('2〜5・13. 種類の切り替え・曜日の複数選択・終わりの日・保存と表示', async ({ page }, info) => {
        const title = `週3の掃除-${info.project.name}`;
        await login(page, 'day');
        await page.goto(`${BASE.day}/tasks/create?date=2026-11-09`);
        const box = page.locator('[data-repeat]');
        const weekly = box.locator('[data-repeat-weekly]');
        const monthly = box.locator('[data-repeat-monthly]');
        const end = box.locator('[data-repeat-end]');
        const until = box.locator('input[name="repeat_until"]');

        // 2. 種類の切り替えで、出る欄が変わる
        await expect(weekly).toBeHidden();
        await expect(monthly).toBeHidden();
        await expect(end).toBeHidden();
        await box.getByLabel('繰り返し', { exact: true }).selectOption('monthly');
        await expect(monthly).toBeVisible();
        await expect(weekly).toBeHidden();
        await expect(end).toBeVisible();
        await box.getByLabel('繰り返し', { exact: true }).selectOption('daily');
        await expect(monthly).toBeHidden();
        await expect(weekly).toBeHidden();
        await box.getByLabel('繰り返し', { exact: true }).selectOption('weekly');
        await expect(weekly).toBeVisible();
        await expect(monthly).toBeHidden();

        // 3. 曜日を複数選ぶ（予定の日 11/9 は月曜なので、月曜は最初から選ばれている）
        const day = (w) => weekly.locator('label.chip').filter({ hasText: w }).locator('input');
        await expect(day('月')).toBeChecked();
        await weekly.locator('label.chip').filter({ hasText: '水' }).click();
        await weekly.locator('label.chip').filter({ hasText: '金' }).click();
        await expect(day('水')).toBeChecked();
        await expect(day('金')).toBeChecked();
        await expect(day('火')).not.toBeChecked();

        // 4. 終わりの日の入力欄は「この日まで」のときだけ使える
        await expect(until).toBeDisabled();
        await end.getByText('この日まで').click();
        await expect(until).toBeEnabled();
        await end.getByText('終わりなし').click();
        await expect(until).toBeDisabled();
        await end.getByText('この日まで').click();
        await until.fill('2026-12-31');

        // 5. 保存
        await page.getByPlaceholder('タイトル（例：歯医者）').fill(title);
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await expectStatus(page, '繰り返しの予定を追加しました');

        // 13. 保存した設定が、ほかの回の編集画面に正しく出る
        await openTask(page, 'day', '2026-11-13', title);
        await expect(page.getByText('いまの繰り返し：毎週 月・水・金・12月31日(木)まで')).toBeVisible();
        await expect(box.getByLabel('繰り返し', { exact: true })).toHaveValue('weekly');
        await expect(day('月')).toBeChecked();
        await expect(day('水')).toBeChecked();
        await expect(day('金')).toBeChecked();
        await expect(day('木')).not.toBeChecked();
        await expect(end.locator('input[value="until"]')).toBeChecked();
        await expect(until).toHaveValue('2026-12-31');
        await expect(until).toBeEnabled();
        await expect(page.getByRole('button', { name: 'この予定だけ保存' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'この予定以後すべてに保存' })).toBeVisible();

        // 一覧にも各回が出る（11/9 から7日間：月・水・金の3回）
        await page.goto(`${BASE.day}/?view=list&date=2026-11-09`);
        await expect(page.getByText(title)).toHaveCount(3);
    });

    test('6〜8. 「この予定だけ」・「以後すべて」・個別変更の上書き確認', async ({ page }, info) => {
        const p = info.project.name;
        const title = `週3の会議-${p}`;
        await login(page, 'day');
        await page.goto(`${BASE.day}/tasks/create?date=2026-11-09`);
        await page.getByPlaceholder('タイトル（例：歯医者）').fill(title);
        await page.locator('[data-repeat]').getByLabel('繰り返し', { exact: true }).selectOption('weekly');
        for (const w of ['水', '金']) await page.locator('[data-repeat-weekly] label.chip').filter({ hasText: w }).click();
        await page.getByRole('button', { name: '保存', exact: true }).click();
        await expectStatus(page, '繰り返しの予定を追加しました');

        // 6. 11/11 だけ変える → 11/13 は変わらない
        await openTask(page, 'day', '2026-11-11', title);
        await page.getByPlaceholder('タイトル（例：歯医者）').fill(`特別な会議-${p}`);
        await page.getByRole('button', { name: 'この予定だけ保存' }).click();
        await expectStatus(page, 'この予定だけ保存しました');
        await openTask(page, 'day', '2026-11-13', title);
        await expect(page.getByPlaceholder('タイトル（例：歯医者）')).toHaveValue(title);
        await openTask(page, 'day', '2026-11-11', `特別な会議-${p}`);
        await expect(page.getByText('（この予定は個別に変更済み）')).toBeVisible();

        // 7. 11/9 から「以後すべて」 → 個別に変更した 11/11 は残る
        await openTask(page, 'day', '2026-11-09', title);
        await expect(page.getByText('個別に変更した1件も上書きする')).toBeVisible();
        await page.getByPlaceholder('タイトル（例：歯医者）').fill(`新しい会議-${p}`);
        await page.getByRole('button', { name: 'この予定以後すべてに保存' }).click();
        await expectStatus(page, '個別に変更した1件はそのまま残しました');
        await openTask(page, 'day', '2026-11-13', `新しい会議-${p}`);
        await openTask(page, 'day', '2026-11-11', `特別な会議-${p}`);

        // 8. 上書きにチェック → 確認画面（件数・日付・いまの内容・上書き後の内容）→ まずは「残して保存」
        await openTask(page, 'day', '2026-11-09', `新しい会議-${p}`);
        await page.getByPlaceholder('タイトル（例：歯医者）').fill(`全部の会議-${p}`);
        await page.getByText('個別に変更した1件も上書きする').click();
        await page.getByRole('button', { name: 'この予定以後すべてに保存' }).click();
        await expect(page.getByRole('heading', { name: '個別に変更した予定の上書き' })).toBeVisible();
        await expect(page.getByText('1件', { exact: true })).toBeVisible();
        await expect(page.getByText(`特別な会議-${p}`)).toBeVisible();
        await expect(page.getByText(`全部の会議-${p}`)).toBeVisible();
        await expect(page.getByText('毎週 月・水・金')).toBeVisible();
        await page.getByRole('button', { name: '個別の変更は残して保存（おすすめ）' }).click();
        await expectStatus(page, 'そのまま残しました');
        await openTask(page, 'day', '2026-11-11', `特別な会議-${p}`);

        // 同じ操作で「上書きして保存」
        await openTask(page, 'day', '2026-11-09', `全部の会議-${p}`);
        await page.getByPlaceholder('タイトル（例：歯医者）').fill(`最終の会議-${p}`);
        await page.getByText('個別に変更した1件も上書きする').click();
        await page.getByRole('button', { name: 'この予定以後すべてに保存' }).click();
        await page.getByRole('button', { name: '1件も上書きして保存' }).click();
        await expectStatus(page, '個別に変更した1件も上書きしました');
        await openTask(page, 'day', '2026-11-11', `最終の会議-${p}`);
        await expect(page.getByText('（この予定は個別に変更済み）')).toHaveCount(0);
    });
});

test.describe('再通知（スヌーズ）', () => {
    test('9. 10分後・1時間後・翌日8時を予約・取り消し', async ({ page }, info) => {
        const title = `スヌーズ確認-${info.project.name}`;
        await login(page, 'day');
        await createTask(page, 'day', { title, date: '2026-11-05' });
        await openTask(page, 'day', '2026-11-05', title);

        const panel = page.locator('#snooze');
        await expect(panel.getByRole('button', { name: /10分後/ })).toContainText('10:10');
        await expect(panel.getByRole('button', { name: /1時間後/ })).toContainText('11:00');
        await expect(panel.getByRole('button', { name: /翌日/ })).toContainText('11/6 08:00');
        await expect(panel.getByRole('button').filter({ hasText: '🌙' })).toHaveCount(0); // 日中なので、ボタンに夜間の印はない

        await panel.getByRole('button', { name: /10分後/ }).click();
        await expectStatus(page, '11/5(木) 10:10 に再通知します');
        await panel.getByRole('button', { name: /1時間後/ }).click();
        await expectStatus(page, '11/5(木) 11:00 に再通知します');
        await panel.getByRole('button', { name: /翌日/ }).click();
        await expectStatus(page, '11/6(金) 08:00 に再通知します');
        for (const t of ['11/5(木) 10:10', '11/5(木) 11:00', '11/6(金) 08:00']) {
            await expect(panel.getByText(t, { exact: true })).toBeVisible();
        }

        await panel.getByRole('button', { name: '取り消す' }).first().click();
        await expectStatus(page, '再通知の予約を取り消しました');
        await expect(panel.getByRole('button', { name: '取り消す' })).toHaveCount(2);
    });

    test('10. 夜間のスヌーズは確認してから予約する', async ({ page }, info) => {
        const title = `寝る前の薬-${info.project.name}`;
        await login(page, 'night');
        await createTask(page, 'night', { title, date: '2026-11-05', start: '22:30', end: '23:00' });
        await openTask(page, 'night', '2026-11-05', title);

        const panel = page.locator('#snooze');
        await expect(panel.getByRole('button', { name: /10分後/ })).toContainText('22:05');
        await expect(panel.getByRole('button', { name: /10分後/ })).toContainText('🌙');

        // 押しても、まだ予約しない（確認が出る）→「やめる」
        await panel.getByRole('button', { name: /10分後/ }).click();
        await expect(page.getByText('11/5(木) 22:05 は夜間（22時〜7時）です。夜間でもLINEに再通知しますか？')).toBeVisible();
        await expect(panel.getByRole('button', { name: '取り消す' })).toHaveCount(0);
        await page.getByRole('link', { name: 'やめる' }).click();
        await expect(page.getByText('夜間でもLINEに再通知しますか？')).toBeHidden();
        await expect(panel.getByRole('button', { name: '取り消す' })).toHaveCount(0);

        // もう一度押して「夜間でも予約する」
        await panel.getByRole('button', { name: /10分後/ }).click();
        await page.getByRole('button', { name: '夜間でも予約する' }).click();
        await expectStatus(page, '11/5(木) 22:05 に再通知します（夜間）');
        await expect(panel.getByText('11/5(木) 22:05', { exact: true })).toBeVisible();
    });
});

test.describe('LINE送信数の上限', () => {
    test('11. 上限に達したときの表示（設定画面・スヌーズ）', async ({ page }) => {
        await login(page, 'limit');
        await page.goto(`${BASE.limit}/settings`);
        const sends = page.locator('#sends');
        await expect(sends).toContainText('今月のLINE送信数：200通');
        await expect(sends).toContainText('上限 200通');
        await expect(sends.getByText('上限に達しました')).toBeVisible();
        await expect(sends).toContainText('LINE側の送信数を確認できませんでした');

        await openTask(page, 'limit', '2026-11-05', '歯医者（上限）');
        const panel = page.locator('#snooze');
        await expect(panel.getByRole('alert')).toContainText('を超えるおそれがあります。新しい再通知は予約できません');
        for (const name of [/10分後/, /1時間後/, /翌日/]) {
            await expect(panel.getByRole('button', { name })).toBeDisabled();
        }
    });
});
