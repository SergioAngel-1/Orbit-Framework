import { useState, useEffect, useRef, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useLocation } from 'react-router-dom';
import { useLanguage } from '../contexts/LanguageContext';
import alertService from '../services/alertService';
import logger from '../utils/logger';
import passwordResetService from '../services/passwordResetService';
import { useAuth } from '../contexts/AuthContext';
import { useSEO } from '../hooks/useSEO';
import SiteLogo from '../components/common/SiteLogo';

// Componentes
import PasswordResetForm from '../components/auth/PasswordResetForm';
import StatusMessage from '../components/auth/StatusMessage';

/**
 * Componente para la página de restablecimiento de contraseña
 */
const ResetPasswordPage = () => {
  const { t } = useTranslation('resetPasswordPage');

  // SEO: Página privada - noindex
  useSEO({
    title: t('seo.title', { defaultValue: 'Restablecer Contraseña' }),
    description: t('seo.description', { defaultValue: 'Restablece tu contraseña.' }),
    noIndex: true,
  });

  const navigate = useNavigate();
  const location = useLocation();
  const { localizedPath } = useLanguage();
  const { isAuthenticated, logout } = useAuth();
  const [isLoading, setIsLoading] = useState(false);
  const [step, setStep] = useState<'reset' | 'error' | 'success'>('reset');

  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [key, setKey] = useState('');
  const [login, setLogin] = useState('');
  const [validToken, setValidToken] = useState(false);
  const hasValidated = useRef(false);

  // Manejar el caso de sesión existente y configuración inicial
  useEffect(() => {
    const processResetFlow = async () => {
      const params = new URLSearchParams(location.search);
      const keyParam = params.get('key');
      const loginParam = params.get('login');
    
      // Registrar información para depuración
      logger.info('reset-password', `URL de restablecimiento: ${location.pathname}${location.search}`);
      logger.info('reset-password', `Parámetros detectados: key=${keyParam ? keyParam.substring(0, 5) + '...' : 'no'}, login=${loginParam ?? 'no'}`);
    
      if (keyParam && loginParam) {
        // Decodificar los parámetros por si vienen codificados
        const decodedKey = decodeURIComponent(keyParam);
        const decodedLogin = decodeURIComponent(loginParam);
        
        setKey(decodedKey);
        setLogin(decodedLogin);

        if (isAuthenticated) {
          logger.info('reset-password', 'Sesión activa detectada, cerrando sesión automáticamente');
          try {
            await logout();
            alertService.info(t('alerts.sessionClosed'));
          } catch (error) {
            logger.error('reset-password', 'Error al cerrar sesión antes de restablecer contraseña', error);
          }
        }
    
        // Validar el token solo una vez usando ref para evitar race conditions
        if (!hasValidated.current) {
          hasValidated.current = true;
          await validateResetToken(decodedKey, decodedLogin);
        }
      } else {
        // Si no hay parámetros, redirigir al login
        logger.error('reset-password', 'Parámetros de URL incompletos o ausentes');
        alertService.error(t('alerts.invalidLink'));
        navigate(localizedPath('/iniciar-sesion'));
      }
    };

    processResetFlow();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.search]);

  // Validar el token de restablecimiento
  const validateResetToken = async (keyValue: string, loginValue: string) => {
    try {
      setIsLoading(true);
      
      // Registrar información para depuración
      logger.info('reset-password', `Intentando validar token: ${keyValue.substring(0, 5)}... para: ${loginValue}`);
      
      const data = await passwordResetService.validateResetToken(keyValue, loginValue);

      if (data.success) {
        setValidToken(true);
        alertService.success(t('alerts.tokenValid'));
      } else {
        setValidToken(false);
        const errorMessage = data.message ?? t('alerts.tokenInvalid');
        alertService.error(errorMessage);
        setStep('error');
        logger.error('reset-password', 'Token inválido', {
          error: data.error,
          error_code: data.error_code,
          message: data.message
        });
        
        // Redirigir al login después de 3 segundos
        setTimeout(() => {
          navigate(localizedPath('/iniciar-sesion'));
        }, 3000);
      }
    } catch (error: unknown) {
      setValidToken(false);
      
      logger.error('reset-password', 'Error al validar token', error);
      
      // Mensaje simple sin detalles técnicos
      alertService.error(t('alerts.tokenExpired'));
      setStep('error');
      
      // Redirigir al login después de 3 segundos
      setTimeout(() => {
        navigate(localizedPath('/iniciar-sesion'));
      }, 3000);
    } finally {
      setIsLoading(false);
    }
  };

  // Memoizar la validación de fortaleza de contraseña
  const passwordStrength = useMemo(
    () => passwordResetService.checkPasswordStrength(password),
    [password]
  );

  // Manejar cambio en el campo de contraseña
  const handlePasswordChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setPassword(e.target.value);
  };

  // Completar el restablecimiento de contraseña
  const handleCompleteReset = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!password || !confirmPassword) {
      alertService.error(t('alerts.fieldsRequired'));
      return;
    }

    if (password !== confirmPassword) {
      alertService.error(t('alerts.passwordsMismatch'));
      return;
    }

    if (passwordStrength.strength < 3) {
      alertService.error(t('alerts.passwordWeak', { message: passwordStrength.message }));
      return;
    }

    // Verificar que el token sea válido antes de intentar restablecer
    if (!validToken) {
      alertService.error(t('alerts.tokenNotValid'));
      setStep('error');
      navigate(localizedPath('/iniciar-sesion'), { replace: true });
      return;
    }

    try {
      setIsLoading(true);
      
      const data = await passwordResetService.completePasswordReset(key, login, password);

      if (data.success) {
        setStep('success');
        alertService.success(t('alerts.resetSuccess'));
        logger.info('reset-password', `Contraseña restablecida para usuario: ${data.username ?? login}`);
        
        // Redirigir al login después de 3 segundos
        setTimeout(() => {
          navigate(localizedPath('/iniciar-sesion'));
        }, 3000);
      } else {
        const errorMessage = data.message ?? t('alerts.resetError', { message: '' });
        alertService.error(errorMessage);
        logger.error('reset-password', 'Error al restablecer', {
          error: data.error,
          message: data.message
        });
        
        // Si hay un problema con el token, mostrar error y redirigir al login
        if (data.error_code === 'expired_key' || data.error_code === 'invalid_key') {
          setStep('error');
          setValidToken(false);
          
          // Redirigir al login después de 3 segundos
          setTimeout(() => {
            navigate(localizedPath('/iniciar-sesion'));
          }, 3000);
        }
      }
    } catch (error: unknown) {
      logger.error('reset-password', 'Error al completar restablecimiento', error);
      const errorMessage = error instanceof Error ? error.message : 'Error desconocido';
      alertService.error(t('alerts.resetError', { message: errorMessage }));
    } finally {
      setIsLoading(false);
    }
  };

  // Renderizar el formulario de restablecimiento de contraseña
  const renderResetForm = () => (
    <PasswordResetForm
      password={password}
      confirmPassword={confirmPassword}
      passwordStrength={passwordStrength}
      isLoading={isLoading}
      validToken={validToken}
      onPasswordChange={handlePasswordChange}
      onConfirmPasswordChange={(e) => setConfirmPassword(e.target.value)}
      onSubmit={handleCompleteReset}
    />
  );

  // Renderizar mensaje de error
  const renderErrorMessage = () => (
    <StatusMessage
      type="error"
      title={t('errorStatus.title')}
      message={t('errorStatus.message')}
      redirectText={t('errorStatus.redirect')}
      buttonText={t('errorStatus.button')}
    />
  );

  // Renderizar el mensaje de éxito
  const renderSuccessMessage = () => (
    <StatusMessage
      type="success"
      title={t('successStatus.title')}
      message={t('successStatus.message')}
      redirectText={t('successStatus.redirect')}
      buttonText={t('successStatus.button')}
    />
  );

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center py-8 px-4 sm:px-6 lg:px-8">
      <div className="w-full max-w-md">
        <div className="text-center mb-10">
          {/* Logo del sitio */}
          <div className="mb-6">
            <SiteLogo maxHeight={128} maxWidth={200} />
          </div>
          
          <h2 className="text-3xl font-bold text-gray-800">
            {step === 'reset' && t('titles.reset')}
            {step === 'error' && t('titles.error')}
            {step === 'success' && t('titles.success')}
          </h2>
          <p className="text-gray-600 mt-2">
            {step === 'reset' && t('subtitles.reset')}
            {step === 'error' && t('subtitles.error')}
            {step === 'success' && t('subtitles.success')}
          </p>
        </div>

        {step === 'reset' && renderResetForm()}
        {step === 'error' && renderErrorMessage()}
        {step === 'success' && renderSuccessMessage()}
      </div>
    </div>
  );
};

export default ResetPasswordPage;
