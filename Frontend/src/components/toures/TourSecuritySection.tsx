/**
 * TourSecuritySection - Sección de Seguridad y Legalidad
 * Transmite confianza con features sobre legalidad, seguridad y cumplimiento normativo
 * Incluye CTA a /marco-legal
 */

import { FC, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';
import { FiShield, FiUsers, FiAward, FiArrowRight, FiLock } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import CollapsibleSection from '../common/CollapsibleSection';
import './TourLanding.css';

interface SecurityFeature {
  icon: any;
  title: string;
  description: string;
  badge?: string;
}

interface TourSecuritySectionProps {
  /** Label superior */
  label?: string;
  /** Título principal */
  title?: string;
  /** Subtítulo descriptivo */
  subtitle?: string;
  /** Features personalizadas */
  features?: SecurityFeature[];
  /** Texto del CTA principal */
  primaryCtaText?: string;
  /** Texto del CTA secundario */
  secondaryCtaText?: string;
  /** URL del CTA principal */
  primaryCtaUrl?: string;
}

// Default features icons mapping (translations provide text, icons are static)
const FEATURE_ICONS = [FiShield, FiLock, FiUsers, FiAward];

const TourSecuritySection: FC<TourSecuritySectionProps> = ({
  label,
  title,
  subtitle,
  features,
  primaryCtaText,
  secondaryCtaText,
  primaryCtaUrl = '/marco-legal',
}) => {
  const { t } = useTranslation('tourSections');
  const { localizedPath } = useLanguage();

  // Build default features from translations
  const defaultFeatures: SecurityFeature[] = (t('security.defaultFeatures', { returnObjects: true }) as any[]).map((f: any, i: number) => ({
    icon: FEATURE_ICONS[i] || FiShield,
    title: f.title,
    description: f.description,
    badge: f.badge,
  }));

  const resolvedLabel = label || t('security.label');
  const resolvedTitle = title || t('security.title');
  const resolvedSubtitle = subtitle || t('security.subtitle');
  const resolvedFeatures = features || defaultFeatures;
  const resolvedPrimaryCta = primaryCtaText || t('security.primaryCta');
  const resolvedSecondaryCta = secondaryCtaText || t('security.secondaryCta');
  const sectionRef = useRef<HTMLDivElement>(null);
  const [isVisible, setIsVisible] = useState(false);

  // IntersectionObserver para animación de entrada
  useEffect(() => {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setIsVisible(true);
          observer.disconnect();
        }
      },
      { threshold: 0.1 }
    );

    if (sectionRef.current) {
      observer.observe(sectionRef.current);
    }

    return () => observer.disconnect();
  }, []);

  return (
    <section
      ref={sectionRef}
      className={`relative overflow-hidden tour-section-animate ${isVisible ? 'tour-section-animate--visible' : ''}`}
      style={{
        paddingTop: fluidSizing.space['2xl'],
        paddingBottom: fluidSizing.space['2xl'],
        paddingLeft: fluidSizing.space.lg,
        paddingRight: fluidSizing.space.lg,
      }}
    >
      {/* Fondo decorativo */}
      <div className="tour-security__bg-pattern absolute inset-0 pointer-events-none" />

      <div
        style={{
          maxWidth: fluidSizing.layout.maxWidth,
          marginLeft: 'auto',
          marginRight: 'auto',
        }}
      >
        {/* Header */}
        <div
          className="text-center max-w-2xl mx-auto"
          style={{ marginBottom: fluidSizing.space['3xl'] }}
        >
          <span
            className="inline-flex items-center gap-1.5 text-primario font-semibold uppercase tracking-wider"
            style={{
              fontSize: fluidSizing.text.xs,
              marginBottom: fluidSizing.space.sm,
            }}
          >
            <FiShield
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

          <p
            className="text-texto leading-relaxed opacity-85"
            style={{ fontSize: fluidSizing.text.base }}
          >
            {resolvedSubtitle}
          </p>
        </div>

        {/* Grid de features 2x2 en desktop, 1 col en mobile */}
        <div
          className="grid grid-cols-1 lg:grid-cols-2 max-w-4xl mx-auto"
          style={{ gap: fluidSizing.space.md, marginBottom: fluidSizing.space['3xl'] }}
        >
          {resolvedFeatures.map((feature, index) => {
            const Icon = feature.icon;
            return (
              <CollapsibleSection
                key={index}
                title={feature.title}
                icon={Icon}
                collapsible={false}
                showCollapseButton={false}
                variant="soft"
              >
                <p
                  className="text-texto leading-relaxed"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {feature.description}
                </p>
              </CollapsibleSection>
            );
          })}
        </div>

        {/* CTAs */}
        <div className="text-center">
          <div
            className="flex flex-col items-center gap-4"
          >
            <Link
              to={localizedPath(primaryCtaUrl)}
              className="inline-flex items-center gap-2 bg-gradient-to-r from-oscuro to-primario text-white hover:text-white font-medium rounded-md transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5"
              style={{
                paddingLeft: fluidSizing.space.xl,
                paddingRight: fluidSizing.space.xl,
                paddingTop: fluidSizing.space.sm,
                paddingBottom: fluidSizing.space.sm,
                fontSize: fluidSizing.text.base,
              }}
            >
              {resolvedPrimaryCta}
              <FiArrowRight
                style={{
                  width: fluidSizing.size.iconSm,
                  height: fluidSizing.size.iconSm,
                }}
              />
            </Link>

            <Link
              to={localizedPath('/privacidad')}
              className="inline-flex items-center gap-2 bg-transparent text-primario font-medium border border-primario/30 rounded-md transition-all duration-300 hover:bg-primario/5 hover:border-primario/50"
              style={{
                paddingLeft: fluidSizing.space.lg,
                paddingRight: fluidSizing.space.lg,
                paddingTop: fluidSizing.space.sm,
                paddingBottom: fluidSizing.space.sm,
                fontSize: fluidSizing.text.sm,
              }}
            >
              {resolvedSecondaryCta}
            </Link>
          </div>
        </div>
      </div>
    </section>
  );
};

export default TourSecuritySection;
