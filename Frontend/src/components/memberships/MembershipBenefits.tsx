/**
 * MembershipBenefits - Sección de beneficios de membresía
 * Muestra los beneficios disponibles en cada nivel
 * Diseño consistente con el resto del sistema
 * 
 * Los datos de niveles se obtienen dinámicamente desde la API
 * para mantener consistencia con el backend.
 */

import { useState, useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCheck, FiX } from 'react-icons/fi';
import Loader from '../ui/Loader';
import useMembershipLevels from '../../hooks/useMembershipLevels';

// Beneficios estáticos como fallback (la configuración de beneficios podría venir de la API en el futuro)
// Benefit keys mapped to translation keys in membershipComponents.benefits.*
const STATIC_BENEFITS = [
  { key: 'points_multiplier', icon: '🌟', titleKey: 'pointsMultiplier', levels: [1, 2, 3, 4, 5] },
  { key: 'category_discount', icon: '💰', titleKey: 'categoryDiscount', levels: [1, 2, 3, 4, 5] },
  { key: 'exclusive_products', icon: '⭐', titleKey: 'exclusiveProducts', levels: [1, 2, 3, 4, 5] },
  { key: 'early_access', icon: '🚀', titleKey: 'earlyAccess', levels: [2, 3, 4, 5] },
  { key: 'birthday_bonus', icon: '🎂', titleKey: 'birthdayBonus', levels: [1, 2, 3, 4, 5] },
  { key: 'referral_bonus', icon: '👥', titleKey: 'referralBonus', levels: [1, 2, 3, 4, 5] },
  { key: 'priority_support', icon: '💬', titleKey: 'prioritySupport', levels: [2, 3, 4, 5] },
  { key: 'exclusive_events', icon: '🎉', titleKey: 'exclusiveEvents', levels: [3, 4, 5] },
  { key: 'gift_wrapping', icon: '🎁', titleKey: 'giftWrapping', levels: [3, 4, 5] },
  { key: 'extended_returns', icon: '🔄', titleKey: 'extendedReturns', levels: [2, 3, 4, 5] },
  { key: 'personal_advisor', icon: '👤', titleKey: 'personalAdvisor', levels: [4, 5] },
  { key: 'free_shipping_threshold', icon: '🚚', titleKey: 'freeShipping', levels: [1, 2, 3, 4, 5] },
];

interface MembershipBenefitsProps {
  highlightLevel?: number;
}

const MembershipBenefits = ({ highlightLevel = 0 }: MembershipBenefitsProps) => {
  const { t } = useTranslation('membershipComponents');
  const { levels, loading: levelsLoading } = useMembershipLevels();
  const [tabLoading, setTabLoading] = useState(false);
  const [activeTab, setActiveTab] = useState(highlightLevel);

  // Crear mapa de niveles para acceso rápido
  const levelInfoMap = useMemo(() => {
    const map: Record<number, { name: string; color: string; icon: string }> = {};
    levels.forEach(level => {
      map[level.id] = {
        name: level.name,
        color: level.color,
        icon: level.icon,
      };
    });
    return map;
  }, [levels]);

  // Actualizar tab activo cuando cambia highlightLevel
  useEffect(() => {
    if (highlightLevel !== undefined) {
      setActiveTab(highlightLevel);
    }
  }, [highlightLevel]);

  // Simular carga al cambiar de tab (para UX consistente)
  useEffect(() => {
    if (activeTab > 0) {
      setTabLoading(true);
      const timer = setTimeout(() => setTabLoading(false), 300);
      return () => clearTimeout(timer);
    }
  }, [activeTab]);

  // Mostrar loader mientras se cargan los niveles
  const loading = levelsLoading || tabLoading;

  return (
    <section className="mb-6">
      {/* Título de sección */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-100 p-4 mb-4">
        <h2 className="text-lg font-semibold text-oscuro border-b pb-2">
          {t('benefits.title')}
        </h2>
      </div>

      {/* Tabs de niveles */}
      <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
        <div className="flex overflow-x-auto border-b border-gray-100">
          {levels.map((levelData) => {
            const info = levelInfoMap[levelData.id];
            if (!info) return null;
            return (
              <button
                key={levelData.id}
                onClick={() => setActiveTab(levelData.id)}
                className={`
                  flex items-center gap-1.5 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors
                  ${activeTab === levelData.id 
                    ? 'border-b-2 text-primario bg-primario/5' 
                    : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'
                  }
                `}
                style={activeTab === levelData.id ? { borderBottomColor: info.color } : {}}
              >
                <span>{info.icon}</span>
                <span className="hidden sm:inline">{info.name}</span>
              </button>
            );
          })}
        </div>

        {/* Contenido del tab */}
        <div className="p-4">
          {loading ? (
            <div className="flex justify-center py-8">
              <Loader text={t('levelCard.loadingBenefits')} />
            </div>
          ) : activeTab === 0 ? (
            <div className="text-center py-8">
              <span className="text-4xl mb-3 block">🥕</span>
              <p className="text-gray-600 text-sm">
                {t('benefits.zanahoriDesc')}
              </p>
              <p className="text-gray-500 text-xs mt-2">
                {t('benefits.zanahoriUpgrade')}
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
              {STATIC_BENEFITS.map((benefit) => {
                const hasAccess = benefit.levels.includes(activeTab);
                return (
                  <div
                    key={benefit.key}
                    className={`
                      flex items-center gap-3 p-3 rounded-lg border transition-colors
                      ${hasAccess 
                        ? 'bg-green-50 border-green-200' 
                        : 'bg-gray-50 border-gray-200 opacity-60'
                      }
                    `}
                  >
                    <span className="text-xl flex-shrink-0">{benefit.icon}</span>
                    <div className="flex-1 min-w-0">
                      <p className={`text-sm font-medium truncate ${hasAccess ? 'text-gray-900' : 'text-gray-500'}`}>
                        {t(`benefits.${benefit.titleKey}`)}
                      </p>
                    </div>
                    {hasAccess ? (
                      <FiCheck className="w-4 h-4 text-green-600 flex-shrink-0" />
                    ) : (
                      <FiX className="w-4 h-4 text-gray-400 flex-shrink-0" />
                    )}
                  </div>
                );
              })}
            </div>
          )}

          {/* Leyenda */}
          {activeTab > 0 && (
            <div className="flex items-center justify-center gap-4 mt-4 pt-4 border-t border-gray-100">
              <div className="flex items-center gap-1.5 text-xs text-gray-500">
                <FiCheck className="w-3.5 h-3.5 text-green-600" />
                <span>{t('benefits.included')}</span>
              </div>
              <div className="flex items-center gap-1.5 text-xs text-gray-500">
                <FiX className="w-3.5 h-3.5 text-gray-400" />
                <span>{t('benefits.notIncluded')}</span>
              </div>
            </div>
          )}
        </div>
      </div>
    </section>
  );
};

export default MembershipBenefits;
