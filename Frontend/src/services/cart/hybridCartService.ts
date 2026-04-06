import { CartItem, Product } from '../../types/woocommerce';
import userCartApiService from './userCartApiService';
import logger from '../../utils/logger';
import alertService from '../alertService';
import i18n from '../../config/i18n';
import productApiService from '../products/productApiService';
import { wooCommerceApi } from '../apiConfig';

/**
 * Servicio híbrido de reserva que combina localStorage con WooCommerce Store API
 * 
 * Estrategia:
 * 1. Operaciones se realizan primero en localStorage (rápido, offline-first)
 * 2. Luego se sincronizan con el servidor en background
 * 3. Al iniciar sesión, se recupera la reserva del servidor
 * 4. Si hay conflictos, el servidor tiene prioridad
 */
const hybridCartService = {
  // Keys de localStorage
  CART_ITEMS_KEY: 'cart_items',
  CART_COUPON_KEY: 'cart_coupon',
  CART_SYNC_PENDING_KEY: 'cart_sync_pending',

  // Cache de variaciones para evitar requests redundantes
  variationsCache: new Map<string, any>(),
  CACHE_TTL: 5 * 60 * 1000, // 5 minutos
  cacheTimestamps: new Map<string, number>(),

  /**
   * Normaliza items para asegurar coherencia:
   * - Si es variación y product.parent_id > 0, usar el id del padre en CartItem.id
   * - Si falta variation_id pero el product tiene parent_id > 0, asumir variation_id = product.id
   */
  normalizeItems(items: CartItem[]): CartItem[] {
    try {
      let changed = false;
      const normalized = (items || []).map((item: any) => {
        if (!item) return item;
        const productObj = item.product;
        const variationObj = item.variation;
        const next = { ...item };

        // Inferir variation_id desde variation.id si falta
        if ((!next.variation_id || next.variation_id <= 0) && variationObj && variationObj.id > 0) {
          next.variation_id = variationObj.id;
          changed = true;
        }

        // Si el product es una variación (tiene parent_id), asegurar variation_id
        if ((!next.variation_id || next.variation_id <= 0) && productObj?.parent_id && productObj.parent_id > 0) {
          next.variation_id = productObj.id;
          changed = true;
        }

        // Determinar parent id desde product.parent_id o variation.parent_id
        let detectedParentId = 0;
        if (productObj?.parent_id && productObj.parent_id > 0) {
          detectedParentId = productObj.parent_id;
        } else if (variationObj?.parent_id && variationObj.parent_id > 0) {
          detectedParentId = variationObj.parent_id;
        }
        if (detectedParentId > 0 && next.id !== detectedParentId) {
          next.id = detectedParentId;
          changed = true;
        }
        return next;
      });
      if (changed) {
        logger.info('hybridCartService', 'Items normalizados (parent id / variation_id inferidos)');
      }
      return normalized as CartItem[];
    } catch (e) {
      return items || [];
    }
  },

  /**
   * Obtener items del localStorage (formato ligero - solo IDs)
   * Nota: Devuelve estructura ligera, usar getLocalItemsHydrated() para datos completos
   */
  getLocalItemsLightweight(): any[] {
    try {
      const cartData = localStorage.getItem(this.CART_ITEMS_KEY);
      if (cartData) {
        const parsedCart = JSON.parse(cartData);
        if (Array.isArray(parsedCart)) {
          return parsedCart;
        }
      }
    } catch (error) {
      logger.error('hybridCartService', 'Error al obtener items locales:', error);
    }
    return [];
  },

  /**
   * Obtener items del localStorage (mantiene compatibilidad - devuelve estructura ligera)
   * NOTA: Esta versión devuelve solo IDs. Para datos completos usar getLocalItemsHydrated()
   */
  getLocalItems(): any[] {
    return this.getLocalItemsLightweight();
  },

  /**
   * Obtener items del localStorage con datos completos (hidratados)
   * Usar esta versión cuando se necesiten datos completos de productos/variaciones
   */
  async getLocalItemsHydrated(): Promise<CartItem[]> {
    try {
      const lightweightItems = this.getLocalItemsLightweight();
      if (lightweightItems.length === 0) {
        return [];
      }

      // Hidratar items con datos completos
      const hydratedItems = await this.hydrateItems(lightweightItems);
      return this.normalizeItems(hydratedItems);
    } catch (error) {
      logger.error('hybridCartService', 'Error al hidratar items locales:', error);
      return [];
    }
  },

  async filterItemsByStock(items: CartItem[]): Promise<{ filtered: CartItem[]; removed: number; membershipRemoved: number }> {
    try {
      if (!items || items.length === 0) return { filtered: [], removed: 0, membershipRemoved: 0 };
      const uniqueIds = Array.from(new Set(items.map(i => i.id).filter(id => id && id > 0)));
      let products: any[] = [];
      if (uniqueIds.length > 0) {
        try {
          const resp = await productApiService.getAll({ include: uniqueIds.join(','), per_page: uniqueIds.length });
          products = Array.isArray(resp.data) ? resp.data : resp.data?.products || [];
        } catch (e) {
          logger.warn('hybridCartService', 'Fallo obtención batch de productos, intentando individual');
          const results: any[] = [];
          for (const id of uniqueIds) {
            try { const r = await productApiService.getById(id); results.push(r.data); } catch {}
          }
          products = results;
        }
      }
      const map = new Map<number, any>(products.map(p => [p.id, p]));
      const variationPairs = Array.from(new Set(items.filter(i => i.variation_id && i.variation_id > 0).map(i => `${i.id}:${i.variation_id}`)));
      const variationMap = new Map<string, any>();
      
      // Agrupar variaciones por producto padre para batch loading
      const variationsByProduct = new Map<number, number[]>();
      variationPairs.forEach(key => {
        const [pidStr, vidStr] = key.split(':');
        const pid = Number(pidStr), vid = Number(vidStr);
        if (!variationsByProduct.has(pid)) {
          variationsByProduct.set(pid, []);
        }
        variationsByProduct.get(pid)!.push(vid);
      });

      // Cargar variaciones en batch por producto
      await Promise.all(Array.from(variationsByProduct.entries()).map(async ([productId, variationIds]) => {
        // Filtrar variaciones que ya están en caché y son válidas
        const uncachedIds: number[] = [];
        const now = Date.now();
        
        variationIds.forEach(vid => {
          const cacheKey = `${productId}:${vid}`;
          const cached = this.variationsCache.get(cacheKey);
          const timestamp = this.cacheTimestamps.get(cacheKey) || 0;
          
          if (cached && (now - timestamp) < this.CACHE_TTL) {
            // Usar caché válido
            variationMap.set(cacheKey, cached);
          } else {
            // Necesita recarga
            uncachedIds.push(vid);
          }
        });

        // Si todas están en caché, no hacer request
        if (uncachedIds.length === 0) {
          logger.info('hybridCartService', `Todas las variaciones del producto ${productId} están en caché`);
          return;
        }

        try {
          // Batch request para variaciones no cacheadas
          const response = await wooCommerceApi.get(`/products/${productId}/variations`, {
            params: { include: uncachedIds.join(','), per_page: 100 }
          });
          
          if (response.data && Array.isArray(response.data)) {
            response.data.forEach((variation: any) => {
              const key = `${productId}:${variation.id}`;
              variationMap.set(key, variation);
              // Actualizar caché
              this.variationsCache.set(key, variation);
              this.cacheTimestamps.set(key, Date.now());
            });
            logger.info('hybridCartService', `Cargadas ${response.data.length} variaciones en batch para beneficio ${productId}`);
          }
        } catch (batchError) {
          // Fallback: cargar individualmente si batch falla
          logger.warn('hybridCartService', `Batch loading falló para beneficio ${productId}, usando fallback`, batchError);
          await Promise.all(uncachedIds.map(async vid => {
            const key = `${productId}:${vid}`;
            try {
              const r = await wooCommerceApi.get(`/products/${productId}/variations/${vid}`);
              variationMap.set(key, r.data);
              this.variationsCache.set(key, r.data);
              this.cacheTimestamps.set(key, Date.now());
            } catch {}
          }));
        }
      }));

      const filtered: CartItem[] = [];
      let removed = 0;
      let membershipRemoved = 0;
      for (const item of items) {
        const product = map.get(item.id);
        if (!product || product.status !== 'publish') { removed++; continue; }
        // Verificar acceso por membresía (el backend ahora anota user_has_access en vez de filtrar)
        if (product.user_has_access === false) {
          membershipRemoved++;
          logger.info('hybridCartService', `Removiendo beneficio ${product.name || item.id} de la reserva: sin acceso por membresía (requiere nivel ${product.membership_required})`);
          continue;
        }
        if (item.variation_id && item.variation_id > 0) {
          const key = `${item.id}:${item.variation_id}`;
          const variation = variationMap.get(key);
          if (!variation) { filtered.push(item as CartItem); continue; }
          if (variation.status !== 'publish' || variation.stock_status !== 'instock') { removed++; continue; }
          const enriched: CartItem = { ...(item as any), variation } as CartItem;
          filtered.push(enriched);
          continue;
        }
        if (product.stock_status !== 'instock') { removed++; continue; }
        filtered.push(item as CartItem);
      }
      return { filtered, removed, membershipRemoved };
    } catch (e) {
      logger.warn('hybridCartService', 'Error al validar stock, devolviendo items originales');
      return { filtered: items || [], removed: 0, membershipRemoved: 0 };
    }
  },

  /**
   * Convertir items completos a formato ligero (solo IDs)
   */
  toLightweightItems(items: CartItem[]): any[] {
    return items.map(item => ({
      id: item.id,
      variation_id: item.variation_id || 0,
      quantity: item.quantity
    }));
  },

  /**
   * Hidratar items ligeros con datos completos de productos/variaciones
   */
  async hydrateItems(lightweightItems: any[]): Promise<CartItem[]> {
    if (!lightweightItems || lightweightItems.length === 0) {
      return [];
    }

    try {
      // Obtener IDs únicos de productos
      const productIds = Array.from(new Set(lightweightItems.map(i => i.id).filter(id => id > 0)));
      
      // Cargar productos en batch
      let products: any[] = [];
      if (productIds.length > 0) {
        try {
          const resp = await productApiService.getAll({ 
            include: productIds.join(','), 
            per_page: productIds.length 
          });
          products = Array.isArray(resp.data) ? resp.data : resp.data?.products || [];
        } catch (e) {
          logger.warn('hybridCartService', 'Error cargando productos, intentando individual');
          for (const id of productIds) {
            try { 
              const r = await productApiService.getById(id); 
              products.push(r.data); 
            } catch {}
          }
        }
      }

      const productMap = new Map(products.map(p => [p.id, p]));

      // Agrupar variaciones por producto para batch loading
      const variationsByProduct = new Map<number, number[]>();
      lightweightItems.forEach(item => {
        if (item.variation_id && item.variation_id > 0) {
          if (!variationsByProduct.has(item.id)) {
            variationsByProduct.set(item.id, []);
          }
          variationsByProduct.get(item.id)!.push(item.variation_id);
        }
      });

      // Cargar variaciones en batch (usando caché)
      const variationMap = new Map<string, any>();
      const now = Date.now();

      await Promise.all(Array.from(variationsByProduct.entries()).map(async ([productId, variationIds]) => {
        const uncachedIds: number[] = [];
        
        variationIds.forEach(vid => {
          const cacheKey = `${productId}:${vid}`;
          const cached = this.variationsCache.get(cacheKey);
          const timestamp = this.cacheTimestamps.get(cacheKey) || 0;
          
          if (cached && (now - timestamp) < this.CACHE_TTL) {
            variationMap.set(cacheKey, cached);
          } else {
            uncachedIds.push(vid);
          }
        });

        if (uncachedIds.length === 0) return;

        try {
          const response = await wooCommerceApi.get(`/products/${productId}/variations`, {
            params: { include: uncachedIds.join(','), per_page: 100 }
          });
          
          if (response.data && Array.isArray(response.data)) {
            response.data.forEach((variation: any) => {
              const key = `${productId}:${variation.id}`;
              variationMap.set(key, variation);
              this.variationsCache.set(key, variation);
              this.cacheTimestamps.set(key, Date.now());
            });
          }
        } catch {
          // Fallback individual
          await Promise.all(uncachedIds.map(async vid => {
            const key = `${productId}:${vid}`;
            try {
              const r = await wooCommerceApi.get(`/products/${productId}/variations/${vid}`);
              variationMap.set(key, r.data);
              this.variationsCache.set(key, r.data);
              this.cacheTimestamps.set(key, Date.now());
            } catch {}
          }));
        }
      }));

      // Construir CartItems hidratados
      const hydratedItems: CartItem[] = [];
      for (const item of lightweightItems) {
        const product = productMap.get(item.id);
        if (!product) continue;

        const cartItem: CartItem = {
          id: item.id,
          quantity: item.quantity,
          product: product,
          variation_id: item.variation_id || 0
        };

        if (item.variation_id && item.variation_id > 0) {
          const variationKey = `${item.id}:${item.variation_id}`;
          const variation = variationMap.get(variationKey);
          if (variation) {
            cartItem.variation = variation;
          }
        }

        hydratedItems.push(cartItem);
      }

      return hydratedItems;
    } catch (error) {
      logger.error('hybridCartService', 'Error hidratando items:', error);
      return [];
    }
  },

  /**
   * Guardar items en localStorage (formato ligero)
   */
  saveLocalItems(items: CartItem[]): void {
    try {
      const normalized = this.normalizeItems(items);
      // Guardar solo IDs para reducir tamaño en localStorage
      const lightweight = this.toLightweightItems(normalized);
      localStorage.setItem(this.CART_ITEMS_KEY, JSON.stringify(lightweight));
    } catch (error) {
      logger.error('hybridCartService', 'Error al guardar items locales:', error);
    }
  },

  /**
   * Verificar si hay sincronización pendiente
   */
  hasPendingSync(): boolean {
    return localStorage.getItem(this.CART_SYNC_PENDING_KEY) === 'true';
  },

  /**
   * Marcar sincronización como completada
   */
  markSyncCompleted(): void {
    localStorage.removeItem(this.CART_SYNC_PENDING_KEY);
  },

  /**
   * Obtener reserva del servidor y actualizar localStorage
   * @param isAuthenticated Si el usuario está autenticado
   */
  async fetchServerCart(isAuthenticated: boolean): Promise<CartItem[]> {
    if (!isAuthenticated) {
      logger.info('hybridCartService', 'Usuario no autenticado, usando reserva local');
      return this.getLocalItems();
    }

    try {
      logger.info('hybridCartService', 'Obteniendo reserva del servidor');
      const { items: serverItemsRaw, removed: backendRemoved } = await userCartApiService.getUserCartWithMeta();
      // Hidratar items ligeros del servidor con datos completos de producto
      const hydratedServer = await this.hydrateItems(serverItemsRaw);
      const normalizedServer = this.normalizeItems(hydratedServer);
      const { filtered, removed: frontendRemoved, membershipRemoved } = await this.filterItemsByStock(normalizedServer);
      const totalStockRemoved = (backendRemoved || 0) + (frontendRemoved || 0);
      this.saveLocalItems(filtered);
      this.markSyncCompleted();
      if (totalStockRemoved > 0) {
        alertService.info(i18n.t('alerts:cart.stockRemoved', { count: totalStockRemoved, plural: totalStockRemoved !== 1 ? 's' : '' }));
      }
      if (membershipRemoved > 0) {
        alertService.info(i18n.t('alerts:cart.membershipRemoved', { count: membershipRemoved, plural: membershipRemoved !== 1 ? 's' : '' }));
      }
      if (totalStockRemoved > 0 || membershipRemoved > 0) {
        try { await userCartApiService.saveUserCart(filtered); } catch {}
      }
      logger.info('hybridCartService', `Reserva del servidor cargada: ${filtered.length} items (stock removidos: ${totalStockRemoved}, membresía removidos: ${membershipRemoved})`);
      return filtered;
    } catch (error) {
      logger.error('hybridCartService', 'Error al obtener reserva del servidor:', error);
      // Si falla, usar reserva local e hidratar
      const fallbackItems = this.getLocalItems();
      try {
        const hydratedFallback = await this.hydrateItems(fallbackItems);
        return this.normalizeItems(hydratedFallback);
      } catch {
        return fallbackItems;
      }
    }
  },

  /**
   * Sincronizar reserva local con el servidor
   * @param isAuthenticated Si el usuario está autenticado
   */
  async syncToServer(isAuthenticated: boolean): Promise<void> {
    if (!isAuthenticated) {
      logger.info('hybridCartService', 'Usuario no autenticado, omitiendo sincronización');
      return;
    }

    if (!this.hasPendingSync()) {
      logger.info('hybridCartService', 'No hay cambios pendientes de sincronizar');
      return;
    }

    try {
      const localItems = this.getLocalItems();
      logger.info('hybridCartService', `Sincronizando ${localItems.length} items con el servidor`);
      
      const ok = await userCartApiService.saveUserCart(localItems);
      if (ok) {
        this.markSyncCompleted();
        logger.info('hybridCartService', 'Sincronización completada');
      } else {
        logger.warn('hybridCartService', 'Sincronización fallida - Manteniendo cambios pendientes para reintentar');
      }
      
    } catch (error) {
      logger.error('hybridCartService', 'Error al sincronizar con servidor:', error);
      // No lanzar error, mantener cambios locales
    }
  },

  /**
   * Añadir item a la reserva (localStorage + servidor)
   */
  async addItem(
    product: Product, 
    quantity: number = 1, 
    variation_id?: number, 
    variation?: any,
    isAuthenticated: boolean = false,
    currentItems?: CartItem[]
  ): Promise<CartItem[]> {
    try {
      // 1. Actualizar localStorage primero (optimistic update)
      // Usar items del estado React si están disponibles (tienen product completo)
      const localItems = currentItems && currentItems.length > 0 ? [...currentItems] : this.getLocalItems();
      const existingItemIndex = localItems.findIndex(item => 
        item.id === product.id && 
        (variation_id ? item.variation_id === variation_id : true)
      );

      let updatedItems: CartItem[];
      
      if (existingItemIndex !== -1) {
        // Actualizar cantidad
        updatedItems = [...localItems];
        updatedItems[existingItemIndex].quantity += quantity;
        alertService.success(quantity > 1 ? i18n.t('alerts:cart.addedMultipleToCart', { quantity, name: product.name }) : i18n.t('alerts:cart.addedToCart', { name: product.name }));
      } else {
        // Añadir nuevo item
        const newItem: CartItem = {
          id: product.id,
          product,
          quantity,
          variation_id,
          variation
        };
        updatedItems = [...localItems, newItem];
        alertService.success(i18n.t('alerts:cart.addedToCart', { name: product.name }));
      }

      const normalizedItems = this.normalizeItems(updatedItems);
      this.saveLocalItems(normalizedItems);

      // 2. Sincronizar con servidor inmediatamente si está autenticado
      if (isAuthenticated) {
        try {
          const ok = await userCartApiService.saveUserCart(normalizedItems);
          if (ok) {
            this.markSyncCompleted();
            logger.info('hybridCartService', 'Reserva sincronizada inmediatamente tras agregar');
          } else {
            logger.warn('hybridCartService', 'Fallo sincronización inmediata; se intentará en background');
          }
        } catch (syncError) {
          logger.warn('hybridCartService', 'Error en sincronización, se intentará luego:', syncError);
        }
      }
      
      return normalizedItems;
    } catch (error) {
      logger.error('hybridCartService', 'Error al añadir item:', error);
      throw error;
    }
  },

  /**
   * Actualizar cantidad de un item
   */
  async updateItemQuantity(
    productId: number, 
    quantity: number, 
    variation_id?: number,
    isAuthenticated: boolean = false,
    currentItems?: CartItem[]
  ): Promise<CartItem[]> {
    try {
      // 1. Usar items del estado React si están disponibles (tienen product completo)
      const localItems = currentItems && currentItems.length > 0 ? [...currentItems] : this.getLocalItems();
      
      if (quantity <= 0) {
        // Eliminar de la reserva si la cantidad es 0 o menos
        return this.removeItem(productId, variation_id, isAuthenticated, currentItems);
      }
      
      // Actualizar cantidad manteniendo datos completos del producto
      const updatedItems = localItems.map(item => {
        if (item.id === productId && (variation_id ? item.variation_id === variation_id : true)) {
          return { ...item, quantity };
        }
        return item;
      });

      const normalizedItems = this.normalizeItems(updatedItems);
      this.saveLocalItems(normalizedItems);

      // 2. Sincronizar con servidor inmediatamente si está autenticado
      if (isAuthenticated) {
        try {
          const ok = await userCartApiService.saveUserCart(normalizedItems);
          if (ok) {
            this.markSyncCompleted();
            logger.info('hybridCartService', 'Reserva sincronizada inmediatamente tras actualizar cantidad');
          } else {
            logger.warn('hybridCartService', 'Fallo sincronización inmediata (update); se intentará en background');
            this.syncToServer(isAuthenticated).catch(error => {
              logger.warn('hybridCartService', 'Error en sincronización background:', error);
            });
          }
        } catch (error) {
          logger.warn('hybridCartService', 'Excepción en sincronización inmediata (update), intentando background:', error);
          this.syncToServer(isAuthenticated).catch(err => {
            logger.warn('hybridCartService', 'Error en sincronización background:', err);
          });
        }
      }

      return normalizedItems;
    } catch (error) {
      logger.error('hybridCartService', 'Error al actualizar cantidad:', error);
      throw error;
    }
  },

  /**
   * Eliminar item del carrito
   */
  async removeItem(
    productId: number, 
    variation_id?: number,
    isAuthenticated: boolean = false,
    currentItems?: CartItem[]
  ): Promise<CartItem[]> {
    try {
      // 1. Usar items del estado React si están disponibles (tienen product completo)
      const localItems = currentItems && currentItems.length > 0 ? [...currentItems] : this.getLocalItems();

      // Filtrar el item a eliminar
      const updatedItems = localItems.filter(item => 
        !(item.id === productId && (variation_id ? item.variation_id === variation_id : true))
      );

      const normalizedItems = this.normalizeItems(updatedItems);
      this.saveLocalItems(normalizedItems);

      // 2. Sincronizar con servidor inmediatamente si está autenticado
      if (isAuthenticated) {
        try {
          const ok = await userCartApiService.saveUserCart(normalizedItems);
          if (ok) {
            this.markSyncCompleted();
            logger.info('hybridCartService', 'Reserva sincronizada inmediatamente tras eliminar');
          } else {
            logger.warn('hybridCartService', 'Fallo sincronización inmediata (remove); se intentará en background');
            this.syncToServer(isAuthenticated).catch(error => {
              logger.warn('hybridCartService', 'Error en sincronización background:', error);
            });
          }
        } catch (error) {
          logger.warn('hybridCartService', 'Excepción en sincronización inmediata (remove), intentando background:', error);
          this.syncToServer(isAuthenticated).catch(err => {
            logger.warn('hybridCartService', 'Error en sincronización background:', err);
          });
        }
      }

      return normalizedItems;
    } catch (error) {
      logger.error('hybridCartService', 'Error al eliminar item:', error);
      throw error;
    }
  },

  /**
   * Vaciar el carrito completamente
   * @param isAuthenticated Si el usuario está autenticado (para sincronizar con servidor)
   * @param silent Si es true, no muestra alerta (útil para logout silencioso)
   */
  async clearCart(isAuthenticated: boolean = false, silent: boolean = false): Promise<CartItem[]> {
    try {
      // 1. Limpiar localStorage (dejando constancia de cambios pendientes)
      this.saveLocalItems([]); // Marca CART_SYNC_PENDING_KEY = 'true'
      localStorage.removeItem(this.CART_COUPON_KEY);

      // 2. Limpiar servidor si está autenticado
      if (isAuthenticated) {
        try {
          const ok = await userCartApiService.clearUserCart();
          if (ok) {
            this.markSyncCompleted();
            logger.info('hybridCartService', 'Reserva del servidor vaciada');
          } else {
            logger.warn('hybridCartService', 'No se pudo vaciar la reserva en servidor con DELETE - Intentando guardar reserva vacía con POST');
            const saved = await userCartApiService.saveUserCart([]);
            if (saved) {
              this.markSyncCompleted();
              logger.info('hybridCartService', 'Reserva del servidor vaciada mediante POST');
            } else {
              logger.warn('hybridCartService', 'Fallo también el POST para vaciar reserva - Se mantendrá pendiente para reintentar');
            }
          }
        } catch (error) {
          logger.warn('hybridCartService', 'Error al vaciar reserva del servidor:', error);
        }
      }

      // Solo mostrar alerta si no es silencioso (ej: logout no debe mostrar alerta)
      if (!silent) {
        alertService.info(i18n.t('alerts:cart.cartCleared'));
      }
      return [];
    } catch (error) {
      logger.error('hybridCartService', 'Error al vaciar reserva:', error);
      throw error;
    }
  },

  /**
   * Recuperar carrito al iniciar sesión
   * ESTRATEGIA: Priorizar servidor, solo agregar items únicos del local
   */
  async recoverCartOnLogin(): Promise<CartItem[]> {
    try {
      logger.info('hybridCartService', '=== INICIO: Recuperación de reserva al iniciar sesión ===');
      
      // CASO ESPECIAL: Detectar si hay un reorder en progreso
      const reorderInProgress = localStorage.getItem('cart_reorder_in_progress');
      
      if (reorderInProgress === 'true') {
        logger.info('hybridCartService', '🔄 REORDER EN PROGRESO - Priorizando reserva local');
        localStorage.removeItem('cart_reorder_in_progress');
        
        const localItems = this.getLocalItems();
        logger.info('hybridCartService', `Items del reorder: ${localItems.length}`);
        
        // Sincronizar con servidor (reemplazar reserva del servidor con el local)
        try {
          const ok = await userCartApiService.saveUserCart(localItems);
          if (ok) {
            this.markSyncCompleted();
            logger.info('hybridCartService', 'Reserva del reorder sincronizada con servidor');
          } else {
            logger.warn('hybridCartService', 'No se pudo sincronizar la reserva del reorder - Se mantendrá pendiente');
          }
        } catch (error) {
          logger.error('hybridCartService', 'Error al sincronizar reorder con servidor:', error);
          // Continuar con reserva local aunque falle la sincronización
        }
        
        // Hidratar items ligeros con datos completos de producto
        const hydratedReorder = await this.hydrateItems(localItems);
        const normalizedReorder = this.normalizeItems(hydratedReorder);
        
        alertService.success(i18n.t('alerts:cart.reorderSuccess'));
        logger.info('hybridCartService', '=== FIN: Reorder procesado exitosamente ===');
        return normalizedReorder;
      }
      
      const localItems = this.getLocalItems();
      const hasLocalItems = localItems.length > 0;

      logger.info('hybridCartService', `Items locales encontrados: ${localItems.length}`);
      if (hasLocalItems) {
        logger.debug('hybridCartService', 'Items locales:', localItems.map(i => ({ id: i.id, qty: i.quantity, name: i.product?.name })));
      }

      const { items: serverItemsPre, removed: backendRemoved } = await userCartApiService.getUserCartWithMeta();
      // Hidratar items ligeros del servidor con datos completos de producto
      const hydratedPre = await this.hydrateItems(serverItemsPre);
      const normalizedPre = this.normalizeItems(hydratedPre);
      const { filtered: serverItems, removed: frontendRemoved, membershipRemoved } = await this.filterItemsByStock(normalizedPre);
      const totalStockRemoved = (backendRemoved || 0) + (frontendRemoved || 0);
      const hasServerItems = serverItems.length > 0;

      logger.info('hybridCartService', `Items en servidor: ${serverItems.length}`);
      if (hasServerItems) {
        logger.debug('hybridCartService', 'Items servidor:', serverItems.map(i => ({ id: i.id, qty: i.quantity, name: i.product?.name })));
      }

      // Comparar y reemplazar: el servidor es la fuente de verdad
      const areItemsSame = this.areCartsIdentical(localItems, serverItems);
      if (areItemsSame) {
        logger.info('hybridCartService', 'Reserva local ya coincide con el servidor');
        this.saveLocalItems(serverItems);
        this.markSyncCompleted();
        if (totalStockRemoved > 0) {
          alertService.info(i18n.t('alerts:cart.stockRemoved', { count: totalStockRemoved, plural: totalStockRemoved !== 1 ? 's' : '' }));
        }
        if (membershipRemoved > 0) {
          alertService.info(i18n.t('alerts:cart.membershipRemoved', { count: membershipRemoved, plural: membershipRemoved !== 1 ? 's' : '' }));
        }
        logger.info('hybridCartService', '=== FIN: Reserva ya sincronizada ===');
        return serverItems;
      }

      if (totalStockRemoved > 0) {
        alertService.info(i18n.t('alerts:cart.stockRemoved', { count: totalStockRemoved, plural: totalStockRemoved !== 1 ? 's' : '' }));
      }
      if (membershipRemoved > 0) {
        alertService.info(i18n.t('alerts:cart.membershipRemoved', { count: membershipRemoved, plural: membershipRemoved !== 1 ? 's' : '' }));
      }
      if (totalStockRemoved > 0 || membershipRemoved > 0) {
        try { await userCartApiService.saveUserCart(serverItems); } catch {}
      }

      logger.info('hybridCartService', 'Diferencias detectadas - Reemplazando reserva local por el del servidor');
      this.saveLocalItems(serverItems);
      this.markSyncCompleted();
      if (totalStockRemoved === 0 && membershipRemoved === 0) {
        alertService.info(i18n.t('alerts:cart.cartRecovered'));
      }
      logger.info('hybridCartService', '=== FIN: Reserva reemplazada por el del servidor ===');
      return serverItems;
    } catch (error) {
      logger.error('hybridCartService', 'ERROR al recuperar reserva:', error);
      // Si falla, mantener reserva local e hidratar
      const fallbackItems = this.getLocalItems();
      logger.warn('hybridCartService', `Usando reserva local como fallback: ${fallbackItems.length} items`);
      try {
        const hydratedFallback = await this.hydrateItems(fallbackItems);
        return this.normalizeItems(hydratedFallback);
      } catch {
        return fallbackItems;
      }
    }
  },

  /**
   * Verificar si dos carritos son idénticos (mismo contenido)
   */
  areCartsIdentical(cart1: CartItem[], cart2: CartItem[]): boolean {
    if (cart1.length !== cart2.length) return false;
    
    // Crear mapas para comparación
    const map1 = new Map(cart1.map(item => [
      `${item.id}-${item.variation_id || 'none'}`,
      item.quantity
    ]));
    
    const map2 = new Map(cart2.map(item => [
      `${item.id}-${item.variation_id || 'none'}`,
      item.quantity
    ]));
    
    // Verificar si todos los items y cantidades coinciden
    for (const [key, qty1] of map1) {
      const qty2 = map2.get(key);
      if (qty2 === undefined || qty1 !== qty2) {
        return false;
      }
    }
    
    return true;
  },

  /**
   * Limpiar reserva al cerrar sesión
   */
  async clearCartOnLogout(): Promise<void> {
    try {
      logger.info('hybridCartService', 'Limpiando reserva al cerrar sesión');
      
      // Limpiar localStorage
      localStorage.removeItem(this.CART_ITEMS_KEY);
      localStorage.removeItem(this.CART_COUPON_KEY);
      this.markSyncCompleted();
      
      logger.info('hybridCartService', 'Reserva local limpiada');
    } catch (error) {
      logger.error('hybridCartService', 'Error al limpiar reserva:', error);
    }
  }
};

export default hybridCartService;
