import fs from 'node:fs';
import path from 'node:path';
import {defineConfig} from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Lightweight Laravel integration without a second frontend application.
 * Production assets are written directly to public/build and Laravel's
 * built-in @vite Blade helper reads public/build/manifest.json.
 * During `npm run dev`, public/hot points Laravel at the Vite dev server.
 */
function laravelHotFile() {
  let hotFile;
  return {
    name: 'vsn-laravel-hot-file',
        /** Documents configure server for this project module. */
configureServer(server) {
      hotFile = path.resolve(process.cwd(), 'public/hot');
      const write = /** Documents write for this project module. */ () => {
        const address = server.httpServer?.address();
        const port = typeof address === 'object' && address ? address.port : 5173;
        const host = process.env.VITE_DEV_HOST || '127.0.0.1';
        fs.mkdirSync(path.dirname(hotFile), {recursive: true});
        fs.writeFileSync(hotFile, `http://${host}:${port}`);
      };
      server.httpServer?.once('listening', write);
      const cleanup = /** Documents cleanup for this project module. */ () => {
        try { if (hotFile && fs.existsSync(hotFile)) fs.unlinkSync(hotFile); } catch {}
      };
      server.httpServer?.once('close', cleanup);
      process.once('exit', cleanup);
    },
  };
}

export default defineConfig(/** Configures Vite for development and Laravel production builds. */ ({command}) => ({
  // Production files live under public/build. Vite must emit dynamic imports
  // with /build/ as their public prefix; otherwise lazy chunks resolve as
  // /assets/* and return 404 from Laravel/Apache. Dev-server URLs stay rooted.
  base: command === 'build' ? '/build/' : '/',
  plugins: [react(), laravelHotFile()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    cors: true,
    hmr: {host: process.env.VITE_DEV_HOST || '127.0.0.1'},
  },
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: {
      input: path.resolve(process.cwd(), 'resources/js/main.jsx'),
    },
  },
}));
