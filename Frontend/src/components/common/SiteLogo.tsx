import React from 'react';
import { useSiteConfig } from '../../contexts/SiteConfigContext';

interface SiteLogoProps {
  className?: string;
  maxHeight?: number;
  maxWidth?: number;
  variant?: 'default' | 'light' | 'dark';
  showFallbackText?: boolean;
}

/**
 * Componente de logo dinámico que lee la URL del logo desde Site Settings.
 * Si no hay logo configurado, muestra el nombre del sitio como texto.
 */
const SiteLogo: React.FC<SiteLogoProps> = ({
  className = '',
  maxHeight = 40,
  maxWidth = 160,
  variant = 'default',
  showFallbackText = true,
}) => {
  const { config } = useSiteConfig();
  const logoUrl = config.branding.branding_logo;
  const siteName = config.identity.site_name;

  if (logoUrl) {
    return (
      <img
        src={logoUrl}
        alt={`${siteName} Logo`}
        className={className}
        style={{
          maxHeight: `${maxHeight}px`,
          maxWidth: `${maxWidth}px`,
          objectFit: 'contain',
        }}
      />
    );
  }

  if (!showFallbackText) return null;

  const textColor =
    variant === 'light'
      ? 'text-white'
      : variant === 'dark'
        ? 'text-gray-900'
        : 'text-[var(--color-primary)]';

  return (
    <span
      className={`font-bold text-xl ${textColor} ${className}`}
    >
      {siteName}
    </span>
  );
};

export default SiteLogo;
