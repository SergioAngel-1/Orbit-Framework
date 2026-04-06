/**
 * Hook para obtener los beneficios activos del usuario
 * 
 * IMPORTANTE: Este hook se recarga automáticamente cuando:
 * - Cambia el estado de autenticación (login/logout)
 * - Cambia el nivel de membresía del usuario (membershipVersion)
 * 
 * CORRECCIONES APLICADAS:
 * - AbortController para cancelar peticiones en vuelo (evita race conditions en logout)
 * - useCallback para fetchBenefits con dependencias correctas
 * - useRef para trackear si el componente sigue montado
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import benefitsApiService, { ActiveBenefit } from '../services/membership/benefitsApiService';
import { useMembership } from '../contexts/MembershipContext';
import logger from '../utils/logger';
import i18n from '../config/i18n';

interface UseActiveBenefitsReturn {
  benefits: ActiveBenefit[];
  loading: boolean;
  error: string | null;
  refetch: () => Promise<void>;
}

export function useActiveBenefits(isAuthenticated: boolean): UseActiveBenefitsReturn {
  // Obtener membershipVersion para recargar cuando cambie el nivel de membresía
  const { membershipVersion } = useMembership();
  
  const [benefits, setBenefits] = useState<ActiveBenefit[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  
  // Ref para AbortController - permite cancelar peticiones en vuelo
  const abortControllerRef = useRef<AbortController | null>(null);

  /**
   * Función para obtener beneficios con soporte para cancelación
   * Memoizada con useCallback para evitar re-creación innecesaria
   */
  const fetchBenefits = useCallback(async (signal?: AbortSignal) => {
    // Si no está autenticado, limpiar beneficios inmediatamente
    if (!isAuthenticated) {
      setBenefits([]);
      setLoading(false);
      setError(null);
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const data = await benefitsApiService.getActiveBenefits(signal);
      
      // Verificar si la petición fue cancelada antes de actualizar estado
      if (signal?.aborted) {
        logger.debug('useActiveBenefits', 'Petición cancelada, descartando resultado');
        return;
      }
      
      setBenefits(data);
      logger.debug('useActiveBenefits', `${data.length} beneficios cargados`);
    } catch (err: any) {
      // Ignorar errores de peticiones canceladas
      if (err.name === 'AbortError' || err.code === 'ERR_CANCELED' || signal?.aborted) {
        logger.debug('useActiveBenefits', 'Petición cancelada, ignorando error');
        return;
      }
      
      logger.error('useActiveBenefits', 'Error al cargar beneficios:', err);
      setError(i18n.t('errors:products.loadBenefitsError'));
      setBenefits([]);
    } finally {
      // Solo actualizar loading si la petición no fue cancelada
      if (!signal?.aborted) {
        setLoading(false);
      }
    }
  }, [isAuthenticated]);

  /**
   * Efecto para cargar beneficios cuando cambian las dependencias
   * Implementa cancelación de peticiones anteriores para evitar race conditions
   */
  useEffect(() => {
    // Cancelar petición anterior si existe
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
    
    // Crear nuevo AbortController para esta petición
    const controller = new AbortController();
    abortControllerRef.current = controller;
    
    // Ejecutar fetch con el signal del controller
    fetchBenefits(controller.signal);
    
    // Cleanup: cancelar petición al desmontar o cuando cambien dependencias
    return () => {
      controller.abort();
      abortControllerRef.current = null;
    };
  }, [fetchBenefits, membershipVersion]);

  /**
   * Función pública para refetch manual (sin cancelación automática)
   * Útil para cuando el usuario quiere forzar una recarga
   */
  const refetch = useCallback(async () => {
    // Cancelar petición anterior si existe
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
    
    // Crear nuevo controller para el refetch
    const controller = new AbortController();
    abortControllerRef.current = controller;
    
    await fetchBenefits(controller.signal);
  }, [fetchBenefits]);

  return {
    benefits,
    loading,
    error,
    refetch
  };
}

export default useActiveBenefits;
