/**
 * TourHeroSection - Banner hero de lado a lado para la landing de Toures Cannábicos
 * 
 * Usa BannerCarousel en modo autónomo con bannerType='landing_toures'.
 * El carrusel se encarga de obtener sus propios datos desde la API.
 * Si no hay banners configurados en el admin, muestra el hero estático como fallback.
 */

import { FC } from 'react';
import { useTranslation } from 'react-i18next';
import { FiChevronDown, FiMapPin, FiCalendar, FiArrowDown } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import BannerCarousel from '../home/BannerCarousel';
import './TourLanding.css';

interface TourHeroSectionProps {
  /** URL de la imagen de fondo del hero (fallback estático) */
  imageUrl?: string;
  /** Texto alternativo para la imagen */
  imageAlt?: string;
  /** Título principal del hero */
  title?: string;
  /** Subtítulo descriptivo */
  subtitle?: string;
  /** Texto del badge superior */
  badgeText?: string;
  /** Texto del botón primario */
  primaryCtaText?: string;
  /** Texto del botón secundario */
  secondaryCtaText?: string;
  /** Callback al hacer click en el CTA primario (scroll a paquetes) */
  onPrimaryClick?: () => void;
  /** Callback al hacer click en el CTA secundario */
  onSecondaryClick?: () => void;
  /** Mostrar indicador de scroll */
  showScrollHint?: boolean;
  /** Si true, usa el carrusel dinámico desde la API. Default: true */
  useDynamicBanners?: boolean;
}

const TourHeroSection: FC<TourHeroSectionProps> = ({
  imageUrl,
  imageAlt: imageAltProp,
  title: titleProp,
  subtitle: subtitleProp,
  badgeText: badgeTextProp,
  primaryCtaText: primaryCtaTextProp,
  secondaryCtaText: secondaryCtaTextProp,
  onPrimaryClick,
  onSecondaryClick,
  showScrollHint = true,
  useDynamicBanners = true,
}) => {
  const { t } = useTranslation('tourSections');

  const imageAlt = imageAltProp ?? t('hero.imageAlt');
  const title = titleProp ?? t('hero.title');
  const subtitle = subtitleProp ?? t('hero.subtitle');
  const badgeText = badgeTextProp ?? t('hero.badgeText');
  const primaryCtaText = primaryCtaTextProp ?? t('hero.primaryCta');
  const secondaryCtaText = secondaryCtaTextProp ?? t('hero.secondaryCta');

  // Modo dinámico: BannerCarousel se encarga de todo (fetch + render)
  if (useDynamicBanners) {
    return (
      <section
        className="relative w-full overflow-hidden"
        style={{ height: fluidSizing.banner.heroHeight }}
      >
        <BannerCarousel bannerType="landing_toures" fullWidth ctaIcon={<FiArrowDown className="ml-2 h-4 w-4 transition-transform group-hover:translate-y-0.5" />} />

        {/* Indicador de scroll sobre el carrusel — oculto en mobile */}
        {showScrollHint && (
          <div
            className="tour-hero__scroll-hint hidden sm:flex absolute left-1/2 transform -translate-x-1/2 z-30 flex-col items-center text-white animate-bounce"
            style={{
              bottom: fluidSizing.space.lg,
              gap: fluidSizing.space.xs,
            }}
          >
            <span style={{ fontSize: fluidSizing.text.xs, textShadow: '0 1px 6px rgba(0,0,0,0.5)' }} className="uppercase tracking-wider font-semibold">{t('hero.discoverMore')}</span>
            <div
              className="flex items-center justify-center rounded-full"
              style={{
                background: 'rgba(0, 0, 0, 0.35)',
                backdropFilter: 'blur(8px)',
                WebkitBackdropFilter: 'blur(8px)',
                border: '1px solid rgba(255, 255, 255, 0.2)',
                width: fluidSizing.size.buttonSm,
                height: fluidSizing.size.buttonSm,
              }}
            >
              <FiChevronDown style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            </div>
          </div>
        )}
      </section>
    );
  }

  // Modo estático (fallback): hero con imagen estática + overlay + CTAs
  const hasImage = !!imageUrl;

  return (
    <section className={`relative w-full overflow-hidden ${!hasImage ? 'tour-hero--fallback' : ''}`}
      style={{
        height: fluidSizing.banner.heroHeightStatic,
        minHeight: '400px',
      }}
    >
      {/* Imagen de fondo */}
      {hasImage && (
        <img
          src={imageUrl}
          alt={imageAlt}
          className="absolute inset-0 w-full h-full object-cover object-center"
          loading="eager"
          fetchPriority="high"
        />
      )}

      {/* Overlay gradiente lateral: denso a la izquierda, se desvanece a la derecha */}
      <div 
        className="absolute inset-0 z-10"
        style={{
          background: 'linear-gradient(to right, rgba(106, 15, 73, 0.75) 0%, rgba(106, 15, 73, 0.50) 20%, rgba(106, 15, 73, 0.20) 40%, transparent 55%)'
        }}
      />

      {/* Partículas decorativas */}
      <div className="tour-hero__particles absolute inset-0 z-20 pointer-events-none" />

      {/* Contenido alineado a la izquierda con contenedor glass */}
      <div
        className="relative z-30 flex items-center w-full h-full"
        style={{
          paddingLeft: fluidSizing.space.lg,
          paddingRight: fluidSizing.space.lg,
          paddingTop: fluidSizing.space['3xl'],
          paddingBottom: fluidSizing.space['3xl'],
        }}
      >
        <div
          className="flex flex-col items-start text-left text-white w-full max-w-xl lg:max-w-2xl rounded-2xl"
          style={{
            background: 'rgba(106, 15, 73, 0.35)',
            backdropFilter: 'blur(16px)',
            WebkitBackdropFilter: 'blur(16px)',
            border: '1px solid rgba(255, 255, 255, 0.12)',
            padding: fluidSizing.space.xl,
          }}
        >
          {/* Badge */}
          <div
            className="tour-hero__badge inline-flex items-center rounded-full font-semibold uppercase tracking-wider"
            style={{
              background: '#EBC7E1',
              color: '#6A0F49',
              gap: fluidSizing.space.xs,
              paddingLeft: fluidSizing.space.md,
              paddingRight: fluidSizing.space.md,
              paddingTop: fluidSizing.space.xs,
              paddingBottom: fluidSizing.space.xs,
              fontSize: fluidSizing.text.xs,
              marginBottom: fluidSizing.space.lg,
            }}
          >
            <FiMapPin
              style={{
                width: fluidSizing.size.iconSm,
                height: fluidSizing.size.iconSm,
                flexShrink: 0,
              }}
            />
            {badgeText}
          </div>

          {/* Título */}
          <h1
            className="tour-hero__title font-bold text-white leading-tight"
            style={{
              fontSize: fluidSizing.text['5xl'],
              marginBottom: fluidSizing.space.md,
              textShadow: '0 2px 20px rgba(0, 0, 0, 0.3)',
            }}
          >
            {title}
          </h1>

          {/* Subtítulo */}
          <p
            className="tour-hero__subtitle text-white/90 leading-relaxed"
            style={{
              fontSize: fluidSizing.text.lg,
              marginBottom: fluidSizing.space.xl,
              textShadow: '0 1px 8px rgba(0, 0, 0, 0.2)',
            }}
          >
            {subtitle}
          </p>

          {/* CTAs */}
          <div
            className="tour-hero__actions flex flex-col sm:flex-row items-start w-full sm:w-auto"
            style={{ gap: fluidSizing.space.sm }}
          >
            <button
              onClick={onPrimaryClick}
              className="inline-flex items-center justify-center font-semibold rounded-md transition-all duration-300 shadow-lg hover:shadow-xl hover:-translate-y-0.5 w-full sm:w-auto"
              style={{
                background: '#EBC7E1',
                color: '#6A0F49',
                gap: fluidSizing.space.xs,
                paddingLeft: fluidSizing.space.xl,
                paddingRight: fluidSizing.space.xl,
                paddingTop: fluidSizing.space.sm,
                paddingBottom: fluidSizing.space.sm,
                fontSize: fluidSizing.text.base,
              }}
              onMouseEnter={e => { e.currentTarget.style.background = '#F5E6E8'; }}
              onMouseLeave={e => { e.currentTarget.style.background = '#EBC7E1'; }}
            >
              <FiCalendar
                style={{
                  width: fluidSizing.size.iconSm,
                  height: fluidSizing.size.iconSm,
                  flexShrink: 0,
                }}
              />
              {primaryCtaText}
            </button>

            <button
              onClick={onSecondaryClick}
              className="inline-flex items-center justify-center font-medium rounded-md transition-all duration-300 hover:-translate-y-0.5 w-full sm:w-auto"
              style={{
                background: 'rgba(255, 255, 255, 0.18)',
                color: '#ffffff',
                border: '1.5px solid rgba(255, 255, 255, 0.5)',
                backdropFilter: 'blur(4px)',
                WebkitBackdropFilter: 'blur(4px)',
                paddingLeft: fluidSizing.space.xl,
                paddingRight: fluidSizing.space.xl,
                paddingTop: fluidSizing.space.sm,
                paddingBottom: fluidSizing.space.sm,
                fontSize: fluidSizing.text.base,
              }}
              onMouseEnter={e => {
                e.currentTarget.style.background = 'rgba(255, 255, 255, 0.30)';
                e.currentTarget.style.borderColor = 'rgba(255, 255, 255, 0.7)';
              }}
              onMouseLeave={e => {
                e.currentTarget.style.background = 'rgba(255, 255, 255, 0.18)';
                e.currentTarget.style.borderColor = 'rgba(255, 255, 255, 0.5)';
              }}
            >
              {secondaryCtaText}
            </button>
          </div>
        </div>
      </div>

      {/* Indicador de scroll */}
      {showScrollHint && (
        <div
          className="tour-hero__scroll-hint absolute left-1/2 transform -translate-x-1/2 z-30 flex flex-col items-center text-white/80"
          style={{ bottom: fluidSizing.space.lg, gap: fluidSizing.space.xs }}
        >
          <span style={{ fontSize: fluidSizing.text['2xs'] }} className="uppercase tracking-wider">{t('hero.discoverMore')}</span>
          <FiChevronDown style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
        </div>
      )}
    </section>
  );
};

export default TourHeroSection;
