import React from 'react'
import ReactDOM from 'react-dom/client'
import { registerSW } from 'virtual:pwa-register'
import i18n from './config/i18n'
import App from './App.tsx'
import './index.css'
import 'flag-icons/css/flag-icons.min.css'
import 'alertifyjs/build/css/alertify.css'
import 'alertifyjs/build/css/themes/default.css'
import { migrateToSecureStorage } from './utils/secureStorage'
import logger from './utils/logger'

// Registrar Service Worker para PWA
if ('serviceWorker' in navigator) {
  registerSW({
    onNeedRefresh() {
      if (confirm(i18n.t('uiComponents:pwa.newVersionAvailable'))) {
        window.location.reload();
      }
    },
    onOfflineReady() {
      logger.info('PWA', 'App lista para uso offline');
    },
    onRegistered(registration: ServiceWorkerRegistration | undefined) {
      logger.info('PWA', 'Service Worker registrado:', registration);
    },
    onRegisterError(error: Error) {
      logger.error('PWA', 'Error al registrar Service Worker:', error);
    }
  });
}

// Migrar tokens existentes a almacenamiento seguro
// Esto solo se ejecuta una vez al cargar la aplicación
migrateToSecureStorage();

// Fix para viewport height en iOS (para modales)
// iOS Safari cambia el tamaño del viewport cuando las barras de navegación aparecen/desaparecen
const setVhProperty = () => {
  const vh = window.innerHeight * 0.01;
  document.documentElement.style.setProperty('--vh', `${vh}px`);
};

// Establecer al cargar
setVhProperty();

// Actualizar cuando cambie el tamaño de la ventana
window.addEventListener('resize', setVhProperty);
window.addEventListener('orientationchange', setVhProperty);

// Desactivar el modo estricto en producción para evitar doble renderizado
const StrictModeWrapper = import.meta.env.DEV 
  ? React.StrictMode 
  : React.Fragment;

ReactDOM.createRoot(document.getElementById('root')!).render(
  <StrictModeWrapper>
    <App />
  </StrictModeWrapper>
)
