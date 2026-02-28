import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.APP_URL || 'http://127.0.0.1:8001';
    const devHost = env.VITE_DEV_HOST || '0.0.0.0';
    const hmrHost = env.VITE_HMR_HOST || undefined;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            vue(),
        ],
        publicDir: 'public',
        server: {
            host: devHost,
            hmr: hmrHost ? { host: hmrHost } : undefined,
            proxy: {
                '^/icons/': appUrl,
                '^/manifest.webmanifest': appUrl,
                '^/penny-': appUrl,
            },
        },
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
    };
});
