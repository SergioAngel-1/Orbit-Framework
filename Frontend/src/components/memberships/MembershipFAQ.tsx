/**
 * MembershipFAQ - Preguntas frecuentes sobre membresías
 * Componente acordeón con las preguntas más comunes
 * Usa CollapsibleSection y paleta de colores del tema
 */

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiMessageCircle, FiHelpCircle, FiStar, FiTrendingUp, FiDollarSign, FiXCircle, FiClock, FiAward } from 'react-icons/fi';
import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';
import { IconType } from 'react-icons';
import CollapsibleSection from '../common/CollapsibleSection';

interface FAQItem {
  questionKey: string;
  answerKey: string;
  icon: IconType;
}

const FAQ_ITEMS: FAQItem[] = [
  { questionKey: 'q1', answerKey: 'a1', icon: FiStar },
  { questionKey: 'q2', answerKey: 'a2', icon: FiTrendingUp },
  { questionKey: 'q3', answerKey: 'a3', icon: FiDollarSign },
  { questionKey: 'q4', answerKey: 'a4', icon: FiXCircle },
  { questionKey: 'q5', answerKey: 'a5', icon: FiClock },
  { questionKey: 'q6', answerKey: 'a6', icon: FiAward },
];

interface FAQItemComponentProps {
  item: FAQItem;
  index: number;
  isOpen: boolean;
  onToggle: (index: number) => void;
}

const FAQItemComponent = ({ item, index, isOpen, onToggle }: FAQItemComponentProps) => {
  const { t } = useTranslation('membershipComponents');
  return (
    <CollapsibleSection
      id={`faq-item-${index}`}
      title={t(`faq.${item.questionKey}`)}
      icon={item.icon}
      variant="soft"
      collapsible={true}
      expanded={isOpen}
      onExpandedChange={() => onToggle(index)}
      showCollapseButton={true}
    >
      <p className="text-texto leading-relaxed" style={{ fontSize: fluidSizing.text.sm }}>
        {t(`faq.${item.answerKey}`)}
      </p>
    </CollapsibleSection>
  );
};

interface MembershipFAQProps {
  /** Estado controlado externamente */
  expanded?: boolean;
  /** Callback cuando cambia el estado */
  onExpandedChange?: (expanded: boolean) => void;
}

const MembershipFAQ: React.FC<MembershipFAQProps> = ({ expanded, onExpandedChange }) => {
  const { t } = useTranslation('membershipComponents');
  const { isAuthenticated } = useAuth();
  const { localizedPath } = useLanguage();
  const [openIndex, setOpenIndex] = useState<number | null>(null);

  const toggleItem = (index: number) => {
    setOpenIndex(openIndex === index ? null : index);
  };

  return (
    <CollapsibleSection
      id="faq-section"
      title={t('faq.title')}
      subtitle={t('faq.subtitle')}
      icon={FiHelpCircle}
      defaultExpanded={!isAuthenticated}
      expanded={expanded}
      onExpandedChange={onExpandedChange}
      collapsible={isAuthenticated}
      className="mb-6"
    >
      {/* Lista de preguntas */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>
        {FAQ_ITEMS.map((item, index) => (
          <FAQItemComponent
            key={index}
            item={item}
            index={index}
            isOpen={openIndex === index}
            onToggle={toggleItem}
          />
        ))}
      </div>

      {/* Enlace a contacto */}
      <div className="border-t border-secundario/30" style={{ marginTop: fluidSizing.space.lg, paddingTop: fluidSizing.space.lg }}>
        <div 
          className="flex flex-col sm:flex-row items-center justify-center bg-secundario/20 rounded-lg" 
          style={{ gap: fluidSizing.space.md, padding: fluidSizing.space.lg }}
        >
          <div className="text-center sm:text-left">
            <p className="text-oscuro font-medium" style={{ fontSize: fluidSizing.text.sm }}>
              {t('faq.notFound')}
            </p>
            <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
              {t('faq.teamReady')}
            </p>
          </div>
          <Link 
            to={localizedPath('/contacto')} 
            className="inline-flex items-center bg-primario text-white rounded-md font-medium hover:bg-hover hover:text-white transition-colors"
            style={{ 
              gap: fluidSizing.space.xs, 
              paddingLeft: fluidSizing.space.lg, 
              paddingRight: fluidSizing.space.lg, 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm, 
              fontSize: fluidSizing.text.sm 
            }}
          >
            <FiMessageCircle style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            {t('faq.contactUs')}
          </Link>
        </div>
      </div>
    </CollapsibleSection>
  );
};

export default MembershipFAQ;
