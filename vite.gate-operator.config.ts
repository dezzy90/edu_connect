import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/gate-operator.tsx'],
            buildDirectory: 'gate-operator-assets/20261022-r7',
            refresh: false,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: { jsx: 'automatic' },
});
