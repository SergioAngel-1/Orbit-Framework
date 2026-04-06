/**
 * CategoryDiscountCard - Tarjeta especial para mostrar el beneficio de descuento en variedades
 * Usa CollapsibleSection con variante default (gradiente)
 */

import { useTranslation } from 'react-i18next';
import { FiCheck, FiTag } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import { ActiveBenefit } from '../../services/membership/benefitsApiService';
import CollapsibleSection from '../common/CollapsibleSection';
import { transformClubText, transformClubTextFull } from '../../utils/clubNarrative';

interface CategoryDiscountCardProps {
  benefit: ActiveBenefit;
  defaultExpanded?: boolean;
}

const CategoryDiscountCard = ({ benefit, defaultExpanded = false }: CategoryDiscountCardProps) => {
  const { t } = useTranslation('membershipComponents');
  const categories = benefit.categories || [];
  const percentage = benefit.display_value?.replace('% de descuento', '') || '0';

  // Header extra: badge con el porcentaje
  const headerExtra = (
    <span 
      className="font-bold rounded-full"
      style={{ 
        fontSize: fluidSizing.text.xs,
        backgroundColor: 'white',
        color: '#C72C6C',
        paddingLeft: fluidSizing.space.sm,
        paddingRight: fluidSizing.space.sm,
        paddingTop: '2px',
        paddingBottom: '2px'
      }}
    >
      -{percentage}%
    </span>
  );

  return (
    <CollapsibleSection
      title={transformClubText(benefit.name)}
      subtitle={transformClubTextFull(benefit.description)}
      variant="default"
      collapsible={true}
      defaultExpanded={defaultExpanded}
      headerExtra={headerExtra}
    >
      {/* Lista de categorías */}
      <div 
        className="flex items-center text-texto mb-3"
        style={{ gap: fluidSizing.space.xs, fontSize: fluidSizing.text.xs }}
      >
        <FiTag style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
        <span>{t('categoryDiscount.varietiesWithDiscount')}</span>
      </div>
      
      <div className="flex flex-wrap" style={{ gap: fluidSizing.space.xs }}>
        {categories.map((category, index) => (
          <span
            key={index}
            className="inline-flex items-center bg-white border border-primario/30 text-oscuro rounded-full"
            style={{ 
              fontSize: fluidSizing.text.xs,
              paddingLeft: fluidSizing.space.sm,
              paddingRight: fluidSizing.space.sm,
              paddingTop: fluidSizing.space.xs,
              paddingBottom: fluidSizing.space.xs,
              gap: fluidSizing.space.xs
            }}
          >
            <FiCheck className="text-primario" style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
            {category}
          </span>
        ))}
      </div>
    </CollapsibleSection>
  );
};

export default CategoryDiscountCard;
