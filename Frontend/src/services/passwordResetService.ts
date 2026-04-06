import { api } from './apiConfig';
import logger from '../utils/logger';
import i18n from '../config/i18n';

/**
 * Servicio para manejar las operaciones relacionadas con el restablecimiento de contraseña
 */
const passwordResetService = {
  /**
   * Valida un token de restablecimiento de contraseña
   * @param keyValue - Token de restablecimiento
   * @param loginValue - Usuario o email
   * @returns Respuesta con el resultado de la validación
   */
  async validateResetToken(keyValue: string, loginValue: string) {
    try {
      logger.info('reset-password', `Validando token: ${keyValue.substring(0, 5)}... para usuario: ${loginValue}`);
      
      const response = await api.post('/starter/v1/validate-password-reset', {
        key: keyValue,
        login: loginValue,
      });

      logger.info('reset-password', 'Respuesta recibida:', response.data);
      
      return response.data;
    } catch (error) {
      logger.error('reset-password', 'Error al validar token', error);
      throw error;
    }
  },

  /**
   * Completa el proceso de restablecimiento de contraseña
   * @param key - Token de restablecimiento
   * @param login - Usuario o email
   * @param password - Nueva contraseña
   * @returns Respuesta con el resultado del restablecimiento
   */
  async completePasswordReset(key: string, login: string, password: string) {
    try {
      logger.info('reset-password', `Intentando restablecer contraseña para usuario: ${login}`);
      
      const response = await api.post('/starter/v1/complete-password-reset', {
        key,
        login,
        password,
      });

      logger.info('reset-password', 'Respuesta recibida:', response.data);
      
      return response.data;
    } catch (error) {
      logger.error('reset-password', 'Error al completar restablecimiento', error);
      throw error;
    }
  },

  /**
   * Verifica la fortaleza de una contraseña
   * @param password - Contraseña a verificar
   * @returns Objeto con la fortaleza y mensaje
   */
  checkPasswordStrength(password: string) {
    let strength = 0;
    let message = '';

    // Longitud mínima
    if (password.length < 8) {
      message = i18n.t('passwordReset:strength.minLength');
      return { strength, message };
    }

    strength++;

    // Verificar si contiene números
    if (/\d/.test(password)) {
      strength++;
    } else {
      message = i18n.t('passwordReset:strength.needNumber');
      return { strength, message };
    }

    // Verificar si contiene letras mayúsculas y minúsculas
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) {
      strength++;
    } else {
      message = i18n.t('passwordReset:strength.needCase');
      return { strength, message };
    }

    // Verificar si contiene caracteres especiales
    if (/[^a-zA-Z\d]/.test(password)) {
      strength++;
    } else {
      message = i18n.t('passwordReset:strength.needSpecial');
      return { strength, message };
    }

    return { strength, message: i18n.t('passwordReset:strength.strong') };
  }
};

export default passwordResetService;
