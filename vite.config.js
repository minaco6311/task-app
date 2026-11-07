import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Docker の node コンテナで動かすための設定（ブラウザからは localhost:5173 で見る）
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: 'localhost' },
        watch: {
            usePolling: true, // Mac のフォルダを共有しているため、変更の検知をポーリングで行う
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
