/**
 * TourPackagesSection - Sección de Paquetes y Tours
 * Carga productos de WooCommerce de una categoría configurable (slug)
 * y los muestra usando el componente ProductCard existente
 */

import { FC, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiPackage, FiCalendar } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';
import './TourLanding.css';

interface TourPackagesSectionProps {
  /** Label superior */
  label?: string;
  /** Título de la sección */
  title?: string;
  /** Subtítulo descriptivo */
  subtitle?: string;
}

const TourPackagesSection: FC<TourPackagesSectionProps> = ({
  label,
  title,
  subtitle,
}) => {
  const { t } = useTranslation('toures');
  const sectionRef = useRef<HTMLDivElement>(null);
  const [isVisible, setIsVisible] = useState(false);

  const resolvedLabel = label ?? (t('packages.label') as string);
  const resolvedTitle = title ?? (t('packages.title') as string);
  const resolvedSubtitle = subtitle ?? (t('packages.subtitle') as string);

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
        paddingTop: fluidSizing.space['xl'],
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
            <FiCalendar
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

        {/* Contenido: Card de próximamente */}
        <div
          className="text-center bg-white rounded-lg border border-dashed border-secundario/40"
          style={{ padding: fluidSizing.space['3xl'] }}
        >
          <div
            className="flex items-center justify-center w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-primario/10 to-secundario/30 text-primario"
            style={{ marginBottom: fluidSizing.space.md }}
          >
            <FiPackage size={24} />
          </div>
          <h3
            className="font-semibold text-oscuro"
            style={{
              fontSize: fluidSizing.text.lg,
              marginBottom: fluidSizing.space.sm,
            }}
          >
            {t('packages.emptyTitle')}
          </h3>
          <p
            className="text-texto opacity-70 max-w-md mx-auto"
            style={{ fontSize: fluidSizing.text.sm }}
          >
            {t('packages.emptyDescription')}
          </p>
        </div>
      </div>
    </section>
  );
};

export default TourPackagesSection;
