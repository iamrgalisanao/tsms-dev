import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js'
            ],
            refresh: true,
        }),
        react({
            jsxRuntime: 'automatic',
            babel: {
                plugins: [
                    '@babel/plugin-transform-react-jsx'
                ]
            }
        })
    ],
    server: {
        hmr: true,
        watch: {
            usePolling: true
        }
    },
    optimizeDeps: {
        include: ['react', 'react-dom']
    }
});
