import logger from '../../utils/logger';
import i18n from '../../config/i18n';

/**
 * Función para extraer mensajes de error legibles de las respuestas de API
 * @param error Error recibido de la API
 * @returns Mensaje de error legible para el usuario
 */
export const getReadableErrorMessage = (error: any): string => {
  logger.info('getReadableErrorMessage', 'Analizando error:', error);
  
  if (error.response) {
    // El servidor respondió con un código de error
    logger.info('getReadableErrorMessage', `Status: ${error.response.status}, Data:`, error.response.data);
    
    // Extraer mensaje de error de la respuesta
    if (error.response.data) {
      // Si hay un mensaje explícito en la respuesta, usarlo
      if (error.response.data.message) {
        return error.response.data.message;
      }
      
      // Si la respuesta es un string, analizar su contenido
      if (typeof error.response.data === 'string') {
        const responseText = error.response.data.toLowerCase();
        
        // Errores comunes en la autenticación de WordPress
        if (responseText.includes('usuario no existe') || responseText.includes('user does not exist')) {
          return i18n.t('errors:generic.userNotExist');
        }
        if (responseText.includes('contraseña incorrecta') || responseText.includes('incorrect password')) {
          return i18n.t('errors:generic.incorrectPassword');
        }
        if (responseText.includes('error crítico')) {
          return i18n.t('errors:generic.serverCriticalError');
        }
        if (responseText.includes('invalid username') || responseText.includes('usuario inválido')) {
          return i18n.t('errors:generic.invalidUsername');
        }
        if (responseText.includes('email inválido') || responseText.includes('invalid email')) {
          return i18n.t('errors:generic.invalidEmail');
        }
        
        // Si no hay un patrón reconocido pero hay texto, mostrarlo
        if (responseText.length > 0) {
          return responseText.charAt(0).toUpperCase() + responseText.slice(1);
        }
      }
    }
    
    // Mensajes basados en códigos de estado HTTP
    switch (error.response.status) {
      case 400:
        return i18n.t('errors:generic.badRequest');
      case 401:
        return i18n.t('errors:generic.unauthorized');
      case 403:
        return i18n.t('errors:generic.forbidden');
      case 404:
        return i18n.t('errors:generic.notFound');
      case 500:
        return i18n.t('errors:generic.serverError');
      case 502:
        return i18n.t('errors:generic.badGateway');
      case 503:
        return i18n.t('errors:generic.serviceUnavailable');
      default:
        return i18n.t('errors:generic.requestErrorWithCode', { code: error.response.status });
    }
  } else if (error.request) {
    // La petición fue realizada pero no se recibió respuesta
    logger.info('getReadableErrorMessage', 'Error de conexión, no se recibió respuesta');
    return i18n.t('errors:generic.connectionError');
  } else if (error.message) {
    // Error con mensaje explícito
    logger.info('getReadableErrorMessage', 'Error con mensaje:', error.message);
    return error.message;
  }

  // Error general
  logger.info('getReadableErrorMessage', 'Error general no identificado');
  return i18n.t('errors:generic.requestError');
};

export default {
  getReadableErrorMessage
};
