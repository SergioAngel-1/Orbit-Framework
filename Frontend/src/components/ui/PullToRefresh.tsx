/**
 * PullToRefresh - Componente para recargar contenido al hacer pull-down
 * 
 * Funciona tanto en PWA como en navegador normal.
 * Detecta cuando el usuario está en el tope de la página y hace un gesto de
 * "tirar hacia abajo", mostrando un indicador visual y ejecutando la acción
 * de refresh al soltar.
 * 
 * Características:
 * - Funciona con touch (móvil) y mouse (desktop para testing)
 * - Indicador visual con animación de carga
 * - Threshold configurable para activar el refresh
 * - Resistencia progresiva al tirar (efecto elástico)
 * - Previene scroll bounce nativo en iOS
 * - Usa fluidSizing para proporciones responsivas
 * - Usa color primario dinámico del tema
 */

import { useState, useRef, useCallback, useEffect, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import logger from '../../utils/logger';
import { FiRefreshCw } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';

interface PullToRefreshProps {
  /** Contenido hijo que será envuelto */
  children: ReactNode;
  /** Función a ejecutar cuando se activa el refresh */
  onRefresh: () => Promise<void>;
  /** Distancia mínima en px para activar el refresh (default: 80) */
  threshold?: number;
  /** Distancia máxima de pull en px (default: 150) */
  maxPull?: number;
  /** Texto a mostrar mientras se tira */
  pullText?: string;
  /** Texto a mostrar cuando se puede soltar */
  releaseText?: string;
  /** Texto a mostrar mientras se recarga */
  refreshingText?: string;
  /** Desactivar el componente */
  disabled?: boolean;
}

type PullState = 'idle' | 'pulling' | 'ready' | 'refreshing';

const PullToRefresh = ({
  children,
  onRefresh,
  threshold = 80,
  maxPull = 150,
  pullText,
  releaseText,
  refreshingText,
  disabled = false,
}: PullToRefreshProps) => {
  const { t } = useTranslation('uiComponents');
  const resolvedPullText = pullText ?? t('pullToRefresh.pull');
  const resolvedReleaseText = releaseText ?? t('pullToRefresh.release');
  const resolvedRefreshingText = refreshingText ?? t('pullToRefresh.refreshing');

  const [pullState, setPullState] = useState<PullState>('idle');
  const [pullDistance, setPullDistance] = useState(0);
  
  const containerRef = useRef<HTMLDivElement>(null);
  const startYRef = useRef(0);
  const currentYRef = useRef(0);
  const isPullingRef = useRef(false);

  /**
   * Verifica si el scroll está en el tope de la página
   */
  const isAtTop = useCallback((): boolean => {
    return window.scrollY <= 0;
  }, []);

  /**
   * Calcula la distancia de pull con resistencia progresiva (efecto elástico)
   */
  const calculatePullDistance = useCallback((deltaY: number): number => {
    if (deltaY <= 0) return 0;
    // Resistencia progresiva: cuanto más tiras, más resistencia
    const resistance = 0.4;
    const adjustedDelta = deltaY * resistance;
    return Math.min(adjustedDelta, maxPull);
  }, [maxPull]);

  /**
   * Maneja el inicio del touch/mouse
   */
  const handleTouchStart = useCallback((clientY: number) => {
    if (disabled || pullState === 'refreshing') return;
    
    if (isAtTop()) {
      startYRef.current = clientY;
      isPullingRef.current = true;
    }
  }, [disabled, pullState, isAtTop]);

  /**
   * Maneja el movimiento del touch/mouse
   */
  const handleTouchMove = useCallback((clientY: number) => {
    if (disabled || !isPullingRef.current || pullState === 'refreshing') return;
    
    // Si ya no estamos en el tope, cancelar
    if (!isAtTop()) {
      isPullingRef.current = false;
      setPullDistance(0);
      setPullState('idle');
      return;
    }
    
    currentYRef.current = clientY;
    const deltaY = currentYRef.current - startYRef.current;
    
    if (deltaY > 0) {
      const distance = calculatePullDistance(deltaY);
      setPullDistance(distance);
      
      if (distance >= threshold) {
        setPullState('ready');
      } else if (distance > 0) {
        setPullState('pulling');
      }
    }
  }, [disabled, pullState, isAtTop, calculatePullDistance, threshold]);

  /**
   * Maneja el fin del touch/mouse
   */
  const handleTouchEnd = useCallback(async () => {
    if (disabled || !isPullingRef.current) return;
    
    isPullingRef.current = false;
    
    if (pullState === 'ready') {
      // Activar refresh
      setPullState('refreshing');
      setPullDistance(threshold); // Mantener visible durante refresh
      
      try {
        await onRefresh();
      } catch (error) {
        logger.error('PullToRefresh', 'Error durante refresh', error);
      } finally {
        // Animar la vuelta
        setPullState('idle');
        setPullDistance(0);
      }
    } else {
      // No alcanzó el threshold, volver a idle
      setPullState('idle');
      setPullDistance(0);
    }
  }, [disabled, pullState, threshold, onRefresh]);

  // Event listeners para touch
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const onTouchStart = (e: TouchEvent) => {
      handleTouchStart(e.touches[0].clientY);
    };

    const onTouchMove = (e: TouchEvent) => {
      handleTouchMove(e.touches[0].clientY);
      
      // Prevenir scroll bounce nativo en iOS cuando estamos tirando
      if (isPullingRef.current && pullDistance > 0) {
        e.preventDefault();
      }
    };

    const onTouchEnd = () => {
      handleTouchEnd();
    };

    // Agregar listeners con passive: false para poder prevenir default
    container.addEventListener('touchstart', onTouchStart, { passive: true });
    container.addEventListener('touchmove', onTouchMove, { passive: false });
    container.addEventListener('touchend', onTouchEnd, { passive: true });

    return () => {
      container.removeEventListener('touchstart', onTouchStart);
      container.removeEventListener('touchmove', onTouchMove);
      container.removeEventListener('touchend', onTouchEnd);
    };
  }, [handleTouchStart, handleTouchMove, handleTouchEnd, pullDistance]);

  // Calcular opacidad y escala del indicador
  const indicatorOpacity = Math.min(pullDistance / threshold, 1);
  const indicatorScale = 0.5 + (indicatorOpacity * 0.5);
  const rotation = pullState === 'refreshing' ? 0 : (pullDistance / maxPull) * 360;

  // Texto a mostrar según el estado
  const getText = () => {
    switch (pullState) {
      case 'pulling': return resolvedPullText;
      case 'ready': return resolvedReleaseText;
      case 'refreshing': return resolvedRefreshingText;
      default: return '';
    }
  };

  // Altura del header + barra de búsqueda + categorías (aproximadamente 180-220px)
  const headerOffset = 'clamp(11rem, 11rem + 2 * ((100vw - 20rem) / 100), 14rem)';

  return (
    <div ref={containerRef} className="relative">
      {/* Indicador de Pull-to-Refresh - Posicionado debajo del header completo */}
      <div
        className="fixed left-0 right-0 flex flex-col items-center justify-center pointer-events-none z-40 transition-all duration-200"
        style={{
          top: headerOffset,
          height: `${pullDistance}px`,
          opacity: indicatorOpacity,
          transform: `translateY(${pullState === 'idle' ? -50 : 0}px)`,
        }}
      >
        <div
          className="flex flex-col items-center justify-center rounded-full shadow-lg transition-all duration-200"
          style={{
            transform: `scale(${indicatorScale})`,
            padding: fluidSizing.space.sm,
            backgroundColor: 'var(--color-primary, #16a34a)',
          }}
        >
          <FiRefreshCw
            className={`text-white ${pullState === 'refreshing' ? 'animate-spin' : ''}`}
            style={{
              width: fluidSizing.size.iconMd,
              height: fluidSizing.size.iconMd,
              transform: pullState !== 'refreshing' ? `rotate(${rotation}deg)` : undefined,
            }}
          />
        </div>
        {pullDistance > 30 && (
          <span 
            className="font-medium bg-white/95 rounded-full shadow-md"
            style={{
              marginTop: fluidSizing.space.xs,
              fontSize: fluidSizing.text.sm,
              paddingLeft: fluidSizing.space.md,
              paddingRight: fluidSizing.space.md,
              paddingTop: fluidSizing.space.xs,
              paddingBottom: fluidSizing.space.xs,
              color: 'var(--color-primary, #16a34a)',
            }}
          >
            {getText()}
          </span>
        )}
      </div>

      {/* Contenido con transform para efecto visual */}
      <div
        className="transition-transform duration-200"
        style={{
          transform: pullDistance > 0 ? `translateY(${pullDistance}px)` : undefined,
        }}
      >
        {children}
      </div>
    </div>
  );
};

export default PullToRefresh;
