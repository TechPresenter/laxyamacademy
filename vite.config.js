import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// The site is served by Apache. In production the compiled files live in /dist
// and .htaccess rewrites every non-file, non-API, non-admin URL to dist/index.html.
// VITE_APP_BASE is the URL path where the project lives ("/" on a real domain,
// "/laxyam/" on a local XAMPP install).
export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const appBase = normalizeBase(env.VITE_APP_BASE || '/');
  const phpOrigin = env.VITE_DEV_PHP_ORIGIN || 'http://localhost';

  return {
    plugins: [react(), tailwindcss()],
    base: command === 'build' ? appBase + 'dist/' : '/',
    define: {
      __APP_BASE__: JSON.stringify(command === 'build' ? appBase : '/'),
    },
    server: {
      port: 5173,
      // During development PHP runs under Apache (XAMPP); proxy API + uploads so
      // cookies stay same-origin with the Vite dev server.
      proxy: {
        '/api': { target: phpOrigin + appBase.replace(/\/$/, ''), changeOrigin: true },
        '/uploads': { target: phpOrigin + appBase.replace(/\/$/, ''), changeOrigin: true },
        '/assets': { target: phpOrigin + appBase.replace(/\/$/, ''), changeOrigin: true },
      },
    },
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      assetsDir: 'static',
      sourcemap: false,
      target: 'es2020',
    },
  };
});

function normalizeBase(b) {
  let s = b.trim();
  if (!s.startsWith('/')) s = '/' + s;
  if (!s.endsWith('/')) s += '/';
  return s;
}
