/**
 * FallbackBanner - Banner reutilizable para páginas con fallback de usuario no autenticado
 * Muestra información introductoria y botones de acción
 * Usa fluidSizing para proporciones y tamaños responsive
 * Basado en el estilo de MembershipCTA
 */

import { FC, ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { FiUserPlus, FiLogIn } from 'react-icons/fi';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';

export interface FallbackBannerTag {
  icon: ReactNode;
  text: string;
}

export interface FallbackBannerFooter {
  icon: ReactNode;
  text: ReactNode;
}

interface FallbackBannerProps {
  title: string;
  description: string;
  tags?: FallbackBannerTag[];
  footer?: FallbackBannerFooter;
  primaryButtonText?: string;
  primaryButtonPath?: string;
  primaryButtonIcon?: ReactNode;
  secondaryButtonText?: string;
  secondaryButtonPath?: string;
  secondaryButtonIcon?: ReactNode;
  className?: string;
}

const FallbackBanner: FC<FallbackBannerProps> = ({
  title,
  description,
  tags = [],
  footer,
  primaryButtonText,
  primaryButtonPath = '/registrarse',
  primaryButtonIcon = <FiUserPlus style={{ marginRight: fluidSizing.space.xs, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />,
  secondaryButtonText,
  secondaryButtonPath = '/iniciar-sesion',
  secondaryButtonIcon = <FiLogIn style={{ marginRight: fluidSizing.space.xs, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />,
  className = ''
}) => {
  const { t } = useTranslation('header');
  const { localizedPath } = useLanguage();

  const resolvedPrimaryText = primaryButtonText || t('createAccount', 'Crear cuenta');
  const resolvedSecondaryText = secondaryButtonText || t('signIn', 'Iniciar sesión');

  return (
    <div 
      className={`bg-gradient-to-r from-primario to-hover rounded-lg text-white ${className}`} 
      style={{ 
        padding: fluidSizing.space.xl,
        marginBottom: fluidSizing.space.lg
      }}
    >
      <div 
        className="flex flex-col lg:flex-row lg:items-center lg:justify-between" 
        style={{ gap: fluidSizing.space.lg }}
      >
        <div>
          <h2 
            className="font-bold" 
            style={{ 
              fontSize: fluidSizing.text.xl,
              marginBottom: fluidSizing.space.xs
            }}
          >
            {title}
          </h2>
          <p 
            className="text-white/90" 
            style={{ fontSize: fluidSizing.text.sm }}
          >
            {description}
          </p>
          
          {tags.length > 0 && (
            <div 
              className="flex flex-col sm:flex-wrap sm:flex-row" 
              style={{ gap: fluidSizing.space.sm, marginTop: fluidSizing.space.md }}
            >
              {tags.map((tag, index) => (
                <div 
                  key={index}
                  className="flex items-center justify-center bg-white/20 rounded-full w-full sm:w-auto" 
                  style={{ 
                    gap: fluidSizing.space.xs, 
                    paddingLeft: fluidSizing.space.md, 
                    paddingRight: fluidSizing.space.md, 
                    paddingTop: fluidSizing.space.xs, 
                    paddingBottom: fluidSizing.space.xs 
                  }}
                >
                  <span 
                    className="flex items-center justify-center flex-shrink-0"
                    style={{ 
                      width: fluidSizing.size.iconSm, 
                      height: fluidSizing.size.iconSm 
                    }}
                  >
                    {tag.icon}
                  </span>
                  <span style={{ fontSize: fluidSizing.text.sm }}>{tag.text}</span>
                </div>
              ))}
            </div>
          )}
        </div>
        
        <div 
          className="flex flex-col sm:flex-row flex-shrink-0" 
          style={{ gap: fluidSizing.space.sm }}
        >
          <Link
            to={localizedPath(primaryButtonPath)}
            className="inline-flex items-center justify-center bg-white text-primario font-medium rounded-md hover:bg-gray-100 transition-colors"
            style={{
              paddingLeft: fluidSizing.space.lg,
              paddingRight: fluidSizing.space.lg,
              paddingTop: fluidSizing.space.sm,
              paddingBottom: fluidSizing.space.sm,
              fontSize: fluidSizing.text.sm
            }}
          >
            {primaryButtonIcon}
            {resolvedPrimaryText}
          </Link>
          <Link
            to={localizedPath(secondaryButtonPath)}
            className="inline-flex items-center justify-center bg-white/20 text-white font-medium rounded-md border border-white/30 hover:bg-white/30 hover:text-white transition-colors"
            style={{
              paddingLeft: fluidSizing.space.lg,
              paddingRight: fluidSizing.space.lg,
              paddingTop: fluidSizing.space.sm,
              paddingBottom: fluidSizing.space.sm,
              fontSize: fluidSizing.text.sm
            }}
          >
            {secondaryButtonIcon}
            {resolvedSecondaryText}
          </Link>
        </div>
      </div>
      
      {footer && (
        <div 
          className="border-t border-white/20 flex items-center" 
          style={{ 
            marginTop: fluidSizing.space.lg, 
            paddingTop: fluidSizing.space.lg, 
            gap: fluidSizing.space.sm 
          }}
        >
          <span style={{ fontSize: fluidSizing.text.lg }}>{footer.icon}</span>
          <span className="text-white/90" style={{ fontSize: fluidSizing.text.xs }}>
            {footer.text}
          </span>
        </div>
      )}
    </div>
  );
};

export default FallbackBanner;
