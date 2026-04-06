import i18n from '../config/i18n';
import alertService from '../services/alertService';
import logger from './logger';

const t = (key: string, options?: Record<string, any>) => i18n.t(key, options);

/**
 * Manejador centralizado de errores para procesos de autenticación
 */
const errorHandler = {
  /**
   * Limpia etiquetas HTML de un mensaje de error
   * @param htmlMessage Mensaje con posibles etiquetas HTML
   * @returns Mensaje limpio sin etiquetas HTML
   */
  cleanHtmlMessage(htmlMessage: string): string {
    if (!htmlMessage) return '';
    
    // Crear un elemento temporal para extraer el texto
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = htmlMessage;
    return tempDiv.textContent || tempDiv.innerText || htmlMessage;
  },

  getRateLimitMessage(error: any): string | null {
    const statusCode = error?.response?.status || 0;
    const rateCode = error?.response?.data?.code || '';
    if (statusCode === 429 || rateCode === 'rate_limit_exceeded') {
      const retryAfter = Number(error?.response?.headers?.['retry-after'] || error?.response?.data?.retry_after || 0);
      const minutes = retryAfter > 0 ? Math.ceil(retryAfter / 60) : 0;
      return minutes > 0
        ? t('alerts:rateLimit.withMinutes', { minutes, minuteWord: minutes === 1 ? t('alerts:rateLimit.minute') : t('alerts:rateLimit.minutes') })
        : t('alerts:rateLimit.generic');
    }
    return null;
  },

  /**
   * Maneja errores de inicio de sesión
   * @param error Error capturado
   */
  handleLoginError(error: any): void {
    logger.error('Auth Error - Login', 'Error al iniciar sesión:', error);
    const rateMsg = this.getRateLimitMessage(error);
    if (rateMsg) {
      alertService.error(rateMsg);
      return;
    }
    
    // Verificar si el error ya viene con un código personalizado desde el servicio de autenticación
    if (error?.code === 'incorrect_password') {
      logger.info('Auth Error Handler', 'Error de contraseña incorrecta detectado');
      alertService.error(t('alerts:auth.incorrectPassword'));
      return;
    }
    
    // Extraer código de error específico si existe en la respuesta
    const errorCode = error?.response?.data?.code || '';
    
    // Extraer mensaje de error y limpiarlo de etiquetas HTML
    let errorMessage = error?.response?.data?.message || 
                      error?.message || 
                      t('alerts:auth.loginError');
    
    // Limpiar etiquetas HTML del mensaje
    errorMessage = this.cleanHtmlMessage(errorMessage);
    
    logger.info('Auth Error Handler', 'Mensaje de error limpio:', errorMessage);
    logger.info('Auth Error Handler', 'Código de error:', errorCode);
    
    // Detectar errores relacionados con cuentas pendientes de aprobación primero
    const isPendingAccount = errorMessage.toLowerCase().includes('pendiente') || 
                            errorMessage.toLowerCase().includes('pending') || 
                            errorMessage.toLowerCase().includes('approval');
    
    if (isPendingAccount) {
      logger.info('Auth Error Handler', 'Detectada cuenta pendiente de aprobación');
      alertService.warning(t('alerts:auth.pendingApproval'));
      return;
    }
    
    // Detectar errores específicos por código de error primero
    if (errorCode === 'incorrect_password') {
      logger.info('Auth Error Handler', 'Error de contraseña incorrecta detectado por código');
      alertService.error(t('alerts:auth.incorrectPassword'));
      return;
    } else if (errorCode === 'invalid_username') {
      alertService.error(t('alerts:auth.invalidUsername'));
      return;
    } else if (errorCode === 'empty_password') {
      alertService.error(t('alerts:auth.emptyPassword'));
      return;
    }
    
    // Detectar errores de contraseña incorrecta por el contenido del mensaje
    if (errorMessage.toLowerCase().includes('contraseña') && 
        (errorMessage.toLowerCase().includes('incorrecta') || errorMessage.toLowerCase().includes('incorrect'))) {
      logger.info('Auth Error Handler', 'Error de contraseña incorrecta detectado por mensaje');
      alertService.error(t('alerts:auth.incorrectPassword'));
      return;
    }
    
    // Si no hay código específico, intentar detectar por el contenido del mensaje
    if (errorMessage.toLowerCase().includes('no registrado') || 
        errorMessage.toLowerCase().includes('not registered') || 
        errorMessage.toLowerCase().includes('invalid_username') || 
        errorMessage.toLowerCase().includes('usuario no existe')) {
      alertService.error(t('alerts:auth.emailNotRegistered'));
    } else if (errorMessage.toLowerCase().includes('contraseña') || 
              errorMessage.toLowerCase().includes('password') || 
              errorMessage.toLowerCase().includes('incorrect') || 
              errorMessage.toLowerCase().includes('incorrecta')) {
      alertService.error(t('alerts:auth.incorrectPassword'));
    } else if (errorMessage.toLowerCase().includes('rechazada') || 
              errorMessage.toLowerCase().includes('rejected')) {
      alertService.error(t('alerts:auth.accountRejected'));
    } else {
      // Mostrar el mensaje de error específico
      alertService.error(errorMessage);
    }
  },

  /**
   * Maneja errores de registro de usuario
   * @param error Error capturado
   * @param callbacks Callbacks opcionales para acciones específicas
   * @returns true si el error fue manejado, false si no
   */
  handleRegisterError(error: any, callbacks?: {
    clearReferralCode?: () => void,
    setRegistrationForm?: () => void,
    resetForm?: () => void
  }): boolean {
    logger.error('Auth Error - Register', 'Error al registrar usuario:', error);
    const rateMsg = this.getRateLimitMessage(error);
    if (rateMsg) {
      alertService.error(rateMsg);
      return true;
    }
    
    // Asegurar que estamos en el formulario de registro cuando hay un error
    if (callbacks?.setRegistrationForm) {
      callbacks.setRegistrationForm();
    }
    
    // Extraer mensaje de error específico
    const errorMessage = error?.response?.data?.message || 
                        error?.message || 
                        t('alerts:network.registerError');
    
    // Registrar el error completo para depuración
    logger.error('Auth Error - Register', 'Mensaje de error completo:', errorMessage);
    
    // Si es necesario limpiar el formulario después de un error
    if (callbacks?.resetForm) {
      callbacks.resetForm();
    }
    
    // Verificar código de error específico del backend
    const errorCode = error?.response?.data?.code || '';
    
    // Usar el código de error específico devuelto por el backend si está disponible
    if (errorCode === 'username_exists' || error?.response?.data?.message?.includes('nombre de usuario ya está registrado')) {
      alertService.error(t('alerts:auth.usernameExists'));
      return true;
    }
    
    if (errorCode === 'email_exists' || error?.response?.data?.message?.includes('correo electrónico ya está registrado')) {
      alertService.error(t('alerts:auth.emailExists'));
      return true;
    }
    
    // Si no hay un código específico, analizar el mensaje de error
    const errorLowerCase = errorMessage.toLowerCase();
    
    // Detectar específicamente errores de correo electrónico ya existente
    if ((errorLowerCase.includes('email') || errorLowerCase.includes('correo')) && 
        (errorLowerCase.includes('exists') || errorLowerCase.includes('taken') || 
         errorLowerCase.includes('ya existe') || errorLowerCase.includes('already exists') || 
         errorLowerCase.includes('ya está registrado') || errorLowerCase.includes('already registered'))) {
      alertService.error(t('alerts:auth.emailExists'));
      return true;
    } 
    
    // Detectar específicamente errores de nombre de usuario ya existente
    if ((errorLowerCase.includes('username') || errorLowerCase.includes('nombre de usuario') || errorLowerCase.includes('usuario')) && 
        (errorLowerCase.includes('exists') || errorLowerCase.includes('taken') || 
         errorLowerCase.includes('ya existe') || errorLowerCase.includes('already exists') || 
         errorLowerCase.includes('ya está registrado') || errorLowerCase.includes('already registered'))) {
      alertService.error(t('alerts:auth.usernameExists'));
      return true;
    } 
    
    if (errorMessage.includes('referido') || errorMessage.includes('referral code') || 
        errorMessage.includes('código de referido')) {
      alertService.error(t('alerts:auth.invalidReferralCode'));
      
      // Limpiar el código de referido inválido si se proporcionó un callback
      if (callbacks?.clearReferralCode) {
        callbacks.clearReferralCode();
      }
      return true;
    }
    
    // Si llegamos aquí, es un error no específico
    alertService.error(errorMessage);
    return false;
  },

  /**
   * Maneja errores de restablecimiento de contraseña
   * @param error Error capturado
   */
  handlePasswordResetError(error: any): void {
    logger.error('Auth Error - Password Reset', 'Error al solicitar restablecimiento:', error);
    const rateMsg = this.getRateLimitMessage(error);
    if (rateMsg) {
      alertService.error(rateMsg);
      return;
    }
    
    const errorMessage = error?.response?.data?.message || 
                        error?.message || 
                        t('alerts:network.passwordResetError');
    
    // Errores específicos de restablecimiento de contraseña
    if (errorMessage.includes('no registrado') || errorMessage.includes('not registered') || 
        errorMessage.includes('no encontrado') || errorMessage.includes('not found')) {
      alertService.error(t('alerts:passwordReset.emailNotRegistered'));
    } else if (errorMessage.includes('demasiadas solicitudes') || errorMessage.includes('too many requests')) {
      alertService.error(t('alerts:passwordReset.tooManyRequests'));
    } else {
      alertService.error(errorMessage);
    }
  },

  /**
   * Maneja errores generales de autenticación
   * @param error Error capturado
   * @param context Contexto del error para el registro
   */
  handleAuthError(error: any, context: string): void {
    logger.error(`Auth Error - ${context}`, `Error de autenticación en ${context}:`, error);
    const rateMsg = this.getRateLimitMessage(error);
    if (rateMsg) {
      alertService.error(rateMsg);
      return;
    }
    
    const errorMessage = error?.response?.data?.message || 
                        error?.message || 
                        t('alerts:network.authError');
    
    alertService.error(errorMessage);
  },

  /**
   * Maneja errores específicos de validación de códigos de referido
   * @param error Error capturado
   * @returns Mensaje de error formateado para mostrar al usuario
   */
  handleReferralCodeError(error: any): string {
    logger.error('Referral Error', 'Error al validar código de referido:', error);
    const rateMsg = this.getRateLimitMessage(error);
    if (rateMsg) {
      return rateMsg;
    }
    
    // Extraer código de error específico si existe en la respuesta
    const errorCode = error?.response?.data?.code || '';
    const statusCode = error?.response?.status || 0;
    
    // Extraer mensaje de error y limpiarlo de etiquetas HTML
    let errorMessage = error?.response?.data?.message || 
                      error?.message || 
                      t('alerts:referral.validationError');
    
    // Limpiar etiquetas HTML del mensaje
    errorMessage = this.cleanHtmlMessage(errorMessage);
    
    logger.info('Referral Error Handler', `Mensaje de error limpio: ${errorMessage}`);
    logger.info('Referral Error Handler', `Código de error: ${errorCode}, Status: ${statusCode}`);
    
    // Manejar errores específicos
    if (statusCode === 404 || errorMessage.includes('No route was found')) {
      return t('alerts:referral.serverError');
    }
    
    if (errorCode === 'invalid_code' || errorMessage.includes('inválido')) {
      return t('alerts:referral.invalidCodeMessage');
    }
    
    if (errorCode === 'own_code') {
      return t('alerts:referral.ownCode');
    }
    
    // Mensaje genérico para otros errores
    return errorMessage;
  }
};

export default errorHandler;
