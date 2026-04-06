import { useState, useEffect, useCallback, memo } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { gsap } from 'gsap';
import { FaFacebookF, FaInstagram, FaWhatsapp, FaTelegramPlane, FaCheckCircle, FaTiktok, FaTwitter, FaYoutube, FaLinkedinIn, FaPinterestP } from 'react-icons/fa';
import { api } from '../../services/apiConfig';
import alertService from '../../services/alertService';
import { useAuth } from '../../contexts/AuthContext';
import { useSocialNetworks } from '../../hooks/useSocialNetworks';
import benefitsApiService from '../../services/membership/benefitsApiService';
import MembershipBadge from '../common/MembershipBadge';
import { useMembership } from '../../contexts/MembershipContext';
import { useLanguage } from '../../contexts/LanguageContext';
import { useSiteFeatures } from '../../contexts/SiteConfigContext';

// Año actual (constante para evitar recreación en cada render)
const CURRENT_YEAR = new Date().getFullYear();

// Nota: Se removió la animación de aparición diferida del footer para que el texto
// se muestre inmediatamente sin esperar a que todo el footer esté visible.

// Mapeo de nombres de redes sociales a componentes de iconos
const socialIconMap: Record<string, React.ComponentType<{ className?: string }>> = {
  facebook: FaFacebookF,
  instagram: FaInstagram,
  whatsapp: FaWhatsapp,
  telegram: FaTelegramPlane,
  tiktok: FaTiktok,
  twitter: FaTwitter,
  youtube: FaYoutube,
  linkedin: FaLinkedinIn,
  pinterest: FaPinterestP,
};

// Función para normalizar el nombre de la red social y obtener el icono correcto
const getSocialIcon = (network: { name: string; icon?: string }) => {
  // Intentar primero con el campo icon si existe
  if (network.icon) {
    const iconKey = network.icon.toLowerCase().trim();
    if (socialIconMap[iconKey]) {
      return socialIconMap[iconKey];
    }
  }
  
  // Intentar con el nombre de la red social
  const nameKey = network.name.toLowerCase().trim();
  
  // Buscar coincidencias parciales
  if (nameKey.includes('facebook') || nameKey.includes('fb')) return FaFacebookF;
  if (nameKey.includes('instagram') || nameKey.includes('ig')) return FaInstagram;
  if (nameKey.includes('whatsapp') || nameKey.includes('wa')) return FaWhatsapp;
  if (nameKey.includes('telegram')) return FaTelegramPlane;
  if (nameKey.includes('tiktok')) return FaTiktok;
  if (nameKey.includes('twitter') || nameKey.includes('x.com')) return FaTwitter;
  if (nameKey.includes('youtube') || nameKey.includes('yt')) return FaYoutube;
  if (nameKey.includes('linkedin')) return FaLinkedinIn;
  if (nameKey.includes('pinterest')) return FaPinterestP;
  
  // Fallback a Instagram
  return FaInstagram;
};

interface FooterProps {
  compact?: boolean;
}

const Footer = memo(({ compact = false }: FooterProps) => {
  const { user, isAuthenticated } = useAuth();
  const { currentLevel } = useMembership();
  const { socialNetworks } = useSocialNetworks();
  const features = useSiteFeatures();
  const { t } = useTranslation('layoutFooter');
  const { t: tLegal } = useTranslation('layoutLegalFramework');
  const { localizedPath } = useLanguage();
  
  // Estados para el formulario de newsletter
  const [email, setEmail] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSubscribed, setIsSubscribed] = useState(false);
  
  // Estado para el beneficio de asesoría jurídica
  const [hasLegalAdvice, setHasLegalAdvice] = useState(false);
  const [legalAdviceWhatsapp, setLegalAdviceWhatsapp] = useState<string | null>(null);
  
  // Autocompletar email y verificar suscripción cuando hay usuario autenticado
  useEffect(() => {
    if (user?.email) {
      setEmail(user.email);
      setIsSubscribed(user.newsletter || false);
    } else {
      setEmail('');
      setIsSubscribed(false);
    }
  }, [user]);
  
  // Verificar si el usuario tiene el beneficio de asesoría jurídica
  useEffect(() => {
    const checkLegalAdviceBenefit = async () => {
      if (!isAuthenticated) {
        setHasLegalAdvice(false);
        setLegalAdviceWhatsapp(null);
        return;
      }
      
      try {
        const status = await benefitsApiService.getBenefitStatus('security_benefits');
        if (status?.is_enabled && status.config?.legal_advice) {
          setHasLegalAdvice(true);
          // Obtener el número de WhatsApp configurado o usar el por defecto
          const whatsapp = status.config?.legal_advice_whatsapp || '573225303310';
          setLegalAdviceWhatsapp(whatsapp);
        } else {
          setHasLegalAdvice(false);
          setLegalAdviceWhatsapp(null);
        }
      } catch {
        setHasLegalAdvice(false);
        setLegalAdviceWhatsapp(null);
      }
    };
    
    checkLegalAdviceBenefit();
  }, [isAuthenticated]);

  // Manejar envío del formulario de newsletter
  const handleNewsletterSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!email.trim()) {
      alertService.error(t('newsletter.emailRequired'));
      return;
    }
    
    setIsSubmitting(true);
    
    try {
      const response = await api.post('/starter/v1/newsletter/subscribe', { email });
      
      if (response.data.success) {
        alertService.success(response.data.message ?? t('newsletter.subscribeSuccess'));
        setIsSubscribed(true); // Actualizar estado inmediatamente
        setEmail('');
      } else {
        alertService.error(response.data.message ?? t('newsletter.subscribeError'));
      }
    } catch (error) {
      const errorMessage = error instanceof Error && 'response' in error && 
        typeof error.response === 'object' && error.response !== null &&
        'data' in error.response && 
        typeof error.response.data === 'object' && error.response.data !== null &&
        'message' in error.response.data && 
        typeof error.response.data.message === 'string'
        ? error.response.data.message 
        : t('newsletter.subscribeNetworkError');
      alertService.error(errorMessage);
    } finally {
      setIsSubmitting(false);
    }
  };

  // Se eliminó el efecto de entrada del footer para evitar retraso en el render de texto

  // Handler memoizado para scroll al top
  const handleScrollToTop = useCallback(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, []);

  // Animación para hover en elementos (con escala) - memoizado
  const handleMouseEnter = useCallback((e: React.MouseEvent<HTMLElement>) => {
    gsap.to(e.currentTarget, {
      scale: 1.05,
      color: 'var(--secundario)',
      duration: 0.3,
      ease: 'power2.out'
    });
  }, []);

  const handleMouseLeave = useCallback((e: React.MouseEvent<HTMLElement>) => {
    gsap.to(e.currentTarget, {
      scale: 1,
      color: '',
      duration: 0.3,
      ease: 'power2.out'
    });
  }, []);

  // Modo compacto: footer minimalista con fondo blanco
  if (compact) {
    return (
      <footer className="bg-primario sm:bg-white text-white sm:text-inherit">
        <div className="hidden sm:flex justify-center pt-6">
          <div className="w-4/5 h-px bg-gradient-to-r from-transparent via-primario to-transparent" />
        </div>
        <div className="container mx-auto px-4 py-6 md:py-8">
          {/* Contenido principal en 2 columnas */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-6">
            {/* Columna izquierda: Tu espacio seguro */}
            <div>
              <h3 className="text-sm font-bold text-white sm:text-primario mb-2">{tLegal('intro.title')}</h3>
              <p className="text-xs text-white/80 sm:text-gray-500 leading-relaxed mb-3">
                {tLegal('intro.subtitle')}
              </p>
              <div className="flex flex-wrap gap-2">
                <Link
                  to={localizedPath('/marco-legal')}
                  className="inline-flex items-center gap-1.5 text-xs font-medium text-white sm:text-primario hover:text-white/80 sm:hover:text-hover border border-white/30 sm:border-primario/20 hover:border-white/50 sm:hover:border-primario/40 rounded-full px-3 py-1 transition-colors duration-200"
                  onClick={handleScrollToTop}
                >
                  <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                  </svg>
                  {t('legal.framework')}
                </Link>
                <Link
                  to={localizedPath('/guia-requisa')}
                  className="inline-flex items-center gap-1.5 text-xs font-medium text-white sm:text-primario hover:text-white/80 sm:hover:text-hover border border-white/30 sm:border-primario/20 hover:border-white/50 sm:hover:border-primario/40 rounded-full px-3 py-1 transition-colors duration-200"
                  onClick={handleScrollToTop}
                >
                  <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                  {t('legal.guide')}
                </Link>
              </div>
            </div>

            {/* Columna derecha: Contacto */}
            <div>
              <h3 className="text-sm font-bold text-white sm:text-primario mb-2">{t('contact.title')}</h3>
              <p className="text-xs text-white/80 sm:text-gray-500 leading-relaxed mb-1">
                {t('contact.compactMessage')}
              </p>
              <p className="text-xs font-medium text-white/90 sm:text-gray-700 mb-3">
                {t('contact.compactEmail')}
              </p>
              <Link
                to={localizedPath('/contacto')}
                className="inline-flex items-center gap-1.5 text-xs font-medium text-white sm:text-primario hover:text-white/80 sm:hover:text-hover border border-white/30 sm:border-primario/20 hover:border-white/50 sm:hover:border-primario/40 rounded-full px-3 py-1 transition-colors duration-200"
                onClick={handleScrollToTop}
              >
                <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                </svg>
                {t('contact.compactCta')}
              </Link>
            </div>
          </div>

          {/* Barra inferior */}
          <div className="border-t border-white/20 sm:border-gray-100 pt-4 flex flex-col sm:flex-row sm:justify-between items-center gap-3 relative">

            <p className="text-xs text-white/60 sm:text-gray-400 order-2 sm:order-1">
              &copy; {CURRENT_YEAR} {t('bottom.copyright')}
            </p>

            {/* Redes sociales - centradas absolutamente */}
            <div className="flex items-center gap-3 order-1 sm:order-2 sm:absolute sm:left-1/2 sm:-translate-x-1/2">
              {socialNetworks.map((network, index) => {
                const IconComponent = getSocialIcon(network);
                return (
                  <a
                    key={`footer-compact-social-${index}`}
                    href={network.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-white/60 sm:text-gray-400 hover:text-white sm:hover:text-primario transition-colors duration-200"
                    aria-label={network.name}
                  >
                    <IconComponent className="h-4 w-4" />
                  </a>
                );
              })}
            </div>

            <div className="flex items-center gap-4 order-3">
              <Link
                to={localizedPath('/terminos')}
                className="text-xs text-white/60 sm:text-gray-400 hover:text-white sm:hover:text-primario transition-colors duration-200"
                onClick={handleScrollToTop}
              >
                {t('bottom.compactTerms')}
              </Link>
              <Link
                to={localizedPath('/privacidad')}
                className="text-xs text-white/60 sm:text-gray-400 hover:text-white sm:hover:text-primario transition-colors duration-200"
                onClick={handleScrollToTop}
              >
                {t('bottom.compactPrivacy')}
              </Link>
            </div>
          </div>
        </div>
      </footer>
    );
  }

  return (
    <footer className="bg-oscuro text-white pt-8 md:pt-12 pb-6">
      <div className="container mx-auto px-4">
        {/* Layout Masonry: 2 columnas principales */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8">
          {/* Columna Izquierda: Tu espacio seguro */}
          <div className="footer-animate">
            <h3 className="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-secundario">{tLegal('intro.title')}</h3>
            <p className="mb-3 text-claro text-sm md:text-base">
              {tLegal('intro.subtitle')}
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {(tLegal('items', { returnObjects: true }) as Array<{ id: string; title: string; description: string; fullWidth?: boolean }>).map((item) => (
                <div 
                  key={item.id}
                  className={`bg-white/5 border border-secundario/20 rounded-md px-3 py-2 ${item.fullWidth ? 'sm:col-span-2' : ''}`}
                >
                  <p className="text-claro text-xs md:text-sm">
                    <strong className="text-secundario">{item.title}:</strong> {item.description}
                  </p>
                </div>
              ))}
            </div>
          </div>

          {/* Columna Derecha: 2 filas verticales */}
          <div className="flex flex-col gap-6 md:gap-8">
            {/* Fila 1: Contacto | Legal (2 columnas horizontales) */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-6 md:gap-8">
              {/* Contacto */}
              <div className="footer-animate">
                <h3 className="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-secundario">{t('contact.title')}</h3>
                <ul className="space-y-2 md:space-y-3">
                  <li className="flex items-start">
                    <svg className="h-5 w-5 mr-2 text-secundario flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span className="text-claro text-sm md:text-base">{t('contact.items.online')}</span>
                  </li>
                  <li className="flex items-start">
                    <svg className="h-5 w-5 mr-2 text-secundario flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                    <span className="text-claro text-sm md:text-base">{t('contact.items.phone')}</span>
                  </li>
                  <li className="flex items-start">
                    <svg className="h-5 w-5 mr-2 text-secundario flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span className="text-claro text-sm md:text-base">{t('contact.items.email')}</span>
                  </li>
                  <li className="flex items-start">
                    <svg className="h-5 w-5 mr-2 text-secundario flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div className="text-claro text-sm md:text-base">
                      <p>{t('contact.items.scheduleWeek')}</p>
                      <p>{t('contact.items.scheduleWeekend')}</p>
                    </div>
                  </li>
                  <li className="flex items-start">
                    <svg className="h-5 w-5 mr-2 text-secundario flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    <Link
                      to={localizedPath('/contacto')}
                      className="text-claro hover:text-secundario transition-colors text-sm md:text-base"
                      onClick={handleScrollToTop}
                    >
                      {t('contact.items.message')}
                    </Link>
                  </li>
                </ul>
              </div>

              {/* Legal */}
              <div className="footer-animate">
                <h3 className="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-secundario">{t('legal.title')}</h3>
                <ul className="space-y-2">
                  <li>
                    <Link
                      to={localizedPath('/terminos')}
                      className="text-claro hover:text-acento transition-colors text-sm md:text-base"
                      onMouseEnter={handleMouseEnter}
                      onMouseLeave={handleMouseLeave}
                      onClick={handleScrollToTop}
                    >
                      {t('legal.terms')}
                    </Link>
                  </li>
                  <li>
                    <Link
                      to={localizedPath('/privacidad')}
                      className="text-claro hover:text-acento transition-colors text-sm md:text-base"
                      onMouseEnter={handleMouseEnter}
                      onMouseLeave={handleMouseLeave}
                      onClick={handleScrollToTop}
                    >
                      {t('legal.privacy')}
                    </Link>
                  </li>
                  {features.referrals_points && (
                    <li>
                      <Link
                        to={localizedPath('/politica-invitados')}
                        className="text-claro hover:text-acento transition-colors text-sm md:text-base"
                        onMouseEnter={handleMouseEnter}
                        onMouseLeave={handleMouseLeave}
                        onClick={handleScrollToTop}
                      >
                        {t('legal.guests')}
                      </Link>
                    </li>
                  )}
                  <li>
                    <Link
                      to={localizedPath('/guia-requisa')}
                      className="text-claro hover:text-acento transition-colors text-sm md:text-base"
                      onMouseEnter={handleMouseEnter}
                      onMouseLeave={handleMouseLeave}
                      onClick={handleScrollToTop}
                    >
                      {t('legal.guide')}
                    </Link>
                  </li>
                  <li>
                    <Link
                      to={localizedPath('/marco-legal')}
                      className="text-claro hover:text-acento transition-colors text-sm md:text-base"
                      onMouseEnter={handleMouseEnter}
                      onMouseLeave={handleMouseLeave}
                      onClick={handleScrollToTop}
                    >
                      {t('legal.framework')}
                    </Link>
                  </li>
                </ul>
                
                {/* Beneficio de Asesoría Jurídica - Botón de emergencia */}
                {hasLegalAdvice && legalAdviceWhatsapp && (
                  <div className="mt-5">
                    <div className="flex items-center gap-3">
                      <a
                        href={`https://wa.me/${legalAdviceWhatsapp}?text=${encodeURIComponent(
                          t('legalAdvice.whatsappMessage', {
                            name: (user?.firstName && user.firstName.trim()) || (user?.name && user.name.trim()) || (user?.email ? user.email.split('@')[0] : t('legalAdvice.anonymous')),
                            userInfo: isAuthenticated && user ? ` (ID: ${user.id}, Email: ${user.email})` : ''
                          })
                        )}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group relative flex items-center justify-center w-12 h-12 rounded-full bg-transparent border-2 border-secundario hover:bg-secundario/20 hover:scale-105 transition-all duration-300"
                        title={t('legalAdvice.title')}
                      >
                        <FaWhatsapp className="text-secundario text-xl group-hover:scale-110 transition-transform" />
                        <span className="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-secundario rounded-full animate-pulse" />
                      </a>
                      <div>
                        <p className="text-secundario font-semibold text-sm">{t('legalAdvice.title')}</p>
                        <div className="flex items-center gap-1.5">
                          <MembershipBadge level={currentLevel} size="xs" />
                          <p className="text-claro/70 text-xs">{t('legalAdvice.badge')}</p>
                        </div>
                      </div>
                    </div>
                    <p className="text-claro/50 text-[10px] mt-2 leading-tight max-w-[220px]">
                      {t('legalAdvice.warning')}
                    </p>
                  </div>
                )}
              </div>
            </div>

            {/* Fila 2: Newsletter (ocupa todo el ancho) */}
            <div className="footer-animate md:bg-white/5 md:border md:border-secundario/20 md:rounded-lg md:p-5">
              <h3 className="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-secundario">{t('newsletter.title')}</h3>
              
              {!isAuthenticated ? (
                // Estado: Usuario no autenticado - bloqueado
                <>
                  <p className="mb-3 md:mb-4 text-claro text-sm md:text-base">
                    {t('newsletter.cta')}
                  </p>
                  <div className="space-y-3 opacity-60">
                    <div>
                      <input
                        type="email"
                        placeholder={t('newsletter.placeholder')}
                        className="w-full px-4 py-2 rounded-md bg-white/10 border border-secundario/30 text-white/40 placeholder-white/30 cursor-not-allowed"
                        disabled
                      />
                    </div>
                    <button
                      type="button"
                      className="w-full bg-primario/50 text-white/60 font-medium py-2 px-4 rounded-md cursor-not-allowed"
                      disabled
                    >
                      {t('newsletter.button')}
                    </button>
                  </div>
                  <p className="text-claro/80 text-xs mt-2">
                    {t('newsletter.loginNotice')}
                  </p>
                </>
              ) : isSubscribed ? (
                // Estado: Usuario ya suscrito
                <div className="flex items-start gap-3">
                  <FaCheckCircle className="text-acento text-2xl flex-shrink-0 mt-0.5" />
                  <div>
                    <p className="text-claro text-sm md:text-base font-medium mb-1">
                      {t('newsletter.subscribedTitle')}
                    </p>
                    <p className="text-claro/80 text-xs md:text-sm">
                      {t('newsletter.subscribedMessage')}
                    </p>
                  </div>
                </div>
              ) : (
                // Estado: Usuario autenticado, no suscrito - formulario activo
                <>
                  <p className="mb-3 md:mb-4 text-claro text-sm md:text-base">
                    {t('newsletter.cta')}
                  </p>
                  <form className="space-y-3" onSubmit={handleNewsletterSubmit}>
                    <div>
                      <input
                        type="email"
                        placeholder={t('newsletter.placeholder')}
                        className="w-full px-4 py-2 rounded-md bg-white/10 border border-secundario/30 text-white placeholder-white/60 focus:outline-none focus:ring-2 focus:ring-acento"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        required
                      />
                    </div>
                    <button
                      type="submit"
                      className="w-full bg-primario hover:bg-hover text-white font-medium py-2 px-4 rounded-md transition-colors duration-300"
                      disabled={isSubmitting}
                    >
                      {isSubmitting ? t('newsletter.sending') : t('newsletter.button')}
                    </button>
                  </form>
                </>
              )}
            </div>
          </div>
        </div>

        {/* Línea divisoria */}
        <div className="border-t border-secundario/30 mt-8 md:mt-10 pt-4 md:pt-6">
          <div className="flex flex-col md:flex-row md:justify-between items-center gap-4 relative">
            {/* Redes sociales - centradas absolutamente para que no dependan de las secciones laterales */}
            <div className="flex items-center space-x-3 order-1 md:order-2 md:absolute md:left-1/2 md:-translate-x-1/2">
              {socialNetworks.map((network, index) => {
                const IconComponent = getSocialIcon(network);
                return (
                  <a
                    key={`footer-bottom-social-${index}`}
                    href={network.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-claro hover:text-acento transition-colors"
                    onMouseEnter={handleMouseEnter}
                    onMouseLeave={handleMouseLeave}
                    aria-label={network.name}
                  >
                    <IconComponent className="h-5 w-5" />
                  </a>
                );
              })}
            </div>
            
            <p className="text-claro text-xs md:text-sm text-center md:text-left order-2 md:order-1">
              &copy; {CURRENT_YEAR} {t('bottom.copyright')}
            </p>
            
            <div className="flex flex-wrap justify-center md:justify-end gap-3 md:space-x-4 order-3">
              <span className="text-claro/80 text-xs md:text-sm">
                {t('bottom.developedBy')}{' '}
                <a
                  href="https://sergioja.com"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-claro hover:text-acento transition-colors font-medium"
                  onMouseEnter={handleMouseEnter}
                  onMouseLeave={handleMouseLeave}
                >
                  {t('bottom.developerName')}
                </a>
              </span>
            </div>
          </div>
        </div>
      </div>
    </footer>
  );
});

Footer.displayName = 'Footer';

export default Footer;
