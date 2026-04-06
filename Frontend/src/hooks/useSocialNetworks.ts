import { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import { bannerService } from '../services/api';
import { useMembership } from '../contexts/MembershipContext';
import logger from '../utils/logger';
import i18n from '../config/i18n';

interface SocialNetwork {
  name: string;
  url: string;
  username?: string;
  icon?: string;
  color?: string;
  membershipInfo?: {
    level: number;
    name: string;
    icon: string;
    color: string;
    mode: string;
  };
}

/**
 * Hook para obtener las redes sociales desde el endpoint de banners
 * Extrae las redes sociales de los banners tipo 'bottom'
 * 
 * IMPORTANTE: El backend filtra automáticamente las redes sociales según
 * el nivel de membresía del usuario autenticado. Este hook se re-ejecuta
 * cuando cambia el nivel de membresía para obtener las redes actualizadas.
 * 
 * OPTIMIZACIÓN: Usa refs para evitar re-fetches innecesarios cuando el nivel
 * de membresía no ha cambiado realmente.
 */
export const useSocialNetworks = () => {
  const { currentLevel } = useMembership();
  const [socialNetworks, setSocialNetworks] = useState<SocialNetwork[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  
  // Refs para evitar re-fetches innecesarios
  const lastFetchedLevelRef = useRef<number | null>(null);
  const isFetchingRef = useRef(false);
  const hasFetchedOnceRef = useRef(false);

  // NOTA: NO bloqueamos por membershipLoading. Cargamos inmediatamente con el nivel
  // actual (0 si aún no se resuelve) y recargamos cuando currentLevel cambie.
  useEffect(() => {
    const fetchSocialNetworks = async () => {
      // Evitar fetches simultáneos
      if (isFetchingRef.current) {
        return;
      }
      
      // Evitar re-fetch si el nivel no ha cambiado y ya tenemos datos
      if (hasFetchedOnceRef.current && lastFetchedLevelRef.current === currentLevel) {
        return;
      }
      
      isFetchingRef.current = true;
      
      try {
        setLoading(true);
        logger.info('useSocialNetworks', `Obteniendo redes sociales (nivel membresía: ${currentLevel})...`);

        const response = await bannerService.getAll();

        if (response && Array.isArray(response)) {
          // Filtrar banners tipo 'bottom' que contienen las redes sociales
          const bottomBanners = response.filter(banner => banner.type === 'bottom');
          
          logger.debug('useSocialNetworks', 'Bottom banners encontrados:', bottomBanners);

          // Extraer todas las redes sociales (ya filtradas por el backend según membresía)
          const networks: SocialNetwork[] = [];
          
          bottomBanners.forEach(banner => {
            if (banner.socialNetworks && Array.isArray(banner.socialNetworks)) {
              banner.socialNetworks.forEach((network: any) => {
                const networkData: SocialNetwork = {
                  name: network.name || network.title || '',
                  url: network.url || network.link || '#',
                  username: network.username || network.subtitle,
                  icon: network.icon,
                  color: network.color,
                  membershipInfo: network.membershipInfo
                };
                
                networks.push(networkData);
                logger.debug('useSocialNetworks', `Red social agregada: ${networkData.name}`, networkData);
              });
            }
          });

          setSocialNetworks(networks);
          lastFetchedLevelRef.current = currentLevel;
          hasFetchedOnceRef.current = true;
          logger.info('useSocialNetworks', `Total de redes sociales encontradas: ${networks.length} (nivel: ${currentLevel})`);
        }

        setError(null);
      } catch (err: any) {
        logger.error('useSocialNetworks', 'Error al cargar redes sociales:', err);
        setError(err.message || i18n.t('errors:socialNetworks.loadError'));
      } finally {
        setLoading(false);
        isFetchingRef.current = false;
      }
    };

    fetchSocialNetworks();
  }, [currentLevel]);

  /**
   * Obtener una red social específica por nombre
   * Memoizado para evitar re-renders innecesarios
   */
  const getSocialNetwork = useCallback((name: string): SocialNetwork | undefined => {
    return socialNetworks.find(
      network => network.name.toLowerCase() === name.toLowerCase()
    );
  }, [socialNetworks]);

  // Memoizar el objeto de retorno para evitar re-renders
  return useMemo(() => ({
    socialNetworks,
    loading,
    error,
    getSocialNetwork
  }), [socialNetworks, loading, error, getSocialNetwork]);
};

export default useSocialNetworks;
