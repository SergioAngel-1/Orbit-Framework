import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react-swc'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'node:path'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // Cargar variables de entorno basadas en el modo (development, production, etc.)
  const env = loadEnv(mode, process.cwd(), '');
  const wpApiUrl = env.VITE_WP_API_URL || 'http://admin.starter.local';
  
  return {
    plugins: [
      react(),
      VitePWA({
        registerType: 'autoUpdate',
        includeAssets: [
          'favicon/favicon.ico',
          'favicon/apple-touch-icon.png',
          'favicon/android-chrome-192x192.png',
          'favicon/android-chrome-512x512.png',
          'robots.txt'
        ],
        manifest: false,
        workbox: {
          globPatterns: ['**/*.{js,css,html,ico,png,svg,woff,woff2}'],
          runtimeCaching: [
            // IMPORTANTE: Endpoints sensibles a membresía - NetworkOnly (sin caché)
            // Estos endpoints retornan datos diferentes según el nivel de membresía del usuario
            // El Service Worker no puede distinguir entre usuarios, así que no cacheamos
            {
              urlPattern: new RegExp(`^${wpApiUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/wp-json/starter/v1/(banners|home-sections|categories|menu)`, 'i'),
              handler: 'NetworkOnly'
            },
            // Endpoints de productos - NetworkOnly (sensibles a membresía)
            {
              urlPattern: new RegExp(`^${wpApiUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/wp-json/starter/v1/wc/products`, 'i'),
              handler: 'NetworkOnly'
            },
            // Resto de la API - NetworkFirst con caché corto
            {
              urlPattern: new RegExp(`^${wpApiUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/wp-json/`, 'i'),
              handler: 'NetworkFirst',
              options: {
                cacheName: 'api-cache',
                expiration: {
                  maxEntries: 50,
                  maxAgeSeconds: 60 * 5 // 5 minutos máximo
                },
                cacheableResponse: {
                  statuses: [0, 200]
                },
                networkTimeoutSeconds: 10
              }
            },
            {
              urlPattern: new RegExp(`^${wpApiUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/wp-content/uploads/`, 'i'),
              handler: 'CacheFirst',
              options: {
                cacheName: 'images-cache',
                expiration: {
                  maxEntries: 200,
                  maxAgeSeconds: 60 * 60 * 24 * 30
                },
                cacheableResponse: {
                  statuses: [0, 200]
                }
              }
            },
            {
              urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
              handler: 'CacheFirst',
              options: {
                cacheName: 'google-fonts-cache',
                expiration: {
                  maxEntries: 10,
                  maxAgeSeconds: 60 * 60 * 24 * 365
                },
                cacheableResponse: {
                  statuses: [0, 200]
                }
              }
            },
            {
              urlPattern: /^https:\/\/fonts\.gstatic\.com\/.*/i,
              handler: 'CacheFirst',
              options: {
                cacheName: 'gstatic-fonts-cache',
                expiration: {
                  maxEntries: 10,
                  maxAgeSeconds: 60 * 60 * 24 * 365
                },
                cacheableResponse: {
                  statuses: [0, 200]
                }
              }
            }
          ],
          skipWaiting: true,
          clientsClaim: true
        },
        devOptions: {
          enabled: false
        }
      })
    ],
    build: {
      outDir: 'dist',
      assetsDir: 'assets',
      // Asegurarse de que los archivos estáticos se copien al directorio de salida
      copyPublicDir: true,
      rollupOptions: {
        output: {
          // Code splitting manual para reducir tamaño de chunks
          manualChunks: (id) => {
            // Vendor chunks - librerías grandes separadas
            if (id.includes('node_modules')) {
              // CRÍTICO: React core + todas las librerías que dependen de React
              // deben estar en el MISMO chunk para evitar problemas de orden de carga.
              // Si se separan, el chunk genérico puede ejecutarse ANTES que React,
              // causando "Cannot read properties of undefined (reading 'createContext')"
              if (
                id.includes('/react-dom/') ||
                id.includes('/react/') ||
                id.includes('/scheduler/') ||
                id.includes('react-router') ||
                id.includes('@remix-run') ||
                id.includes('react-i18next') ||
                id.includes('react-transition-group') ||
                id.includes('styled-components') ||
                id.includes('react-toastify') ||
                id.includes('react-country-flag') ||
                id.includes('react-phone-input') ||
                id.includes('react-international-phone') ||
                id.includes('@gsap/react') ||
                id.includes('use-sync-external-store')
              ) {
                return 'vendor-react';
              }
              // Swiper (no depende de React directamente en su core)
              if (id.includes('swiper')) {
                return 'vendor-swiper';
              }
              // Axios (sin dependencia de React)
              if (id.includes('axios')) {
                return 'vendor-axios';
              }
              // Flag icons CSS (solo assets, sin JS de React)
              if (id.includes('flag-icons')) {
                return 'vendor-flags';
              }
              // React PDF (muy grande, lazy load)
              if (id.includes('@react-pdf') || id.includes('react-pdf')) {
                return 'vendor-pdf';
              }
              // GSAP core (sin React)
              if (id.includes('gsap')) {
                return 'vendor-gsap';
              }
              // React Icons (tree-shakeable, separar por tamaño)
              if (id.includes('react-icons')) {
                return 'vendor-react-icons';
              }
              // DOMPurify (sin dependencia de React)
              if (id.includes('dompurify')) {
                return 'vendor-sanitize';
              }
              // QRCode + Alertify + OAuth (evitar mezcla con React en chunk genérico)
              if (id.includes('qrcode') || id.includes('alertifyjs') || id.includes('oauth-1.0a')) {
                return 'vendor-utils';
              }
              // i18next core (sin React, solo lógica de traducción)
              if (id.includes('i18next') && !id.includes('react-i18next')) {
                return 'vendor-i18n';
              }
              // CryptoJS (sin dependencia de React)
              if (id.includes('crypto-js')) {
                return 'vendor-crypto';
              }
              // Resto de node_modules
              return 'vendor';
            }
          },
          assetFileNames: (assetInfo) => {
            const sanitizeName = (value: string) =>
              value
                .toLowerCase()
                .replace(/[^a-z0-9.-]/g, '-')
                .replace(/-+/g, '-');

            const pickFirst = (value?: string | string[]) => {
              if (!value) return '';
              return Array.isArray(value) ? value[0] ?? '' : value;
            };

            const rawPath =
              pickFirst(assetInfo.originalFileNames) ||
              pickFirst(assetInfo.names) ||
              (assetInfo.name ?? '') ||
              (typeof assetInfo === 'object' &&
              assetInfo !== null &&
              'facadeModuleId' in assetInfo
                ? (assetInfo as any).facadeModuleId ?? ''
                : '');

            const normalizedPath = rawPath.replace(/\\/g, '/');
            const parsed = path.parse(normalizedPath);
            const baseName = sanitizeName(parsed.name || 'asset');

            const makePath = (folder: string) => {
              const prefix = folder ? `${folder}/` : '';
              return `assets/${prefix}${baseName}-[hash][extname]`;
            };

            // PRIORIDAD 1: Banderas de flag-icons (antes de verificar extensión)
            if (normalizedPath.includes('flag-icons/flags')) {
              return makePath('flags');
            }

            // PRIORIDAD 2: Fuentes
            if (/\.(woff2?|eot|ttf|otf)$/i.test(normalizedPath)) {
              return makePath('fonts');
            }

            // PRIORIDAD 3: Imágenes (PNG, JPG, GIF, WebP)
            if (/\.(png|jpe?g|gif|webp|avif)$/i.test(normalizedPath)) {
              return makePath('images');
            }

            // PRIORIDAD 4: SVG genéricos (que no sean banderas)
            if (/\.svg$/i.test(normalizedPath)) {
              return makePath('images');
            }

            // Por defecto: CSS y otros assets en raíz de assets
            return makePath('');
          }
        }
      }
    },
    server: {
      proxy: {
        '/wp-json': {
          target: wpApiUrl,
          changeOrigin: true,
          secure: false,
          rewrite: (path) => path
        },
        '/jwt-auth': {
          target: wpApiUrl,
          changeOrigin: true,
          secure: false
        },
        // Proxy para imágenes y archivos estáticos
        '/wp-content': {
          target: wpApiUrl,
          changeOrigin: true,
          secure: false
        },
        // Proxy para la API de WooCommerce
        '/wc-api': {
          target: wpApiUrl,
          changeOrigin: true,
          secure: false
        }
      },
      cors: {
        origin: ['http://localhost:5173', 'http://localhost:5174', 'http://admin.starter.local', 'https://admin.starter.local'],
        methods: ['GET', 'HEAD', 'PUT', 'PATCH', 'POST', 'DELETE', 'OPTIONS'],
        allowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With'],
        credentials: true
      }
    }
  };
})
