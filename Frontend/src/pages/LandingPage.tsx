/**
 * LandingPage - Página de autenticación (Login/Register)
 * 
 * Refactorizado para:
 * - Separar lógica en hooks personalizados (useLoginForm, useRegisterForm)
 * - Corregir bug de navigate('/pending-approval') → usar modal
 * - Mejorar botón "Volver" para manejar historial vacío
 * - Limpiar código duplicado y mejorar mantenibilidad
 * 
 * @package Starter
 */

import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';
import DOMPurify from 'dompurify';
import logger from '../utils/logger';
import { fluidSizing } from '../utils/fluidSizing';
import alertService from '../services/alertService';
import { systemApiService } from '../services/system/systemApiService';
import { LoginForm, RegisterForm, PasswordResetModal } from '../components/auth';
import { HeroSection, AuthFormContainer, LandingPageMobile } from '../components/landing';
import Footer from '../components/layout/Footer';
import useLoginForm from '../hooks/useLoginForm';
import useRegisterForm from '../hooks/useRegisterForm';
import { useLanguage, getLangFromPath } from '../contexts/LanguageContext';
import { useSEO } from '../hooks/useSEO';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';
import i18n from '../config/i18n';
import LanguageSwitch from '../components/common/LanguageSwitch';

const LandingPage = () => {
  const { t } = useTranslation('landingPage');
  const { localizedPath } = useLanguage();
  const location = useLocation();
  const navigate = useNavigate();

  // Determinar el formulario inicial basado en la ruta actual (sin prefijo de idioma)
  const { pathWithoutLang } = getLangFromPath(location.pathname);
  const isRegisterRoute = pathWithoutLang === '/registrarse' || pathWithoutLang === '/register';

  // SEO: Diferente configuración para /login vs /register
  // /login → noIndex (página privada, no aporta valor SEO)
  // /register → indexable con canonical (evita duplicados por ?ref=)
  // og:url incluye ?ref= si existe, para que WhatsApp/redes preserven el código de referido
  // canonicalUrl siempre limpia (sin ?ref=) para SEO deduplication
  const registerOgUrl = location.search && isRegisterRoute
    ? `${getBaseUrl()}/registrarse${location.search}`
    : `${getBaseUrl()}/registrarse`;

  useSEO(isRegisterRoute ? {
    title: t('seo.title'),
    description: t('seo.description'),
    keywords: t('seo.keywords'),
    url: registerOgUrl,
    canonicalUrl: `${getBaseUrl()}/registrarse`,
    image: OG_IMAGES.home,
  } : {
    title: t('seo.loginTitle', { defaultValue: i18n.t('seo:pages.login.title') }),
    description: t('seo.loginDescription', { defaultValue: i18n.t('seo:pages.login.description') }),
    url: `${getBaseUrl()}/iniciar-sesion`,
    image: OG_IMAGES.home,
    noIndex: true,
  });
  
  // Detectar si es mobile (< 1024px que es lg en Tailwind)
  const [isMobile, setIsMobile] = useState(() => 
    typeof window !== 'undefined' ? window.innerWidth < 1024 : false
  );
  
  // Listener para cambios de tamaño de ventana
  useEffect(() => {
    const handleResize = () => {
      setIsMobile(window.innerWidth < 1024);
    };
    
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);
  const [isLoginForm, setIsLoginForm] = useState(!isRegisterRoute);
  const [isResetModalOpen, setIsResetModalOpen] = useState(false);
  const [autoApprovalEnabled, setAutoApprovalEnabled] = useState<boolean | null>(null);

  // Cargar configuración de aprobación automática
  useEffect(() => {
    const loadConfig = async () => {
      try {
        const config = await systemApiService.getPublicConfig();
        setAutoApprovalEnabled(config.registration?.auto_approval_enabled ?? false);
      } catch (error) {
        logger.error('LandingPage', 'Error cargando configuración:', error);
        setAutoApprovalEnabled(false);
      }
    };
    loadConfig();
  }, []);

  // Hook para lógica de login
  const loginForm = useLoginForm({
    onSuccess: () => {
      logger.info('LandingPage', 'Login exitoso');
    }
  });

  // Hook para lógica de registro
  const registerForm = useRegisterForm({
    onSuccess: () => {
      // Después de registro exitoso con auto-aprobación, cambiar a login
      setTimeout(() => {
        setIsLoginForm(true);
        // Pre-llenar el campo de identificador con el email
        loginForm.setIdentifier(registerForm.email);
      }, 1500);
    }
  });

  /**
   * Sanitiza y valida un código de referido
   * Formato esperado: username-sanitizado + 4 dígitos (ej: juan-perez1234, admin5678)
   * Generado por el backend con: sanitize_title(username) + mt_rand(1000, 9999)
   */
  const sanitizeReferralCode = useCallback((refCode: string): string | null => {
    logger.info('LandingPage', 'Código de invitación detectado (sin sanitizar):', refCode);
    
    // SANITIZACIÓN: Eliminar cualquier tag HTML o script
    const sanitizedCode = DOMPurify.sanitize(refCode, {
      ALLOWED_TAGS: [],
      ALLOWED_ATTR: []
    }).trim().toLowerCase(); // Convertir a minúsculas (el backend usa sanitize_title)
    
    // LIMPIEZA: Solo permitir caracteres válidos (letras minúsculas, números, guiones)
    const cleanCode = sanitizedCode.replace(/[^a-z0-9-]/g, '');
    
    // VALIDACIÓN COMPLETA en una sola verificación:
    // - Formato: letras/números/guiones + exactamente 4 dígitos al final
    // - Longitud: mínimo 5 caracteres (1 char + 4 dígitos), máximo 50
    const isValidFormat = /^[a-z0-9-]+\d{4}$/.test(cleanCode);
    const isValidLength = cleanCode.length >= 5 && cleanCode.length <= 50;
    
    if (!isValidFormat || !isValidLength) {
      logger.warn('LandingPage', 'Código de invitación inválido:', { 
        original: refCode, 
        cleaned: cleanCode,
        isValidFormat,
        isValidLength 
      });
      alertService.warning(t('alerts.invalidReferralCode'));
      return null;
    }
    
    return cleanCode;
  }, []);

  // Sincronizar el estado del formulario con la ruta cuando cambia la URL
  // Si /login tiene parámetros, limpiar la URL (los parámetros solo son válidos en /register)
  useEffect(() => {
    const { pathWithoutLang: cleanPath } = getLangFromPath(location.pathname);
    const isRegister = cleanPath === '/registrarse' || cleanPath === '/register';
    setIsLoginForm(!isRegister);
    
    // Si estamos en /login y hay parámetros en la URL, limpiarlos
    if ((cleanPath === '/iniciar-sesion' || cleanPath === '/login') && location.search) {
      logger.info('LandingPage', 'Limpiando parámetros de URL en /login');
      navigate(localizedPath('/iniciar-sesion'), { replace: true });
    }
  }, [location.pathname, location.search, navigate, localizedPath]);
  
  // Obtener y procesar el código de referido de la URL SOLO en /register
  // Usar useRef para evitar múltiples ejecuciones
  const refCodeProcessedRef = useRef<string | null>(null);
  
  useEffect(() => {
    const { pathWithoutLang: cleanPath } = getLangFromPath(location.pathname);
    if (cleanPath !== '/registrarse' && cleanPath !== '/register') {
      refCodeProcessedRef.current = null; // Reset cuando salimos de /register
      return;
    }
    
    const params = new URLSearchParams(location.search);
    const refCode = params.get('ref');
    
    // Si no hay código o ya procesamos este mismo código, no hacer nada
    if (!refCode || refCodeProcessedRef.current === refCode) {
      return;
    }
    
    // Marcar como procesado ANTES de validar para evitar múltiples ejecuciones
    refCodeProcessedRef.current = refCode;
    
    const sanitizedCode = sanitizeReferralCode(refCode);
    
    if (sanitizedCode) {
      logger.info('LandingPage', 'Código de invitación sanitizado:', sanitizedCode);
      registerForm.setReferralCode(sanitizedCode);
      
      // Validar automáticamente después de un pequeño delay
      // Pasamos el código directamente para evitar stale closure
      setTimeout(() => {
        registerForm.validateReferralCode(sanitizedCode);
      }, 300);
    }
  }, [location.pathname, location.search, sanitizeReferralCode, registerForm.setReferralCode, registerForm.validateReferralCode]);

  /**
   * Maneja el botón "Volver" - siempre va al home
   * No usamos navigate(-1) porque podría llevar a /login o /register
   */
  const handleGoBack = useCallback(() => {
    navigate(localizedPath('/'));
  }, [navigate]);

  /**
   * Alterna entre formularios de login y registro
   */
  const toggleForm = useCallback(() => {
    // Limpiar formularios
    loginForm.resetForm();
    registerForm.resetForm();
    
    // Cambiar la URL sin recargar la página
    if (isLoginForm) {
      logger.info('LandingPage', 'Cambiando a formulario de registro');
      navigate(localizedPath('/registrarse'), { replace: true });
    } else {
      logger.info('LandingPage', 'Cambiando a formulario de login');
      navigate(localizedPath('/iniciar-sesion'), { replace: true });
    }
    
    setIsLoginForm(!isLoginForm);
  }, [isLoginForm, navigate, loginForm, registerForm]);

  // Funciones para modal de recuperación de contraseña
  const openResetModal = useCallback(() => setIsResetModalOpen(true), []);
  const closeResetModal = useCallback(() => setIsResetModalOpen(false), []);

  // Componente de formulario memoizado para evitar recreación
  const formContent = useMemo(() => (
    isLoginForm ? (
      <LoginForm
        onSubmit={loginForm.handleLogin}
        identifier={loginForm.identifier}
        setIdentifier={loginForm.setIdentifier}
        password={loginForm.password}
        setPassword={loginForm.setPassword}
        loading={loginForm.loading}
        onResetPassword={openResetModal}
      />
    ) : (
      <RegisterForm
        onSubmit={registerForm.handleRegister}
        identifier={registerForm.username}
        setIdentifier={registerForm.setUsername}
        email={registerForm.email}
        setEmail={registerForm.setEmail}
        password={registerForm.password}
        setPassword={registerForm.setPassword}
        phone={registerForm.phone}
        setPhone={registerForm.setPhone}
        cedula={registerForm.cedula}
        setCedula={registerForm.setCedula}
        referralCode={registerForm.referralCode}
        setReferralCode={registerForm.setReferralCode}
        referrerName={registerForm.referrerName}
        birthDate={registerForm.birthDate}
        setBirthDate={registerForm.setBirthDate}
        acceptedDataVeracity={registerForm.acceptedDataVeracity}
        setAcceptedDataVeracity={registerForm.setAcceptedDataVeracity}
        acceptedTerms={registerForm.acceptedTerms}
        setAcceptedTerms={registerForm.setAcceptedTerms}
        loading={registerForm.loading}
        validatingReferralCode={registerForm.validatingReferralCode}
        onReferralCodeChange={registerForm.handleReferralCodeChange}
        onValidateReferralCode={registerForm.validateReferralCode}
      />
    )
  ), [
    isLoginForm,
    loginForm.handleLogin, loginForm.identifier, loginForm.setIdentifier,
    loginForm.password, loginForm.setPassword, loginForm.loading,
    registerForm.handleRegister, registerForm.username, registerForm.setUsername,
    registerForm.email, registerForm.setEmail, registerForm.password, registerForm.setPassword,
    registerForm.phone, registerForm.setPhone, registerForm.cedula, registerForm.setCedula,
    registerForm.referralCode, registerForm.setReferralCode, registerForm.referrerName,
    registerForm.birthDate, registerForm.setBirthDate,
    registerForm.acceptedDataVeracity, registerForm.setAcceptedDataVeracity,
    registerForm.acceptedTerms, registerForm.setAcceptedTerms,
    registerForm.loading, registerForm.validatingReferralCode,
    registerForm.handleReferralCodeChange, registerForm.validateReferralCode,
    openResetModal
  ]);

  // Renderizado Mobile
  if (isMobile) {
    return (
      <>
        <LandingPageMobile
          isLoginForm={isLoginForm}
          onGoBack={handleGoBack}
        >
          {formContent}
          
          {/* Botón para cambiar entre login/register */}
          <div className="mt-4 text-center">
            <button
              type="button"
              onClick={toggleForm}
              className="text-primario hover:text-primario/80 font-medium border-none bg-transparent"
              style={{ fontSize: fluidSizing.text.sm }}
            >
              {isLoginForm ? t('toggleRegister') : t('toggleLogin')}
            </button>
          </div>
        </LandingPageMobile>

        <Footer compact />
        
        <PasswordResetModal
          isOpen={isResetModalOpen}
          onClose={closeResetModal}
        />
      </>
    );
  }

  // Renderizado Desktop (original)
  return (
    <>
      {/* Botón Volver + Idioma */}
      <div className="fixed top-4 left-0 z-50 flex items-center justify-between w-full px-4">
        <button
          onClick={handleGoBack}
          className="flex items-center gap-2 px-4 py-2 bg-white/90 hover:bg-white rounded-lg shadow-md hover:shadow-lg transition-all duration-300 text-primario font-semibold"
        >
          <svg 
            className="w-5 h-5" 
            fill="none" 
            stroke="currentColor" 
            viewBox="0 0 24 24"
          >
            <path 
              strokeLinecap="round" 
              strokeLinejoin="round" 
              strokeWidth={2} 
              d="M10 19l-7-7m0 0l7-7m-7 7h18" 
            />
          </svg>
          <span className="hidden sm:inline">{t('backButton')}</span>
        </button>
        <LanguageSwitch variant="mobile" />
      </div>

      {/* Sección derecha: Hero - 50% fixed */}
      <HeroSection />

      {/* Sección izquierda: Formulario + Footer - scrollable */}
      <div className="w-full lg:w-1/2 min-h-screen flex flex-col bg-white">
        <div className="flex-1 flex items-center justify-center p-4 sm:p-8 lg:p-12">
          <AuthFormContainer
            title={isLoginForm ? t('desktop.loginTitle') : t('desktop.registerTitle')}
            subtitle={isLoginForm
              ? t('desktop.loginSubtitle')
              : (autoApprovalEnabled ? '' : t('desktop.registerSubtitle'))}
            toggleForm={toggleForm}
            toggleButtonText={isLoginForm
              ? t('desktop.loginToggle')
              : t('desktop.registerToggle')}
          >
            {formContent}
          </AuthFormContainer>
        </div>

        <Footer compact />
      </div>

      {/* Modal de recuperación de contraseña */}
      <PasswordResetModal
        isOpen={isResetModalOpen}
        onClose={closeResetModal}
      />
    </>
  );
};

export default LandingPage;
