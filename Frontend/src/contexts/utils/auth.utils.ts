import { User } from '../types/auth.types';
import secureStorage from '../../utils/secureStorage';

/**
 * Mapea los datos de la respuesta de la API a un objeto de usuario
 * @param userData Datos del usuario desde la API
 * @returns Objeto de usuario formateado
 */
export const mapApiUserToUser = (userData: any): User => {
  return {
    id: userData.id,
    name: userData.name || userData.displayName || userData.username || '',
    email: userData.email || '',
    avatar: userData.avatar || userData.avatar_urls?.['96'] || '',
    addresses: userData.addresses || [],
    defaultAddress: userData.defaultAddress || null,
    pending: userData.pending || false,
    firstName: userData.first_name || userData.firstName || '',
    lastName: userData.last_name || userData.lastName || '',
    phone: userData.phone || '',
    birthDate: userData.birthDate || '',
    gender: userData.gender || '',
    newsletter: userData.newsletter || false,
    active: userData.active || false,
    emailChangePending: userData.emailChangePending || false,
    newEmail: userData.newEmail || '',
    documentId: userData.documentId || userData.document_id || '',
    acceptedTerms: userData.acceptedTerms || userData.accepted_terms || false,
    acceptedTermsDate: userData.acceptedTermsDate || userData.accepted_terms_date || null,
    acceptedDataVeracity: userData.acceptedDataVeracity || userData.accepted_data_veracity || false,
    acceptedDataVeracityDate: userData.acceptedDataVeracityDate || userData.accepted_data_veracity_date || null,
  };
};

/**
 * Verifica si un usuario está pendiente de aprobación
 * @param userData Datos del usuario
 * @returns true si el usuario está pendiente de aprobación
 */
export const isUserPendingApproval = (userData: any): boolean => {
  return userData.pending || false;
};

/**
 * Verifica si un usuario ha sido rechazado
 * @param userData Datos del usuario
 * @returns true si el usuario ha sido rechazado
 */
export const isUserRejected = (userData: any): boolean => {
  const roles = userData.roles || [];
  return roles.includes('rejected');
};

/**
 * Obtiene el token de autenticación del almacenamiento seguro
 * @returns Token de autenticación o null si no existe
 */
export const getAuthToken = (): string | null => {
  return secureStorage.getItem('authToken');
};

/**
 * Guarda el token de autenticación en el almacenamiento seguro (encriptado)
 * @param token Token de autenticación
 */
export const setAuthToken = (token: string): void => {
  secureStorage.setItem('authToken', token);
};

/**
 * Elimina el token de autenticación del almacenamiento seguro
 */
export const removeAuthToken = (): void => {
  secureStorage.removeItem('authToken');
};
