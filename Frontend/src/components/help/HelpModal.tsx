import { useState, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { FaQuestionCircle, FaInfoCircle, FaCoins, FaChevronLeft, FaChevronRight } from 'react-icons/fa';
import AnimatedModal from '../ui/AnimatedModal';
import CollapsibleSection from '../common/CollapsibleSection';
import { useLanguage } from '../../contexts/LanguageContext';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';

interface HelpModalProps {
  isOpen: boolean;
  onClose: () => void;
  initialTab?: 'help' | 'howToRequest' | 'coinsSystem';
}

const HelpModal: React.FC<HelpModalProps> = ({ isOpen, onClose, initialTab = 'help' }) => {
  const { t } = useTranslation('helpModal');
  const { localizedPath } = useLanguage();
  const [activeTab, setActiveTab] = useState<'help' | 'howToRequest' | 'coinsSystem'>(initialTab);
  const contentRef = useRef<HTMLDivElement>(null);

  // Actualizar la pestaña activa cuando cambie initialTab
  useEffect(() => {
    setActiveTab(initialTab);
  }, [initialTab]);

  useEffect(() => {
    if (isOpen) {
      setActiveTab(initialTab);
    } else {
      setActiveTab('help');
    }
  }, [isOpen, initialTab]);

  // Scroll al inicio cuando cambie la pestaña
  useEffect(() => {
    if (contentRef.current) {
      contentRef.current.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    }
  }, [activeTab]);

  // Array de pestañas para navegación en móvil
  const tabs = [
    {
      key: 'help' as const,
      icon: FaQuestionCircle,
      label: t('tabs.help')
    },
    {
      key: 'howToRequest' as const,
      icon: FaInfoCircle,
      label: t('tabs.howToRequest')
    },
    {
      key: 'coinsSystem' as const,
      icon: FaCoins,
      label: t('tabs.coinsSystem')
    }
  ];

  // Funciones para navegación en móvil
  const currentTabIndex = tabs.findIndex(tab => tab.key === activeTab);
  
  const goToPreviousTab = () => {
    const prevIndex = currentTabIndex > 0 ? currentTabIndex - 1 : tabs.length - 1;
    setActiveTab(tabs[prevIndex].key);
  };

  const goToNextTab = () => {
    const nextIndex = currentTabIndex < tabs.length - 1 ? currentTabIndex + 1 : 0;
    setActiveTab(tabs[nextIndex].key);
  };

  const currentTab = tabs[currentTabIndex];

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={onClose}
      className="max-w-3xl"
      title={t('title')}
    >
      {/* Tabs - Diseño unificado con navegación para evitar desbordamiento */}
      <div className="bg-gray-100 px-2 sm:px-4 py-3 border-b border-gray-200">
        <div className="flex items-center gap-2">
          <button
            onClick={goToPreviousTab}
            className="flex-shrink-0 p-2 bg-primario text-white rounded-md hover:bg-hover transition-colors"
            aria-label={t('nav.prevAria')}
          >
            <FaChevronLeft className="h-4 w-4 sm:h-5 sm:w-5" />
          </button>
          
          <div className="flex-1 min-w-0">
            <div className="bg-primario text-white px-2 sm:px-3 py-2 rounded-md flex items-center justify-center text-xs sm:text-sm">
              <currentTab.icon className="mr-1.5 sm:mr-2 flex-shrink-0 text-sm sm:text-base" />
              <span className="text-center leading-tight">{currentTab.label}</span>
            </div>
          </div>
          
          <button
            onClick={goToNextTab}
            className="flex-shrink-0 p-2 bg-primario text-white rounded-md hover:bg-hover transition-colors"
            aria-label={t('nav.nextAria')}
          >
            <FaChevronRight className="h-4 w-4 sm:h-5 sm:w-5" />
          </button>
        </div>
        
        {/* Indicador de posición */}
        <div className="flex justify-center mt-2 space-x-1">
          {tabs.map((_, index) => (
            <div
              key={index}
              className={`h-1.5 w-1.5 rounded-full transition-colors ${
                index === currentTabIndex ? 'bg-primario' : 'bg-gray-300'
              }`}
            />
          ))}
        </div>
      </div>

      {/* Content */}
      <div ref={contentRef} className="bg-white px-4 pt-5 pb-6 sm:p-6 overflow-y-auto max-h-[calc(80vh-200px)]">
        {activeTab === 'help' ? (
          <div className="help-section">
            <h4 className="text-lg font-medium mb-4">{t('faq.title')}</h4>
            
            <div className="space-y-3">
              {([1, 2, 3, 4] as const).map((num, index) => (
                <CollapsibleSection
                  key={num}
                  title={t(`faq.q${num}.title`)}
                  variant="soft"
                  defaultExpanded={index === 0}
                  collapsible={true}
                  className="hover:border-primario/30 transition-all duration-300"
                >
                  {num === 3 ? (
                    <p className="text-sm" dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t(`faq.q${num}.answer`)) }} />
                  ) : (
                    <p className="text-sm">{t(`faq.q${num}.answer`)}</p>
                  )}
                </CollapsibleSection>
              ))}
            </div>

            {/* CTA a página de FAQ completa */}
            <Link
              to={localizedPath('/faq')}
              onClick={onClose}
              className="mt-4 flex items-center justify-center gap-2 w-full py-2.5 bg-primario/10 text-primario font-medium rounded-md hover:bg-primario/20 transition-colors text-sm"
            >
              <FaQuestionCircle className="w-4 h-4" />
              {t('faq.viewAllCta')}
            </Link>
            
            <div className="mt-6">
              <h5 className="font-medium text-lg mb-3">{t('faq.contact.title')}</h5>
              <p className="mb-4">{t('faq.contact.description')}</p>
              
              <ul className="list-disc pl-5 space-y-2">
                <li dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('faq.contact.email')) }} />
                <li dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('faq.contact.phone')) }} />
                <li dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('faq.contact.form')) }} />
              </ul>
            </div>
          </div>
        ) : activeTab === 'howToRequest' ? (
          <div className="how-to-request-section">
            <h4 className="text-base sm:text-lg font-medium mb-4">{t('howToRequest.title')}</h4>
            
            <div className="space-y-4 sm:space-y-6">
              {[1, 2, 3, 4, 5].map((step) => (
                <div key={step} className="flex">
                  <div className="flex-shrink-0">
                    <div className="flex items-center justify-center h-8 w-8 sm:h-10 sm:w-10 rounded-full bg-primario text-white font-bold text-sm sm:text-base">{step}</div>
                  </div>
                  <div className="ml-3 sm:ml-4">
                    <h5 className="text-base sm:text-lg font-medium text-primario">{t(`howToRequest.step${step}.title`)}</h5>
                    <p className="mt-1 text-sm sm:text-base">{t(`howToRequest.step${step}.description`)}</p>
                  </div>
                </div>
              ))}
            </div>
            
            <div className="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-md">
              <h5 className="font-medium text-lg text-yellow-800 mb-2">{t('howToRequest.important.title')}</h5>
              <p className="text-yellow-700">{t('howToRequest.important.intro')}</p>
              <ul className="list-disc pl-5 mt-2 text-yellow-700">
                {(t('howToRequest.important.items', { returnObjects: true }) as string[]).map((item, i) => (
                  <li key={i}>{item}</li>
                ))}
              </ul>
            </div>
          </div>
        ) : activeTab === 'coinsSystem' ? (
          <div className="coins-system-section">
            <h4 className="text-base sm:text-lg font-medium mb-4">{t('coinsSystem.title')}</h4>
            
            {/* Sección 1: Cómo gano Virtual Coins */}
            <div className="mb-8">
              <h5 className="text-sm sm:text-md font-semibold text-primario mb-4 pb-2 border-b-2 border-primario">{t('coinsSystem.howToEarn.title')}</h5>
              
              <div className="space-y-4 sm:space-y-6">
                <div className="flex">
                  <div className="flex-shrink-0">
                    <div className="flex items-center justify-center h-8 w-8 sm:h-10 sm:w-10 rounded-full bg-primario text-white font-bold text-sm sm:text-base">1</div>
                  </div>
                  <div className="ml-3 sm:ml-4">
                    <h6 className="text-base sm:text-lg font-medium text-primario">{t('coinsSystem.howToEarn.referrals.title')}</h6>
                    <p className="mt-1 text-sm sm:text-base">{t('coinsSystem.howToEarn.referrals.description')}</p>
                    <Link 
                      to={localizedPath('/invitados')} 
                      className="inline-flex items-center mt-3 text-sm text-primario hover:text-primario-dark transition-colors duration-200"
                      onClick={onClose}
                    >
                      <span>{t('coinsSystem.howToEarn.referrals.cta')}</span>
                      <svg xmlns="http://www.w3.org/2000/svg" className="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                      </svg>
                    </Link>
                  </div>
                </div>
                
                {(['welcome', 'reviews', 'birthday', 'promotions'] as const).map((key, i) => (
                  <div key={key} className="flex">
                    <div className="flex-shrink-0">
                      <div className="flex items-center justify-center h-8 w-8 sm:h-10 sm:w-10 rounded-full bg-primario text-white font-bold text-sm sm:text-base">{i + 2}</div>
                    </div>
                    <div className="ml-3 sm:ml-4">
                      <h6 className="text-base sm:text-lg font-medium text-primario">{t(`coinsSystem.howToEarn.${key}.title`)}</h6>
                      <p className="mt-1 text-sm sm:text-base">{t(`coinsSystem.howToEarn.${key}.description`)}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            
            {/* Sección 2: Para qué sirven */}
            <div className="mb-6">
              <h5 className="text-sm sm:text-md font-semibold text-primario mb-4 pb-2 border-b-2 border-primario">{t('coinsSystem.whatFor.title')}</h5>
              
              <div className="p-4 bg-green-50 border border-green-200 rounded-md">
                <p className="text-green-700 mb-3 text-sm sm:text-base">{t('coinsSystem.whatFor.intro')}</p>
                <ul className="list-disc pl-5 space-y-2 text-green-700 text-sm sm:text-base">
                  {(t('coinsSystem.whatFor.items', { returnObjects: true }) as string[]).map((item, i) => (
                    <li key={i} dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(item) }} />
                  ))}
                </ul>
              </div>
            </div>
            
            <div className="mt-4 text-center">
              <p className="text-xs sm:text-sm text-gray-600">
                {t('coinsSystem.disclaimer')} 
                <a href={localizedPath('/politica-invitados')} className="text-primario hover:underline ml-1">{t('coinsSystem.policyLink')}</a>
              </p>
            </div>
          </div>
        ) : null}
      </div>
    </AnimatedModal>
  );
};

export default HelpModal;
