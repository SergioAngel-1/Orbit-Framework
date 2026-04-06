/**
 * @file queryServices.ts
 * @description Servicio de consultas optimizadas con caché para la aplicación.
 * Este archivo es un wrapper que importa y utiliza el servicio modularizado.
 * Se mantiene para compatibilidad con el código existente.
 */

import queryService from './query';

// Re-exportar tipos para mantener compatibilidad
export type {
  CacheableContentType,
  CacheItem,
  QueryOptions,
  BatchRequest
} from './query';

// Exportar la instancia por defecto
export default queryService;
