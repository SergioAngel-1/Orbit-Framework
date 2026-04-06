import React from 'react';
import { Link } from 'react-router-dom';
import DOMPurify from 'dompurify';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';
import { isBannerAction, executeBannerAction } from '../../utils/bannerActions';

export interface BannerInfoOverlayProps {
  /** Título/subtítulo del banner */
  subtitle?: string;
  /** Descripción HTML del banner */
  description?: string;
  /** URL del enlace del CTA */
  link?: string;
  /** Texto del botón CTA */
  cta?: string;
  /** Si la imagen ya cargó (para animación de entrada) */
  visible?: boolean;
  /** Icono personalizado para el CTA (ReactNode). Por defecto: chevron derecho */
  ctaIcon?: React.ReactNode;
  /** Modo compacto: usa el layout mobile (pill + CTA) en todos los tamaños. Para banners no fullWidth. */
  compact?: boolean;
}

/**
 * BannerInfoOverlay — Overlay de información para banners hero.
 *
 * Desktop: Ocupa el lado izquierdo completo con gradiente, tipografía grande y prominente.
 * Mobile: Título como pill flotante arriba + botón CTA abajo (layout compacto).
 */
const BannerInfoOverlay: React.FC<BannerInfoOverlayProps> = ({
  subtitle,
  description,
  link,
  cta,
  visible = true,
  ctaIcon,
  compact = false,
}) => {
  const { localizedPath } = useLanguage();

  const ctaText = cta;

  const renderCta = (className: string, style?: React.CSSProperties) => {
    if (!link) return null;

    const defaultIcon = (
      <svg xmlns="http://www.w3.org/2000/svg" className="ml-2 h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
      </svg>
    );

    const inner = (
      <>
        {ctaText}
        {ctaIcon ?? defaultIcon}
      </>
    );

    if (isBannerAction(link)) {
      return (
        <button
          onClick={() => executeBannerAction(link)}
          className={className}
          style={style}
        >
          {inner}
        </button>
      );
    }

    if (link.startsWith('#')) {
      return (
        <button
          onClick={() => {
            const targetId = link.substring(1);
            const el = document.getElementById(targetId);
            el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }}
          className={className}
          style={style}
        >
          {inner}
        </button>
      );
    }

    return (
      <Link to={localizedPath(link)} className={className} style={style}>
        {inner}
      </Link>
    );
  };

  if (!visible) return null;

  return (
    <>
      {/* ═══════════════════════════════════════════════════════════════
          MOBILE (o compact): pill de título arriba + CTA abajo
          ═══════════════════════════════════════════════════════════════ */}
      <div className={`flex ${compact ? '' : 'sm:hidden'} absolute inset-0 flex-col ${subtitle ? 'justify-between' : 'justify-end'} p-4 pt-10 z-20`}>
        {subtitle && (
          <div className="self-start">
            <span
              className="inline-flex items-center font-bold text-white px-4 py-2 rounded-lg shadow-lg"
              style={{
                fontSize: fluidSizing.text.base,
                background: 'linear-gradient(135deg, #B91E59, #D4367A)',
                textShadow: '0 1px 4px rgba(0, 0, 0, 0.3)',
              }}
            >
              {subtitle}
            </span>
          </div>
        )}
        <div className="flex flex-col items-start gap-2">
          {description && (
            <div
              className="text-white/95 leading-snug rounded-lg text-xs"
              style={{
                background: 'rgba(0, 0, 0, 0.4)',
                backdropFilter: 'blur(10px)',
                WebkitBackdropFilter: 'blur(10px)',
                padding: '6px 10px',
              }}
              dangerouslySetInnerHTML={{
                __html: DOMPurify.sanitize(description, {
                  ALLOWED_TAGS: ['p', 'strong', 'em', 'br'],
                  ALLOWED_ATTR: [],
                }),
              }}
            />
          )}
          {link && cta && renderCta(
            'inline-flex items-center bg-primario hover:bg-hover text-white py-1.5 px-3 rounded-md transition-all duration-300 shadow-lg group',
            { fontSize: fluidSizing.text.xs }
          )}
        </div>
      </div>

      {/* ═══════════════════════════════════════════════════════════════
          DESKTOP: overlay izquierdo prominente con tipografía grande
          (solo para banners anchos / fullWidth)
          ═══════════════════════════════════════════════════════════════ */}
      {!compact && (
      <div className="hidden sm:flex absolute inset-0 z-20 pointer-events-none">
        {/* Gradiente lateral que ocupa ~50% del ancho */}
        <div
          className="absolute inset-y-0 left-0 w-[55%]"
          style={{
            background: 'linear-gradient(to right, rgba(10, 6, 20, 0.82) 0%, rgba(10, 6, 20, 0.65) 55%, rgba(10, 6, 20, 0.25) 80%, transparent 100%)',
          }}
        />

        {/* Contenido alineado a la izquierda, centrado verticalmente */}
        <div className="relative flex items-center w-full h-full px-8 md:px-14 lg:px-20">
          <div className="max-w-lg xl:max-w-xl flex flex-col pointer-events-auto">
            {/* Título grande y prominente */}
            {subtitle && (
              <h2
                className="font-bold text-white leading-tight tracking-tight drop-shadow-lg"
                style={{
                  fontSize: fluidSizing.text['4xl'],
                  marginBottom: fluidSizing.space.md,
                }}
              >
                {subtitle}
              </h2>
            )}

            {/* Línea decorativa con degradado */}
            <div
              className="w-20 h-1 rounded-full bg-gradient-to-r from-primario to-primario-light"
              style={{ marginBottom: fluidSizing.space.md }}
            />

            {/* Descripción con fondo adaptable para legibilidad en cualquier banner */}
            {description && (
              <div
                className="text-white/95 leading-relaxed rounded-lg"
                style={{
                  fontSize: fluidSizing.text.base,
                  marginBottom: link ? fluidSizing.space.lg : undefined,
                  background: 'rgba(0, 0, 0, 0.35)',
                  backdropFilter: 'blur(12px)',
                  WebkitBackdropFilter: 'blur(12px)',
                  padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`,
                }}
                dangerouslySetInnerHTML={{
                  __html: DOMPurify.sanitize(description, {
                    ALLOWED_TAGS: ['p', 'strong', 'em', 'br'],
                    ALLOWED_ATTR: [],
                  }),
                }}
              />
            )}

            {/* CTA prominente */}
            {link && cta && renderCta(
              'group inline-flex items-center bg-primario hover:bg-hover text-white font-semibold py-3 px-6 rounded-lg transition-all duration-300 hover:scale-[1.03] hover:shadow-xl hover:shadow-primario/30 w-fit',
              { fontSize: fluidSizing.text.base }
            )}
          </div>
        </div>
      </div>
      )}
    </>
  );
};

export default BannerInfoOverlay;
