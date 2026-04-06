import { useState, useEffect, useRef } from 'react';
import homeSectionApiService from '../services/home/homeSectionApiService';
import { Product } from '../types/woocommerce';
import { useMembership } from '../contexts/MembershipContext';
import { useAuth } from '../contexts/AuthContext';
import { useLanguage } from '../contexts/LanguageContext';
import { cacheManager } from '../services/query/cacheManager';
import { DEFAULT_TTL } from '../services/query/types';
import logger from '../utils/logger';
import i18n from '../config/i18n';

// Interfaces para las secciones
export interface Section {
  id: string;
  layout_type: string;
  grid_type: 'standard' | 'wide' | 'compact' | 'compact_pair';
  zone: string;
  category_id: number;
  category_name: string;
  category_slug: string;
  title: string;
  subtitle: string;
  products: Product[];
  limit: number;
  min_products: number;
  order: number;
  /** Nivel mínimo de membresía requerido para ver esta sección */
  min_membership_level?: number;
  /** Propiedades para compact_pair (segunda categoría) */
  category_id_2?: number;
  category_name_2?: string;
  category_slug_2?: string;
  title_2?: string;
  subtitle_2?: string;
  products_2?: Product[];
}

/**
 * Hook personalizado para obtener los datos de una sección específica
 * 
 * IMPORTANTE: Este hook se recarga automáticamente cuando:
 * - Cambia el sectionId
 * - Cambia el nivel de membresía del usuario (membershipVersion)
 */
export const useProductSection = (sectionId: string) => {
  // Obtener membershipVersion para recargar cuando cambie el nivel de membresía
  const { membershipVersion } = useMembership();
  const { currentLang } = useLanguage();
  
  const [section, setSection] = useState<Section | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const controller = useRef<AbortController | null>(null);
  const retryCountRef = useRef(0);
  const MAX_RETRIES = 2;

  // Timeout base por sección (ms)
  const BASE_TIMEOUT = 10000; // 10 s por defecto
  // Delay base entre reintentos (ms)
  const RETRY_BASE_DELAY = 1200;

  // NOTA: NO bloqueamos por membershipLoading. Cargamos inmediatamente con el nivel
  // actual y recargamos cuando membershipVersion cambie.
  useEffect(() => {
    // Resetear contador de reintentos al cambiar de sección
    retryCountRef.current = 0;
    
    // Cancelar petición anterior si existe
    if (controller.current) {
      controller.current.abort();
    }
    controller.current = new AbortController();
    
    let isCancelled = false;
    
    // Crear una función para cargar los datos con reintentos
    const fetchSectionData = async () => {
      const currentRetry = retryCountRef.current;
      
      try {
        setLoading(true);
        logger.info('ProductSection', `Obteniendo sección ${sectionId}... (intento ${currentRetry + 1})`);
        
        // Verificar caché in-memory antes de hacer petición HTTP
        const cacheKey = cacheManager.buildCacheKey('homeSection', sectionId);
        const cached = cacheManager.get<Section>(cacheKey);
        if (cached) {
          logger.info('ProductSection', `Cache HIT [${cacheKey}] para sección ${sectionId}`);
          setSection(cached);
          setError(null);
          setLoading(false);
          return;
        }
        
        // El backend controla el límite de productos según el layout configurado
        // No forzamos ningún límite desde el frontend
        const response = await homeSectionApiService.getSectionProducts(sectionId, {
          signal: controller.current?.signal,
          timeout: BASE_TIMEOUT,
        });
        
        // Verificar si fue cancelado antes de actualizar estado
        if (isCancelled) {
          logger.info('ProductSection', `Petición cancelada para sección ${sectionId}`);
          return;
        }
        
        logger.info('ProductSection', `Datos de la sección ${sectionId}:`, response.data);
        
        // Verificar cuántos productos se recibieron
        if (response.data && response.data.products) {
          // Filtrar productos que no estén en stock (doble verificación por si el backend no filtró correctamente)
          const filteredProducts = response.data.products.filter((product: any) => 
            product.stock_status === 'instock'
          );
          
          // Filtrar también products_2 si existe (para secciones compact_pair)
          let filteredProducts2: any[] | undefined;
          if (response.data.products_2 && Array.isArray(response.data.products_2)) {
            filteredProducts2 = response.data.products_2.filter((product: any) => 
              product.stock_status === 'instock'
            );
          }
          
          // Actualizar la sección con los productos filtrados
          const sectionData = {
            ...response.data,
            products: filteredProducts,
            ...(filteredProducts2 !== undefined && { products_2: filteredProducts2 })
          };
          cacheManager.set(cacheKey, sectionData, DEFAULT_TTL.homeSection);
          setSection(sectionData);
        } else {
          cacheManager.set(cacheKey, response.data, DEFAULT_TTL.homeSection);
          setSection(response.data);
        }
        setError(null);
        setLoading(false);
      } catch (err: any) {
        // Verificar si la solicitud fue abortada intencionalmente
        if (isCancelled || err.code === 'ERR_CANCELED' || err.name === 'CanceledError') {
          logger.info('ProductSection', `Petición cancelada para sección ${sectionId}`);
          return;
        }
        
        const isTimeout = err.message && err.message.includes('timeout');
        const canRetry = isTimeout && currentRetry < MAX_RETRIES;

        if (canRetry) {
          logger.warn('ProductSection', `Timeout al obtener sección ${sectionId}. Intento ${currentRetry + 1} de ${MAX_RETRIES + 1}`);
          retryCountRef.current = currentRetry + 1;
          const backoffDelay = (currentRetry + 1) * RETRY_BASE_DELAY;
          setTimeout(() => {
            if (!isCancelled) {
              fetchSectionData();
            }
          }, backoffDelay);
          return;
        }
        
        // Error final (no timeout o máximo de reintentos alcanzado)
        logger.error('ProductSection', `Error fetching section ${sectionId}:`, err);
        setError(i18n.t('errors:generic.loadSectionError'));
        setLoading(false);
      }
    };

    fetchSectionData();
    
    // Cleanup: abortar solicitud pendiente cuando el componente se desmonte o cambie sectionId
    return () => {
      isCancelled = true;
      if (controller.current) {
        controller.current.abort();
        controller.current = null;
      }
    };
  }, [sectionId, membershipVersion, currentLang]); // Recargar cuando cambie sectionId, membresía o idioma

  return { section, loading, error };
};

/**
 * Hook personalizado para obtener todas las secciones
 * Se recarga automáticamente cuando cambia el nivel de membresía del usuario
 * 
 * IMPORTANTE: Este hook sigue el mismo patrón que useCategories para asegurar
 * que los datos se recargan correctamente al cambiar la autenticación/membresía
 */
export const useHomeSections = () => {
  const { currentLevel, membershipVersion } = useMembership();
  const { isAuthenticated } = useAuth();
  const { currentLang } = useLanguage();
  const [sections, setSections] = useState<{[key: string]: Section}>({});
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const controller = useRef<AbortController | null>(null);

  // NOTA: NO bloqueamos por membershipLoading. Cargamos inmediatamente con el nivel
  // actual (0 si aún no se resuelve) y recargamos cuando membershipVersion cambie.
  // El backend filtra por JWT del usuario, así que siempre retorna datos correctos.
  useEffect(() => {
    // Cancelar petición anterior si existe
    if (controller.current) {
      controller.current.abort();
    }
    controller.current = new AbortController();
    
    let isCancelled = false;
    
    const fetchAllSections = async () => {
      try {
        setLoading(true);
        logger.info('useHomeSections', `Obteniendo secciones (auth: ${isAuthenticated}, nivel: ${currentLevel}, version: ${membershipVersion})`);
        
        // Verificar caché in-memory antes de hacer petición HTTP
        const cacheKey = cacheManager.buildCacheKey('homeSection', 'all', { level: currentLevel });
        const cached = cacheManager.get<{[key: string]: Section}>(cacheKey);
        if (cached) {
          logger.info('useHomeSections', `Cache HIT [${cacheKey}]: ${Object.keys(cached).length} secciones`);
          setSections(cached);
          setError(null);
          setLoading(false);
          return;
        }
        
        // Solicitar secciones CON productos incluidos para evitar N+1 requests HTTP
        const response = await homeSectionApiService.getAll(true);
        
        // Verificar si fue cancelado antes de actualizar estado
        if (isCancelled) {
          logger.info('useHomeSections', 'Petición cancelada, descartando resultado');
          return;
        }
        
        logger.info('useHomeSections', 'Datos de todas las secciones:', response.data);
        
        // Convertir el array a un objeto con las claves de sección
        const sectionsObj: {[key: string]: Section} = {};
        response.data.forEach((section: Section) => {
          sectionsObj[section.id] = section;
          
          // Pre-poblar caché individual de cada sección para que
          // useProductSection encuentre cache HIT y no haga request HTTP
          if (section.products) {
            const sectionCacheKey = cacheManager.buildCacheKey('homeSection', section.id);
            cacheManager.set(sectionCacheKey, section, DEFAULT_TTL.homeSection);
          }
        });
        
        // Guardar en caché in-memory
        cacheManager.set(cacheKey, sectionsObj, DEFAULT_TTL.homeSection);
        
        setSections(sectionsObj);
        setError(null);
      } catch (err: any) {
        // Verificar si la solicitud fue abortada intencionalmente
        if (isCancelled || err.name === 'AbortError' || err.name === 'CanceledError') {
          logger.info('useHomeSections', 'Solicitud cancelada');
          return;
        }
        
        logger.error('useHomeSections', 'Error fetching home sections:', err);
        setError(i18n.t('errors:products.loadSectionsError'));
        
        // Si es un error de timeout, intentar cargar las secciones individualmente
        if (err.message && err.message.includes('timeout')) {
          logger.info('useHomeSections', 'Timeout detectado, intentando cargar secciones individualmente');
          // Cargar las secciones principales individualmente
          loadIndividualSections();
        }
      } finally {
        if (!isCancelled) {
          setLoading(false);
        }
      }
    };
    
    // Fallback: obtener secciones sin productos (más ligero) y luego cargar productos individualmente
    const loadIndividualSections = async () => {
      try {
        // Paso 1: Obtener solo metadatos de secciones (sin productos, mucho más ligero)
        const metaResponse = await homeSectionApiService.getAll(false);
        if (!metaResponse.data || !Array.isArray(metaResponse.data) || metaResponse.data.length === 0) {
          return;
        }
        
        const sectionsObj: {[key: string]: Section} = {};
        
        // Paso 2: Cargar productos de cada sección individualmente en paralelo
        await Promise.allSettled(
          metaResponse.data.map(async (sectionMeta: Section) => {
            try {
              const response = await homeSectionApiService.getSectionProducts(sectionMeta.id);
              if (response.data && response.data.id) {
                sectionsObj[response.data.id] = response.data;
                // Pre-poblar caché individual
                const sectionCacheKey = cacheManager.buildCacheKey('homeSection', response.data.id);
                cacheManager.set(sectionCacheKey, response.data, DEFAULT_TTL.homeSection);
                logger.info('useHomeSections', `Sección ${sectionMeta.id} cargada individualmente`);
              }
            } catch (sectionErr: any) {
              logger.warn('useHomeSections', `Error cargando sección individual ${sectionMeta.id}:`, sectionErr);
            }
          })
        );
        
        if (Object.keys(sectionsObj).length > 0) {
          setSections(sectionsObj);
          setError(null);
        }
      } catch (fallbackErr: any) {
        logger.error('useHomeSections', 'Fallback individual también falló:', fallbackErr);
      }
    };

    fetchAllSections();
    
    // Cleanup: abortar solicitud pendiente cuando el componente se desmonte o cambien dependencias
    return () => {
      isCancelled = true;
      if (controller.current) {
        controller.current.abort();
        controller.current = null;
      }
    };
  }, [currentLevel, membershipVersion, isAuthenticated, currentLang]);
  
  // Calcular qué zonas tienen secciones
  const hasTopSections = Object.values(sections).some(s => s.zone === 'top');
  const hasMiddleSections = Object.values(sections).some(s => s.zone === 'middle');
  const hasBottomSections = Object.values(sections).some(s => s.zone === 'bottom');
  
  return { 
    sections, 
    loading, 
    error,
    hasTopSections,
    hasMiddleSections,
    hasBottomSections
  };
};
