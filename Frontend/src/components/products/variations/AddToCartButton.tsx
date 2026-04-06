import React from 'react';
import { useTranslation } from 'react-i18next';
import { IoMdCart } from 'react-icons/io';
import Loader from '../../ui/Loader';

interface AddToCartButtonProps {
  onAddToCart: () => void;
  loading?: boolean;
  disabled?: boolean;
}

/**
 * Botón para agregar al carrito
 */
const AddToCartButton: React.FC<AddToCartButtonProps> = ({ 
  onAddToCart, 
  loading = false,
  disabled = false 
}) => {
  const { t } = useTranslation('uiComponents');
  return (
    <button
      onClick={onAddToCart}
      disabled={disabled || loading}
      className={`w-full bg-primario hover:bg-primario-dark text-white font-bold py-3 md:py-4 px-6 rounded-md transition-all flex items-center justify-center text-base md:text-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 ${
        (disabled || loading) ? 'opacity-50 cursor-not-allowed' : ''
      }`}
    >
      {loading ? (
        <Loader text={t('addToCart.adding')} size="small" />
      ) : (
        <>
          <IoMdCart className="h-7 w-7 md:h-8 md:w-8 mr-2" />
          {t('addToCart.reserveButton')}
        </>
      )}
    </button>
  );
};

export default AddToCartButton;
