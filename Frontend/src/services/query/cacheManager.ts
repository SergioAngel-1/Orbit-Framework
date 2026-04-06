import { api } from '../apiConfig';
import logger from '../../utils/logger';
import { CacheableContentType, CacheItem, BatchRequest } from './types';

/**
 * Detecta si el dispositivo es iOS (iPhone, iPad, iPod)
 */
const isIOS = (): boolean => {
  if (typeof navigator === 'undefined') return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || 
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
};

/**
 * Tipos de mensajes para comunicación cross-tab via BroadcastChannel
 */
interface CacheBroadcastMessage {
  type: 'MEMBERSHIP_CHANGED' | 'CACHE_CLEARED' | 'AUTH_LOGOUT' | 'LANGUAGE_CHANGED';
  level?: number;
  lang?: string;
  timestamp: number;
}

/**
 * Sistema de Gestión de Caché para consultas optimizadas
 * Proporciona funcionalidades para almacenar, recuperar e invalidar datos en caché,
 * así como para procesar solicitudes en lote.
 * 
 * INTEGRACIÓN CON MEMBRESÍA:
 * - El nivel de membresía se incluye en claves de caché para contenido sensible
 * - Cuando cambia el nivel, se invalida automáticamente el caché relacionado
 * - Los componentes deben usar membershipVersion como dependencia para forzar recarga
 * 
 * SOPORTE iOS:
 * - Detecta cuando la app vuelve del background y revalida el caché
 * - TTL más corto en iOS para evitar datos stale
 * - Limpia caché automáticamente cuando hay problemas de memoria
 * 
 * SINCRONIZACIÓN CROSS-TAB:
 * - Usa BroadcastChannel API para sincronizar invalidaciones entre pestañas
 * - Cuando una pestaña invalida el caché, todas las demás reciben la notificación
 * - Soporta: cambio de membresía, logout, limpieza completa de caché
 */
export class CacheManager {
  private cache: Map<string, CacheItem<any>> = new Map();
  private batchRequests: Map<string, BatchRequest[]> = new Map();
  private batchTimeouts: Map<string, NodeJS.Timeout> = new Map();
  private BATCH_DELAY = 50; // milisegundos de espera para agrupar solicitudes
  
  // Nivel de membresía actual para incluir en claves de caché
  private currentMembershipLevel: number = 0;
  
  // Idioma actual para incluir en claves de caché de contenido traducible
  private currentLang: string = 'es';
  
  // BroadcastChannel para sincronización cross-tab
  private broadcastChannel: BroadcastChannel | null = null;
  // Nombre del canal de broadcast
  private readonly BROADCAST_CHANNEL_NAME = 'starter_cache_sync';
  // Callback para notificar cambios de membresía a MembershipContext
  private onMembershipChangeCallback: ((level: number) => void) | null = null;
  // Callback para notificar logout a AuthContext
  private onLogoutCallback: (() => void) | null = null;
  
  // iOS: timestamp de última actividad para detectar background prolongado
  private lastActivityTimestamp: number = Date.now();
  // iOS: umbral removido — MembershipContext maneja la lógica de revalidación por inactividad
  // iOS: flag para saber si es dispositivo iOS
  private readonly isIOSDevice: boolean = isIOS();
  
  constructor() {
    this.setupVisibilityListener();
    this.setupMemoryWarningListener();
    this.setupBroadcastChannel();
  }
  
  /**
   * Configura el BroadcastChannel para sincronización cross-tab
   * Permite que múltiples pestañas del mismo navegador sincronicen su caché
   */
  private setupBroadcastChannel(): void {
    // BroadcastChannel no está disponible en SSR ni en algunos navegadores antiguos
    if (typeof BroadcastChannel === 'undefined') {
      logger.info('cache', '📡 BroadcastChannel not available, cross-tab sync disabled');
      return;
    }
    
    try {
      this.broadcastChannel = new BroadcastChannel(this.BROADCAST_CHANNEL_NAME);
      
      this.broadcastChannel.onmessage = (event: MessageEvent<CacheBroadcastMessage>) => {
        this.handleBroadcastMessage(event.data);
      };
      
      this.broadcastChannel.onmessageerror = () => {
        logger.warn('cache', '📡 BroadcastChannel message error');
      };
      
      logger.info('cache', '📡 BroadcastChannel initialized for cross-tab sync');
    } catch (error) {
      logger.warn('cache', '📡 Failed to initialize BroadcastChannel:', error);
      this.broadcastChannel = null;
    }
  }
  
  /**
   * Maneja mensajes recibidos de otras pestañas
   */
  private handleBroadcastMessage(message: CacheBroadcastMessage): void {
    logger.info('cache', `📡 Received cross-tab message: ${message.type}`);
    
    switch (message.type) {
      case 'MEMBERSHIP_CHANGED':
        // Otra pestaña cambió el nivel de membresía
        if (message.level !== undefined && message.level !== this.currentMembershipLevel) {
          logger.info('cache', `📡 Cross-tab: Membership changed to level ${message.level}`);
          this.currentMembershipLevel = message.level;
          this.invalidateMembershipRelatedInternal();
          
          // Notificar al MembershipContext si hay callback registrado
          if (this.onMembershipChangeCallback) {
            this.onMembershipChangeCallback(message.level);
          }
        }
        break;
        
      case 'CACHE_CLEARED':
        // Otra pestaña limpió todo el caché
        logger.info('cache', '📡 Cross-tab: Cache cleared by another tab');
        this.cache.clear();
        break;
        
      case 'AUTH_LOGOUT':
        // Otra pestaña hizo logout
        logger.info('cache', '📡 Cross-tab: Logout detected from another tab');
        this.currentMembershipLevel = 0;
        this.cache.clear();
        
        // Notificar al AuthContext si hay callback registrado
        if (this.onLogoutCallback) {
          this.onLogoutCallback();
        }
        break;
        
      case 'LANGUAGE_CHANGED':
        // Otra pestaña cambió el idioma
        if (message.lang && message.lang !== this.currentLang) {
          logger.info('cache', `📡 Cross-tab: Language changed to ${message.lang}`);
          this.currentLang = message.lang;
          this.invalidateLanguageRelatedInternal();
        }
        break;
    }
  }
  
  /**
   * Envía un mensaje a todas las demás pestañas
   */
  private broadcast(message: CacheBroadcastMessage): void {
    if (!this.broadcastChannel) return;
    
    try {
      this.broadcastChannel.postMessage(message);
      logger.debug('cache', `📡 Broadcast sent: ${message.type}`);
    } catch (error) {
      logger.warn('cache', '📡 Failed to broadcast message:', error);
    }
  }
  
  /**
   * Registra un callback para ser notificado cuando otra pestaña cambie la membresía
   * Debe llamarse desde MembershipContext para sincronizar el estado
   */
  public onMembershipChange(callback: (level: number) => void): void {
    this.onMembershipChangeCallback = callback;
  }
  
  /**
   * Registra un callback para ser notificado cuando otra pestaña haga logout
   * Debe llamarse desde AuthContext para sincronizar el estado
   */
  public onLogout(callback: () => void): void {
    this.onLogoutCallback = callback;
  }
  
  /**
   * Notifica a otras pestañas que el usuario hizo logout
   * Debe llamarse desde AuthContext.logout()
   */
  public notifyLogout(): void {
    this.broadcast({
      type: 'AUTH_LOGOUT',
      timestamp: Date.now()
    });
  }
  
  /**
   * Configura listener para detectar cuando la app vuelve del background
   * 
   * NOTA: La lógica principal de revalidación al volver del background se maneja
   * en MembershipContext.tsx, que tiene acceso al estado de autenticación y decide:
   * - Si inactivo >5 min: clearAll() + reload membership
   * - Si inactivo >30s: reload membership
   * 
   * CacheManager solo maneja:
   * - Tracking del timestamp de background (para que otros puedan consultarlo)
   * - Restauración desde bfcache de iOS (pageshow event)
   */
  private setupVisibilityListener(): void {
    if (typeof document === 'undefined') return;
    
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        // Solo actualizar timestamp; MembershipContext maneja la lógica de invalidación
        this.lastActivityTimestamp = Date.now();
      } else {
        // Guardar timestamp cuando la app va a background
        this.lastActivityTimestamp = Date.now();
      }
    });
    
    // iOS: También escuchar pageshow para detectar restauración de página
    if (typeof window !== 'undefined') {
      window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
          // La página fue restaurada desde bfcache (común en iOS Safari)
          logger.info('cache', '📱 Page restored from bfcache, invalidating stale cache');
          this.invalidateAllStale();
        }
      });
    }
  }
  
  /**
   * Configura listener para advertencias de memoria (iOS)
   */
  private setupMemoryWarningListener(): void {
    if (typeof window === 'undefined') return;
    
    // iOS Safari no tiene un evento específico de memoria, pero podemos
    // limpiar caché periódicamente si hay muchos items
    if (this.isIOSDevice) {
      setInterval(() => {
        if (this.cache.size > 100) {
          this.pruneExpiredItems();
        }
      }, 30000); // Cada 30 segundos
    }
  }
  
  /**
   * Obtiene el tiempo en milisegundos desde la última actividad
   * Útil para que MembershipContext determine el tiempo en background
   */
  public getTimeSinceLastActivity(): number {
    return Date.now() - this.lastActivityTimestamp;
  }
  
  /**
   * Invalida todos los items que podrían estar stale
   * Usado principalmente cuando iOS vuelve del background
   */
  private invalidateAllStale(): void {
    const now = Date.now();
    const keysToRemove: string[] = [];
    
    this.cache.forEach((item, key) => {
      // En iOS, ser más agresivo: invalidar items que expiran pronto
      const remainingTTL = item.expiresAt - now;
      const originalTTL = item.expiresAt - item.timestamp;
      
      // Si queda menos del 50% del TTL original, invalidar
      if (remainingTTL < originalTTL * 0.5) {
        keysToRemove.push(key);
      }
    });
    
    keysToRemove.forEach(key => this.cache.delete(key));
    
    if (keysToRemove.length > 0) {
      logger.info('cache', `📱 Invalidated ${keysToRemove.length} stale items after resume`);
    }
  }
  
  /**
   * Elimina items expirados del caché (limpieza de memoria)
   */
  private pruneExpiredItems(): void {
    const now = Date.now();
    const keysToRemove: string[] = [];
    
    this.cache.forEach((item, key) => {
      if (item.expiresAt <= now) {
        keysToRemove.push(key);
      }
    });
    
    keysToRemove.forEach(key => this.cache.delete(key));
    
    if (keysToRemove.length > 0 && process.env.NODE_ENV === 'development') {
      logger.debug('cache', `🧹 Pruned ${keysToRemove.length} expired items`);
    }
  }

  /**
   * Actualiza el nivel de membresía actual
   * Debe llamarse desde MembershipContext cuando cambia el nivel
   * 
   * NOTA: La versión de membresía se maneja en MembershipContext.membershipVersion
   * para que los componentes React puedan usarla como dependencia de efectos.
   */
  public setMembershipLevel(level: number): void {
    if (this.currentMembershipLevel !== level) {
      const oldLevel = this.currentMembershipLevel;
      this.currentMembershipLevel = level;
      
      if (process.env.NODE_ENV === 'development') {
        logger.info('cache', `🔑 Membership level changed: ${oldLevel} -> ${level}`);
      }
    }
  }
  
  /**
   * Obtiene el nivel de membresía actual
   */
  public getMembershipLevel(): number {
    return this.currentMembershipLevel;
  }
  
  /**
   * @deprecated La versión de membresía ahora se maneja en MembershipContext.membershipVersion
   * Este método se mantiene por compatibilidad pero siempre retorna 0.
   * Usar useMembership().membershipVersion en su lugar.
   */
  public getMembershipVersion(): number {
    return 0;
  }

  /**
   * Construye una clave de caché única basada en el tipo de contenido, ID y parámetros
   * Para contenido sensible a membresía, incluye el nivel en la clave
   */
  public buildCacheKey(contentType: CacheableContentType, id: string | number | null, params?: any): string {
    const paramsString = params ? JSON.stringify(params) : '';
    
    // Tipos de contenido que varían según el nivel de membresía
    const membershipSensitiveTypes: CacheableContentType[] = [
      'products', 'product', 'categories', 'category', 'homeSection', 'banner', 'benefits', 'membership'
    ];
    
    // Tipos de contenido que varían según el idioma (contenido traducible)
    const languageSensitiveTypes: CacheableContentType[] = [
      'products', 'product', 'categories', 'category', 'homeSection', 'banner', 'membershipLevels'
    ];
    
    let key = `${contentType}_${id || 'list'}`;
    
    // Incluir nivel de membresía en la clave para contenido sensible
    if (membershipSensitiveTypes.includes(contentType)) {
      key += `_ml${this.currentMembershipLevel}`;
    }
    
    // Incluir idioma en la clave para contenido traducible
    if (languageSensitiveTypes.includes(contentType)) {
      key += `_${this.currentLang}`;
    }
    
    key += `_${paramsString}`;
    return key;
  }

  /**
   * Obtiene un elemento de la caché si existe y no ha expirado
   */
  get<T>(key: string): T | null {
    const item = this.cache.get(key);
    
    // Verificar si el elemento existe y no ha expirado
    if (item && item.expiresAt > Date.now()) {
      return item.data as T;
    }
    
    // Si ha expirado, eliminarlo de la caché
    if (item) {
      this.cache.delete(key);
    }
    
    return null;
  }

  /**
   * Verifica si el dispositivo es iOS
   */
  public isIOS(): boolean {
    return this.isIOSDevice;
  }
  
  /**
   * Ajusta el TTL para iOS (reduce a la mitad para evitar datos stale)
   */
  public adjustTTLForDevice(ttl: number): number {
    if (this.isIOSDevice) {
      // En iOS, usar TTL más corto para evitar problemas de caché stale
      return Math.floor(ttl * 0.5);
    }
    return ttl;
  }

  /**
   * Guarda un elemento en la caché con un tiempo de vida específico
   * En iOS, el TTL se reduce automáticamente para evitar datos stale
   */
  set<T>(key: string, data: T, ttl: number): void {
    const timestamp = Date.now();
    // Ajustar TTL para iOS
    const adjustedTTL = this.adjustTTLForDevice(ttl);
    const expiresAt = timestamp + adjustedTTL;
    
    this.cache.set(key, {
      data,
      timestamp,
      expiresAt
    });
    
    // Log para desarrollo
    if (process.env.NODE_ENV === 'development') {
      const iosNote = this.isIOSDevice ? ' (iOS adjusted)' : '';
      logger.debug('cache', `📦 Cache: Item added [${key}], expires in ${adjustedTTL/1000}s${iosNote}`);
    }
  }

  /**
   * Invalida un elemento específico de la caché
   */
  invalidate(key: string): void {
    if (this.cache.has(key)) {
      this.cache.delete(key);
      if (process.env.NODE_ENV === 'development') {
        logger.debug('cache', `🗑️ Cache: Item invalidated [${key}]`);
      }
    }
  }

  /**
   * Invalida todos los elementos de un tipo específico
   */
  invalidateByType(contentType: CacheableContentType): void {
    // Obtener todas las claves que comienzan con el tipo de contenido
    const keysToRemove: string[] = [];
    
    this.cache.forEach((_, key) => {
      if (key.startsWith(contentType)) {
        keysToRemove.push(key);
      }
    });
    
    // Eliminar elementos
    keysToRemove.forEach(key => this.cache.delete(key));
    
    if (process.env.NODE_ENV === 'development') {
      logger.debug('cache', `🗑️ Cache: Invalidated ${keysToRemove.length} items of type [${contentType}]`);
    }
  }

  /**
   * Invalida elementos relacionados (ej: al actualizar un producto, invalidar listas de productos)
   */
  invalidateRelated(contentType: CacheableContentType, _id?: string | number): void {
    this.invalidateByType(contentType);
    
    // Si se actualiza un producto, también invalidar categorías relacionadas
    if (contentType === 'product') {
      this.invalidateByType('products');
      // Idealmente aquí también invalidaríamos las categorías específicas
      // pero necesitaríamos conocer a qué categorías pertenece el producto
    }
    
    // Si se actualiza una categoría, invalidar productos
    if (contentType === 'category') {
      this.invalidateByType('categories');
      this.invalidateByType('products');
    }
  }

  /**
   * Invalida todos los cachés relacionados con membresía (versión interna sin broadcast)
   * Usada internamente cuando se recibe un mensaje de otra pestaña para evitar loops
   */
  private invalidateMembershipRelatedInternal(): void {
    // Invalidar categorías (pueden tener restricciones de membresía)
    this.invalidateByType('categories');
    this.invalidateByType('category');
    
    // Invalidar productos (pueden tener precios o acceso diferente por membresía)
    this.invalidateByType('products');
    this.invalidateByType('product');
    
    // Invalidar secciones del home (pueden mostrar contenido diferente por membresía)
    this.invalidateByType('homeSection');
    
    // Invalidar banners (pueden tener contenido diferente por membresía)
    this.invalidateByType('banner');
    
    if (process.env.NODE_ENV === 'development') {
      logger.info('cache', `🔄 Cache: Invalidated all membership-related data (level: ${this.currentMembershipLevel})`);
    }
  }

  /**
   * Invalida todos los cachés relacionados con membresía
   * Debe llamarse cuando cambia el nivel de membresía del usuario
   * ya que los datos de categorías y productos pueden variar según el nivel
   * 
   * NOTA: Este método también notifica a otras pestañas via BroadcastChannel
   * 
   * @param newLevel - Nuevo nivel de membresía (opcional, para actualizar internamente)
   */
  invalidateMembershipRelated(newLevel?: number): void {
    // Actualizar nivel si se proporciona
    if (newLevel !== undefined) {
      this.setMembershipLevel(newLevel);
    }
    
    // Invalidar caché local
    this.invalidateMembershipRelatedInternal();
    
    // Notificar a otras pestañas
    this.broadcast({
      type: 'MEMBERSHIP_CHANGED',
      level: this.currentMembershipLevel,
      timestamp: Date.now()
    });
  }

  /**
   * Actualiza el idioma actual y invalida cachés de contenido traducible
   * Debe llamarse desde LanguageContext cuando cambia el idioma
   */
  public setLanguage(lang: string): void {
    if (this.currentLang !== lang) {
      const oldLang = this.currentLang;
      this.currentLang = lang;
      
      logger.info('cache', `🌐 Language changed: ${oldLang} -> ${lang}`);
      
      // Invalidar caché local
      this.invalidateLanguageRelatedInternal();
      
      // Notificar a otras pestañas
      this.broadcast({
        type: 'LANGUAGE_CHANGED',
        lang,
        timestamp: Date.now()
      });
    }
  }

  /**
   * Obtiene el idioma actual
   */
  public getLanguage(): string {
    return this.currentLang;
  }

  /**
   * Invalida cachés de contenido traducible (versión interna sin broadcast)
   */
  private invalidateLanguageRelatedInternal(): void {
    this.invalidateByType('products');
    this.invalidateByType('product');
    this.invalidateByType('categories');
    this.invalidateByType('category');
    this.invalidateByType('homeSection');
    this.invalidateByType('banner');
    
    logger.info('cache', `🌐 Cache: Invalidated all language-sensitive data (lang: ${this.currentLang})`);
  }

  /**
   * Obtiene estadísticas del caché (útil para debugging en iOS)
   * 
   * NOTA: membershipVersion se eliminó de las estadísticas.
   * Usar useMembership().membershipVersion para obtener la versión actual.
   */
  public getStats(): { size: number; isIOS: boolean; membershipLevel: number } {
    return {
      size: this.cache.size,
      isIOS: this.isIOSDevice,
      membershipLevel: this.currentMembershipLevel
    };
  }
  
  /**
   * Fuerza la invalidación de todo el caché stale (público para debugging)
   */
  public forceInvalidateStale(): void {
    this.invalidateAllStale();
  }

  /**
   * Invalida caché cuando ocurre un error de autorización (401/403).
   * 
   * Esto previene el escenario donde:
   * 1. Una petición falla con 403 (sesión expirada, permisos cambiados, etc.)
   * 2. El caché anterior permanece válido
   * 3. Futuras peticiones siguen usando datos stale o el error persiste
   * 
   * Estrategia:
   * - Para errores 401: limpiar todo el caché (sesión inválida)
   * - Para errores 403 CSRF: no limpiar (se reintenta con token nuevo)
   * - Para errores 403 otros: limpiar caché sensible a autenticación
   * 
   * @param statusCode - Código HTTP del error (401 o 403)
   * @param errorCode - Código de error del backend (ej: 'csrf_token_invalid')
   * @param url - URL de la petición que falló (opcional, para invalidación selectiva)
   */
  public invalidateOnAuthError(statusCode: number, errorCode?: string, url?: string): void {
    // No invalidar para errores CSRF — el interceptor reintenta automáticamente
    const csrfErrors = ['csrf_token_invalid', 'csrf_token_missing', 'csrf_token_expired'];
    if (statusCode === 403 && errorCode && csrfErrors.includes(errorCode)) {
      logger.debug('cache', '🔐 Auth error is CSRF-related, skipping cache invalidation (will retry)');
      return;
    }

    // Para 401 (no autenticado): limpiar todo el caché
    if (statusCode === 401) {
      logger.info('cache', '🔐 401 Unauthorized: Clearing all cache');
      this.clearAllInternal();
      return;
    }

    // Para 403 (prohibido): limpiar caché sensible a autenticación
    if (statusCode === 403) {
      logger.info('cache', `🔐 403 Forbidden: Invalidating auth-sensitive cache${url ? ` (triggered by ${url})` : ''}`);
      
      // Invalidar tipos de contenido que dependen de autenticación/permisos
      this.invalidateByType('membership');
      this.invalidateByType('benefits');
      this.invalidateByType('points');
      this.invalidateByType('referrals');
      this.invalidateByType('transactions');
      this.invalidateByType('order');
      this.invalidateByType('reviews');
      
      // También invalidar contenido sensible a membresía (puede haber cambiado)
      this.invalidateMembershipRelatedInternal();
    }
  }

  /**
   * Limpia toda la caché (versión interna sin broadcast)
   */
  private clearAllInternal(): void {
    const count = this.cache.size;
    this.cache.clear();
    
    if (process.env.NODE_ENV === 'development') {
      logger.info('cache', `🗑️ Cache: Cleared all ${count} items`);
    }
  }

  /**
   * Limpia toda la caché y notifica a otras pestañas
   * 
   * @param broadcast - Si es true (default), notifica a otras pestañas. Usar false cuando se recibe mensaje de otra pestaña.
   */
  clearAll(broadcast: boolean = true): void {
    this.clearAllInternal();
    
    // Notificar a otras pestañas solo si se indica
    if (broadcast) {
      this.broadcast({
        type: 'CACHE_CLEARED',
        timestamp: Date.now()
      });
    }
  }

  /**
   * Añade una solicitud al lote para procesamiento agrupado
   */
  addToBatch(batchKey: string, request: BatchRequest): void {
    // Obtener o inicializar el array de solicitudes para esta clave de lote
    const requests = this.batchRequests.get(batchKey) || [];
    requests.push(request);
    this.batchRequests.set(batchKey, requests);
    
    // Cancelar el timeout anterior si existe
    if (this.batchTimeouts.has(batchKey)) {
      clearTimeout(this.batchTimeouts.get(batchKey)!);
    }
    
    // Establecer un nuevo timeout para procesar este lote
    const timeout = setTimeout(() => {
      this.processBatch(batchKey);
    }, this.BATCH_DELAY);
    
    this.batchTimeouts.set(batchKey, timeout);
  }

  /**
   * Procesa un lote de solicitudes agrupadas
   */
  private async processBatch(batchKey: string): Promise<void> {
    const requests = this.batchRequests.get(batchKey) || [];
    
    if (requests.length === 0) {
      return;
    }
    
    if (process.env.NODE_ENV === 'development') {
      logger.debug('batch', `🔄 Processing batch [${batchKey}] with ${requests.length} requests`);
    }
    
    this.batchRequests.delete(batchKey);
    this.batchTimeouts.delete(batchKey);
    
    // Si solo hay una solicitud, procesarla normalmente
    if (requests.length === 1) {
      const request = requests[0];
      try {
        const response = await api({
          url: request.url,
          method: request.method,
          params: request.params,
          data: request.data
        });
        request.resolve({ data: response.data });
      } catch (error) {
        request.reject(error);
      }
      return;
    }
    
    // Múltiples solicitudes: enviar como un solo POST al endpoint /starter/v1/batch
    try {
      const batchPayload = requests.map((request) => ({
        id: request.id,
        method: request.method.toUpperCase(),
        path: (request.url || '').replace(/^\//, ''), // Remove leading slash for backend
        params: request.params || {},
        ...(request.data ? { body: request.data } : {}),
      }));

      const batchResponse = await api.post('/starter/v1/batch', {
        requests: batchPayload,
      });

      const responseMap = new Map<string, { status: number; data: any }>();
      if (batchResponse.data?.responses && Array.isArray(batchResponse.data.responses)) {
        for (const resp of batchResponse.data.responses) {
          responseMap.set(resp.id, { status: resp.status, data: resp.data });
        }
      }

      // Resolve/reject each original request based on batch response
      for (const request of requests) {
        const resp = responseMap.get(request.id);
        if (resp && resp.status >= 200 && resp.status < 400) {
          request.resolve({ data: resp.data });
        } else if (resp) {
          request.reject({ response: { status: resp.status, data: resp.data } });
        } else {
          request.reject(new Error(`No response for batch request ${request.id}`));
        }
      }
    } catch (batchError) {
      // Fallback: si el endpoint batch falla, ejecutar requests en paralelo individualmente
      if (process.env.NODE_ENV === 'development') {
        logger.warn('batch', `Batch endpoint failed, falling back to parallel requests`, batchError);
      }
      
      const fallbackPromises = requests.map(async (request) => {
        try {
          const response = await api({
            url: request.url,
            method: request.method,
            params: request.params,
            data: request.data
          });
          request.resolve({ data: response.data });
        } catch (error) {
          request.reject(error);
        }
      });
      
      await Promise.all(fallbackPromises);
    }
  }
}

// Instancia global del gestor de caché
export const cacheManager = new CacheManager();
