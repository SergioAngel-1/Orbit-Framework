import { api } from '../apiConfig';
import logger from '../../utils/logger';
import { User } from '../types/user.types';
import secureStorage from '../../utils/secureStorage';
import i18n from '../../config/i18n';

/**
 * Servicio de autenticación de WordPress
 */
const authApiService = {
  /**
   * Solicitar restablecimiento de contraseña
   * @param email Email del usuario
   * @returns Promesa con el resultado de la solicitud
   */
  requestPasswordReset(email: string): Promise<{success: boolean, message: string}> {
    logger.info('authApiService', 'Solicitando restablecimiento de contraseña para:', { email });
    const GENERIC_SUCCESS_MESSAGE = i18n.t('errors:auth.passwordResetGenericSuccess');
    
    return new Promise((resolve, reject) => {
      api.post('/starter/v1/request-password-reset', { email })
        .then(response => {
          logger.info('authApiService', 'Respuesta de solicitud de restablecimiento recibida:', response.status);
          resolve({
            success: true,
            message: response.data?.message || GENERIC_SUCCESS_MESSAGE
          });
        })
        .catch(error => {
          logger.error('authApiService', 'Error al solicitar restablecimiento:', error);
          
          const status = error.response?.status;
          const serverMessage = error.response?.data?.message;
          
          if (status === 400 && serverMessage) {
            // Errores de validación (ej. formato inválido) sí se muestran
            reject(new Error(serverMessage));
            return;
          }
          
          reject(new Error(serverMessage || i18n.t('errors:auth.passwordResetRequestError')));
        });
    });
  },
  
  /**
   * Iniciar sesión con WordPress
   * @param identifier Usuario o email
   * @param password Contraseña
   * @returns Promesa con los datos del usuario
   */
  login(identifier: string, password: string): Promise<User> {
    logger.info('authApiService', 'Iniciando sesión con:', { identifier });

    return new Promise<User>((resolve, reject) => {
      // Usar el endpoint personalizado de autenticación
      logger.info('authApiService', 'Usando el endpoint personalizado de autenticación');
      api.post('/starter/v1/auth', {
        username: identifier,
        password: password
      })
        .then(response => {
          logger.info('authApiService', 'Respuesta de login recibida:', response.status);
          
          // Verificar si es una cuenta rechazada (ahora con código 200)
          if (response.data && response.data.rejected === true) {
            logger.warn('authApiService', 'Intento de login con cuenta rechazada (código 200)');
            
            // Construir usuario rechazado con datos de la respuesta
            const rejectedUser = {
              id: response.data.user_id || 0,
              username: response.data.user_nicename || identifier,
              email: response.data.user_email || identifier,
              firstName: '',
              lastName: '',
              displayName: response.data.user_display_name || identifier,
              rejected: true,
              pending: false
            };
            
            // Crear un error personalizado con información adicional
            const rejectedError: any = new Error(response.data.message || i18n.t('errors:auth.accountRejectedShort'));
            rejectedError.isRejectedAccount = true;
            rejectedError.rejectedUser = rejectedUser;
            
            logger.info('authApiService', 'Datos de usuario rechazado (código 200):', rejectedUser);
            reject(rejectedError);
            return;
          }
          
          // Verificar si es un usuario pendiente de aprobación (ahora viene como respuesta exitosa)
          if (response.data && response.data.pending === true) {
            logger.info('authApiService', 'Usuario pendiente de aprobación detectado');
            
            // Construir un usuario con estado pendiente
            const pendingUser: User = {
              id: response.data.user_id || 0,
              username: response.data.user_nicename || identifier,
              email: response.data.user_email || '',
              firstName: '',
              lastName: '',
              displayName: response.data.user_display_name || identifier,
              pending: true
            };
            
            // Rechazar con un error especial que incluye el usuario pendiente
            const pendingError: any = new Error(response.data.message || i18n.t('errors:auth.pendingApproval'));
            pendingError.pendingUser = pendingUser;
            pendingError.isPendingAccount = true;
            
            logger.info('authApiService', 'Datos de usuario pendiente (código 200):', pendingUser);
            reject(pendingError);
            return;
          }
          
          // Guardar token encriptado en localStorage para usuarios normales
          if (response.data && response.data.token) {
            secureStorage.setItem('authToken', response.data.token);
            logger.info('authApiService', 'Token guardado de forma segura');

            // Guardar CSRF token si viene en la respuesta
            if (response.data.csrf_token) {
              secureStorage.setItem('csrfToken', response.data.csrf_token);
              logger.info('authApiService', 'CSRF token guardado de forma segura');
            }

            // NOTA: No seteamos api.defaults.headers aquí.
            // El interceptor de request en apiConfig.ts lee el token desde secureStorage
            // en cada petición, que es la única fuente de verdad para el header Authorization.

            // Construir usuario minimo con la información disponible
            const minimalUser: User = {
              id: response.data.user_id || 0,
              username: response.data.user_nicename || identifier,
              email: response.data.user_email || '',
              firstName: '',
              lastName: '',
              displayName: response.data.user_display_name || identifier
            };

            // Intentar obtener datos completos del usuario
            this.getCurrentUser()
              .then(user => {
                logger.info('authApiService', 'Datos completos del usuario obtenidos');
                resolve(user);
              })
              .catch(error => {
                logger.warn('authApiService', 'No se pudieron obtener datos completos del usuario, usando datos mínimos', error);
                resolve(minimalUser);
              });
          } else {
            logger.error('authApiService', 'La respuesta no contiene un token válido:', response.data);
            reject(new Error(i18n.t('errors:auth.noValidToken')));
          }
        })
        .catch(error => {
          logger.error('authApiService', 'Error en login:', error);

          // Verificar si el error es debido a una cuenta pendiente o rechazada
          if (error.response && error.response.data) {
            logger.info('authApiService', 'Datos de error recibidos:', error.response.data);
            
            // Capturar el código de error específico si existe
            const errorCode = error.response.data.code || '';
            
            if (error.response.data.pending) {
              logger.warn('authApiService', 'Intento de login con cuenta pendiente de aprobación');
              reject(new Error(i18n.t('errors:auth.pendingApproval')));
              return;
            }
            
            if (error.response.data.rejected) {
              logger.warn('authApiService', 'Intento de login con cuenta rechazada');
              
              // Construir usuario rechazado con datos mínimos
              const rejectedUser = {
                id: 0,
                username: identifier,
                email: identifier,
                firstName: '',
                lastName: '',
                displayName: identifier,
                rejected: true,
                pending: false // Requerido por el tipo User
              };
              
              // Crear un error personalizado con información adicional
              const rejectedError: any = new Error(i18n.t('errors:auth.accountRejected'));
              rejectedError.isRejectedAccount = true;
              rejectedError.rejectedUser = rejectedUser;
              
              logger.info('authApiService', 'Datos de error de cuenta rechazada:', rejectedError);
              
              reject(rejectedError);
              return;
            }

            // Verificar errores específicos por código
            if (errorCode === 'incorrect_password') {
              logger.warn('authApiService', 'Contraseña incorrecta detectada');
              const customError: any = new Error(i18n.t('errors:auth.incorrectPassword'));
              customError.code = 'incorrect_password';
              reject(customError);
              return;
            }
            
            // Verificar si el error es debido a un correo no registrado
            if (errorCode === 'invalid_username' || 
                (error.response.data.message && error.response.data.message.includes('usuario no existe'))) {
              logger.warn('authApiService', 'Intento de login con correo no registrado');
              reject(new Error(i18n.t('errors:auth.emailNotRegistered')));
              return;
            }
          }

          reject(error);
        });
    });
  },

  /**
   * Registro de usuario
   * @param username Nombre de usuario
   * @param email Email
   * @param password Contraseña
   * @param phone Teléfono (opcional)
   * @param referralCode Código de referido (opcional)
   * @param cedula Cédula o documento de identidad (opcional)
   * @param acceptedTerms Aceptación de términos y condiciones
   * @param acceptedDataVeracity Aceptación de veracidad de datos
   * @returns Promesa con el resultado del registro
   */
  register(
    username: string, 
    email: string, 
    password: string, 
    phone?: string, 
    referralCode?: string, 
    cedula?: string,
    birthDate?: string,
    acceptedTerms?: boolean,
    acceptedDataVeracity?: boolean
  ): Promise<any> {
    logger.info('authApiService', 'Registrando nuevo usuario:', { username, email, phone, referralCode, cedula, birthDate });
    
    const userData = {
      username,
      email,
      password,
      phone,
      cedula,
      birth_date: birthDate,
      referral_code: referralCode,
      accepted_terms: acceptedTerms,
      accepted_data_veracity: acceptedDataVeracity
    };
    
    return api.post('/starter/v1/register', userData);
  },

  /**
   * Validar si un email está disponible (no registrado)
   * @param email Email a validar
   * @returns Promesa con el resultado de la validación
   */
  validateEmailUnique(email: string): Promise<{ is_unique: boolean; message: string }> {
    logger.info('authApiService', 'Validando unicidad de email:', email);
    
    return api.post('/starter/v1/validate/email', { email })
      .then((response: { data: { is_unique: boolean; message: string } }) => response.data)
      .catch((error: Error) => {
        logger.error('authApiService', 'Error validando email:', error);
        throw error;
      });
  },

  /**
   * Validar si un teléfono está disponible (no registrado)
   * @param phone Número de teléfono a validar
   * @returns Promesa con el resultado de la validación
   */
  validatePhoneUnique(phone: string): Promise<{ is_unique: boolean; message: string }> {
    logger.info('authApiService', 'Validando unicidad de teléfono:', phone);
    
    return api.post('/starter/v1/validate/phone', { phone })
      .then((response: { data: { is_unique: boolean; message: string } }) => response.data)
      .catch((error: Error) => {
        logger.error('authApiService', 'Error validando teléfono:', error);
        throw error;
      });
  },

  /**
   * Validar si una cédula está disponible (no registrada)
   * @param cedula Cédula a validar
   * @returns Promesa con el resultado de la validación
   */
  validateCedulaUnique(cedula: string): Promise<{ is_unique: boolean; message: string }> {
    logger.info('authApiService', 'Validando unicidad de cédula:', cedula);
    
    return api.post('/starter/v1/validate/cedula', { cedula })
      .then((response: { data: { is_unique: boolean; message: string } }) => response.data)
      .catch((error: Error) => {
        logger.error('authApiService', 'Error validando cédula:', error);
        throw error;
      });
  },

  /**
   * Comprobar si el usuario está autenticado
   * @returns true si hay un token de autenticación
   */
  isAuthenticated(): boolean {
    const token = secureStorage.getItem('authToken');
    return !!token;
  },

  /**
   * Obtener el usuario actual
   * @returns Promesa con los datos del usuario
   */
  getCurrentUser(): Promise<User> {
    logger.info('authApiService', 'Obteniendo usuario actual');
    
    return new Promise<User>((resolve, reject) => {
      const token = secureStorage.getItem('authToken');
      
      if (!token) {
        logger.warn('authApiService', 'No hay token de autenticación');
        reject(new Error(i18n.t('errors:auth.noActiveSession')));
        return;
      }
      
      // NOTA: No seteamos api.defaults.headers aquí.
      // El interceptor de request en apiConfig.ts lee el token desde secureStorage
      // en cada petición, que es la única fuente de verdad para el header Authorization.
      
      // Obtener datos del usuario desde endpoint estándar WP
      api.get('/wp/v2/users/me')
        .then(response => {
          logger.info('authApiService', 'Datos del usuario obtenidos');
          
          if (response.data && response.data.id) {
            // Construir objeto de usuario con los datos recibidos
            const user: User = {
              id: response.data.id,
              username: response.data.username || '',
              email: response.data.email || '',
              firstName: response.data.first_name || '',
              lastName: response.data.last_name || '',
              displayName: response.data.display_name || response.data.username || '',
              pending: response.data.pending || false,
              phone: response.data.phone || '',
              documentId: response.data.document_id || '',
              birthDate: response.data.birth_date || '',
              gender: response.data.gender || '',
              newsletter: response.data.newsletter || false
            };
            
            resolve(user);
          } else {
            logger.error('authApiService', 'Respuesta de API no contiene datos de usuario válidos:', response.data);
            reject(new Error(i18n.t('errors:auth.couldNotGetUserData')));
          }
        })
        .catch(error => {
          logger.error('authApiService', 'Error al obtener datos del usuario:', error);
          
          // Si el error es 401 (no autorizado), limpiar el token
          if (error.response && error.response.status === 401) {
            logger.warn('authApiService', 'Token inválido o expirado, cerrando sesión');
            secureStorage.removeItem('authToken');
          }
          
          reject(error);
        });
    });
  },
};

export default authApiService;
