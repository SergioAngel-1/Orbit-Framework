import { api } from '../../services/apiConfig';
import { User } from '../types/auth.types';
import logger from '../../utils/logger';
import { mapApiUserToUser } from './auth.utils';

/**
 * Actualiza el perfil de usuario
 * @param profileData Datos del perfil a actualizar
 * @returns Promesa con la respuesta de la API
 */
export const updateUserProfile = async (profileData: Partial<User>): Promise<any> => {
  try {
    logger.info('ProfileUtils', 'Datos enviados a la API:', profileData);
    
    const response = await api.post('/starter/v1/user/profile', profileData);
    
    logger.info('ProfileUtils', 'Respuesta del servidor:', response.data);
    
    return response.data;
  } catch (error: any) {
    logger.error('ProfileUtils', 'Error al actualizar perfil', error);
    throw error;
  }
};

/**
 * Obtiene los datos actualizados del usuario.
 * Fuente principal: /starter/v1/user/profile (datos completos: phone, documentId, birthDate, etc.)
 * Complemento: /wp/v2/users/me (campos WP: addresses, defaultAddress, pending, roles)
 * Fallback: solo /wp/v2/users/me si el endpoint de profile falla
 * @returns Promesa con los datos del usuario
 */
export const fetchCurrentUser = async (): Promise<User | null> => {
  try {
    // Paso 1: Obtener datos base de WordPress (addresses, defaultAddress, pending, roles)
    const userResponse = await api.get('/wp/v2/users/me');
    
    if (!userResponse?.data) {
      return null;
    }

    const wpData = userResponse.data;

    // Paso 2: Enriquecer con datos completos del perfil custom
    try {
      const profileResponse = await api.get('/starter/v1/user/profile');
      
      if (profileResponse?.data) {
        const profileData = profileResponse.data;
        
        // Merge: profile como fuente principal, WP como complemento para campos no cubiertos
        const mergedData = {
          ...wpData,
          // Campos del profile (fuente principal — sobrescriben WP)
          firstName: profileData.firstName || wpData.first_name || '',
          first_name: profileData.firstName || wpData.first_name || '',
          lastName: profileData.lastName || wpData.last_name || '',
          last_name: profileData.lastName || wpData.last_name || '',
          email: profileData.email || wpData.email || '',
          phone: profileData.phone || '',
          documentId: profileData.documentId || '',
          birthDate: profileData.birthDate || '',
          gender: profileData.gender || '',
          newsletter: profileData.newsletter || false,
          active: profileData.active || false,
          acceptedTerms: profileData.acceptedTerms || false,
          acceptedTermsDate: profileData.acceptedTermsDate || null,
          acceptedDataVeracity: profileData.acceptedDataVeracity || false,
          acceptedDataVeracityDate: profileData.acceptedDataVeracityDate || null,
          // Avatar: preferir el custom del profile si existe
          avatar: profileData.avatar || wpData.avatar_urls?.['96'] || '',
          // Campos WP que no vienen del profile (se mantienen del wpData)
          // addresses, defaultAddress, pending, roles — ya están en wpData
        };

        logger.info('ProfileUtils', 'Usuario obtenido con datos completos del perfil');
        return mapApiUserToUser(mergedData);
      }
    } catch (profileError) {
      logger.warn('ProfileUtils', 'No se pudo obtener perfil completo, usando solo datos de WP', profileError);
    }

    // Fallback: solo datos de WordPress
    return mapApiUserToUser(wpData);
  } catch (error) {
    logger.error('ProfileUtils', 'Error al obtener datos del usuario', error);
    return null;
  }
};

/**
 * Actualiza el usuario con los datos del perfil
 * @param user Usuario actual
 * @param profileData Datos del perfil
 * @returns Usuario actualizado
 */
export const updateUserWithProfileData = (user: User | null, profileData: Partial<User>): User | null => {
  if (!user) return null;
  
  return {
    ...user,
    ...profileData
  };
};
