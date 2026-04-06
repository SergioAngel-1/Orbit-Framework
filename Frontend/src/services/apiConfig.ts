import axios from 'axios';
import * as CryptoJS from 'crypto-js';
//@ts-ignore
import OAuth from 'oauth-1.0a';
import { showServerErrorAlert } from './alertService';
import logger from '../utils/logger';
import secureStorage from '../utils/secureStorage';
import i18n from '../config/i18n';
import { getLangFromPath, buildLocalizedPath } from '../contexts/LanguageContext';
import { cacheManager } from './query/cacheManager';

/**
 * Redirige a una ruta respetando el prefijo de idioma actual.
 * Usa window.location.pathname para detectar el idioma activo.
 * Solo para uso fuera de componentes React (interceptors de axios, etc.).
 */
function localizedRedirect(path: string): void {
  const { lang } = getLangFromPath(window.location.pathname);
  window.location.href = buildLocalizedPath(path, lang);
}

// Variables para controlar los errores de servidor
let serverErrorShown = false;
let lastErrorTime = 0;
const ERROR_COOLDOWN = 10000; // 10 segundos entre alertas

// Singleton para CSRF refresh: evita race conditions cuando múltiples POSTs
// fallan con 403 simultáneamente (ej: al volver de background en mobile)
let csrfRefreshPromise: Promise<string | null> | null = null;

async function refreshCsrfTokenSingleton(): Promise<string | null> {
  // Si ya hay un refresh en curso, reutilizar la misma promesa
  if (csrfRefreshPromise) {
    return csrfRefreshPromise;
  }
  
  csrfRefreshPromise = (async () => {
    try {
      logger.info('API', 'Refrescando token CSRF (singleton)...');
      const response = await axios.get(
        `${baseApiUrl}/wp-json/starter/v1/csrf/refresh`,
        {
          headers: {
            'Authorization': `Bearer ${secureStorage.getItem('authToken')}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          timeout: API_CONFIG.timeouts.short
        }
      );
      
      if (response.data?.csrf_token) {
        secureStorage.setItem('csrfToken', response.data.csrf_token);
        logger.info('API', 'Token CSRF refrescado exitosamente');
        return response.data.csrf_token;
      }
      return null;
    } catch (err) {
      logger.error('API', 'Error al refrescar CSRF token:', err);
      return null;
    } finally {
      // Limpiar la promesa para permitir futuros refreshes
      csrfRefreshPromise = null;
    }
  })();
  
  return csrfRefreshPromise;
}

// Configuración centralizada de timeouts y reintentos
export const API_CONFIG = {
  timeouts: {
    woocommerce: 30000, // 30 segundos para WooCommerce
    api: 45000,         // 45 segundos para API general
    short: 15000,       // 15 segundos para operaciones rápidas
    long: 60000         // 60 segundos para operaciones pesadas
  },
  retries: {
    default: 2,         // 2 reintentos por defecto
    critical: 3,        // 3 reintentos para operaciones críticas
    quick: 1            // 1 reintento para operaciones rápidas
  },
  delays: {
    base: 1000,         // Delay base de 1 segundo
    exponential: true   // Usar delay exponencial
  }
};

// Configuración de timeouts más generosos
const WOOCOMMERCE_TIMEOUT = API_CONFIG.timeouts.woocommerce;
const API_TIMEOUT = API_CONFIG.timeouts.api;

const USE_WC_PROXY = (import.meta.env as any).VITE_USE_WC_PROXY === 'true';

// Validación de seguridad: En producción SIEMPRE debe usarse el proxy
if (import.meta.env.PROD && !USE_WC_PROXY) {
  throw new Error(
    'ERROR DE SEGURIDAD: VITE_USE_WC_PROXY debe estar en "true" en producción.\n' +
    'Las credenciales de WooCommerce NO deben exponerse en el frontend.\n' +
    'El backend maneja la autenticación OAuth de forma segura mediante el proxy.'
  );
}

// Claves para WooCommerce API (OAuth) solo requeridas si no usamos proxy
if (!USE_WC_PROXY) {
  if (!import.meta.env.VITE_WC_CONSUMER_KEY || !import.meta.env.VITE_WC_CONSUMER_SECRET) {
    throw new Error(
      'ERROR CRÍTICO: Las credenciales de WooCommerce no están configuradas.\n' +
      'Por favor, define VITE_WC_CONSUMER_KEY y VITE_WC_CONSUMER_SECRET en tu archivo .env\n' +
      'NOTA: En producción, usa VITE_USE_WC_PROXY=true para seguridad.'
    );
  }
}

// Estas variables solo se usan cuando USE_WC_PROXY=false (desarrollo local)
// En producción con proxy, estas quedan undefined pero no se usan
export const consumerKey = import.meta.env.VITE_WC_CONSUMER_KEY;
export const consumerSecret = import.meta.env.VITE_WC_CONSUMER_SECRET;

// Obtener la URL base de las variables de entorno
// En desarrollo: http://admin.starter.local
// En producción: https://admin.example.com (configurar en .env.production)
if (!import.meta.env.VITE_WP_API_URL) {
  throw new Error(
    'ERROR CRÍTICO: La URL de la API no está configurada.\n' +
    'Por favor, define VITE_WP_API_URL en tu archivo .env'
  );
}

export const baseApiUrl = import.meta.env.VITE_WP_API_URL;

// Configuración de OAuth 1.0a (solo cuando NO se usa proxy)
// En producción con proxy, esto es null y no se usa
export const oauth: any = !USE_WC_PROXY ? new OAuth({
  consumer: {
    key: consumerKey,
    secret: consumerSecret
  },
  signature_method: 'HMAC-SHA1',
  hash_function: function(base_string: string, key: string) {
    return CryptoJS.HmacSHA1(base_string, key).toString(CryptoJS.enc.Base64);
  }
}) : null as any;

// Función para obtener los headers de autenticación
export const getAuthHeaders = (url: string, method: string) => {
  const requestData = {
    url,
    method
  };
  if (!oauth) return {} as any;
  return oauth.authorize(requestData);
};

// Crear instancia de Axios para WooCommerce API
// En producción: usa proxy seguro en /starter/v1/wc
// En desarrollo: puede usar directamente /wc/v3 con OAuth
export const wooCommerceApi = axios.create({
  baseURL: USE_WC_PROXY ? `${baseApiUrl}/wp-json/starter/v1/wc` : `${baseApiUrl}/wp-json/wc/v3`,
  timeout: WOOCOMMERCE_TIMEOUT,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  },
  withCredentials: true
});

// Interceptor para inyectar el idioma actual en todas las peticiones
wooCommerceApi.interceptors.request.use(config => {
  const { lang } = getLangFromPath(window.location.pathname);
  if (lang && lang !== 'es') {
    config.params = config.params || {};
    config.params.lang = lang;
  }
  return config;
});

// Interceptor para autenticación con proxy (JWT) o OAuth directo
wooCommerceApi.interceptors.request.use(config => {
  if (USE_WC_PROXY) {
    // Modo proxy: adjuntar token JWT para autenticación del usuario
    const token = secureStorage.getItem('authToken');
    if (token) {
      config.headers = config.headers || {};
      config.headers['Authorization'] = `Bearer ${token}`;
      
      // Agregar CSRF token para endpoints que lo requieren
      const csrfToken = secureStorage.getItem('csrfToken');
      const method = (config.method || 'GET').toUpperCase();
      if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
        config.headers['X-CSRF-Token'] = csrfToken;
      }
    } else if (config.headers && 'Authorization' in config.headers) {
      delete config.headers['Authorization'];
    }
  }
  return config;
});

// Interceptor OAuth solo para modo sin proxy (desarrollo)
if (!USE_WC_PROXY) {
  wooCommerceApi.interceptors.request.use(config => {
    config.params = config.params || {};
    const urlObj = new URL(`${baseApiUrl}/wp-json/wc/v3${config.url || ''}`);
    Object.entries(config.params).forEach(([key, value]) => {
      urlObj.searchParams.append(key, value as string);
    });
    const fullUrl = urlObj.toString();
    const method = config.method?.toUpperCase() || 'GET';
    const requestData = { url: fullUrl, method } as any;
    const oauthData = oauth.authorize(requestData);
    config.params = {
      ...config.params,
      oauth_consumer_key: oauthData.oauth_consumer_key,
      oauth_nonce: oauthData.oauth_nonce,
      oauth_signature: oauthData.oauth_signature,
      oauth_signature_method: oauthData.oauth_signature_method,
      oauth_timestamp: oauthData.oauth_timestamp,
      oauth_version: oauthData.oauth_version
    };
    logger.debug('API', `Petición OAuth ${method} a ${fullUrl}`);
    logger.debug('API', 'Parámetros OAuth:', oauthData);
    return config;
  });
}

// Interceptor de respuesta para manejo de errores global
wooCommerceApi.interceptors.response.use(
  response => response,
  async error => {
    // Ignorar peticiones canceladas
    if (error.code === 'ERR_CANCELED' || error.message === 'canceled') {
      logger.debug('API', 'Petición cancelada en WooCommerce API');
      return Promise.reject(error);
    }
    
    // Manejo específico para errores de timeout
    // IMPORTANTE: No crear new Error() — preservar el error original de Axios
    // para que los servicios downstream puedan inspeccionar .response, .request, etc.
    if (error.code === 'ECONNABORTED' && error.message.includes('timeout')) {
      logger.warn('API', 'Timeout en WooCommerce API:', error.message);
      error.message = i18n.t('errors:api.timeout');
      error.isTimeout = true;
      return Promise.reject(error);
    }
    
    // Manejo de otros errores de conexión
    // IMPORTANTE: No crear new Error() — el error original contiene metadata crítica
    // (.request, .config) que los servicios downstream necesitan para clasificar el error.
    if (error.request && !error.response) {
      logger.warn('API', 'Error de red o servidor no disponible en WooCommerce API:', error.message);
      error.message = i18n.t('errors:api.networkError');
      error.isNetworkError = true;
      return Promise.reject(error);
    }
    
    const originalRequest = error.config;
    const status = error?.response?.status || 0;
    const code = error?.response?.data?.code || '';
    const url = originalRequest?.url || '';
    
    // Invalidar caché en errores de autorización (401/403)
    // Esto previene que respuestas de error queden "pegadas" en el sistema
    if (status === 401 || status === 403) {
      cacheManager.invalidateOnAuthError(status, code, url);
    }
    
    // Manejo de errores CSRF en WooCommerce API
    if (status === 403 && 
        (code === 'csrf_token_invalid' || 
         code === 'csrf_token_missing' || 
         code === 'csrf_token_expired')) {
      
      if (originalRequest._csrfRetry) {
        logger.error('API', 'CSRF refresh falló en WooCommerce API');
        secureStorage.removeItem('authToken');
        secureStorage.removeItem('csrfToken');
        localizedRedirect('/iniciar-sesion');
        return Promise.reject(new Error(i18n.t('errors:api.sessionExpired')));
      }
      
      originalRequest._csrfRetry = true;
      
      const newToken = await refreshCsrfTokenSingleton();
      
      if (newToken) {
        originalRequest.headers = originalRequest.headers || {};
        originalRequest.headers['X-CSRF-Token'] = newToken;
        return wooCommerceApi(originalRequest);
      } else {
        secureStorage.removeItem('authToken');
        secureStorage.removeItem('csrfToken');
        localizedRedirect('/iniciar-sesion');
        return Promise.reject(new Error(i18n.t('errors:api.sessionExpired')));
      }
    }
    
    // 403 por JWT inválido/expirado: limpiar tokens para romper el ciclo
    // donde un JWT corrupto en localStorage envenena todas las peticiones.
    // Solo actuar en códigos jwt_auth_* (token corrupto), NO en rest_forbidden
    // (permisos de membresía) ni en not_order_owner (autorización de recurso).
    if (status === 403 && originalRequest?.headers?.['Authorization'] && code && code.startsWith('jwt_auth_')) {
      logger.warn('API', `WC API 403 JWT inválido (code=${code}): limpiando tokens y notificando sesión expirada`);
      secureStorage.removeItem('authToken');
      secureStorage.removeItem('csrfToken');
      cacheManager.clearAll();
      
      // Notificar a AuthContext para que ejecute logout completo y sincronice la UI
      // Mismo patrón que usa MembershipContext cuando detecta un 401
      window.dispatchEvent(new CustomEvent('auth:sessionExpired', {
        detail: { reason: 'jwt_auth_invalid_403', code }
      }));
    }
    
    // Manejo de rate limiting
    if (status === 429 || code === 'rate_limit_exceeded') {
      const retryAfterHeader = error?.response?.headers?.['retry-after'];
      const retryAfterBody = error?.response?.data?.retry_after;
      const retryAfter = Number(retryAfterHeader || retryAfterBody || 0);
      const minutes = retryAfter > 0 ? Math.ceil(retryAfter / 60) : 0;
      error.message = minutes > 0
        ? i18n.t('errors:api.rateLimitedWithMinutes', { minutes, minuteLabel: i18n.t(minutes === 1 ? 'errors:api.minuteSingular' : 'errors:api.minutePlural') })
        : i18n.t('errors:api.rateLimitedGeneric');
    }
    
    if (error.response) {
      logger.error('API', `Error ${error.response.status}:`, error.response.data);
      
      if (error.response.status >= 500 && error.response.status < 600) {
        const currentTime = Date.now();
        
        if (!serverErrorShown || (currentTime - lastErrorTime > ERROR_COOLDOWN)) {
          serverErrorShown = true;
          lastErrorTime = currentTime;
          
          showServerErrorAlert();
          
          setTimeout(() => {
            serverErrorShown = false;
          }, ERROR_COOLDOWN);
        }
      }
    } else {
      // Errores de configuración (URL malformada, etc.)
      // Nota: errores de red (error.request && !error.response) ya fueron
      // manejados por el early return al inicio del interceptor.
      logger.error('API', 'Error al configurar la solicitud:', error.message);
    }
    
    return Promise.reject(error);
  }
);

// Configurar una instancia global de Axios para las demás peticiones
export const api = axios.create({
  baseURL: `${baseApiUrl}/wp-json`,
  timeout: API_TIMEOUT, // Aumentado a 45 segundos
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  },
  withCredentials: true // Habilitar credenciales para autenticación
});

// Interceptor para inyectar el idioma actual en las peticiones de API general
api.interceptors.request.use(config => {
  const { lang } = getLangFromPath(window.location.pathname);
  if (lang && lang !== 'es') {
    config.params = config.params || {};
    config.params.lang = lang;
  }
  return config;
});

// Interceptor para los logs de peticiones y corrección de URLs
api.interceptors.request.use(
  config => {
    // Registrar la petición
    logger.debug('API', `Petición ${config.method?.toUpperCase()} a ${config.url}`);
    
    // Agregar token de autenticación JWT para identificar al usuario
    // secureStorage es la ÚNICA fuente de verdad para el token de auth.
    // Si no hay token, limpiamos cualquier residuo en headers para evitar
    // enviar un JWT stale que quedó en api.defaults.headers.
    const token = secureStorage.getItem('authToken');
    if (token) {
      config.headers = config.headers || {};
      config.headers['Authorization'] = `Bearer ${token}`;
      
      // Agregar CSRF token para endpoints que modifican datos
      const csrfToken = secureStorage.getItem('csrfToken');
      const method = (config.method || 'GET').toUpperCase();
      if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
        config.headers['X-CSRF-Token'] = csrfToken;
      }
    } else if (config.headers && 'Authorization' in config.headers) {
      delete config.headers['Authorization'];
    }
    
    if (USE_WC_PROXY && config.url && config.url.startsWith('/wc/v3')) {
      config.url = config.url.replace('/wc/v3', '/starter/v1/wc');
    }
    
    // Solo en producción, verificar si la URL contiene el host de desarrollo y corregirla
    if (import.meta.env.PROD) {
      const prodApiHost = new URL(import.meta.env.VITE_WP_API_URL || '').hostname;
      const devHosts = ['admin.starter.local', 'localhost'];
      
      if (config.url) {
        const devHost = devHosts.find(h => config.url!.includes(h));
        if (devHost && prodApiHost) {
          const correctedUrl = config.url.replace(devHost, prodApiHost);
          logger.warn('apiConfig', `Corrigiendo URL: ${config.url} -> ${correctedUrl}`);
          config.url = correctedUrl;
        }
      }
      
      if (config.baseURL) {
        const devHost = devHosts.find(h => config.baseURL!.includes(h));
        if (devHost && prodApiHost) {
          const correctedBaseUrl = config.baseURL.replace(devHost, prodApiHost);
          logger.warn('apiConfig', `Corrigiendo URL base: ${config.baseURL} -> ${correctedBaseUrl}`);
          config.baseURL = correctedBaseUrl;
        }
      }
    }
    
    return config;
  },
  error => {
    logger.error('API', 'Error en la configuración de la petición', error);
    return Promise.reject(error);
  }
);

// Interceptor de respuesta para la instancia api
api.interceptors.response.use(
  response => response,
  async error => {
    // Ignorar peticiones canceladas
    if (error.code === 'ERR_CANCELED' || error.message === 'canceled') {
      logger.debug('API', 'Petición cancelada en API general');
      return Promise.reject(error);
    }
    
    // Manejo específico para errores de timeout
    // IMPORTANTE: No crear new Error() — preservar el error original de Axios
    // para que los servicios downstream puedan inspeccionar .response, .request, etc.
    // membershipApiService marca error.isNetworkError que MembershipContext necesita.
    if (error.code === 'ECONNABORTED' && error.message.includes('timeout')) {
      logger.warn('API', 'Timeout en API general:', error.message);
      error.message = i18n.t('errors:api.timeout');
      error.isTimeout = true;
      return Promise.reject(error);
    }
    
    // Manejo de otros errores de conexión
    // IMPORTANTE: No crear new Error() — el error original contiene metadata crítica
    // (.request, .config) que los servicios downstream necesitan para clasificar el error.
    if (error.request && !error.response) {
      logger.warn('API', 'Error de red o servidor no disponible:', error.message);
      error.message = i18n.t('errors:api.networkError');
      error.isNetworkError = true;
      return Promise.reject(error);
    }
    
    const originalRequest = error.config;
    const status = error?.response?.status || 0;
    const code = error?.response?.data?.code || '';
    const url = originalRequest?.url || '';
    
    // Invalidar caché en errores de autorización (401/403)
    // Esto previene que respuestas de error queden "pegadas" en el sistema
    if (status === 401 || status === 403) {
      cacheManager.invalidateOnAuthError(status, code, url);
    }
    
    // Manejo de errores CSRF (403 con códigos específicos)
    if (status === 403 && 
        (code === 'csrf_token_invalid' || 
         code === 'csrf_token_missing' || 
         code === 'csrf_token_expired')) {
      
      // Evitar loop infinito de reintentos
      if (originalRequest._csrfRetry) {
        logger.error('API', 'CSRF refresh falló, requiere re-autenticación');
        secureStorage.removeItem('authToken');
        secureStorage.removeItem('csrfToken');
        localizedRedirect('/iniciar-sesion');
        return Promise.reject(new Error(i18n.t('errors:api.sessionExpired')));
      }
      
      originalRequest._csrfRetry = true;
      
      const newToken = await refreshCsrfTokenSingleton();
      
      if (newToken) {
        originalRequest.headers = originalRequest.headers || {};
        originalRequest.headers['X-CSRF-Token'] = newToken;
        return api(originalRequest);
      } else {
        secureStorage.removeItem('authToken');
        secureStorage.removeItem('csrfToken');
        localizedRedirect('/iniciar-sesion');
        return Promise.reject(new Error(i18n.t('errors:api.sessionExpired')));
      }
    }
    
    // 403 por JWT inválido/expirado: limpiar tokens para romper el ciclo
    // donde un JWT corrupto en localStorage envenena todas las peticiones.
    // Solo actuar en códigos jwt_auth_* (token corrupto), NO en rest_forbidden
    // (permisos de membresía) ni en not_order_owner (autorización de recurso).
    if (status === 403 && originalRequest?.headers?.['Authorization'] && code && code.startsWith('jwt_auth_')) {
      logger.warn('API', `API 403 JWT inválido (code=${code}): limpiando tokens y notificando sesión expirada`);
      secureStorage.removeItem('authToken');
      secureStorage.removeItem('csrfToken');
      cacheManager.clearAll();
      
      // Notificar a AuthContext para que ejecute logout completo y sincronice la UI
      // Mismo patrón que usa MembershipContext cuando detecta un 401
      window.dispatchEvent(new CustomEvent('auth:sessionExpired', {
        detail: { reason: 'jwt_auth_invalid_403', code }
      }));
    }
    
    // Manejo de rate limiting
    if (status === 429 || code === 'rate_limit_exceeded') {
      const retryAfterHeader = error?.response?.headers?.['retry-after'];
      const retryAfterBody = error?.response?.data?.retry_after;
      const retryAfter = Number(retryAfterHeader || retryAfterBody || 0);
      const minutes = retryAfter > 0 ? Math.ceil(retryAfter / 60) : 0;
      error.message = minutes > 0
        ? i18n.t('errors:api.rateLimitedWithMinutes', { minutes, minuteLabel: i18n.t(minutes === 1 ? 'errors:api.minuteSingular' : 'errors:api.minutePlural') })
        : i18n.t('errors:api.rateLimitedGeneric');
    }
    
    logger.error('API', 'Error en respuesta:', error);
    return Promise.reject(error);
  }
);

// Exportar tipos comunes
export interface User {
  id: number;
  username: string;
  email: string;
  firstName: string;
  lastName: string;
  displayName?: string;
  pending?: boolean;
  phone?: string;
  documentId?: string;
  birthDate?: string;
  gender?: string;
  newsletter?: boolean;
}

// Función utilitaria para realizar peticiones con reintentos automáticos
export const createApiRequest = async <T>(
  requestFn: () => Promise<T>,
  maxRetries: number = 2,
  baseDelay: number = 1000
): Promise<T> => {
  let lastError: any;
  
  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      return await requestFn();
    } catch (error: any) {
      lastError = error;
      
      // No reintentar si la petición fue cancelada (AbortController) - es comportamiento normal
      const isCanceled = error.code === 'ERR_CANCELED' || error.name === 'CanceledError' || error.message === 'canceled';
      if (isCanceled) {
        throw error;
      }
      
      // No reintentar si es un error de autorización o cliente
      if (error.response?.status && error.response.status < 500) {
        throw error;
      }
      
      // No reintentar en el último intento
      if (attempt === maxRetries) {
        break;
      }
      
      // Calcular delay exponencial
      const delay = baseDelay * Math.pow(2, attempt);
      logger.debug('API', `Intento ${attempt + 1} falló, reintentando en ${delay}ms:`, error.message);
      
      // Esperar antes del siguiente intento
      await new Promise(resolve => setTimeout(resolve, delay));
    }
  }
  
  throw lastError;
};
