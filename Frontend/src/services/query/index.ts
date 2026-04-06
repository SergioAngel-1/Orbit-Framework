import { OptimizedQuery } from './optimizedQuery';
import { cacheManager } from './cacheManager';

// Exportar tipos para uso en componentes
export type { 
  CacheableContentType,
  CacheItem,
  QueryOptions,
  BatchRequest
} from './types';

// Instancia única para toda la aplicación
const queryService = new OptimizedQuery();

// Exportar la instancia por defecto
export default queryService;

// Exportar el gestor de caché para uso avanzado
export { cacheManager };
