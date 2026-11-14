import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// In the Base44 sandbox the preview is served through a proxy, so the dev server
// must accept the sandbox hostname. Only applied in preview mode; unset keeps
// Vite's default (localhost only).
const previewHosts = []
if (process.env.BASE44_PREVIEW_MODE === '1' && process.env.BASE44_SANDBOX_HOST_DOMAIN) {
  previewHosts.push(`.${process.env.BASE44_SANDBOX_HOST_DOMAIN}`)
}

// Aplikasi Kantor Agent AI adalah PWA statis di public/kantor. Dev server Vite
// tidak menebak index.html untuk direktori public, jadi `/kantor/` diarahkan ke
// berkasnya (produksi dilayani Apache/artisan serve sebagai direktori biasa).
function kantorIndex() {
  return {
    name: 'kantor-index',
    configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        if (req.url === '/kantor' || req.url === '/kantor/') req.url = '/kantor/index.html'
        next()
      })
    },
  }
}

export default defineConfig(({ command }) => ({
  // Produksi disajikan Laravel dari public/app, jadi aset memakai prefiks /app/.
  base: command === 'build' ? '/app/' : '/',
  plugins: [react(), kantorIndex()],
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: previewHosts.length ? previewHosts : undefined,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY || 'http://laravel:8000',
        changeOrigin: true,
        // Teruskan IP klien asli (X-Forwarded-For) ke Laravel supaya info
        // "terakhir masuk" di profil menunjukkan IP/lokasi pengguna, bukan proxy.
        xfwd: true,
      },
    },
  },
}))
