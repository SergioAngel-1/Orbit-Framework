import React from 'react';
import { useTranslation } from 'react-i18next';
import { FiExternalLink } from 'react-icons/fi';
import { useLanguage } from '../../../contexts/LanguageContext';

interface TermsCheckboxProps {
  accepted: boolean;
  setAccepted: (value: boolean) => void;
  disabled?: boolean;
}

/**
 * Componente de checkbox para aceptar términos y condiciones
 * Incluye enlaces a documentos legales
 */
const TermsCheckbox: React.FC<TermsCheckboxProps> = ({
  accepted,
  setAccepted,
  disabled = false
}) => {
  const { t } = useTranslation('registerForm');
  const { localizedPath } = useLanguage();

  // URLs de los documentos legales
  const termsUrl = localizedPath('/terminos');
  const privacyUrl = localizedPath('/privacidad');

  return (
    <label 
        className={`relative flex items-start space-x-3 p-3 rounded-lg border-2 cursor-pointer transition-all ${
          accepted ? 'bg-primario/5 border-primario shadow-sm' : 'bg-white border-gray-200 hover:border-gray-300'
        } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
      >
        {/* Checkbox visual personalizado */}
        <div className="flex items-center pt-0.5">
          <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-all ${
            accepted ? 'border-primario bg-primario' : 'border-gray-300 bg-white'
          }`}>
            {accepted && (
              <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
              </svg>
            )}
          </div>
        </div>
        
        <input
          type="checkbox"
          name="terms"
          checked={accepted}
          onChange={(e) => setAccepted(e.target.checked)}
          disabled={disabled}
          className="sr-only"
        />
        
        <div className="flex-1 text-sm text-gray-700 select-none">
          {t('terms.prefix')}{' '}
          <a
            href={termsUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="text-primario hover:text-hover font-medium inline-flex items-center gap-0.5"
            onClick={(e) => e.stopPropagation()}
          >
            {t('terms.termsLink')}
            <FiExternalLink className="w-3 h-3" />
          </a>
          {' '}{t('terms.conjunction')}{' '}
          <a
            href={privacyUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="text-primario hover:text-hover font-medium inline-flex items-center gap-0.5"
            onClick={(e) => e.stopPropagation()}
          >
            {t('terms.privacyLink')}
            <FiExternalLink className="w-3 h-3" />
          </a>
          .
          <span className="text-red-500 ml-1">*</span>
        </div>
    </label>
  );
};

export default TermsCheckbox;
