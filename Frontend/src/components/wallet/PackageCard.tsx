/**
 * PackageCard - Card seleccionable para un paquete de Virtual Coins
 * Diseño moderno con secciones claras para FC, bonus y precio
 */

import { FC } from 'react';
import { FiShoppingCart } from 'react-icons/fi';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { fluidSizing } from '../../utils/fluidSizing';
import { formatCurrency } from '../../utils/formatters';
import type { VirtualCoinsPackage } from '../../types/wompi';

interface PackageCardProps {
  /** Datos del paquete */
  package: VirtualCoinsPackage;
  /** Callback cuando se selecciona */
  onSelect: () => void;
  /** Si es membresía de antigüedad (estilo especial) */
  isLegacy?: boolean;
  /** Si el paquete está deshabilitado (nivel de membresía insuficiente) */
  disabled?: boolean;
  /** Clase CSS adicional */
  className?: string;
}

const PackageCard: FC<PackageCardProps> = ({
  package: pkg,
  onSelect,
  isLegacy = false,
  disabled = false,
  className = ''
}) => {
  const hasDiscount = pkg.is_on_sale && pkg.regular_price;
  
  // Calcular porcentaje de bonus sobre los coins base
  const bonusPercent = pkg.bonus > 0 && pkg.coins > 0
    ? Math.round((pkg.bonus / pkg.coins) * 100)
    : 0;

  return (
    <div
      className={`
        relative flex flex-col rounded-2xl w-full overflow-hidden
        transition-all duration-200 ease-out
        ${disabled 
          ? 'opacity-50 cursor-not-allowed grayscale' 
          : 'hover:shadow-xl hover:scale-[1.02]'
        }
        ${pkg.popular && !disabled
          ? 'ring-2 ring-primario shadow-lg shadow-primario/20'
          : 'shadow-md hover:shadow-primario/10'
        }
        ${isLegacy ? 'bg-gradient-to-r from-yellow-50 to-white' : 'bg-white'}
        ${className}
      `}
    >
      {/* Badge Porcentaje Bonus - Fucsia (esquina superior derecha) */}
      {bonusPercent > 0 && (
        <div 
          className="absolute top-1 right-1 bg-primario text-white font-bold whitespace-nowrap rounded-full shadow-md z-10"
          style={{ 
            fontSize: fluidSizing.text['2xs'],
            padding: `2px ${fluidSizing.space.xs}`
          }}
        >
          +{bonusPercent}%
        </div>
      )}

      {/* Contenido: Total FC destacado, con desglose si hay bonus */}
      <div 
        className="flex flex-col items-center text-center"
        style={{ padding: fluidSizing.space.sm }}
      >
        {/* Total de Virtual Coins */}
        <VirtualCoinPrice amount={pkg.total_coins} size="sm" showLabel />
        
        {/* Desglose: coins base + bonus (solo si hay bonus) */}
        {pkg.bonus > 0 && (
          <div 
            className="flex items-center text-texto/60"
            style={{ marginTop: fluidSizing.space.xs, fontSize: fluidSizing.text['2xs'] }}
          >
            <span>{pkg.coins.toLocaleString()}</span>
            <span className="mx-1">+</span>
            <span className="text-primario font-semibold">{pkg.bonus.toLocaleString()} bonus</span>
          </div>
        )}
        
        {/* Precio regular tachado (si hay descuento) */}
        {hasDiscount && (
          <div 
            className="text-texto/40 line-through font-medium"
            style={{ fontSize: fluidSizing.text['2xs'], marginTop: fluidSizing.space.xs }}
          >
            {formatCurrency(pkg.regular_price!)}
          </div>
        )}
      </div>

      {/* Footer/Botón: Precio final + Carrito */}
      <button
        onClick={disabled ? undefined : onSelect}
        disabled={disabled}
        className={`
          w-full flex items-center justify-center gap-1 bg-primario text-white font-semibold
          transition-colors
          ${disabled ? 'cursor-not-allowed' : 'hover:bg-hover active:scale-[0.98]'}
        `}
        style={{ 
          padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
          fontSize: fluidSizing.text.xs
        }}
      >
        <span className="font-bold">{formatCurrency(pkg.price)}</span>
        <FiShoppingCart className="flex-shrink-0" style={{ width: 14, height: 14 }} />
      </button>
    </div>
  );
};

export default PackageCard;
