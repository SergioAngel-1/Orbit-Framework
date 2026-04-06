/**
 * TourTestimonialsSection - Sección de Testimonios con diseño variado
 * Carousel horizontal en mobile, grid asimétrico en desktop
 * Testimonio destacado grande + cards regulares para dinamismo visual
 */

import { FC, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiMessageSquare, FiArrowRight } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import './TourLanding.css';

interface Testimonial {
  id: number;
  name: string;
  role: string;
  avatar: string;
  content: string;
  rating: number;
  tourType?: string;
  featured?: boolean;
}

interface TourTestimonialsSectionProps {
  /** Label superior */
  label?: string;
  /** Título principal */
  title?: string;
  /** Subtítulo descriptivo */
  subtitle?: string;
  /** Testimonios personalizados */
  testimonials?: Testimonial[];
  /** Texto del CTA */
  ctaText?: string;
  /** URL del CTA */
  ctaUrl?: string;
}

// Default testimonials are loaded from translations

const TourTestimonialsSection: FC<TourTestimonialsSectionProps> = ({
  label,
  title,
  subtitle,
  testimonials,
  ctaText,
  ctaUrl: _ctaUrl = '/catalogo',
}) => {
  const { t } = useTranslation('tourSections');

  // Build default testimonials from translations
  const defaultTestimonials: Testimonial[] = (t('testimonials.defaultTestimonials', { returnObjects: true }) as any[]).map((tm: any, i: number) => ({
    id: i + 1,
    name: tm.name,
    role: tm.role,
    avatar: tm.avatar,
    content: tm.content,
    rating: tm.rating,
    tourType: tm.tourType,
    featured: tm.featured,
  }));

  const resolvedLabel = label || t('testimonials.label');
  const resolvedTitle = title || t('testimonials.title');
  const resolvedSubtitle = subtitle || t('testimonials.subtitle');
  const resolvedTestimonials = testimonials || defaultTestimonials;
  const resolvedCtaText = ctaText || t('testimonials.ctaText');
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

  // Renderizar estrellas de rating
  const renderStars = (rating: number, light = false) => {
    const starSize = 16;
    const filledColor = light ? '#FFD700' : '#b91e59';
    const emptyColor = light ? 'rgba(255,255,255,0.35)' : 'rgba(185,30,89,0.25)';
    return (
      <div className="flex items-center gap-0.5">
        {[1, 2, 3, 4, 5].map((star) => (
          <svg
            key={star}
            width={starSize}
            height={starSize}
            viewBox="0 0 24 24"
            fill={star <= rating ? filledColor : 'none'}
            stroke={star <= rating ? filledColor : emptyColor}
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
          </svg>
        ))}
      </div>
    );
  };

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
      <div className="tour-testimonials__bg-particles absolute inset-0 pointer-events-none" />

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
            <FiMessageSquare
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

        {/* Grid uniforme de testimonios */}
        <div
          className="grid grid-cols-2 lg:grid-cols-3 gap-5"
          style={{ marginBottom: fluidSizing.space['3xl'] }}
        >
          {resolvedTestimonials.map((testimonial) => {
            const isFeatured = testimonial.featured;
            const baseClasses = isFeatured
              ? 'bg-gradient-to-br from-primario to-hover text-white tour-testimonials__featured relative overflow-hidden'
              : 'bg-white border border-secundario/20 hover:border-primario/25';
            return (
              <div
                key={testimonial.id}
                className={`rounded-xl transition-all duration-300 hover:shadow-md ${baseClasses}`}
              >
                {/* Mobile: compact card */}
                <div className="flex flex-col sm:hidden" style={{ padding: fluidSizing.space.sm }}>
                  <div className="flex items-center gap-2 mb-2">
                    <div className={`rounded-full flex items-center justify-center font-bold flex-shrink-0 ${
                      isFeatured ? 'bg-white/20 text-white' : 'bg-gradient-to-br from-secundario to-primario text-white'
                    }`} style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg, fontSize: fluidSizing.text['2xs'] }}>
                      {testimonial.avatar}
                    </div>
                    <div className="flex-1 min-w-0">
                      <h3 className={`font-semibold truncate ${isFeatured ? 'text-white' : 'text-oscuro'}`}
                        style={{ fontSize: fluidSizing.text.xs }}>
                        {testimonial.name}
                      </h3>
                    </div>
                  </div>
                  <p className={`leading-snug mb-2 line-clamp-3 ${isFeatured ? 'text-white/90 italic' : 'text-texto'}`}
                    style={{ fontSize: fluidSizing.text['2xs'] }}>
                    "{testimonial.content}"
                  </p>
                  <div className="flex items-center gap-1">
                    {renderStars(testimonial.rating, isFeatured)}
                  </div>
                </div>

                {/* Desktop: full card */}
                <div className="hidden sm:flex flex-col justify-between h-full" style={{ padding: fluidSizing.space.md }}>
                  <div>
                    <div className="flex items-center gap-3 mb-4">
                      <div className={`rounded-full flex items-center justify-center font-bold flex-shrink-0 ${
                        isFeatured ? 'bg-white/20 text-white' : 'bg-gradient-to-br from-secundario to-primario text-white'
                      }`} style={{ width: fluidSizing.size.avatar, height: fluidSizing.size.avatar, fontSize: fluidSizing.text.sm }}>
                        {testimonial.avatar}
                      </div>
                      <div className="flex-1 min-w-0">
                        <h3 className={`font-semibold text-sm truncate ${isFeatured ? 'text-white' : 'text-oscuro'}`}>
                          {testimonial.name}
                        </h3>
                        <p className={`text-xs ${isFeatured ? 'text-white/70' : 'text-texto/70'}`}>
                          {testimonial.role}
                        </p>
                      </div>
                      {testimonial.tourType && (
                        <span className={`font-medium text-xs px-2.5 py-0.5 rounded-full flex-shrink-0 ${
                          isFeatured
                            ? 'bg-white/15 text-white border border-white/25'
                            : 'bg-primario/8 text-primario border border-primario/15'
                        }`}>
                          {testimonial.tourType}
                        </span>
                      )}
                    </div>

                    <p className={`text-sm leading-relaxed mb-4 ${isFeatured ? 'text-white/90 italic' : 'text-texto'}`}>
                      "{testimonial.content}"
                    </p>
                  </div>

                  <div className={`flex items-center gap-2 pt-3 ${isFeatured ? 'border-t border-white/15' : 'border-t border-secundario/15'}`}>
                    {renderStars(testimonial.rating, isFeatured)}
                    <span className={`text-xs font-medium ${isFeatured ? 'text-white/60' : 'text-texto/50'}`}>
                      {testimonial.rating}.0
                    </span>
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        {/* CTA inferior — deshabilitado (próximamente) */}
        <div className="text-center">
          <span
            className="inline-flex items-center gap-2 bg-gray-400 text-white font-medium rounded-md cursor-not-allowed opacity-60"
            style={{
              paddingLeft: fluidSizing.space.xl,
              paddingRight: fluidSizing.space.xl,
              paddingTop: fluidSizing.space.sm,
              paddingBottom: fluidSizing.space.sm,
              fontSize: fluidSizing.text.base,
            }}
          >
            {resolvedCtaText}
            <FiArrowRight
              style={{
                width: fluidSizing.size.iconSm,
                height: fluidSizing.size.iconSm,
              }}
            />
          </span>
        </div>
      </div>
    </section>
  );
};

export default TourTestimonialsSection;
