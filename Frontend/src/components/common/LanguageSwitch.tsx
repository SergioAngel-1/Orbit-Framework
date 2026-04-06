/**
 * LanguageSwitch - Selector de idioma para el TopBar
 * Estilo visual idéntico al AddressBar (pestaña colgante con borde primario)
 * Abre un AnimatedModal con los idiomas disponibles
 * 
 * Usa LanguageContext como fuente única de verdad para el idioma.
 */
import { FC, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiGlobe } from 'react-icons/fi';
import AnimatedModal from '../ui/AnimatedModal';
import { useLanguage, type SupportedLang } from '../../contexts/LanguageContext';

interface Language {
  code: SupportedLang;
  flag: string;
  label: string;
  name: string;
}

const languages: Language[] = [
  { code: 'es', flag: 'fi fi-co', label: 'ES', name: 'Español' },
  { code: 'en', flag: 'fi fi-us', label: 'EN', name: 'English' },
];

interface LanguageSwitchProps {
  className?: string;
  variant?: 'desktop' | 'mobile';
}

const LanguageSwitch: FC<LanguageSwitchProps> = ({ className = '', variant = 'desktop' }) => {
  const { t } = useTranslation('topbar');
  const { currentLang, switchLanguage } = useLanguage();
  const [isOpen, setIsOpen] = useState(false);

  const activeLang = languages.find(l => l.code === currentLang) || languages[0];

  const handleSelect = (langCode: SupportedLang) => {
    switchLanguage(langCode);
    setIsOpen(false);
  };

  return (
    <>
      {/* Botón — mismo estilo que AddressBar */}
      <span
        onClick={() => setIsOpen(true)}
        className={`${variant === 'desktop' ? 'hidden md:inline-flex px-2 pt-0 pb-1 rounded-b-lg border-b-2 border-l-2 border-r-2 border-primario relative group' : 'inline-flex px-3 py-1.5 rounded-full border border-primario bg-white/90 shadow-sm'} text-primario text-sm font-bold items-center cursor-pointer tab-push-effect ${className}`}
      >
        {variant === 'desktop' && (
          <span className="absolute inset-0 bg-white group-hover:bg-gray-50 transition-colors duration-300 -z-10 rounded-b-lg"></span>
        )}
        <span className={`${activeLang.flag} text-base mr-1`} />
        <span className="font-bold">{activeLang.label}</span>
        <svg
          xmlns="http://www.w3.org/2000/svg"
          className="h-4 w-4 ml-1 text-primario"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </span>

      {/* Modal de selección de idioma */}
      <AnimatedModal
        isOpen={isOpen}
        onClose={() => setIsOpen(false)}
        title={
          <span className="flex items-center gap-2">
            <FiGlobe className="text-primario" />
            <span>{t('languageModalTitle')}</span>
          </span>
        }
        maxWidth="max-w-xs"
      >
        <div className="flex flex-col gap-2">
          {languages.map((lang) => (
            <button
              key={lang.code}
              onClick={() => handleSelect(lang.code)}
              className={`flex items-center gap-3 w-full px-4 py-3 rounded-lg text-left transition-all duration-200 ${
                lang.code === currentLang
                  ? 'bg-primario/10 border-2 border-primario text-primario font-bold shadow-sm'
                  : 'bg-gray-50 border-2 border-transparent text-texto hover:bg-gray-100 hover:border-gray-300'
              }`}
            >
              <span className={`${lang.flag} text-2xl`} />
              <div className="flex flex-col">
                <span className="font-bold text-sm">{lang.name}</span>
                <span className="text-xs opacity-60">{lang.label}</span>
              </div>
              {lang.code === currentLang && (
                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 ml-auto text-primario" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                </svg>
              )}
            </button>
          ))}
        </div>
      </AnimatedModal>
    </>
  );
};

export default LanguageSwitch;
