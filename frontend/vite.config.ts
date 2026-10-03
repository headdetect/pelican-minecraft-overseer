import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// One script and one stylesheet with fixed names in resources/dist/. The
// plugin serves them through its own route, so the panel's yarn build never
// sees this source. Commit the output with the source change.
export default defineConfig({
    plugins: [react()],
    define: { 'process.env.NODE_ENV': JSON.stringify('production') },
    build: {
        outDir: '../resources/dist',
        emptyOutDir: true,
        target: 'es2022',
        sourcemap: false,
        minify: true,
        lib: {
            entry: 'src/main.tsx',
            formats: ['iife'],
            name: 'Overseer',
            fileName: () => 'overseer.js',
            cssFileName: 'overseer',
        },
    },
});
