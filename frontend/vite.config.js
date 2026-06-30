import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
   //base: '/One-Hotel-Avenue/',  


  server: {
    proxy: {
      '/One-Hotel-Avenue': {
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
