/**
 * Utilidad centralizada para el manejo de logs en la aplicación
 * Solo mostrará logs en entorno de desarrollo
 */

type LogLevel = 'debug' | 'info' | 'warn' | 'error';

// Detectar entorno de producción (Vite usa import.meta.env)
const isDevelopment = (import.meta as any).env?.DEV ?? process.env.NODE_ENV === 'development';

// Si estamos en producción, mostrar una única advertencia de seguridad y deshabilitar logs
if (!isDevelopment) {
  // Mostrar aviso una sola vez
  try {
    // Mensaje inspirado en medidas anti-phishing para evitar que los usuarios peguen código malicioso
    console.info('%c🚫 Consola para desarrolladores', 'font-size: 18px; color: #e00;');
    console.info('%cEsta consola es solo para uso interno. Si alguien te pidió pegar algo aquí, podría ser un fraude.', 'color: #555;');
  } catch (_) {
    // Ignorar en navegadores que no soporten formateo
  }

  // Reemplazar métodos de console usados por logger con no-op
  ['debug', 'log', 'info', 'warn', 'error'].forEach(key => {
    // @ts-ignore
    console[key] = () => {};
  });
}

// Categorías que deben ser silenciadas (demasiado verbosas)
const mutedCategories = [
  'BannerCarousel',
  'SocialNetworks',
  'formatters',
  'FeaturedCategories'
];

// Categorías que siempre se muestran, independientemente de su verbosidad
const alwaysShownCategories = [
  'auth',
  'api',
  'cache',
  'batch'
];

/**
 * Función centralizada para manejo de logs
 * @param category Categoría del log (ej: 'AuthContext', 'api')
 * @param message Mensaje a mostrar
 * @param level Nivel del log (debug, info, warn, error)
 * @param data Datos adicionales (opcional)
 */
export const log = (
  category: string,
  message: string,
  level: LogLevel = 'info',
  data?: any
): void => {
  // Solo mostrar logs en desarrollo
  if (!isDevelopment) return;

  // Verificar si la categoría está silenciada, a menos que esté en alwaysShownCategories
  if (mutedCategories.includes(category) && !alwaysShownCategories.includes(category)) return;

  // Formatear el prefijo del mensaje
  const prefix = `[${category}]`;

  // Elegir la función de console adecuada según el nivel
  switch (level) {
    case 'debug':
      console.debug(prefix, message, data || '');
      break;
    case 'info':
      console.log(prefix, message, data || '');
      break;
    case 'warn':
      console.warn(prefix, message, data || '');
      break;
    case 'error':
      console.error(prefix, message, data || '');
      break;
  }
};

/**
 * Abreviaciones para cada nivel de log
 */
export const logger = {
  debug: (category: string, message: string, data?: any) => log(category, message, 'debug', data),
  info: (category: string, message: string, data?: any) => log(category, message, 'info', data),
  warn: (category: string, message: string, data?: any) => log(category, message, 'warn', data),
  error: (category: string, message: string, data?: any) => log(category, message, 'error', data)
};

export default logger;
