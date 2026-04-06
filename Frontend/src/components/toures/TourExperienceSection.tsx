/**
 * TourExperienceSection - Sección "La Experiencia 360°"
 * Layout de 2 columnas: features de la experiencia | imagen
 * Incluye animación de entrada con IntersectionObserver
 */

import { FC, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconType } from 'react-icons';
import { FiSun, FiBookOpen, FiCoffee, FiUsers, FiCamera, FiHeart, FiArrowDown } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import BannerCarousel from '../home/BannerCarousel';
import './TourLanding.css';

interface ExperienceFeature {
  icon: IconType;
  title: string;
  description: string;
}

interface TourExperienceSectionProps {
  /** Label superior de la sección */
  label?: string;
  /** Título principal */
  title?: string;
  /** Descripción introductoria */
  description?: string;
  /** Features personalizados (override los defaults) */
  features?: ExperienceFeature[];
}

const FEATURE_ICONS: IconType[] = [FiSun, FiBookOpen, FiCoffee, FiUsers, FiCamera, FiHeart];

const TourExperienceSection: FC<TourExperienceSectionProps> = ({
  label,
  title,
  description,
  features,
}) => {
  const { t } = useTranslation('toures');
  const sectionRef = useRef<HTMLDivElement>(null);
  const [isVisible, setIsVisible] = useState(false);

  const translatedFeatures = useMemo(() => {
    if (features) return features;
    const featureTranslations = t('experience.features', { returnObjects: true }) as Array<{ title: string; description: string }>;
    return FEATURE_ICONS.map((icon, index) => {
      const fallbackTitle = t(`experience.features.${index}.title`, {
        defaultValue: featureTranslations?.[index]?.title,
      }) as string;
      const fallbackDesc = t(`experience.features.${index}.description`, {
        defaultValue: featureTranslations?.[index]?.description,
      }) as string;
      return {
        icon,
        title: fallbackTitle,
        description: fallbackDesc,
      } as ExperienceFeature;
    });
  }, [features, t]);

  const resolvedLabel = label ?? (t('experience.label') as string);
  const resolvedTitle = title ?? (t('experience.title') as string);
  const resolvedDescription = description ?? (t('experience.description') as string);

  useEffect(() => {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setIsVisible(true);
          observer.disconnect();
        }
      },
      { threshold: 0.15 }
    );

    if (sectionRef.current) {
      observer.observe(sectionRef.current);
    }

    return () => observer.disconnect();
  }, []);

  return (
    <section
      ref={sectionRef}
      className="relative overflow-hidden"
      style={{
        paddingTop: fluidSizing.space['4xl'],
        paddingBottom: fluidSizing.space['4xl'],
        paddingLeft: fluidSizing.space.lg,
        paddingRight: fluidSizing.space.lg,
      }}
    >
      {/* Acentos decorativos de fondo */}
      <div className="tour-experience__bg-accent tour-experience__bg-accent--top absolute w-96 h-96 rounded-full pointer-events-none z-0" 
        style={{ top: '-120px', right: '-100px' }} />
      <div className="tour-experience__bg-accent tour-experience__bg-accent--bottom absolute w-96 h-96 rounded-full pointer-events-none z-0" 
        style={{ bottom: '-120px', left: '-100px' }} />

      {/* Carousel image element (shared, rendered in different positions per breakpoint) */}
      {(() => {
        const carouselElement = (
          <div
            className={`relative flex items-center justify-center tour-section-animate tour-section-animate--delay-1 ${isVisible ? 'tour-section-animate--visible' : ''}`}
          >
            <div
              className="relative rounded-2xl overflow-hidden experience-carousel-container"
              style={{ width: '100%' }}
            >
              <style>{`
                .experience-carousel-container {
                  aspect-ratio: 4/3;
                }
                @media (min-width: 1024px) {
                  .experience-carousel-container {
                    aspect-ratio: 3/4;
                  }
                }
                .experience-carousel-container img {
                  object-fit: contain !important;
                  object-position: center center !important;
                  border-radius: 1rem !important;
                }
              `}</style>
              <BannerCarousel bannerType="experience_toures" className="rounded-2xl" tall ctaIcon={<FiArrowDown className="ml-2 h-4 w-4 transition-transform group-hover:translate-y-0.5" />} />
            </div>
          </div>
        );

        const renderFeatureCard = (feature: ExperienceFeature, index: number) => {
          const Icon = feature.icon;
          return (
            <div
              key={index}
              className="relative bg-white rounded-lg border border-secundario/25 transition-all duration-300 hover:border-primario/20 hover:shadow-lg hover:-translate-y-1"
            >
              <div
                className="flex flex-col"
                style={{ padding: fluidSizing.space.sm }}
              >
                {/* Icono badge en esquina superior derecha */}
                <div
                  className="absolute top-0 right-0 flex items-center justify-center rounded-bl-lg rounded-tr-lg bg-gradient-to-br from-primario/10 to-secundario/30 text-primario"
                  style={{
                    width: fluidSizing.size.iconLg,
                    height: fluidSizing.size.iconLg,
                  }}
                >
                  <Icon style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
                </div>
                <h3
                  className="font-semibold text-oscuro leading-tight pr-8 text-xs sm:text-sm"
                  style={{
                    marginBottom: fluidSizing.space.xs,
                  }}
                >
                  {feature.title}
                </h3>
                <p
                  className="text-texto leading-snug opacity-80 text-[11px] sm:text-xs"
                >
                  {feature.description}
                </p>
              </div>
            </div>
          );
        };

        const firstFeatures = translatedFeatures.slice(0, 4);
        const lastFeatures = translatedFeatures.slice(4);

        return (
          <>
            {/* ═══ MOBILE: texto → 4 features → imagen → 2 features ═══ */}
            <div className="flex flex-col lg:hidden relative z-1" style={{ maxWidth: fluidSizing.layout.maxWidth, marginLeft: 'auto', marginRight: 'auto' }}>
              <div className={`flex flex-col tour-section-animate ${isVisible ? 'tour-section-animate--visible' : ''}`}>
                {/* Label */}
                <span
                  className="inline-flex items-center gap-1.5 text-primario font-semibold uppercase tracking-wider"
                  style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.sm }}
                >
                  <FiSun style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  {resolvedLabel}
                </span>

                {/* Título */}
                <h2
                  className="font-bold text-oscuro leading-tight"
                  style={{ fontSize: fluidSizing.text['4xl'], marginBottom: fluidSizing.space.md }}
                >
                  {resolvedTitle}
                </h2>

                {/* Descripción */}
                <p
                  className="text-texto leading-relaxed"
                  style={{ fontSize: fluidSizing.text.base, marginBottom: fluidSizing.space.xl }}
                >
                  {resolvedDescription}
                </p>

                {/* First 4 features */}
                <div className="grid grid-cols-2" style={{ gap: fluidSizing.space.md, marginBottom: fluidSizing.space.md }}>
                  {firstFeatures.map((f, i) => renderFeatureCard(f, i))}
                </div>

                {/* Carousel image between rows */}
                <div style={{ marginBottom: fluidSizing.space.md }}>
                  {carouselElement}
                </div>

                {/* Last 2 features */}
                {lastFeatures.length > 0 && (
                  <div className="grid grid-cols-2" style={{ gap: fluidSizing.space.md }}>
                    {lastFeatures.map((f, i) => renderFeatureCard(f, i + 4))}
                  </div>
                )}
              </div>
            </div>

            {/* ═══ DESKTOP: imagen izquierda | contenido derecha (sin cambios) ═══ */}
            <div
              className="hidden lg:grid lg:grid-cols-3 gap-12 items-stretch relative z-1"
              style={{ maxWidth: fluidSizing.layout.maxWidth, marginLeft: 'auto', marginRight: 'auto' }}
            >
              {/* Columna izquierda: Carrusel */}
              {carouselElement}

              {/* Columna derecha: Contenido */}
              <div className={`lg:col-span-2 flex flex-col tour-section-animate ${isVisible ? 'tour-section-animate--visible' : ''}`}>
                {/* Label */}
                <span
                  className="inline-flex items-center gap-1.5 text-primario font-semibold uppercase tracking-wider"
                  style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.sm }}
                >
                  <FiSun style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                  {resolvedLabel}
                </span>

                {/* Título */}
                <h2
                  className="font-bold text-oscuro leading-tight"
                  style={{ fontSize: fluidSizing.text['4xl'], marginBottom: fluidSizing.space.md }}
                >
                  {resolvedTitle}
                </h2>

                {/* Descripción */}
                <p
                  className="text-texto leading-relaxed"
                  style={{ fontSize: fluidSizing.text.base, marginBottom: fluidSizing.space.xl }}
                >
                  {resolvedDescription}
                </p>

                {/* Grid de features */}
                <div className="grid grid-cols-2" style={{ gap: fluidSizing.space.md }}>
                  {translatedFeatures.map((f, i) => renderFeatureCard(f, i))}
                </div>
              </div>
            </div>
          </>
        );
      })()}
    </section>
  );
};

export default TourExperienceSection;
