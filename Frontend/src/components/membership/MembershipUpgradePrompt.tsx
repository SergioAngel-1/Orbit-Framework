/**
 * MembershipUpgradePrompt - Componente para mostrar mensaje de upgrade de membresía
 * 
 * Los datos de niveles se obtienen dinámicamente desde la API
 * para mantener consistencia con el backend.
 */

import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';

interface MembershipUpgradePromptProps {
  requiredLevel: number;
  currentLevel: number;
  currentMembershipName: string;
  currentMembershipIcon: string;
}

const MembershipUpgradePrompt = ({
  requiredLevel,
  currentLevel: _currentLevel,
  currentMembershipName,
  currentMembershipIcon,
}: MembershipUpgradePromptProps) => {
  const { t } = useTranslation('membershipUpgrade');
  const { localizedPath } = useLanguage();
  const { getLevelById } = useMembershipLevels();
  
  // Obtener datos del nivel requerido desde la API
  const requiredMembership = getLevelById(requiredLevel) || { 
    name: t('defaultName'), 
    icon: '🔒', 
    color: '#666666' 
  };

  return (
    <div className="flex flex-col items-center justify-center p-8 bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg border-2 border-dashed border-gray-300 min-h-[400px]">
      <div className="text-center max-w-md">
        {/* Icono de candado */}
        <div className="mb-6">
          <div className="inline-flex items-center justify-center w-20 h-20 bg-white rounded-full shadow-lg">
            <svg
              className="w-10 h-10 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
              />
            </svg>
          </div>
        </div>

        {/* Título */}
        <h3 className="text-2xl font-bold text-gray-900 mb-3">
          {t('exclusiveContent')}
        </h3>

        {/* Descripción */}
        <p className="text-gray-600 mb-6">
          {t('requiresMembership')}{' '}
          <span
            className="font-semibold"
            style={{ color: requiredMembership.color }}
          >
            {requiredMembership.icon} {requiredMembership.name}
          </span>{' '}
          {t('orHigher')}
        </p>

        {/* Membresía actual */}
        <div className="bg-white rounded-lg p-4 mb-6 shadow-sm">
          <p className="text-sm text-gray-500 mb-1">{t('currentMembership')}</p>
          <p className="text-lg font-semibold text-gray-900">
            {currentMembershipIcon} {currentMembershipName}
          </p>
        </div>

        {/* Botones de acción */}
        <div className="flex flex-col sm:flex-row gap-3 justify-center">
          <Link
            to={localizedPath('/membresias')}
            className="inline-flex items-center justify-center px-6 py-3 bg-primary-600 text-white font-medium rounded-lg hover:bg-primary-700 transition-colors shadow-md hover:shadow-lg"
          >
            <svg
              className="w-5 h-5 mr-2"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
              />
            </svg>
            {t('upgradeMembership')}
          </Link>

          <Link
            to={localizedPath('/')}
            className="inline-flex items-center justify-center px-6 py-3 bg-white text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors border border-gray-300"
          >
            {t('backToHome')}
          </Link>
        </div>

        {/* Información adicional */}
        <p className="text-xs text-gray-500 mt-6">
          {t('questions')}{' '}
          <Link to={localizedPath('/contacto')} className="text-primary-600 hover:underline">
            {t('contactUs')}
          </Link>
        </p>
      </div>
    </div>
  );
};

export default MembershipUpgradePrompt;
