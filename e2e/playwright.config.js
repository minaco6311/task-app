// ブラウザテスト（Playwright）の設定。docker-compose.e2e.yml の playwright コンテナから動かす
// テスト用のアプリ（app-day / app-night / app-limit）は外につながらないネットワークの中にある
import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests',
    outputDir: './.runtime/test-results',
    reporter: [['list'], ['html', { outputFolder: './.runtime/report', open: 'never' }]],
    // 同じテスト用 DB を使うので、1つずつ順番に動かす
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 60_000,
    use: {
        locale: 'ja-JP',
        timezoneId: 'Asia/Tokyo',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'pc', use: { browserName: 'chromium', viewport: { width: 1280, height: 900 } } },
        { name: 'phone', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3 } },
    ],
});
