import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Served at the root of edu.debuggersspace.com; the PHP API lives at /api on the same host.
// `npm run dev` proxies /api to a local PHP server (php -S 127.0.0.1:8090 -t ../api ../api/index.php).
export default defineConfig({
  plugins: [react()],
  base: '/',
  server: { proxy: { '/api': 'http://127.0.0.1:8090' } },
  build: { target: 'es2019', chunkSizeWarningLimit: 400 },
})
