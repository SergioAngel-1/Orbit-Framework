import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiPlus, FiMinus, FiTrash2 } from 'react-icons/fi';
import { useCart } from '../../contexts/CartContext';
import Loader from '../ui/Loader';

interface QuantityCounterProps {
  productId: number;
  quantity: number;
  productName?: string; // Mantenemos esta propiedad para compatibilidad con componentes existentes
  size?: 'sm' | 'md' | 'lg';
  showRemoveButton?: boolean;
  className?: string;
  onQuantityChange?: (newQuantity: number) => void;
  orientation?: 'horizontal' | 'vertical'; // Nueva propiedad para controlar la orientación
  variationId?: number;
  maxQuantity?: number; // Cantidad máxima permitida (ej: stock disponible)
}

const QuantityCounter: React.FC<QuantityCounterProps> = ({
  productId,
  quantity,
  productName: _productName = '', // Prefijo _ indica que es un parámetro no utilizado
  size = 'md',
  showRemoveButton = false,
  className = '',
  onQuantityChange,
  orientation = 'horizontal', // Por defecto, mantener la orientación horizontal
  variationId,
  maxQuantity // undefined = sin límite
}) => {
  const { t } = useTranslation('quantityCounter');
  const { updateItemQuantity, removeItem } = useCart();
  const [loading, setLoading] = useState(false);

  // Tamaños predefinidos para los botones y el texto
  const sizeClasses = {
    sm: {
      container: 'text-xs',
      button: 'p-1 min-w-[28px]',
      icon: 'w-3 h-3',
      text: 'px-1.5 min-w-[20px] flex-1 flex justify-center'
    },
    md: {
      container: 'text-sm',
      button: 'p-1.5 min-w-[32px]',
      icon: 'w-4 h-4',
      text: 'px-2 min-w-[24px] flex-1 flex justify-center'
    },
    lg: {
      container: 'text-base',
      button: 'p-2 min-w-[36px]',
      icon: 'w-5 h-5',
      text: 'px-3 min-w-[28px] flex-1 flex justify-center'
    }
  };

  const handleDecrease = async (e: React.MouseEvent) => {
    // Prevenir la propagación del evento para evitar redirecciones
    e.stopPropagation();
    e.preventDefault();
    
    const newQuantity = quantity - 1;
    
    // Si la cantidad es 1 y se decrementa, eliminar el producto
    if (newQuantity < 1) {
      return;
    }
    
    if (onQuantityChange) {
      onQuantityChange(newQuantity);
    } else {
      // Usar el parámetro showAlert para que el CartContext maneje las alertas
      try {
        setLoading(true);
        await updateItemQuantity(productId, newQuantity, variationId, true);
      } finally {
        setLoading(false);
      }
    }
  };

  const handleIncrease = async (e: React.MouseEvent) => {
    // Prevenir la propagación del evento para evitar redirecciones
    e.stopPropagation();
    e.preventDefault();
    
    // Validar contra stock máximo si está definido
    if (maxQuantity !== undefined && quantity >= maxQuantity) {
      return; // No permitir incrementar más allá del stock
    }
    
    const newQuantity = quantity + 1;
    
    if (onQuantityChange) {
      onQuantityChange(newQuantity);
    } else {
      // Usar el parámetro showAlert para que el CartContext maneje las alertas
      try {
        setLoading(true);
        await updateItemQuantity(productId, newQuantity, variationId, true);
      } finally {
        setLoading(false);
      }
    }
  };

  const handleRemove = async (e: React.MouseEvent) => {
    // Prevenir la propagación del evento para evitar redirecciones
    e.stopPropagation();
    e.preventDefault();
    
    if (onQuantityChange) {
      onQuantityChange(0);
    } else {
      // Eliminar el producto y dejar que el CartContext maneje la alerta
      try {
        setLoading(true);
        await removeItem(productId, variationId);
      } finally {
        setLoading(false);
      }
    }
  };

  return (
    <div className={`flex ${orientation === 'vertical' ? 'flex-col' : 'items-center'} ${className} ${sizeClasses[size].container} w-full`}>
      <div className={`relative flex ${orientation === 'vertical' ? 'flex-col' : 'items-center'} border rounded-md overflow-hidden w-full`}>
        {/* Mantenemos el mismo orden lógico en ambas orientaciones: primero - luego cantidad luego + */}
        <button 
          onClick={quantity === 1 && !onQuantityChange ? handleRemove : handleDecrease}
          disabled={loading || (quantity <= 1 && !!onQuantityChange)}
          className={`${
            quantity === 1 && !onQuantityChange
              ? 'bg-red-50 hover:bg-red-100 text-red-600' 
              : 'bg-primario/10 hover:bg-primario/20 text-primario'
          } transition-colors ${sizeClasses[size].button} flex items-center justify-center ${orientation === 'vertical' ? 'w-full' : ''} disabled:opacity-50 disabled:cursor-not-allowed`}
          aria-label={quantity === 1 && !onQuantityChange ? t('aria.removeProduct') : t('aria.decrease')}
        >
          {quantity === 1 && !onQuantityChange ? (
            <FiTrash2 className={sizeClasses[size].icon} />
          ) : (
            <FiMinus className={sizeClasses[size].icon} />
          )}
        </button>
        <span className={`text-center font-medium ${sizeClasses[size].text} ${orientation === 'vertical' ? 'py-1' : ''}`}>
          {quantity}
        </span>
        <button 
          onClick={handleIncrease}
          disabled={loading || (maxQuantity !== undefined && quantity >= maxQuantity)}
          className={`bg-primario/10 hover:bg-primario/20 text-primario transition-colors ${sizeClasses[size].button} flex items-center justify-center ${orientation === 'vertical' ? 'w-full' : ''} disabled:opacity-50 disabled:cursor-not-allowed`}
          aria-label={t('aria.increase')}
        >
          <FiPlus className={sizeClasses[size].icon} />
        </button>
        {loading && (
          <div className="absolute inset-0 bg-white/50 flex items-center justify-center">
            <Loader size="xsmall" text="" />
          </div>
        )}
      </div>
      
      {showRemoveButton && (
        <button 
          onClick={handleRemove}
          disabled={loading}
          className={`${orientation === 'vertical' ? 'mt-2' : 'ml-2'} text-red-600 hover:text-red-500 transition-colors flex items-center`}
          aria-label={t('aria.removeProduct')}
        >
          <span className="text-xs">{t('buttons.remove')}</span>
        </button>
      )}
      
      {/* Indicador de stock máximo alcanzado */}
      {maxQuantity !== undefined && quantity >= maxQuantity && (
        <div className={`text-amber-600 text-2xs font-medium ${orientation === 'vertical' ? 'mt-1 text-center' : 'ml-2'}`}>
          {t('messages.maxStock')}
        </div>
      )}
    </div>
  );
};

export default QuantityCounter;
