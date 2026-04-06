/**
 * MembershipPackageGroup - Contenedor agrupador de paquetes por nivel de membresía
 * Muestra el icono de membresía como header y los paquetes disponibles para ese nivel
 * Diseño con gradiente fucsia y cards blancas
 */

import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import MembershipBadge from '../common/MembershipBadge';
import PackageCard from './PackageCard';
import { fluidSizing } from '../../utils/fluidSizing';
import type { VirtualCoinsPackage } from '../../types/wompi';

interface MembershipPackageGroupProps {
  /** Nivel de membresía del grupo */
  level: number;
  /** Lista de paquetes para este nivel */
  packages: VirtualCoinsPackage[];
  /** Callback cuando se selecciona un paquete */
  onSelectPackage: (pkg: VirtualCoinsPackage) => void;
  /** Clase CSS adicional */
  className?: string;
}

const MembershipPackageGroup: FC<MembershipPackageGroupProps> = ({
  level,
  packages,
  onSelectPackage,
  className = ''
}) => {
  const { t } = useTranslation('walletComponents');
  // Ordenar paquetes de mayor a menor FC
  const sortedPackages = [...packages].sort((a, b) => b.total_coins - a.total_coins);
  
  // Membresía de Antigüedad (nivel 5) tiene estilo especial dorado
  const isLegacy = level === 5;
  
  // Colores según tipo de membresía
  const colors = isLegacy 
    ? {
        gradientStart: 'rgba(218, 165, 32, 0.15)', // Dorado
        gradientEnd: 'rgba(218, 165, 32, 0.05)',
        headerGradient: 'rgba(218, 165, 32, 0.1)',
        border: 'rgba(218, 165, 32, 0.3)',
        accent: '#DAA520'
      }
    : {
        gradientStart: 'rgba(199, 21, 133, 0.12)', // Fucsia primario
        gradientEnd: 'rgba(199, 21, 133, 0.04)',
        headerGradient: 'rgba(199, 21, 133, 0.08)',
        border: 'rgba(199, 21, 133, 0.2)',
        accent: '#C71585'
      };

  return (
    <div 
      className={`flex flex-col items-center rounded-2xl border overflow-hidden ${className}`}
      style={{ 
        minWidth: '180px',
        background: `linear-gradient(180deg, ${colors.gradientStart} 0%, ${colors.gradientEnd} 100%)`,
        borderColor: colors.border
      }}
    >
      {/* Header con icono de membresía */}
      <div 
        className="w-full flex flex-col items-center justify-center"
        style={{ 
          padding: fluidSizing.space.md,
          paddingBottom: fluidSizing.space.sm,
          background: `linear-gradient(180deg, ${colors.headerGradient} 0%, transparent 100%)`
        }}
      >
        {/* Badge especial para Antigüedad */}
        {isLegacy && (
          <div 
            className="text-white font-semibold rounded-full shadow-sm mb-2"
            style={{ 
              fontSize: fluidSizing.text['2xs'],
              padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
              background: 'linear-gradient(135deg, #DAA520 0%, #B8860B 100%)'
            }}
          >
            {t('membershipPackageGroup.exclusive')}
          </div>
        )}
        <div 
          className={`rounded-full shadow-md flex items-center justify-center ${isLegacy ? 'ring-2 ring-yellow-400/50' : ''}`}
          style={{ 
            width: fluidSizing.size.floatingButton,
            height: fluidSizing.size.floatingButton,
            padding: '4px',
            background: isLegacy ? 'linear-gradient(135deg, #FFFEF0 0%, #FFF8DC 100%)' : 'white'
          }}
        >
          <MembershipBadge 
            level={level} 
            size="lg" 
            disableModal 
          />
        </div>
      </div>
      
      {/* Paquetes */}
      <div 
        className="flex flex-col w-full"
        style={{ 
          padding: fluidSizing.space.md,
          paddingTop: fluidSizing.space.sm,
          gap: fluidSizing.space.md
        }}
      >
        {sortedPackages.map((pkg) => (
          <PackageCard
            key={pkg.id}
            package={pkg}
            onSelect={() => onSelectPackage(pkg)}
            isLegacy={isLegacy}
          />
        ))}
      </div>
    </div>
  );
};

export default MembershipPackageGroup;
