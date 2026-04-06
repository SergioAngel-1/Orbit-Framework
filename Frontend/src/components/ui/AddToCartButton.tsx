import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { gsap } from 'gsap';
import { Product } from '../../types/woocommerce';
import { useCart } from '../../contexts/CartContext';
import { useLanguage } from '../../contexts/LanguageContext';
import ScrollToTopLink from '../common/ScrollToTopLink';
import QuantityCounter from '../common/QuantityCounter';
import Loader from './Loader';

interface AddToCartButtonProps {
  product: Product;
  showQuantity?: boolean;
  buttonText?: string;
  className?: string;
  onAddToCart?: () => void;
}

const AddToCartButton = ({
  product,
  showQuantity = true,
  buttonText,
  className = '',
  onAddToCart
}: AddToCartButtonProps) => {
  const { t } = useTranslation('uiComponents');
  const { localizedPath } = useLanguage();
  const resolvedButtonText = buttonText || t('addToCart.defaultButton');
  const [quantity, setQuantity] = useState(1);
  const [adding, setAdding] = useState(false);
  const [added, setAdded] = useState(false);
  const { items, addItem, updateItemQuantity } = useCart();
  const existingItem = items.find((item) => item.product.id === product.id);

  const handleQuantityChange = (newQuantity: number) => {
    if (newQuantity >= 1) {
      setQuantity(newQuantity);
    }
  };

  const handleAddToCart = () => {
    setAdding(true);
    
    // Simulamos un pequeño retraso para la experiencia de usuario
    setTimeout(() => {
      // Verificar si el producto ya está en el carrito
      const existingItem = items.find((item) => item.product.id === product.id);
      
      if (existingItem) {
        // Si ya existe, SUMAR la cantidad nueva a la existente
        const newQuantity = existingItem.quantity + quantity;
        updateItemQuantity(product.id, newQuantity, undefined, true);
      } else {
        // Si no existe, añadir como nuevo
        addItem(product, quantity);
      }
      
      // La alerta ahora se maneja en el CartContext
      // alertService.success(`${product.name} agregado al carrito`);
      
      // Mostrar animación de éxito
      setAdding(false);
      setAdded(true);
      
      // Animar el botón con GSAP
      const button = document.querySelector(`.add-to-cart-btn-${product.id}`);
      if (button) {
        gsap.fromTo(
          button,
          { backgroundColor: '#8FD8B9' },
          { 
            backgroundColor: '#B91E59', 
            duration: 1,
            ease: 'power2.out'
          }
        );
      }
      
      // Llamar al callback si existe
      if (onAddToCart) {
        onAddToCart();
      }
      
      // Resetear el estado después de un tiempo
      setTimeout(() => {
        setAdded(false);
      }, 2000);
    }, 500);
  };

  return (
    <div className={`flex flex-col ${className}`}>
      {showQuantity && (
        <div className="flex items-center mb-3">
          <span className="text-sm text-gray-600 mr-3">{t('addToCart.quantity')}</span>
          {existingItem ? (
            <QuantityCounter
              productId={product.id}
              quantity={existingItem.quantity}
              size="md"
              maxQuantity={product.stock_quantity ?? undefined}
            />
          ) : (
            <QuantityCounter
              productId={product.id}
              quantity={quantity}
              size="md"
              onQuantityChange={handleQuantityChange}
              maxQuantity={product.stock_quantity ?? undefined}
            />
          )}
        </div>
      )}
      
      {!existingItem && (
        <button
          type="button"
          onClick={handleAddToCart}
          disabled={adding}
          className={`add-to-cart-btn-${product.id} bg-primario hover:bg-hover text-white py-2 px-4 rounded-md transition-colors duration-300 flex items-center justify-center ${className}`}
        >
          {adding ? (
            <span className="flex items-center">
              <Loader size="xsmall" text="" />
              <span className="ml-2">{t('addToCart.adding')}</span>
            </span>
          ) : added ? (
            <span className="flex items-center">
              <svg className="-ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
              {t('addToCart.added')}
            </span>
          ) : (
            <span className="flex items-center">
              <svg className="-ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
              {resolvedButtonText}
            </span>
          )}
        </button>
      )}
      
      {added && !existingItem && (
        <div className="mt-2 flex justify-center">
          <ScrollToTopLink 
            to={localizedPath('/reserva')} 
            className="text-sm text-primario hover:text-hover transition-colors"
          >
            {t('addToCart.viewCart')}
          </ScrollToTopLink>
        </div>
      )}
    </div>
  );
}

export default AddToCartButton;
