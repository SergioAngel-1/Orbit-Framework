import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { FiLock } from 'react-icons/fi';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';

interface AccessDeniedMessageProps {
  reason?: string | null;
  title?: string;
  description?: string;
  showCatalogButton?: boolean;
  showMembershipButton?: boolean;
  catalogButtonText?: string;
  catalogButtonPath?: string;
  membershipButtonText?: string;
  membershipButtonPath?: string;
  compact?: boolean;
  onButtonClick?: () => void;
}

/**
 * Componente reutilizable para mostrar mensaje de acceso denegado por membresía
 * Soporta modo compacto para uso en modales
 */
const AccessDeniedMessage: React.FC<AccessDeniedMessageProps> = ({
  reason,
  title,
  description,
  showCatalogButton = true,
  showMembershipButton = true,
  catalogButtonText,
  catalogButtonPath = '/catalogo',
  membershipButtonText,
  membershipButtonPath = '/membresias',
  compact = false,
  onButtonClick
}) => {
  const { t } = useTranslation('header');
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();

  const resolvedTitle = title || t('exclusiveContent');
  const resolvedDescription = description || t('exclusiveContentDescription');
  const resolvedReason = reason || t('exclusiveContentReason');
  const resolvedCatalogText = catalogButtonText || t('viewCatalog');
  const resolvedMembershipText = membershipButtonText || t('viewMemberships');

  const handleNavigate = (path: string) => {
    if (onButtonClick) onButtonClick();
    navigate(localizedPath(path));
  };

  return (
    <div 
      className={`bg-gray-50 border border-gray-200 rounded-xl text-center mx-auto ${compact ? '' : 'my-4 sm:my-8'}`}
      style={{ 
        padding: compact ? fluidSizing.space.lg : fluidSizing.space.xl,
        maxWidth: compact ? '100%' : '42rem'
      }}
    >
      <div 
        className="flex items-center justify-center mx-auto bg-primario/10 rounded-full"
        style={{ 
          width: compact ? '3rem' : '4rem', 
          height: compact ? '3rem' : '4rem', 
          marginBottom: fluidSizing.space.md 
        }}
      >
        <FiLock 
          className="text-primario" 
          style={{ 
            width: compact ? '1.5rem' : '2rem', 
            height: compact ? '1.5rem' : '2rem' 
          }} 
        />
      </div>
      <h2 
        className="font-bold text-primario"
        style={{ fontSize: compact ? fluidSizing.text.lg : fluidSizing.text.xl, marginBottom: fluidSizing.space.sm }}
      >
        {resolvedTitle}
      </h2>
      <p 
        className="text-gray-700"
        style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.md }}
      >
        {resolvedReason}
      </p>
      <p 
        className="text-gray-500"
        style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.lg }}
      >
        {resolvedDescription}
      </p>
      <div className="flex justify-center flex-col sm:flex-row" style={{ gap: fluidSizing.space.md }}>
        {showCatalogButton && (
          <button 
            onClick={() => handleNavigate(catalogButtonPath)}
            className="bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors"
            style={{ 
              paddingLeft: fluidSizing.space.lg, 
              paddingRight: fluidSizing.space.lg, 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm,
              fontSize: fluidSizing.text.sm
            }}
          >
            {resolvedCatalogText}
          </button>
        )}
        {showMembershipButton && (
          <button 
            onClick={() => handleNavigate(membershipButtonPath)}
            className="bg-primario text-white rounded-lg hover:bg-hover transition-colors"
            style={{ 
              paddingLeft: fluidSizing.space.lg, 
              paddingRight: fluidSizing.space.lg, 
              paddingTop: fluidSizing.space.sm, 
              paddingBottom: fluidSizing.space.sm,
              fontSize: fluidSizing.text.sm
            }}
          >
            {resolvedMembershipText}
          </button>
        )}
      </div>
    </div>
  );
};

export default AccessDeniedMessage;
