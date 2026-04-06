/**
 * TourFAQSection - Sección de FAQ con acordeón
 * Preguntas frecuentes sobre los tours cannábicos
 */

import { FC, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiHelpCircle } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import CollapsibleSection from '../common/CollapsibleSection';
import './TourLanding.css';

interface FAQItem {
  id: number;
  question: string;
  answer: string;
  category?: string;
}

interface TourFAQSectionProps {
  /** Label superior */
  label?: string;
  /** Título principal */
  title?: string;
  /** Subtítulo descriptivo */
  subtitle?: string;
  /** FAQs personalizadas */
  faqs?: FAQItem[];
  /** Máximo de items a mostrar inicialmente */
  initialItems?: number;
  /** URL de la imagen lateral */
  imageUrl?: string;
}

// Default FAQs are loaded from translations - see getDefaultFaqs()

const TourFAQSection: FC<TourFAQSectionProps> = ({
  label,
  title,
  subtitle,
  faqs,
  initialItems = 4,
  imageUrl = new URL('./assets/landing-faq.webp', import.meta.url).href,
}) => {
  const { t } = useTranslation('tourSections');
  const [showAll, setShowAll] = useState(false);

  // Build default FAQs from translations
  const defaultFaqs: FAQItem[] = (t('faq.defaultFaqs', { returnObjects: true }) as any[]).map((faq: any, i: number) => ({
    id: i + 1,
    question: faq.question,
    answer: faq.answer,
    category: faq.category,
  }));

  const resolvedLabel = label || t('faq.label');
  const resolvedTitle = title || t('faq.title');
  const resolvedSubtitle = subtitle || t('faq.subtitle');
  const resolvedFaqs = faqs || defaultFaqs;

  const displayedFaqs = showAll ? resolvedFaqs : resolvedFaqs.slice(0, initialItems);

  return (
    <section
      className="relative overflow-hidden"
      style={{
        paddingTop: fluidSizing.space['2xl'],
        paddingBottom: fluidSizing.space['2xl'],
        paddingLeft: fluidSizing.space.lg,
        paddingRight: fluidSizing.space.lg,
      }}
    >
      <div
        style={{
          maxWidth: fluidSizing.layout.maxWidth,
          marginLeft: 'auto',
          marginRight: 'auto',
        }}
      >
        {/* Layout: 1 col mobile, 5 cols desktop */}
        <div className="grid grid-cols-1 lg:grid-cols-5 gap-8 items-start">
          {/* Columna izquierda: Header + Acordeón */}
          <div className="lg:col-span-3">
            {/* Header */}
            <div style={{ marginBottom: fluidSizing.space['2xl'] }}>
              <span
                className="inline-flex items-center gap-1.5 text-primario font-semibold uppercase tracking-wider"
                style={{
                  fontSize: fluidSizing.text.xs,
                  marginBottom: fluidSizing.space.sm,
                }}
              >
                <FiHelpCircle
                  style={{
                    width: fluidSizing.size.iconSm,
                    height: fluidSizing.size.iconSm,
                  }}
                />
                {resolvedLabel}
              </span>

              <h2
                className="font-bold text-oscuro leading-tight"
                style={{
                  fontSize: fluidSizing.text['4xl'],
                  marginBottom: fluidSizing.space.md,
                }}
              >
                {resolvedTitle}
              </h2>

              {/* Descripción: en mobile 2 cols (texto + imagen), en lg solo texto */}
              <div className="grid grid-cols-3 lg:grid-cols-1 gap-3 items-center">
                <p
                  className="col-span-2 lg:col-span-1 text-texto leading-relaxed opacity-85"
                  style={{ fontSize: fluidSizing.text.base }}
                >
                  {resolvedSubtitle}
                </p>
                <div className="col-span-1 lg:hidden flex justify-end">
                  <img
                    src={imageUrl}
                    alt={t('faq.imageAlt')}
                    className="w-full h-auto rounded-xl object-contain"
                    loading="lazy"
                  />
                </div>
              </div>
            </div>

            {/* Acordeón con CollapsibleSection */}
            <div className="space-y-3">
              {displayedFaqs.map((faq, index) => (
                <CollapsibleSection
                  key={faq.id}
                  title={faq.question}
                  subtitle={faq.category ? faq.category : undefined}
                  variant="soft"
                  defaultExpanded={index === 0}
                  collapsible={true}
                  className="hover:border-primario/30 hover:shadow-md transition-all duration-300"
                >
                  <p className="text-texto leading-relaxed" style={{ fontSize: fluidSizing.text.sm }}>
                    {faq.answer}
                  </p>
                </CollapsibleSection>
              ))}
            </div>

            {/* Botón mostrar más / mostrar menos */}
            {resolvedFaqs.length > initialItems && (
              <div style={{ marginTop: fluidSizing.space.lg }}>
                <button
                  onClick={() => setShowAll(!showAll)}
                  className="inline-flex items-center gap-2 text-primario hover:text-hover font-medium transition-colors duration-300"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {showAll
                    ? t('faq.showLess')
                    : t('faq.showMore', { count: resolvedFaqs.length - initialItems })
                  }
                </button>
              </div>
            )}

          </div>

          {/* Columna derecha: Imagen (solo desktop) */}
          <div className="lg:col-span-2 lg:sticky lg:top-24 hidden lg:flex items-center justify-end self-stretch">
            <img
              src={imageUrl}
              alt={t('faq.imageAlt')}
              className="h-auto rounded-2xl max-w-[85%]"
              loading="lazy"
            />
          </div>
        </div>
      </div>
    </section>
  );
};

export default TourFAQSection;
