import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite-plus';

// Component and composable tests (`npm run test:js`, i.e. `vp test`). Kept apart
// from vite.config.ts so the test runner skips the Laravel, Wayfinder and
// Tailwind plugins.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.spec.ts'],
        restoreMocks: true,
    },
});
