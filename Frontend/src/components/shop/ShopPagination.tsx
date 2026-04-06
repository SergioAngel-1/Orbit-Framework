import React from 'react';
import Pagination from '../common/Pagination';
import logger from '../../utils/logger';

interface ShopPaginationProps {
  currentPage: number;
  totalPages: number;
  setSearchParams: (params: URLSearchParams, options?: { replace?: boolean }) => void;
  searchParams: URLSearchParams;
  setCurrentPage: (page: number) => void;
}

/**
 * Componente para manejar la paginación en la tienda
 */
const ShopPagination: React.FC<ShopPaginationProps> = ({
  currentPage,
  totalPages,
  setSearchParams,
  searchParams,
  setCurrentPage
}) => {
  /**
   * Maneja el cambio de página en la paginación
   * Actualiza los parámetros de la URL y el estado local
   */
  const handlePageChange = (page: number) => {
    // Validar que la página sea válida
    if (page < 1 || (totalPages > 0 && page > totalPages) || page === currentPage) {
      logger.warn('ShopPage', `Intento de cambio a página inválida: ${page}`);
      return;
    }

    logger.info('ShopPage', `Cambiando a página: ${page}`);
    
    try {
      // Actualizar el estado local primero
      setCurrentPage(page);
      
      // Crear una copia de los parámetros de búsqueda actuales
      const newParams = new URLSearchParams(searchParams.toString());
      
      // Añadir el parámetro de página solo si no es la página 1 (por defecto)
      if (page === 1) {
        newParams.delete('page');
      } else {
        newParams.set('page', page.toString());
      }
      
      // Actualizar los parámetros de búsqueda en la URL sin recargar la página
      // Usamos replace: true para evitar problemas con la navegación
      setSearchParams(newParams, { replace: true });
      
      // Hacer scroll al inicio de los productos con una mejor experiencia de usuario
      setTimeout(() => {
        const productGrid = document.querySelector('.product-grid');
        if (productGrid) {
          const topPosition = productGrid.getBoundingClientRect().top + window.scrollY - 100;
          window.scrollTo({
            top: topPosition,
            behavior: 'instant' // Usar 'instant' para evitar problemas visuales
          });
        }
      }, 10);
    } catch (error) {
      logger.error('ShopPage', 'Error al cambiar de página:', error);
      // En caso de error, asegurarnos de que al menos el estado local esté actualizado
      setCurrentPage(page);
    }
  };

  // No mostrar paginación si solo hay una página
  if (totalPages <= 1) return null;

  return (
    <Pagination 
      currentPage={currentPage} 
      totalPages={totalPages} 
      onPageChange={handlePageChange} 
    />
  );
};

export default ShopPagination;
