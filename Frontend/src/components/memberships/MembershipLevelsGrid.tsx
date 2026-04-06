/**
 * MembershipLevelsGrid - Grid de niveles de membresía
 * Muestra todos los niveles disponibles en un grid responsivo
 */

import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useMembership } from '../../contexts/MembershipContext';
import { getMembershipLevels, getLevelBenefits } from '../../services/membership/membershipApiService';
import { MembershipLevel, MembershipBenefit } from '../../services/membership/membershipTypes';
import MembershipLevelCard from './MembershipLevelCard';
import Loader from '../ui/Loader';
import { fluidSizing } from '../../utils/fluidSizing';

interface MembershipLevelsGridProps {
  onLevelSelect?: (level: MembershipLevel) => void;
  showOnlyUpgrades?: boolean;
}

const MembershipLevelsGrid = ({ 
  onLevelSelect,
  showOnlyUpgrades = false
}: MembershipLevelsGridProps) => {
  const { t } = useTranslation('membershipsPage');
  const { currentLevel, membership } = useMembership();
  const [levels, setLevels] = useState<MembershipLevel[]>([]);
  const [benefitsByLevel, setBenefitsByLevel] = useState<Record<number, MembershipBenefit[]>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const loadLevelsAndBenefits = async () => {
      try {
        setLoading(true);
        const data = await getMembershipLevels();
        
        // Filtrar solo upgrades si se solicita
        const filteredLevels = showOnlyUpgrades 
          ? data.filter(l => (l.level !== undefined ? l.level : l.id) > currentLevel)
          : data;
        
        setLevels(filteredLevels);
        
        // Cargar beneficios para cada nivel en paralelo
        const benefitsPromises = filteredLevels.map(async (level) => {
          const levelNum = level.level !== undefined ? level.level : level.id;
          try {
            const benefits = await getLevelBenefits(levelNum);
            return { level: levelNum, benefits };
          } catch {
            return { level: levelNum, benefits: [] };
          }
        });
        
        const benefitsResults = await Promise.all(benefitsPromises);
        const benefitsMap: Record<number, MembershipBenefit[]> = {};
        benefitsResults.forEach(({ level, benefits }) => {
          benefitsMap[level] = benefits;
        });
        setBenefitsByLevel(benefitsMap);
      } catch (err) {
        setError(t('levelsGrid.error'));
      } finally {
        setLoading(false);
      }
    };

    loadLevelsAndBenefits();
  }, [currentLevel, showOnlyUpgrades]);

  if (loading) {
    return (
      <div className="flex justify-center" style={{ paddingTop: fluidSizing.space['3xl'], paddingBottom: fluidSizing.space['3xl'] }}>
        <Loader text={t('levelsGrid.loading')} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-center" style={{ paddingTop: fluidSizing.space['3xl'], paddingBottom: fluidSizing.space['3xl'] }}>
        <p className="text-red-500" style={{ fontSize: fluidSizing.text.base }}>{error}</p>
      </div>
    );
  }

  if (levels.length === 0) {
    return (
      <div className="text-center" style={{ paddingTop: fluidSizing.space['3xl'], paddingBottom: fluidSizing.space['3xl'] }}>
        <p className="text-gray-500" style={{ fontSize: fluidSizing.text.base }}>{t('levelsGrid.empty')}</p>
      </div>
    );
  }

  // Función para verificar si un nivel es el actual del usuario
  // Compara por level Y por slug/name para mayor robustez
  const isCurrentUserLevel = (level: MembershipLevel): boolean => {
    // Comparación por nivel numérico
    const levelMatch = Number(level.level) === Number(currentLevel);
    
    // Comparación por slug (más confiable)
    const slugMatch = !!(membership?.slug && level.slug && 
      membership.slug.toLowerCase() === level.slug.toLowerCase());
    
    // Comparación por nombre
    const nameMatch = !!(membership?.name && level.name && 
      membership.name.toLowerCase() === level.name.toLowerCase());
    
    return levelMatch || slugMatch || nameMatch;
  };

  return (
    <section>
      {/* Grid de tarjetas con beneficios integrados */}
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3" style={{ gap: fluidSizing.space.lg }}>
        {levels.map((level) => {
          const levelNum = level.level !== undefined ? level.level : level.id;
          return (
            <MembershipLevelCard
              key={level.id}
              level={level}
              benefits={benefitsByLevel[levelNum] || []}
              isCurrentLevel={isCurrentUserLevel(level)}
              isUpgrade={(level.level !== undefined ? Number(level.level) : Number(level.id)) > Number(currentLevel)}
              onSelect={onLevelSelect}
              showBenefits={true}
            />
          );
        })}
      </div>
    </section>
  );
};

export default MembershipLevelsGrid;
