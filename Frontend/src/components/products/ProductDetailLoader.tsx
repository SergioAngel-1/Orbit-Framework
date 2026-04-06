import React from 'react';
import { useTranslation } from 'react-i18next';
import Loader from '../ui/Loader';

/**
 * Componente de carga para la página de detalle del producto
 */
const ProductDetailLoader: React.FC = () => {
  const { t } = useTranslation('productDetailPage');

  return (
    <div className="container mx-auto px-4 py-16 flex justify-center items-center min-h-[60vh]">
      <Loader text={t('page.loading')} size="large" />
    </div>
  );
};

export default ProductDetailLoader;
