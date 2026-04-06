import React from 'react';
import { useTranslation } from 'react-i18next';

interface ResultCounterProps {
  total: number;
  showing?: number; // Productos visibles actualmente (para scroll infinito)
  currentPage?: number; // Página actual (para paginación)
  totalPages?: number; // Total de páginas (para paginación)
  itemName?: string;
  className?: string;
  searchTerm?: string; // Término de búsqueda activo (para mostrar mensaje contextual)
}

/**
 * Componente reutilizable para mostrar contadores de resultados
 * Soporta tanto scroll infinito como paginación
 */
const ResultCounter: React.FC<ResultCounterProps> = ({
  total,
  showing,
  currentPage,
  totalPages,
  itemName,
  className = '',
  searchTerm
}) => {
  const { t } = useTranslation('shopPage');
  
  // Cuando hay búsqueda activa, el total mostrado es el de productos filtrados (showing)
  // No tiene sentido mostrar "Mostrando 5 de 100" cuando el usuario buscó algo específico
  const hasActiveSearch = searchTerm && searchTerm.trim().length > 0;
  const effectiveTotal = hasActiveSearch ? (showing ?? total) : total;
  
  // Determinar si mostrar información de scroll infinito
  // No mostrar "Mostrando X de Y" cuando hay búsqueda (ya que effectiveTotal = showing)
  const showScrollInfo = !hasActiveSearch && showing !== undefined && showing < total;
  
  // Determinar si mostrar información de paginación
  const showPaginationInfo = currentPage !== undefined && totalPages !== undefined;
  
  return (
    <div className={`flex flex-col mb-4 bg-gray-50 p-3 rounded-lg ${className}`}>
      {/* Primera fila: total de productos (o resultados de búsqueda) */}
      <div className="flex flex-row justify-between items-center w-full">
        <div className="text-gray-700 font-medium">
          {hasActiveSearch ? (
            // Mensaje contextual para búsqueda activa
            t('resultCounter.searchResults', { count: effectiveTotal, term: searchTerm })
          ) : (
            // Mensaje normal sin búsqueda
            itemName 
              ? <><span className="text-primario font-bold">{effectiveTotal}</span> {`${itemName}${effectiveTotal !== 1 ? 's' : ''} encontrado${effectiveTotal !== 1 ? 's' : ''}`}</>
              : t('resultCounter.found', { count: effectiveTotal })
          )}
        </div>
        
        {/* Información de paginación (si aplica) */}
        {showPaginationInfo && (
          <div className="text-sm text-gray-500">
            {t('resultCounter.page', { current: currentPage, total: totalPages })}
          </div>
        )}
      </div>
      
      {/* Segunda fila: información de productos mostrados (scroll infinito) */}
      {showScrollInfo && (
        <div className="text-sm text-gray-500 mt-2 w-full">
          {itemName ? `Mostrando ${showing} de ${total} ${itemName}${total !== 1 ? 's' : ''}` : t('resultCounter.showing', { showing, count: total })}
        </div>
      )}
    </div>
  );
};

export default ResultCounter;
