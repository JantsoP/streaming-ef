import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        plugins: [
            laravel({
                input: 'resources/js/app.js',
                refresh: true,
            }),
            tailwindcss(),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        server: {
            host: '0.0.0.0',
            hmr: {
                // When accessing from a remote machine (e.g. a VM), set VITE_DEV_SERVER_HOST
                // in your .env to the VM's IP address so the browser can connect to Vite's
                // HMR websocket. Defaults to localhost for local development.
                host: env.VITE_DEV_SERVER_HOST || 'localhost',
            },
        },
    };
});
