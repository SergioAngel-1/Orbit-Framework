import React from 'react';
import Loader from '../ui/Loader';

/**
 * Componente para mostrar un indicador de carga durante el cambio de categoría
 */
const LoadingCategoryIndicator: React.FC = () => {
  return (
    <div className="py-8 text-center">
      <Loader size="small" />
    </div>
  );
};

export default LoadingCategoryIndicator;
