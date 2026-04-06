import React, { useEffect, useRef, memo } from 'react';
import { useTranslation } from 'react-i18next';
import Loader from '../ui/Loader';

interface InfiniteScrollProps {
  hasMore: boolean;
  loading: boolean;
  onLoadMore: () => void;
  threshold?: number; // Distancia en px desde el bottom para cargar más
  error?: Error | null; // Error de carga para mostrar al usuario
  onRetry?: () => void; // Callback para reintentar la carga
}

/**
 * Componente de Infinite Scroll para cargar productos automáticamente
 * al hacer scroll hacia abajo
 */
const InfiniteScroll: React.FC<InfiniteScrollProps> = memo(({
  hasMore,
  loading,
  onLoadMore,
  threshold = 300,
  error = null,
  onRetry
}) => {
  const { t } = useTranslation('shopComponents');
  const observerRef = useRef<IntersectionObserver | null>(null);
  const sentinelRef = useRef<HTMLDivElement | null>(null);
  const isLoadingRef = useRef(false);
  // Guardar referencia estable de onLoadMore
  const onLoadMoreRef = useRef(onLoadMore);
  onLoadMoreRef.current = onLoadMore;
  // Ref para loading para evitar recrear el observer innecesariamente
  const loadingRef = useRef(loading);
  loadingRef.current = loading;
  // Ref para hasMore para evitar recrear el observer innecesariamente
  const hasMoreRef = useRef(hasMore);
  hasMoreRef.current = hasMore;

  // Sincronizar ref con prop loading para evitar llamadas duplicadas
  useEffect(() => {
    if (!loading) {
      isLoadingRef.current = false;
    }
  }, [loading]);

  useEffect(() => {
    const handleObserver = (entries: IntersectionObserverEntry[]) => {
      const target = entries[0];
      
      // Prevenir múltiples llamadas mientras está cargando
      // Usar refs para tener siempre los valores actuales sin recrear el observer
      if (target.isIntersecting && hasMoreRef.current && !loadingRef.current && !isLoadingRef.current) {
        isLoadingRef.current = true;
        onLoadMoreRef.current();
      }
    };

    // Limpiar observer anterior
    if (observerRef.current) {
      observerRef.current.disconnect();
    }

    // Crear el observer solo cuando cambia el threshold
    // No recrear cuando cambian loading/hasMore (usamos refs para esos valores)
    const options = {
      root: null,
      rootMargin: `${threshold}px`,
      threshold: 0.1
    };

    observerRef.current = new IntersectionObserver(handleObserver, options);

    // Observar el elemento sentinel
    if (sentinelRef.current) {
      observerRef.current.observe(sentinelRef.current);
    }

    // Cleanup
    return () => {
      if (observerRef.current) {
        observerRef.current.disconnect();
      }
    };
  }, [threshold]); // Solo recrear observer cuando cambia threshold

  return (
    <div ref={sentinelRef} className="w-full py-8">
      {loading && (
        <Loader text={t('infiniteScroll.loadingMore')}/>
      )}
      
      {/* Mostrar error con opción de reintentar */}
      {error && !loading && (
        <div className="text-center py-4 bg-red-50 rounded-lg border border-red-200 mx-4">
          <p className="text-red-600 text-sm mb-3">
            {t('infiniteScroll.errorLoading')}
          </p>
          {onRetry && (
            <button
              onClick={onRetry}
              className="px-4 py-2 bg-primario text-white text-sm rounded-lg hover:bg-primario/90 transition-colors"
            >
              {t('infiniteScroll.retry')}
            </button>
          )}
        </div>
      )}
      
      {!hasMore && !loading && !error && (
        <div className="text-center py-4">
          <p className="text-gray-500 text-sm">
            {t('infiniteScroll.endOfList')}
          </p>
        </div>
      )}
    </div>
  );
});

InfiniteScroll.displayName = 'InfiniteScroll';

export default InfiniteScroll;
