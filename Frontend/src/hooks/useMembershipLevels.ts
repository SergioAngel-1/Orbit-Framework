/**
 * Hook para obtener y cachear niveles de membresía
 * Centraliza el acceso a la configuración de niveles desde la API
 * 
 * IMPORTANTE: Este hook NO usa caché persistente entre sesiones.
 * Los niveles se recargan cada vez que el componente se monta,
 * asegurando datos frescos después de login/logout.
 */

import { useState, useEffect, useCallback, useMemo } from 'react';
import { getMembershipLevels } from '../services/membership/membershipApiService';
import { MembershipLevel } from '../services/membership/membershipTypes';
import { useLanguage } from '../contexts/LanguageContext';
import { useSiteFeatures } from '../contexts/SiteConfigContext';
import logger from '../utils/logger';
import i18n from '../config/i18n';

// Cache global para evitar múltiples llamadas SIMULTÁNEAS a la API
// Se limpia en cada carga de página y en logout
let cachedLevels: MembershipLevel[] | null = null;
let cachePromise: Promise<MembershipLevel[]> | null = null;
// Versión del caché para invalidar promesas en vuelo
let cacheVersion = 0;
// Timestamp de última carga exitosa
let lastFetchTimestamp = 0;
// Idioma del caché actual (los nombres se traducen según idioma)
let cachedLang: string = 'es';
// TTL del caché: 5 minutos (los niveles no cambian frecuentemente)
const CACHE_TTL = 5 * 60 * 1000;

interface UseMembershipLevelsReturn {
  /** Lista de todos los niveles de membresía */
  levels: MembershipLevel[];
  /** Estado de carga */
  loading: boolean;
  /** Error si ocurrió alguno */
  error: string | null;
  /** Obtener un nivel específico por ID */
  getLevelById: (id: number) => MembershipLevel | undefined;
  /** Obtener información de icono para un nivel */
  getLevelIcon: (id: number) => { icon: string; icon_url: string; color: string } | null;
  /** Obtener nombre de un nivel */
  getLevelName: (id: number) => string;
  /** Refrescar los niveles desde la API */
  refresh: () => Promise<void>;
}

/**
 * Hook para acceder a los niveles de membresía
 * Los niveles se cachean globalmente para evitar llamadas repetidas
 * 
 * @example
 * const { levels, getLevelById, getLevelIcon } = useMembershipLevels();
 * const level = getLevelById(2); // Zanahoria Bronce
 * const icon = getLevelIcon(2); // { icon: '🥉', icon_url: '/assets/...', color: '#CD7F32' }
 */
const useMembershipLevels = (): UseMembershipLevelsReturn => {
  const { currentLang } = useLanguage();
  const features = useSiteFeatures();
  
  // Verificar si el caché es válido (tiene elementos, no ha expirado Y coincide el idioma)
  const isCacheValid = (lang: string) => {
    if (!cachedLevels || cachedLevels.length === 0) return false;
    if (Date.now() - lastFetchTimestamp > CACHE_TTL) return false;
    if (cachedLang !== lang) return false;
    return true;
  };
  
  const hasValidCache = isCacheValid(currentLang);
  const [levels, setLevels] = useState<MembershipLevel[]>(hasValidCache ? cachedLevels! : []);
  const [loading, setLoading] = useState(!hasValidCache);
  const [error, setError] = useState<string | null>(null);

  const fetchLevels = useCallback(async (forceRefresh = false) => {
    // Si el feature de memberships no está activo, no hacer fetch
    if (!features.memberships) {
      setLevels([]);
      setLoading(false);
      return;
    }

    // Si ya tenemos cache válido (con elementos, no expirado, mismo idioma) y no es refresh forzado, usar cache
    if (isCacheValid(currentLang) && !forceRefresh) {
      setLevels(cachedLevels!);
      setLoading(false);
      return;
    }

    // Si ya hay una petición en curso, esperar a que termine
    if (cachePromise && !forceRefresh) {
      try {
        const result = await cachePromise;
        setLevels(result);
        setLoading(false);
      } catch (err) {
        setError(i18n.t('errors:membership.loadLevelsError'));
        setLoading(false);
      }
      return;
    }

    setLoading(true);
    setError(null);

    // Guardar versión actual para detectar invalidaciones durante el fetch
    const fetchVersion = cacheVersion;
    
    // Crear promesa y guardarla para evitar llamadas duplicadas
    cachePromise = getMembershipLevels();

    try {
      const result = await cachePromise;
      
      // Solo actualizar caché si no fue invalidado durante el fetch Y tiene resultados
      if (fetchVersion === cacheVersion && result && result.length > 0) {
        cachedLevels = result;
        cachedLang = currentLang;
        lastFetchTimestamp = Date.now(); // Actualizar timestamp
        setLevels(result);
        logger.info('useMembershipLevels', `Niveles cargados: ${result.length} (lang=${currentLang}`);
      } else if (fetchVersion !== cacheVersion) {
        // Caché invalidado durante fetch - re-intentar con la nueva versión
        logger.info('useMembershipLevels', 'Caché invalidado durante fetch, re-intentando...');
        cachePromise = null;
        // Programar re-fetch en el siguiente tick para evitar recursión infinita
        setTimeout(() => fetchLevels(true), 0);
        return; // No cambiar loading state, el re-fetch lo hará
      } else {
        // Resultado vacío - no cachear pero sí actualizar estado
        logger.warn('useMembershipLevels', 'API retornó niveles vacíos, no se cachea');
        setLevels(result);
      }
    } catch (err) {
      logger.error('useMembershipLevels', 'Error al cargar niveles', err);
      setError(i18n.t('errors:membership.loadLevelsError'));
    } finally {
      setLoading(false);
      cachePromise = null;
    }
  }, [currentLang, features.memberships]);

  useEffect(() => {
    fetchLevels();
  }, [fetchLevels]);

  // Mapa de niveles por ID para acceso rápido
  const levelsMap = useMemo(() => {
    const map = new Map<number, MembershipLevel>();
    levels.forEach(level => map.set(level.id, level));
    return map;
  }, [levels]);

  const getLevelById = useCallback((id: number): MembershipLevel | undefined => {
    return levelsMap.get(id);
  }, [levelsMap]);

  const getLevelIcon = useCallback((id: number): { icon: string; icon_url: string; color: string } | null => {
    const level = levelsMap.get(id);
    if (!level) return null;
    
    return {
      icon: level.icon,
      icon_url: level.icon_url,
      color: level.color,
    };
  }, [levelsMap]);

  const getLevelName = useCallback((id: number): string => {
    const level = levelsMap.get(id);
    return level?.name || '';
  }, [levelsMap]);

  const refresh = useCallback(async () => {
    cachedLevels = null;
    await fetchLevels(true);
  }, [fetchLevels]);

  return {
    levels,
    loading,
    error,
    getLevelById,
    getLevelIcon,
    getLevelName,
    refresh,
  };
};

export default useMembershipLevels;

/**
 * Función utilitaria para obtener niveles sin usar el hook
 * Útil para componentes que solo necesitan los datos una vez
 */
export const fetchMembershipLevels = async (): Promise<MembershipLevel[]> => {
  // Detectar idioma actual desde la URL (fuera de React context)
  const currentLang = window.location.pathname.startsWith('/en/') || window.location.pathname === '/en' ? 'en' : 'es';
  
  // Solo usar caché si tiene elementos, no ha expirado Y coincide el idioma
  const isCacheValid = cachedLevels && cachedLevels.length > 0
    && (Date.now() - lastFetchTimestamp <= CACHE_TTL)
    && cachedLang === currentLang;
  if (isCacheValid) return cachedLevels!;
  
  const levels = await getMembershipLevels();
  // Solo cachear si tiene elementos
  if (levels && levels.length > 0) {
    cachedLevels = levels;
    cachedLang = currentLang;
    lastFetchTimestamp = Date.now();
  }
  return levels;
};

/**
 * Limpiar cache de niveles (útil para testing o logout)
 * 
 * IMPORTANTE: Incrementa cacheVersion para invalidar cualquier promesa en vuelo.
 * Esto evita que una petición que inició antes del logout complete después
 * y re-pueble el caché con datos del usuario anterior.
 */
/**
 * Acceso sincrónico a los niveles cacheados en memoria.
 * Usado por LanguageContext para traducir slugs de membresía al cambiar idioma.
 * Retorna array vacío si aún no se han cargado.
 */
export const getCachedMembershipLevels = (): MembershipLevel[] => {
  return cachedLevels ?? [];
};

export const clearMembershipLevelsCache = (): void => {
  cachedLevels = null;
  cachePromise = null;
  cacheVersion++; // Invalida promesas en vuelo
  lastFetchTimestamp = 0; // Resetear timestamp para forzar recarga
  cachedLang = 'es'; // Resetear idioma
  logger.info('useMembershipLevels', 'Cache de niveles limpiado');
};
