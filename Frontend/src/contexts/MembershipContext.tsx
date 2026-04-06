/**
 * MembershipContext - Contexto para gestionar la membresía del usuario
 */

import { createContext, useContext, useState, useEffect, ReactNode, useMemo, useCallback, useRef } from 'react';
import { useAuth } from './AuthContext';
import { useSiteFeatures } from './SiteConfigContext';
import membershipService, { MembershipData, ActiveBenefitData, FreeSamplesData, FreeDeliveriesData } from '../services/membershipService';
import { cacheManager } from '../services/query/cacheManager';
import { clearMembershipLevelsCache } from '../hooks/useMembershipLevels';
import productApiService from '../services/products/productApiService';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import { removeAuthToken } from './utils/auth.utils';

// Caché global para niveles de membresía de categorías
const categoryLevelCache = new Map<number, number>();

interface MembershipContextType {
  // Estado de la membresía
  membership: MembershipData | null;
  loading: boolean;
  error: string | null;
  
  // Información del nivel
  currentLevel: number;
  membershipName: string;
  membershipIcon: string;
  membershipColor: string;
  isActive: boolean;
  daysRemaining: number | null;
  
  // Beneficios especiales
  freeDeliveries: FreeDeliveriesData | null;
  freeSamples: FreeSamplesData | null;
  
  // Todos los beneficios activos (optimización: vienen en la respuesta de membresía)
  activeBenefits: ActiveBenefitData[];
  
  // Funciones de verificación
  hasAccessToLevel: (requiredLevel: number) => boolean;
  canAccessCategory: (categoryId: number) => Promise<boolean>;
  getCategoryMembershipLevel: (categoryId: number) => Promise<number>;
  
  // Funciones de control
  refreshMembership: () => Promise<void>;
  
  // Versión de membresía para forzar recarga de componentes dependientes
  // Se incrementa cada vez que cambia el nivel de membresía
  membershipVersion: number;
}

const MembershipContext = createContext<MembershipContextType | undefined>(undefined);

/**
 * Hook para usar el contexto de membresía
 */
export const useMembership = () => {
  const context = useContext(MembershipContext);
  if (context === undefined) {
    throw new Error('useMembership debe ser usado dentro de un MembershipProvider');
  }
  return context;
};

interface MembershipProviderProps {
  children: ReactNode;
}

// Valor por defecto estático cuando el feature de membresías está desactivado
const disabledMembershipValue: MembershipContextType = {
  membership: null,
  loading: false,
  error: null,
  currentLevel: 0,
  membershipName: 'Default',
  membershipIcon: '',
  membershipColor: '#999999',
  isActive: false,
  daysRemaining: null,
  freeDeliveries: null,
  freeSamples: null,
  activeBenefits: [],
  hasAccessToLevel: (requiredLevel: number) => requiredLevel === 0,
  canAccessCategory: async () => true,
  getCategoryMembershipLevel: async () => 0,
  refreshMembership: async () => {},
  membershipVersion: 0,
};

/**
 * Provider del contexto de membresía.
 * Si el feature de memberships está desactivado, devuelve valores por defecto sin hacer fetch.
 */
export const MembershipProvider = ({ children }: MembershipProviderProps) => {
  const features = useSiteFeatures();

  // Si memberships está desactivado, bypass completo sin fetch ni lógica
  if (!features.memberships) {
    return (
      <MembershipContext.Provider value={disabledMembershipValue}>
        {children}
      </MembershipContext.Provider>
    );
  }

  return <MembershipProviderInternal>{children}</MembershipProviderInternal>;
};

/**
 * Provider interno real — solo se monta si memberships está activo
 */
const MembershipProviderInternal = ({ children }: MembershipProviderProps) => {
  const { isAuthenticated, user, loading: authLoading } = useAuth();
  
  const [membership, _setMembership] = useState<MembershipData | null>(null);
  // Wrapper que mantiene la ref sincronizada con el estado
  const setMembership = useCallback((value: MembershipData | null | ((prev: MembershipData | null) => MembershipData | null)) => {
    _setMembership(prev => {
      const newVal = typeof value === 'function' ? value(prev) : value;
      membershipRef.current = newVal;
      return newVal;
    });
  }, []);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  
  // Versión de membresía: se incrementa cada vez que cambia el nivel
  // Los componentes pueden usar esto como dependencia para forzar recarga
  const [membershipVersion, setMembershipVersion] = useState<number>(0);
  
  // Ref para trackear el último userId cargado y evitar recargas innecesarias
  const lastLoadedUserIdRef = useRef<number | null>(null);
  // Ref para trackear el último nivel cargado y detectar cambios
  const lastLoadedLevelRef = useRef<number | null>(null);
  // Ref para almacenar la Promise activa y evitar race conditions
  const loadingPromiseRef = useRef<Promise<void> | null>(null);
  // Ref para AbortController - cancela peticiones pendientes al cambiar de usuario
  const abortControllerRef = useRef<AbortController | null>(null);
  // Ref para trackear el userId de la petición en curso
  const pendingUserIdRef = useRef<number | null>(null);
  // Ref para saber si ya se ejecutó la limpieza inicial
  const initialCleanupDoneRef = useRef<boolean>(false);
  // Ref para el timeout de retry en caso de error de red
  const retryTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  // Ref para trackear si ya procesamos el logout (evita ciclos infinitos)
  const logoutProcessedRef = useRef<boolean>(false);
  // Ref para almacenar loadMembership y poder llamarla desde callbacks externos
  const loadMembershipRef = useRef<((forceReload?: boolean) => Promise<void>) | null>(null);
  // Ref para acceder al valor fresco de membership sin incluirlo en dependencias de useCallback
  const membershipRef = useRef<MembershipData | null>(null);

  /**
   * LIMPIEZA INICIAL AL CARGAR LA WEB
   * Esto asegura que cualquier cache residual de sesiones anteriores se limpie
   * antes de cargar los datos del usuario actual (o asignar membresía por defecto)
   * 
   * NOTA: NO reseteamos el nivel de membresía a 0 aquí porque el usuario puede
   * estar autenticado. El nivel correcto se establecerá cuando se cargue la membresía.
   */
  useEffect(() => {
    if (initialCleanupDoneRef.current) return;
    initialCleanupDoneRef.current = true;
    
    logger.info('MembershipContext', 'Limpieza inicial de cache al cargar la web');
    
    // Limpiar cache global de categorías
    categoryLevelCache.clear();
    
    // Limpiar cache de datos (productos, categorías, etc.) pero NO el nivel de membresía
    // El nivel se establecerá correctamente cuando se cargue la membresía del usuario
    // IMPORTANTE: Usar broadcast=false para NO notificar a otras pestañas
    // Esta es limpieza local al cargar la página, no un cambio de estado
    cacheManager.clearAll(false);
    // NO llamar setMembershipLevel(0) aquí - causa el bug de "membresía perdida"
    
    // Limpiar caché de productos inválidos (404s por membresía no son permanentes)
    productApiService.clearInvalidProductCache();
    
    // Limpiar cache de niveles de membresía (useMembershipLevels)
    clearMembershipLevelsCache();
    
    // Resetear refs por si quedaron valores de una sesión anterior (hot reload, etc.)
    lastLoadedUserIdRef.current = null;
    lastLoadedLevelRef.current = null;
    loadingPromiseRef.current = null;
    pendingUserIdRef.current = null;
    
    logger.info('MembershipContext', 'Limpieza inicial completada');
  }, []);

  /**
   * TIMEOUT DE SEGURIDAD
   * Última línea de defensa: si loading lleva más de 15 segundos en true,
   * forzar loading=false y asignar membresía por defecto para desbloquear la UI.
   * Esto cubre cualquier edge case donde el finally no se ejecute o una Promise
   * quede colgada (errores de red sin respuesta, AbortController no limpiado, etc.).
   */
  useEffect(() => {
    if (!loading) return;
    
    const LOADING_TIMEOUT = 15000; // 15 segundos máximo
    
    const timeoutId = setTimeout(() => {
      logger.error('MembershipContext', `⚠️ TIMEOUT DE SEGURIDAD: loading=true por más de ${LOADING_TIMEOUT / 1000}s — forzando desbloqueo`);
      
      // Limpiar refs que podrían estar en estado inconsistente
      loadingPromiseRef.current = null;
      pendingUserIdRef.current = null;
      
      // Asignar membresía por defecto si no hay una cargada
      if (!membershipRef.current) {
        setMembership(membershipService.getDefaultMembership());
        cacheManager.setMembershipLevel(0);
        lastLoadedLevelRef.current = 0;
      }
      
      setLoading(false);
      setError(null);
    }, LOADING_TIMEOUT);
    
    return () => clearTimeout(timeoutId);
  }, [loading, setMembership]);

  /**
   * SINCRONIZACIÓN CROSS-TAB
   * Registra callback para recibir notificaciones cuando otra pestaña cambie la membresía.
   * Esto permite que todas las pestañas del mismo navegador se mantengan sincronizadas.
   */
  useEffect(() => {
    // Registrar callback para cambios de membresía desde otras pestañas
    cacheManager.onMembershipChange((newLevel: number) => {
      logger.info('MembershipContext', `📡 Cross-tab: Membresía cambiada a nivel ${newLevel} desde otra pestaña`);
      
      // Limpiar cache local de categorías
      categoryLevelCache.clear();
      
      // Limpiar cache de niveles
      clearMembershipLevelsCache();
      
      // Forzar recarga de membresía desde el servidor para obtener datos completos
      // Usamos la ref porque loadMembership se define después de este efecto
      if (loadMembershipRef.current) {
        loadMembershipRef.current(true);
      }
    });
    
    // No hay cleanup necesario - el callback se sobrescribe si se re-registra
  }, []);

  /**
   * Cargar la membresía del usuario
   * Implementa deduplicación de Promises y protección contra race conditions:
   * - Si hay una carga en progreso para el MISMO usuario, retorna la misma Promise
   * - Si el usuario cambió, cancela la petición anterior y crea una nueva
   * - Verifica que el usuario no haya cambiado antes de aplicar los datos
   */
  const loadMembership = useCallback(async (forceReload = false): Promise<void> => {
    const currentUserId = user?.id ?? null;
    
    // CRÍTICO: Si el usuario cambió (incluyendo logout), SIEMPRE recargar
    const userChanged = lastLoadedUserIdRef.current !== currentUserId;
    
    // Evitar recargar si ya tenemos los datos del mismo usuario (y no es logout)
    if (!forceReload && !userChanged && currentUserId !== null) {
      return;
    }
    
    // Si hay una carga en progreso para un usuario DIFERENTE, cancelarla
    if (loadingPromiseRef.current && pendingUserIdRef.current !== currentUserId) {
      logger.warn('MembershipContext', `Usuario cambió durante carga (${pendingUserIdRef.current} -> ${currentUserId}), cancelando petición anterior`);
      abortControllerRef.current?.abort();
      loadingPromiseRef.current = null;
    }
    
    // Si hay una carga en progreso para el MISMO usuario, esperar a que termine
    if (loadingPromiseRef.current && pendingUserIdRef.current === currentUserId) {
      logger.debug('MembershipContext', 'Carga en progreso para mismo usuario, esperando Promise existente');
      return loadingPromiseRef.current;
    }
    
    // Crear nuevo AbortController para esta petición
    abortControllerRef.current = new AbortController();
    const signal = abortControllerRef.current.signal;
    
    // Guardar el userId de la petición en curso
    pendingUserIdRef.current = currentUserId;
    
    // Crear nueva Promise y guardarla en el ref
    const loadPromise = (async () => {
      try {
        setLoading(true);
        setError(null);
        
        // Limpiar caché local de niveles de categoría
        categoryLevelCache.clear();
        
        if (isAuthenticated && user) {
          // Usuario autenticado: obtener su membresía
          const membershipData = await membershipService.getCurrentMembership();
          
          // VERIFICACIÓN CRÍTICA: Comprobar si la petición fue cancelada
          if (signal.aborted) {
            logger.debug('MembershipContext', 'Petición cancelada, descartando resultado');
            return;
          }
          
          // Verificar que el usuario actual sigue siendo el mismo que inició la petición
          // NOTA: Usamos pendingUserIdRef en lugar de user?.id para evitar closure stale
          if (pendingUserIdRef.current !== currentUserId) {
            logger.warn('MembershipContext', `Usuario cambió durante petición (esperado: ${currentUserId}, actual: ${pendingUserIdRef.current}), descartando resultado`);
            return;
          }
          
          setMembership(membershipData);
          lastLoadedUserIdRef.current = user.id;
          
          // Detectar cambio de nivel e incrementar versión
          const newLevel = membershipData?.level ?? 0;
          if (lastLoadedLevelRef.current !== null && lastLoadedLevelRef.current !== newLevel) {
            setMembershipVersion(v => v + 1);
            // Sincronizar nivel con cacheManager e invalidar cachés relacionados
            cacheManager.invalidateMembershipRelated(newLevel);
            // Limpiar caché de productos inválidos (un 404 por membresía no es permanente)
            productApiService.clearInvalidProductCache();
            logger.info('MembershipContext', `Nivel de membresía cambió: ${lastLoadedLevelRef.current} -> ${newLevel}, cachés invalidados`);
          } else {
            // Asegurar que el cacheManager tenga el nivel correcto aunque no haya cambiado
            cacheManager.setMembershipLevel(newLevel);
          }
          lastLoadedLevelRef.current = newLevel;
          
          logger.info('MembershipContext', `Membresía cargada para usuario ${user.id}`);
        } else {
          // Usuario no autenticado: membresía nivel 0 (público)
          
          // Verificar que sigue sin autenticar
          if (signal.aborted) {
            logger.debug('MembershipContext', 'Petición cancelada, descartando resultado');
            return;
          }
          
          const defaultMembership = membershipService.getDefaultMembership();
          setMembership(defaultMembership);
          lastLoadedUserIdRef.current = null;
          
          // Detectar cambio de nivel e incrementar versión
          if (lastLoadedLevelRef.current !== null && lastLoadedLevelRef.current !== 0) {
            setMembershipVersion(v => v + 1);
            // Sincronizar nivel con cacheManager e invalidar cachés relacionados
            cacheManager.invalidateMembershipRelated(0);
            // Limpiar caché de productos inválidos (un 404 por membresía no es permanente)
            productApiService.clearInvalidProductCache();
            logger.info('MembershipContext', `Nivel de membresía cambió: ${lastLoadedLevelRef.current} -> 0, cachés invalidados`);
          } else {
            // Asegurar que el cacheManager tenga el nivel correcto
            cacheManager.setMembershipLevel(0);
          }
          lastLoadedLevelRef.current = 0;
          
          logger.info('MembershipContext', 'Usuario no autenticado, membresía nivel 0 asignada');
        }
      } catch (err: any) {
        // Ignorar errores de peticiones canceladas
        if (signal.aborted || err.name === 'AbortError') {
          logger.debug('MembershipContext', 'Petición cancelada, ignorando error');
          return;
        }
        
        // CRÍTICO: Si es un error de autenticación (401), forzar logout
        // Esto soluciona el bug donde la membresía pasa a básica pero el usuario
        // sigue "autenticado" en el frontend
        if (err.isAuthError || err.response?.status === 401) {
          logger.warn('MembershipContext', 'Error de autenticación detectado - sesión expirada');
          
          // Limpiar tokens
          removeAuthToken();
          
          // Asignar membresía por defecto
          setMembership(membershipService.getDefaultMembership());
          lastLoadedUserIdRef.current = null;
          lastLoadedLevelRef.current = 0;
          
          // Disparar evento para que AuthContext maneje el logout
          // Esto evita dependencias circulares y permite una experiencia más suave
          window.dispatchEvent(new CustomEvent('auth:sessionExpired', { 
            detail: { reason: 'membership_401' } 
          }));
          return;
        }
        
        // CRÍTICO: Para errores de red, NO asignar membresía por defecto
        // Esto evita que el usuario pierda su membresía por un error temporal
        if (err.isNetworkError) {
          logger.warn('MembershipContext', 'Error de red al cargar membresía - manteniendo estado actual');
          setError(i18n.t('errors:membership.connectionRetrying'));
          
          // Cancelar retry anterior si existe
          if (retryTimeoutRef.current) {
            clearTimeout(retryTimeoutRef.current);
          }
          
          // Programar un retry automático en 5 segundos si el usuario sigue autenticado
          retryTimeoutRef.current = setTimeout(() => {
            retryTimeoutRef.current = null;
            // Verificar que el usuario sigue siendo el mismo antes de reintentar
            if (pendingUserIdRef.current === null && lastLoadedUserIdRef.current === currentUserId) {
              logger.info('MembershipContext', 'Reintentando carga de membresía después de error de red');
              loadMembership(true);
            }
          }, 5000);
          return;
        }
        
        const errorMessage = err.message || i18n.t('errors:membership.loadError');
        setError(errorMessage);
        logger.error('MembershipContext', 'Error al cargar membresía:', err);
        
        // Solo asignar membresía por defecto si NO hay membresía actual
        // Esto evita perder la membresía por errores temporales
        // NOTA: Usar membershipRef.current en lugar de membership para evitar stale closure
        if (!membershipRef.current) {
          setMembership(membershipService.getDefaultMembership());
        }
      } finally {
        // SAFETY NET: Siempre desbloquear loading para evitar que la UI quede
        // congelada indefinidamente. Antes, si la petición era reemplazada por otra
        // (pendingUserIdRef !== currentUserId), setLoading(false) nunca se llamaba.
        // Ahora solo limpiamos refs de tracking si la petición sigue siendo la actual,
        // pero SIEMPRE desbloqueamos loading.
        if (pendingUserIdRef.current === currentUserId) {
          loadingPromiseRef.current = null;
          pendingUserIdRef.current = null;
        }
        setLoading(false);
      }
    })();
    
    // Guardar la Promise en el ref para que otras llamadas puedan esperarla
    loadingPromiseRef.current = loadPromise;
    
    return loadPromise;
  // IMPORTANTE: No incluir 'membership' ni 'loading' en las dependencias para evitar ciclos infinitos
  // La detección de membresía perdida se maneja con un efecto separado
  }, [isAuthenticated, user]);

  // Actualizar la ref con la función loadMembership para uso en callbacks externos (cross-tab)
  useEffect(() => {
    loadMembershipRef.current = loadMembership;
  }, [loadMembership]);

  /**
   * Refrescar la membresía manualmente
   */
  const refreshMembership = useCallback(async () => {
    await loadMembership(true);
  }, [loadMembership]);

  /**
   * Verificar si el usuario tiene acceso a un nivel específico
   * Memoizado para evitar re-renders innecesarios
   */
  const hasAccessToLevel = useCallback((requiredLevel: number): boolean => {
    if (!membership) return requiredLevel === 0;
    return membershipService.hasAccessToLevel(membership.level, requiredLevel);
  }, [membership]);

  /**
   * Verificar si el usuario puede acceder a una categoría
   */
  const canAccessCategory = useCallback(async (categoryId: number): Promise<boolean> => {
    return await membershipService.canAccessCategory(categoryId);
  }, []);

  /**
   * Obtener el nivel de membresía requerido para una categoría (con caché)
   */
  const getCategoryMembershipLevel = useCallback(async (categoryId: number): Promise<number> => {
    // Verificar caché primero
    if (categoryLevelCache.has(categoryId)) {
      return categoryLevelCache.get(categoryId)!;
    }
    
    try {
      const accessInfo = await membershipService.checkCategoryAccess(categoryId);
      const level = accessInfo?.required_level ?? 0;
      // Guardar en caché
      categoryLevelCache.set(categoryId, level);
      return level;
    } catch {
      // En caso de error, retornar 0 y no cachear
      return 0;
    }
  }, []);

  /**
   * DETECCIÓN DE MEMBRESÍA PERDIDA
   * Si el usuario está autenticado pero membership es null y no estamos cargando,
   * forzar una recarga. Esto soluciona el bug donde la membresía se "pierde"
   * pero el usuario sigue autenticado.
   * 
   * IMPORTANTE: Solo intentar una vez por usuario para evitar ciclos infinitos
   * si hay un error persistente en el backend.
   */
  useEffect(() => {
    // Solo actuar si: autenticado, tiene user, membership es null, no está cargando
    if (!isAuthenticated || !user || membership !== null || loading || authLoading) {
      return;
    }
    
    // Verificar que no estemos ya intentando cargar para este usuario
    // (pendingUserIdRef se establece cuando hay una carga en progreso)
    if (pendingUserIdRef.current === user.id) {
      return;
    }
    
    // Verificar que ya hayamos intentado cargar para este usuario antes
    // Si lastLoadedUserIdRef es diferente, significa que aún no hemos cargado para este usuario
    // y el efecto principal debería encargarse
    if (lastLoadedUserIdRef.current !== user.id) {
      return;
    }
    
    // Si llegamos aquí, significa que ya cargamos para este usuario pero membership es null
    // Esto indica que se perdió la membresía - intentar recargar UNA vez
    logger.warn('MembershipContext', `Membresía perdida detectada para usuario ${user.id}, recargando...`);
    loadMembership(true);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated, user, membership, loading, authLoading]);

  /**
   * Cargar membresía cuando cambia el estado de autenticación
   * CRÍTICO: Este efecto debe dispararse cuando isAuthenticated cambia
   */
  useEffect(() => {
    // Esperar a que termine de cargar la autenticación
    if (authLoading) {
      return;
    }
    
    // LOGOUT DETECTADO: Si no está autenticado, limpiar todo inmediatamente
    if (!isAuthenticated) {
      // IMPORTANTE: Evitar ejecutar múltiples veces si ya procesamos el logout
      if (logoutProcessedRef.current) {
        return; // Ya procesamos el logout, no hacer nada
      }
      logoutProcessedRef.current = true;
      
      logger.info('MembershipContext', 'Logout detectado - limpiando TODOS los caches');
      
      // Cancelar peticiones pendientes
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      
      // Cancelar retry timeout si existe
      if (retryTimeoutRef.current) {
        clearTimeout(retryTimeoutRef.current);
        retryTimeoutRef.current = null;
      }
      
      // Resetear todos los refs
      lastLoadedUserIdRef.current = null;
      lastLoadedLevelRef.current = null;
      loadingPromiseRef.current = null;
      pendingUserIdRef.current = null;
      
      // Limpiar cache de categorías
      categoryLevelCache.clear();
      
      // CRÍTICO: Limpiar TODO el cacheManager
      cacheManager.clearAll();
      cacheManager.setMembershipLevel(0);
      
      // Limpiar cache de niveles de membresía
      clearMembershipLevelsCache();
      
      // Asignar membresía por defecto inmediatamente
      const defaultMembership = membershipService.getDefaultMembership();
      setMembership(defaultMembership);
      setLoading(false);
      setError(null);
      
      // Incrementar versión para forzar re-render de componentes dependientes
      setMembershipVersion(v => v + 1);
      
      return;
    }
    
    // Usuario autenticado: cargar membresía
    // Resetear flag de logout procesado ya que el usuario está autenticado
    logoutProcessedRef.current = false;
    loadMembership();
    
    // Cleanup: cancelar peticiones pendientes y retry timeout al desmontar o cambiar usuario
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
        logger.debug('MembershipContext', 'Cleanup: petición de membresía cancelada');
      }
      if (retryTimeoutRef.current) {
        clearTimeout(retryTimeoutRef.current);
        retryTimeoutRef.current = null;
      }
      // Resetear flag de logout para permitir re-procesamiento si las dependencias cambian
      // Esto evita estados inconsistentes si el efecto se re-ejecuta
      logoutProcessedRef.current = false;
    };
  // IMPORTANTE: No incluir loadMembership en las dependencias para evitar ciclos
  // El efecto se dispara cuando cambia isAuthenticated o authLoading
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated, authLoading]);

  /**
   * DETECCIÓN DE EXPIRACIÓN EN TIEMPO REAL
   * 
   * Este efecto verifica si la membresía ha expirado basándose en:
   * 1. days_remaining <= 0 cuando antes era > 0
   * 2. end_date ha pasado
   * 
   * Si detecta expiración, fuerza una recarga de la membresía desde el backend
   * para sincronizar el estado correctamente.
   */
  useEffect(() => {
    // Solo verificar si hay membresía activa con fecha de expiración
    if (!membership || !membership.end_date || !membership.is_active) {
      return;
    }

    const checkExpiration = () => {
      const endDate = new Date(membership.end_date!);
      const now = new Date();
      
      // Si la fecha de expiración ya pasó, forzar recarga
      if (endDate <= now) {
        logger.warn('MembershipContext', 'Membresía expirada detectada en tiempo real, recargando...');
        refreshMembership();
        return true;
      }
      return false;
    };

    // Verificar inmediatamente
    if (checkExpiration()) {
      return;
    }

    // Calcular tiempo hasta expiración
    const endDate = new Date(membership.end_date);
    const now = new Date();
    const msUntilExpiration = endDate.getTime() - now.getTime();
    
    // Si expira en menos de 24 horas, configurar timer preciso
    // Si expira en más de 24 horas, verificar cada hora
    const checkInterval = msUntilExpiration < 24 * 60 * 60 * 1000 
      ? Math.max(msUntilExpiration + 1000, 1000) // Timer preciso + 1 segundo de margen
      : 60 * 60 * 1000; // Cada hora

    logger.debug('MembershipContext', `Membresía expira en ${Math.round(msUntilExpiration / 1000 / 60)} minutos, verificando cada ${Math.round(checkInterval / 1000 / 60)} minutos`);

    const intervalId = setInterval(() => {
      checkExpiration();
    }, checkInterval);

    // Si expira pronto, también configurar un timer exacto
    let timeoutId: NodeJS.Timeout | undefined;
    if (msUntilExpiration > 0 && msUntilExpiration < 24 * 60 * 60 * 1000) {
      timeoutId = setTimeout(() => {
        logger.info('MembershipContext', 'Timer de expiración alcanzado, recargando membresía');
        refreshMembership();
      }, msUntilExpiration + 1000);
    }

    return () => {
      clearInterval(intervalId);
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
    };
  }, [membership?.end_date, membership?.is_active, refreshMembership]);

  /**
   * REVALIDACIÓN AL VOLVER A LA PÁGINA (visibilitychange)
   * 
   * Cuando el usuario deja la página inactiva (cambia de pestaña, minimiza, etc.)
   * y luego vuelve, revalidamos la membresía para asegurar que esté sincronizada.
   * 
   * Esto soluciona el bug donde la membresía se "pierde" después de inactividad
   * mientras el usuario sigue autenticado.
   * 
   * Condiciones para revalidar:
   * 1. La página vuelve a ser visible (document.visibilityState === 'visible')
   * 2. El usuario está autenticado
   * 3. Han pasado al menos 30 segundos desde la última carga (evita spam)
   * 
   * MEJORA: Si el usuario estuvo inactivo por más de 5 minutos, se limpia
   * completamente el caché para garantizar datos frescos.
   */
  useEffect(() => {
    // Timestamp de la última revalidación para evitar spam
    let lastRevalidationTime = Date.now();
    // Timestamp de cuando la página se ocultó
    let hiddenTimestamp = Date.now();
    
    const MIN_REVALIDATION_INTERVAL = 30 * 1000; // 30 segundos mínimo entre revalidaciones
    const FORCE_CLEAR_THRESHOLD = 5 * 60 * 1000; // 5 minutos para forzar limpieza completa
    
    const handleVisibilityChange = () => {
      const now = Date.now();
      
      // Cuando la página se oculta, guardar timestamp
      if (document.visibilityState === 'hidden') {
        hiddenTimestamp = now;
        return;
      }
      
      // Solo actuar cuando la página vuelve a ser visible
      if (document.visibilityState !== 'visible') {
        return;
      }
      
      // Solo revalidar si el usuario está autenticado
      if (!isAuthenticated || authLoading) {
        return;
      }
      
      // Evitar revalidaciones muy frecuentes
      if (now - lastRevalidationTime < MIN_REVALIDATION_INTERVAL) {
        logger.debug('MembershipContext', 'Revalidación omitida - muy pronto desde la última');
        return;
      }
      
      // Calcular tiempo de inactividad
      const inactiveTime = now - hiddenTimestamp;
      
      // Si estuvo inactivo por más de 5 minutos, limpiar caché completo
      if (inactiveTime > FORCE_CLEAR_THRESHOLD) {
        logger.info('MembershipContext', `Página visible después de ${Math.round(inactiveTime / 1000 / 60)} minutos - limpiando caché completo`);
        categoryLevelCache.clear();
        cacheManager.clearAll(false); // No broadcast, es limpieza local
        clearMembershipLevelsCache();
      } else {
        logger.info('MembershipContext', 'Página visible de nuevo - revalidando membresía');
      }
      
      lastRevalidationTime = now;
      
      // Forzar recarga de la membresía
      loadMembership(true);
    };
    
    // Agregar listener
    document.addEventListener('visibilitychange', handleVisibilityChange);
    
    // Cleanup
    return () => {
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [isAuthenticated, authLoading, loadMembership]);

  // Valores computados memoizados
  const currentLevel = membership?.level ?? 0;
  const membershipName = membership?.name || 'Zanahoria';
  const membershipIcon = membership?.icon || '🥕';
  const membershipColor = membership?.color || '#FF6B35';
  const isActive = membership?.is_active ?? false;
  const daysRemaining = membership?.days_remaining ?? null;
  const freeDeliveries = membership?.benefits?.free_deliveries ?? null;
  const freeSamples = membership?.benefits?.free_samples ?? null;
  const activeBenefits = membership?.active_benefits ?? [];

  // Memoizar el valor del contexto para evitar re-renders innecesarios
  const value = useMemo<MembershipContextType>(() => ({
    membership,
    loading,
    error,
    currentLevel,
    membershipName,
    membershipIcon,
    membershipColor,
    isActive,
    daysRemaining,
    freeDeliveries,
    freeSamples,
    activeBenefits,
    hasAccessToLevel,
    canAccessCategory,
    getCategoryMembershipLevel,
    refreshMembership,
    membershipVersion,
  }), [
    membership,
    loading,
    error,
    currentLevel,
    membershipName,
    membershipIcon,
    membershipColor,
    isActive,
    daysRemaining,
    freeDeliveries,
    freeSamples,
    activeBenefits,
    hasAccessToLevel,
    canAccessCategory,
    getCategoryMembershipLevel,
    refreshMembership,
    membershipVersion,
  ]);

  return (
    <MembershipContext.Provider value={value}>
      {children}
    </MembershipContext.Provider>
  );
};

export default MembershipContext;
