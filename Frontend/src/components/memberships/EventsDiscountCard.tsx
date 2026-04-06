/**
 * EventsDiscountCard - Tarjeta especial para mostrar el beneficio de descuento en eventos
 * Usa CollapsibleSection con variante personalizada para eventos
 */

import { useTranslation } from 'react-i18next';
import { FiCheck, FiCalendar } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import { ActiveBenefit } from '../../services/membership/benefitsApiService';
import CollapsibleSection from '../common/CollapsibleSection';

interface EventsDiscountCardProps {
  benefit: ActiveBenefit;
  defaultExpanded?: boolean;
}

const EventsDiscountCard = ({ benefit, defaultExpanded = false }: EventsDiscountCardProps) => {
  const { t } = useTranslation('membershipComponents');
  const categories = benefit.categories || [];
  const percentage = benefit.display_value?.replace('% de descuento', '').replace(/ en .*/, '') || '0';

  // Header extra: badge con el porcentaje
  const headerExtra = (
    <span 
      className="font-bold rounded-full"
      style={{ 
        fontSize: fluidSizing.text.xs,
        backgroundColor: 'white',
        color: '#C72C6C', // Fucsia oscuro
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
      title={benefit.name}
      subtitle={benefit.description}
      variant="default"
      collapsible={true}
      defaultExpanded={defaultExpanded}
      headerExtra={headerExtra}
    >
      {/* Lista de categorías de eventos */}
      <div 
        className="flex items-center text-texto mb-3"
        style={{ gap: fluidSizing.space.xs, fontSize: fluidSizing.text.xs }}
      >
        <FiCalendar style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
        <span>{t('eventsDiscount.eventTypes')}</span>
      </div>
      
      <div className="flex flex-wrap" style={{ gap: fluidSizing.space.xs }}>
        {categories.length > 0 ? (
          categories.map((category, index) => (
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
          ))
        ) : (
          <span className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>
            {t('eventsDiscount.noEvents')}
          </span>
        )}
      </div>
    </CollapsibleSection>
  );
};

export default EventsDiscountCard;
