import { api } from '../../services/apiConfig';
import { Address, User } from '../types/auth.types';
import logger from '../../utils/logger';
import i18n from '../../config/i18n';

/**
 * Guarda o actualiza una dirección de usuario
 * @param addressData Datos de la dirección a guardar o actualizar
 * @param currentAddresses Direcciones actuales del usuario
 * @returns Promesa con la respuesta de la API
 */
export const saveUserAddress = async (
  addressData: Partial<Address>, 
  currentAddresses: Address[]
): Promise<any> => {
  // Verificar límite de 3 direcciones
  if (!addressData.id && currentAddresses.length >= 3) {
    throw new Error(i18n.t('errors:auth.maxAddressesReached'));
  }
  
  try {
    const response = await api.post(
      '/starter/v1/user/addresses',
      addressData
    );
    
    return response.data;
  } catch (error: any) {
    logger.error('AddressUtils', 'Error al guardar dirección', error);
    throw error;
  }
};

/**
 * Elimina una dirección de usuario
 * @param addressId ID de la dirección a eliminar
 * @returns Promesa con la respuesta de la API
 */
export const deleteUserAddress = async (addressId: number): Promise<any> => {
  try {
    const response = await api.delete(
      `/starter/v1/user/addresses/${addressId}`
    );
    
    return response.data;
  } catch (error: any) {
    logger.error('AddressUtils', 'Error al eliminar dirección', error);
    throw error;
  }
};

/**
 * Establece una dirección como predeterminada
 * @param addressId ID de la dirección a establecer como predeterminada
 * @returns Promesa con la respuesta de la API
 */
export const setUserDefaultAddress = async (addressId: number): Promise<any> => {
  try {
    const response = await api.post(
      `/starter/v1/user/addresses/default/${addressId}`
    );
    
    return response.data;
  } catch (error: any) {
    logger.error('AddressUtils', 'Error al establecer dirección predeterminada', error);
    throw error;
  }
};

/**
 * Actualiza el estado del usuario con las nuevas direcciones
 * @param user Usuario actual
 * @param addresses Nuevas direcciones
 * @returns Usuario actualizado
 */
export const updateUserWithAddresses = (user: User, addresses: Address[]): User => {
  if (!user) return user;
  
  return {
    ...user,
    addresses,
    defaultAddress: addresses.find(addr => addr.isDefault) || null
  };
};
