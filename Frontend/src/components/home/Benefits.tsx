import React from 'react';
import { FaTruck, FaLock, FaHeadset, FaMoneyBillWave } from 'react-icons/fa';
import { IconType } from 'react-icons';
import { useTranslation } from 'react-i18next';
import { fluidSizing } from '../../utils/fluidSizing';

type BenefitKey = 'shipping' | 'secure' | 'support' | 'quality';

const iconMap: Record<BenefitKey, IconType> = {
  shipping: FaTruck,
  secure: FaLock,
  support: FaHeadset,
  quality: FaMoneyBillWave
};

const benefitOrder: BenefitKey[] = ['shipping', 'secure', 'support', 'quality'];

const Benefits: React.FC = () => {
  const { t } = useTranslation('homeBenefits');
  const localizedBenefits = benefitOrder.map((key) => ({
    id: key,
    icon: iconMap[key],
    title: t(`items.${key}.title`),
    description: t(`items.${key}.description`)
  }));

  return (
    <section 
      className="bg-white"
      style={{ paddingTop: fluidSizing.space['2xl'], paddingBottom: fluidSizing.space['2xl'] }}
    >
      <div className="container mx-auto" style={{ paddingLeft: fluidSizing.space.md, paddingRight: fluidSizing.space.md }}>
        {/* Header */}
        <div className="max-w-3xl mx-auto text-center" style={{ marginBottom: fluidSizing.space.xl }}>
          <h2 
            className="font-bold text-primario"
            style={{ fontSize: fluidSizing.text['3xl'], marginBottom: fluidSizing.space.sm }}
          >
            {t('title')}
          </h2>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.base }}>
            {t('subtitle')}
          </p>
          <div className="w-24 h-1 bg-gradient-to-r from-primario to-primario-light mx-auto mt-6 rounded-full" />
        </div>
        
        {/* Grid de beneficios */}
        <div 
          className="grid grid-cols-2 lg:grid-cols-4 max-w-6xl mx-auto"
          style={{ gap: fluidSizing.space.md }}
        >
          {localizedBenefits.map((benefit) => {
            const IconComponent = benefit.icon;
            return (
              <div 
                key={benefit.id} 
                className="flex flex-col items-center text-center bg-white rounded-lg border border-secundario/30 hover:border-primario/30 hover:shadow-md transition-all duration-300 group"
                style={{ padding: fluidSizing.space.lg }}
              >
                <div 
                  className="bg-primario/10 rounded-full flex items-center justify-center group-hover:bg-primario/20 transition-colors"
                  style={{ 
                    width: fluidSizing.size.floatingButton, 
                    height: fluidSizing.size.floatingButton,
                    marginBottom: fluidSizing.space.md
                  }}
                >
                  <IconComponent 
                    className="text-primario" 
                    style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg }} 
                  />
                </div>
                <h3 
                  className="font-semibold text-oscuro"
                  style={{ fontSize: fluidSizing.text.base, marginBottom: fluidSizing.space.xs }}
                >
                  {benefit.title}
                </h3>
                <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                  {benefit.description}
                </p>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
};

export default Benefits;
