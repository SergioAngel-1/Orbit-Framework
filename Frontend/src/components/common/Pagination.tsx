import React from 'react';
import { useTranslation } from 'react-i18next';
import { FaChevronLeft, FaChevronRight } from 'react-icons/fa';
import { fluidSizing } from '../../utils/fluidSizing';

interface PaginationProps {
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  className?: string;
  ariaLabel?: string;
}

/**
 * Componente de paginación responsivo que muestra diferentes cantidades de páginas según el tamaño de pantalla
 */
const Pagination: React.FC<PaginationProps> = ({
  currentPage,
  totalPages,
  onPageChange,
  className = '',
  ariaLabel
}) => {
  const { t } = useTranslation('commonComponents');
  const resolvedAriaLabel = ariaLabel || t('pagination.ariaLabel');
  // No mostrar paginación si solo hay una página
  if (totalPages <= 1) return null;

  // Función para generar los números de página a mostrar
  const getPageNumbers = () => {
    // Validar que currentPage esté dentro de los límites válidos
    const validCurrentPage = Math.max(1, Math.min(currentPage, totalPages));
    
    const maxVisiblePages = 7;
    
    // Función para generar el array de páginas con ellipsis donde sea necesario
    const generatePagesArray = (maxPages: number) => {
      const pages = [];
      
      // Si hay pocas páginas, mostrar todas sin ellipsis
      if (totalPages <= maxPages) {
        for (let i = 1; i <= totalPages; i++) {
          pages.push(i);
        }
        return pages;
      }
      
      // Siempre mostrar la primera página
      pages.push(1);
      
      // Calcular el rango de páginas a mostrar alrededor de la página actual
      const sidePages = Math.floor((maxPages - 2) / 2); // Restamos 2 por primera y última página
      let startPage = Math.max(2, validCurrentPage - sidePages);
      let endPage = Math.min(totalPages - 1, validCurrentPage + sidePages);
      
      // Ajustar el rango si estamos cerca del inicio o del final
      if (validCurrentPage <= sidePages + 1) {
        // Cerca del inicio, mostrar más páginas al final
        endPage = Math.min(totalPages - 1, maxPages - 1);
      } else if (validCurrentPage >= totalPages - sidePages) {
        // Cerca del final, mostrar más páginas al inicio
        startPage = Math.max(2, totalPages - maxPages + 2);
      }
      
      // Añadir ellipsis al inicio si es necesario
      if (startPage > 2) {
        pages.push('ellipsis-start');
      }
      
      // Añadir páginas intermedias
      for (let i = startPage; i <= endPage; i++) {
        pages.push(i);
      }
      
      // Añadir ellipsis al final si es necesario
      if (endPage < totalPages - 1) {
        pages.push('ellipsis-end');
      }
      
      // Siempre mostrar la última página
      if (totalPages > 1) {
        pages.push(totalPages);
      }
      
      return pages;
    };
    
    return generatePagesArray(maxVisiblePages);
  };
  
  const pages = getPageNumbers();
  
  return (
    <nav 
      aria-label={resolvedAriaLabel}
      className={`pagination-container ${className}`}
      style={{ marginTop: fluidSizing.space.md, marginBottom: fluidSizing.space.sm }}
      data-testid="pagination"
    >
      {/* Información de página actual para lectores de pantalla */}
      <p className="sr-only" aria-live="polite">
        {t('pagination.pageOf', { current: currentPage, total: totalPages })}
      </p>
      
      <div className="flex justify-center items-center" style={{ gap: fluidSizing.space.xs }}>
        {/* Botón Anterior (oculto si estamos en la primera página) */}
        {currentPage > 1 && (
          <button
            onClick={(e) => {
              e.preventDefault();
              onPageChange(currentPage - 1);
            }}
            aria-label={t('pagination.previousPage')}
            className="relative inline-flex items-center justify-center rounded-md transition-all duration-200 z-10 bg-white border border-gray-300 hover:bg-secundario hover:border-secundario shadow-sm"
            style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
          >
            <FaChevronLeft 
              className="absolute text-primario" 
              aria-hidden="true" 
              style={{ display: 'block', width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }}
            />
          </button>
        )}
        
        {/* Números de página */}
        <div className="flex items-center" style={{ gap: fluidSizing.space.xs }} role="group" aria-label={t('pagination.desktopNav')}>
          {pages.map((item, index) => {
            // Renderizar ellipsis
            if (item === 'ellipsis-start' || item === 'ellipsis-end') {
              return (
                <span 
                  key={`${item}-${index}`} 
                  className="flex items-center justify-center text-gray-500"
                  style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm, fontSize: fluidSizing.text.sm }}
                  aria-hidden="true"
                >
                  &hellip;
                </span>
              );
            }
            
            // Renderizar botón de página
            const page = item as number;
            const isCurrentPage = currentPage === page;
            
            return (
              <button
                key={`page-${page}`}
                onClick={(e) => {
                  e.preventDefault();
                  if (!isCurrentPage) {
                    onPageChange(page);
                  }
                }}
                aria-label={t('pagination.goToPage', { page })}
                aria-current={isCurrentPage ? 'page' : undefined}
                className={`flex items-center justify-center rounded-md font-medium transition-all duration-200 ${
                  isCurrentPage 
                    ? 'bg-primario text-white border-2 border-primario shadow-md' 
                    : 'bg-white border border-gray-200 hover:border-primario text-gray-700 hover:text-primario'
                }`}
                style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm, fontSize: fluidSizing.text.sm }}
                disabled={isCurrentPage}
              >
                {page}
              </button>
            );
          })}
        </div>
        
        {/* Botón Siguiente (oculto si estamos en la última página) */}
        {currentPage < totalPages && (
          <button
            onClick={(e) => {
              e.preventDefault();
              onPageChange(currentPage + 1);
            }}
            aria-label={t('pagination.nextPage')}
            className="relative inline-flex items-center justify-center rounded-md transition-all duration-200 z-10 bg-white border border-gray-300 hover:bg-secundario hover:border-secundario shadow-sm"
            style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
          >
            <FaChevronRight 
              className="absolute text-primario" 
              aria-hidden="true" 
              style={{ display: 'block', width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }}
            />
          </button>
        )}
      </div>
    </nav>
  );
};

export default Pagination;