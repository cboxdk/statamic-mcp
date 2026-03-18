import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

/**
 * Statamic v6 externals plugin.
 * Rewrites `import { ... } from 'vue'` to use `window.Vue` at runtime,
 * matching Statamic's own vite-plugin/externals behavior.
 */
function statamicExternals() {
    return {
        name: 'statamic-externals',
        config() {
            return {
                build: {
                    rollupOptions: {
                        external: ['vue'],
                        output: {
                            format: 'iife',
                            inlineDynamicImports: true,
                            globals: {
                                vue: 'Vue',
                            },
                        },
                    },
                },
            };
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/addon.js'],
            publicDirectory: 'resources/dist',
        }),
        vue(),
        statamicExternals(),
    ],
});
