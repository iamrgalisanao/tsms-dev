import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        react({
            jsxRuntime: 'classic'
        }),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        hmr: true,
        watch: {
            usePolling: true,
        }
    },
    optimizeDeps: {
        include: ['react', 'react-dom']
    },
    esbuild: {
        loader: { '.js': 'jsx' },
        jsxFactory: 'React.createElement',
        jsxFragment: 'React.Fragment'
    }
});
