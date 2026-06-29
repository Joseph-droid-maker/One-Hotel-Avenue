import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
   //base: '/Jing-Jing-Store/',  


  server: {
    proxy: {
      '/Jing-Jing-Store': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },

  build: {
    outDir: '../dist',
    emptyOutDir: true,
  },
});
