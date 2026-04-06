import React, { useState, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { FiMail, FiPhone, FiMapPin, FiSend, FiLock, FiShield, FiUser } from 'react-icons/fi';
import { useLanguage } from '../contexts/LanguageContext';
import alertService from '../services/alertService';
import Loader from '../components/ui/Loader';
import { useAuth } from '../contexts/AuthContext';
import { api } from '../services/apiConfig';
import PhoneInputComponent from '../components/auth/form-inputs/PhoneInput';
import ProfileModal from '../components/profile/ProfileModal';
import CollapsibleSection from '../components/common/CollapsibleSection';
import Select from '../components/common/Select';
import { fluidSizing } from '../utils/fluidSizing';
import { useSEO } from '../hooks/useSEO';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';

const ContactPage: React.FC = () => {
  const { t } = useTranslation('contactPage');
  const { localizedPath } = useLanguage();

  // SEO: Meta tags optimizados para indexación en Google - Contacto
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    keywords: t('seo.keywords'),
    url: `${getBaseUrl()}/contacto`,
    type: 'website',
    image: OG_IMAGES.contacto,
    schema: {
      '@context': 'https://schema.org',
      '@type': 'ContactPage',
      'name': t('seo.schemaName'),
      'description': t('seo.schemaDescription'),
      'url': `${getBaseUrl()}/contacto`,
      'isPartOf': {
        '@type': 'WebSite',
        'name': 'My Store',
        'url': getBaseUrl()
      },
      'breadcrumb': {
        '@type': 'BreadcrumbList',
        'itemListElement': [
          {
            '@type': 'ListItem',
            'position': 1,
            'name': t('seo.breadcrumbHome'),
            'item': getBaseUrl()
          },
          {
            '@type': 'ListItem',
            'position': 2,
            'name': t('seo.breadcrumbContact'),
            'item': `${getBaseUrl()}/contacto`
          }
        ]
      },
      'mainEntity': {
        '@type': 'Organization',
        'name': 'My Store',
        'url': getBaseUrl(),
        'contactPoint': {
          '@type': 'ContactPoint',
          'contactType': 'customer service',
          'availableLanguage': 'Spanish'
        }
      }
    }
  });
  
  const { user, isAuthenticated } = useAuth();
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    subject: '',
    message: ''
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isProfileModalOpen, setIsProfileModalOpen] = useState(false);
  const [activeProfileSection, setActiveProfileSection] = useState<'profile' | 'addresses' | 'orders' | 'referrals'>('profile');

  // Control de autocompletado inicial y bloqueo selectivo (similar a Checkout)
  const [initialFilled, setInitialFilled] = useState<{ name: boolean; email: boolean; phone: boolean } | null>(null);
  const hasInitializedForm = useRef(false);

  // Opciones de asunto
  const subjectOptions = [
    { value: '', label: t('form.subjectPlaceholder') },
    { value: 'consulta_producto', label: t('form.subjects.productQuery') },
    { value: 'problema_pedido', label: t('form.subjects.orderProblem') },
    { value: 'devolucion', label: t('form.subjects.return') },
    { value: 'sugerencia', label: t('form.subjects.suggestion') },
    { value: 'otro', label: t('form.subjects.other') }
  ];

  // Autocompletar datos del usuario autenticado (una sola vez) sin sobreescribir lo que el usuario ya escribió
  useEffect(() => {
    if (isAuthenticated && user && !hasInitializedForm.current) {
      const userName = (user as any).display_name || (user as any).username || user.firstName || '';
      const userEmail = user.email || '';
      const userPhone = (user as any).phone || (user as any).billing?.phone || '';

      setFormData(prev => ({
        ...prev,
        name: prev.name || userName,
        email: prev.email || userEmail,
        phone: prev.phone || userPhone
      }));

      setInitialFilled({
        name: !!userName,
        email: !!userEmail,
        phone: !!userPhone,
      });

      hasInitializedForm.current = true;
    }
  }, [isAuthenticated, user]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value
    });
  };

  const handlePhoneChange = (value: string) => {
    setFormData({
      ...formData,
      phone: value
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);

    try {
      await api.post('/contact', formData);
      alertService.success(t('alerts.success'));
      setFormData({
        name: '',
        email: '',
        phone: '',
        subject: '',
        message: ''
      });
    } catch (error) {
      alertService.error(t('alerts.error'));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-3xl font-bold text-oscuro mb-6">{t('pageTitle')}</h1>
      
      <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
        <div>
          <CollapsibleSection
            title={t('form.sectionTitle')}
            icon={FiSend}
            collapsible={false}
            showCollapseButton={false}
            headerExtra={
              isAuthenticated ? (
                <button
                  onClick={() => {
                    setActiveProfileSection('profile');
                    setIsProfileModalOpen(true);
                  }}
                  className="text-white/90 hover:text-white bg-white/20 hover:bg-white/30 rounded-md transition-colors font-medium"
                  style={{ 
                    padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
                    fontSize: fluidSizing.text.xs
                  }}
                >
                  {t('form.myProfile')}
                </button>
              ) : undefined
            }
          >
            <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
              <div>
                <label htmlFor="name" className="block text-texto font-medium" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}>
                  {t('form.nameLabel')}
                </label>
                <input
                  type="text"
                  id="name"
                  name="name"
                  value={formData.name}
                  onChange={handleChange}
                  required
                  disabled={!!(isAuthenticated && user && initialFilled && initialFilled.name)}
                  className={`w-full border border-secundario/50 rounded-md focus:ring-primario focus:border-primario ${isAuthenticated && user && initialFilled && initialFilled.name ? 'bg-secundario/20 cursor-not-allowed' : ''}`}
                  style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm }}
                />
              </div>

              <div>
                <label htmlFor="email" className="block text-texto font-medium" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}>
                  {t('form.emailLabel')}
                </label>
                <input
                  type="email"
                  id="email"
                  name="email"
                  value={formData.email}
                  onChange={handleChange}
                  required
                  disabled={!!(isAuthenticated && user && initialFilled && initialFilled.email)}
                  className={`w-full border border-secundario/50 rounded-md focus:ring-primario focus:border-primario ${isAuthenticated && user && initialFilled && initialFilled.email ? 'bg-secundario/20 cursor-not-allowed' : ''}`}
                  style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm }}
                />
              </div>

              <div>
                <PhoneInputComponent
                  phone={formData.phone}
                  setPhone={handlePhoneChange}
                  disabled={!!(isAuthenticated && user && initialFilled && initialFilled.phone)}
                  showLabel={true}
                  skipUniqueValidation={true}
                />
              </div>

              <div>
                <label htmlFor="subject" className="block text-texto font-medium" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}>
                  {t('form.subjectLabel')}
                </label>
                <Select
                  id="subject"
                  name="subject"
                  value={formData.subject}
                  onChange={(value) => setFormData({ ...formData, subject: value })}
                  options={subjectOptions}
                  placeholder={t('form.subjectPlaceholder')}
                  required
                />
              </div>

              <div>
                <label htmlFor="message" className="block text-texto font-medium" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}>
                  {t('form.messageLabel')}
                </label>
                <textarea
                  id="message"
                  name="message"
                  value={formData.message}
                  onChange={handleChange}
                  required
                  rows={5}
                  className="w-full border border-secundario/50 rounded-md focus:ring-primario focus:border-primario"
                  style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm }}
                />
              </div>

              <div>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className={`w-full flex justify-center items-center rounded-md text-white bg-primario hover:bg-hover transition-colors ${isSubmitting ? 'opacity-75 cursor-not-allowed' : ''}`}
                  style={{ padding: fluidSizing.space.md, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.sm }}
                >
                  {isSubmitting ? (
                    <>
                      <Loader text="" size="small" />
                      <span>{t('form.submitting')}</span>
                    </>
                  ) : (
                    <>
                      <FiSend style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                      {t('form.submitButton')}
                    </>
                  )}
                </button>
              </div>
            </form>
          </CollapsibleSection>
        </div>
        
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.lg }}>
          <CollapsibleSection
            title={t('contactInfo.sectionTitle')}
            variant="soft"
            collapsible={false}
            showCollapseButton={false}
          >
            <ul style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
              <li className="flex items-start">
                <FiMapPin className="mt-1 text-primario flex-shrink-0" style={{ marginRight: fluidSizing.space.md, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('contactInfo.address')}</span>
              </li>
              <li className="flex items-start">
                <FiPhone className="mt-1 text-primario flex-shrink-0" style={{ marginRight: fluidSizing.space.md, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('contactInfo.phone')}</span>
              </li>
              <li className="flex items-start">
                <FiMail className="mt-1 text-primario flex-shrink-0" style={{ marginRight: fluidSizing.space.md, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('contactInfo.email')}</span>
              </li>
            </ul>
            
            <div className="border-t border-secundario/30" style={{ marginTop: fluidSizing.space.lg, paddingTop: fluidSizing.space.lg }}>
              <h3 className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.sm }}>{t('contactInfo.hoursTitle')}</h3>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('contactInfo.hoursWeekdays')}</p>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>{t('contactInfo.hoursWeekends')}</p>
            </div>
          </CollapsibleSection>
          
          <CollapsibleSection
            title={t('privacy.sectionTitle')}
            variant="soft"
            collapsible={false}
            showCollapseButton={false}
          >
            <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
              <div className="flex items-start">
                <FiShield className="mt-1 text-primario flex-shrink-0" style={{ marginRight: fluidSizing.space.md, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <div>
                  <h3 className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('privacy.dataProtectedTitle')}</h3>
                  <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('privacy.dataProtectedDesc')}</p>
                </div>
              </div>
              
              <div className="flex items-start">
                <FiLock className="mt-1 text-primario flex-shrink-0" style={{ marginRight: fluidSizing.space.md, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <div>
                  <h3 className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('privacy.noShareTitle')}</h3>
                  <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('privacy.noShareDesc')}</p>
                </div>
              </div>
              
              <div className="flex items-start">
                <FiUser className="mt-1 text-primario flex-shrink-0" style={{ marginRight: fluidSizing.space.md, width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <div>
                  <h3 className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{t('privacy.dataControlTitle')}</h3>
                  <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('privacy.dataControlDesc')}</p>
                </div>
              </div>
            </div>
            
            <div className="border-t border-secundario/30" style={{ marginTop: fluidSizing.space.lg, paddingTop: fluidSizing.space.lg }}>
              <a href={localizedPath('/privacidad')} className="text-primario hover:text-hover flex items-center transition-colors" style={{ fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}>
                {t('privacy.policyLink')}
                <span>→</span>
              </a>
            </div>
          </CollapsibleSection>
        </div>
      </div>

      {/* Modal de perfil */}
      {isAuthenticated && (
        <ProfileModal
          isOpen={isProfileModalOpen}
          onClose={() => setIsProfileModalOpen(false)}
          activeSection={activeProfileSection}
        />
      )}
    </div>
  );
};

export default ContactPage;
