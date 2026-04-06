/**
 * Secure Storage Utility
 * 
 * Proporciona almacenamiento encriptado para datos sensibles en localStorage
 * usando AES-256 encryption con derivación de clave PBKDF2.
 * 
 * Seguridad:
 * - Clave del servidor (VITE_STORAGE_SECRET) + fingerprint del navegador
 * - Derivación PBKDF2 con 100,000 iteraciones
 * - Los tokens encriptados solo pueden desencriptarse en el mismo navegador
 * - Si la clave del servidor se compromete, aún se necesita el navegador específico
 * 
 * @package Starter
 * @version 2.0.0
 */

import * as CryptoJS from 'crypto-js';
import logger from './logger';
import i18n from '../config/i18n';

/**
 * Genera una clave de encriptación derivada del navegador
 * Combina información del navegador con la clave del servidor para mayor seguridad
 */
// Caché en memoria para valores desencriptados (evita AES.decrypt repetido en interceptores HTTP)
const _decryptedCache = new Map<string, string>();

/**
 * Deriva la clave de encriptación una sola vez al cargar el módulo.
 * PBKDF2 con 100K iteraciones es costoso (~200-400ms), así que se ejecuta
 * una única vez y el resultado se reutiliza durante toda la vida del tab.
 */
const deriveEncryptionKey = (): string => {
  const serverKey = import.meta.env.VITE_STORAGE_SECRET;
  
  if (!serverKey) {
    throw new Error(
      'CRÍTICO: VITE_STORAGE_SECRET no está configurado.\n' +
      'Desarrollo: Configura en .env local\n' +
      'Producción: Configura en variables de entorno del servidor (Vercel/Netlify).'
    );
  }
  
  // En desarrollo, usar la clave directamente sin derivación
  // Esto permite desarrollo más simple y debugging
  if (import.meta.env.DEV) {
    return serverKey;
  }
  
  // En producción, derivar clave única por navegador combinando:
  // 1. Clave del servidor (serverKey)
  // 2. User Agent hash (fingerprint del navegador)
  // 3. Dominio actual
  const browserFingerprint = CryptoJS.SHA256(
    navigator.userAgent + window.location.hostname
  ).toString();
  
  // Combinar ambas claves para generar la clave final
  return CryptoJS.PBKDF2(
    serverKey,
    browserFingerprint,
    { keySize: 256/32, iterations: 100000 }
  ).toString();
};

// Memoización real: se calcula al importar el módulo, nunca más
const ENCRYPTION_KEY = deriveEncryptionKey();

const getEncryptionKey = (): string => ENCRYPTION_KEY;

/**
 * Encripta un valor usando AES
 */
const encrypt = (value: string): string => {
  try {
    const key = getEncryptionKey();
    const encrypted = CryptoJS.AES.encrypt(value, key).toString();
    return encrypted;
  } catch (error) {
    logger.error('secureStorage', 'Error al encriptar:', error);
    throw error;
  }
};

/**
 * Desencripta un valor usando AES
 */
const decrypt = (encryptedValue: string): string => {
  try {
    const key = getEncryptionKey();
    const decrypted = CryptoJS.AES.decrypt(encryptedValue, key);
    const plaintext = decrypted.toString(CryptoJS.enc.Utf8);
    
    if (!plaintext) {
      throw new Error(i18n.t('errors:storage.decryptionFailed'));
    }
    
    return plaintext;
  } catch (error) {
    logger.error('secureStorage', 'Error al desencriptar:', error);
    throw error;
  }
};

/**
 * Secure Storage API
 * 
 * Proporciona una interfaz similar a localStorage pero con encriptación automática
 */
export const secureStorage = {
  /**
   * Guarda un valor encriptado en localStorage
   * @param key Clave del item
   * @param value Valor a guardar (será encriptado)
   */
  setItem(key: string, value: string): void {
    try {
      const encrypted = encrypt(value);
      localStorage.setItem(key, encrypted);
      // Actualizar caché en memoria con el valor plano
      _decryptedCache.set(key, value);
      logger.debug('secureStorage', `Item guardado de forma segura: ${key}`);
    } catch (error) {
      logger.error('secureStorage', `Error al guardar item ${key}:`, error);
      throw error;
    }
  },

  /**
   * Obtiene y desencripta un valor de localStorage
   * @param key Clave del item
   * @returns Valor desencriptado o null si no existe
   */
  getItem(key: string): string | null {
    try {
      // Retornar desde caché en memoria si disponible (evita AES.decrypt repetido)
      const cached = _decryptedCache.get(key);
      if (cached !== undefined) {
        return cached;
      }

      const encrypted = localStorage.getItem(key);
      
      if (!encrypted) {
        return null;
      }
      
      const decrypted = decrypt(encrypted);
      // Cachear en memoria para futuras lecturas
      _decryptedCache.set(key, decrypted);
      logger.debug('secureStorage', `Item recuperado de forma segura: ${key}`);
      return decrypted;
    } catch (error) {
      logger.error('secureStorage', `Error al recuperar item ${key}:`, error);
      // Si hay error de desencriptación, limpiar el item corrupto
      localStorage.removeItem(key);
      _decryptedCache.delete(key);
      return null;
    }
  },

  /**
   * Elimina un item de localStorage
   * @param key Clave del item
   */
  removeItem(key: string): void {
    try {
      localStorage.removeItem(key);
      _decryptedCache.delete(key);
      logger.debug('secureStorage', `Item eliminado: ${key}`);
    } catch (error) {
      logger.error('secureStorage', `Error al eliminar item ${key}:`, error);
    }
  },

  /**
   * Limpia todos los items de localStorage
   */
  clear(): void {
    try {
      localStorage.clear();
      _decryptedCache.clear();
      logger.debug('secureStorage', 'Storage limpiado completamente');
    } catch (error) {
      logger.error('secureStorage', 'Error al limpiar storage:', error);
    }
  },

  /**
   * Verifica si existe un item en localStorage
   * @param key Clave del item
   * @returns true si existe, false si no
   */
  hasItem(key: string): boolean {
    return localStorage.getItem(key) !== null;
  }
};

/**
 * Migración de tokens encriptados con clave antigua
 * 
 * Si se cambia VITE_STORAGE_SECRET en el servidor, los tokens antiguos
 * no podrán desencriptarse. Esta función los limpia automáticamente.
 */
export const migrateToSecureStorage = (): void => {
  try {
    const authToken = localStorage.getItem('authToken');
    
    if (authToken) {
      // Intentar desencriptar con la clave actual
      try {
        const decrypted = decrypt(authToken);
        
        // Validar que el token desencriptado tenga formato JWT válido
        if (decrypted && isValidJWT(decrypted)) {
          logger.info('secureStorage', 'Token válido y encriptado correctamente');
        } else {
          // Token corrupto, limpiar
          logger.warn('secureStorage', 'Token corrupto detectado, limpiando');
          localStorage.removeItem('authToken');
        }
      } catch {
        // No se puede desencriptar (clave cambió o token corrupto)
        logger.warn('secureStorage', 'Token no puede desencriptarse, limpiando (posible rotación de clave)');
        localStorage.removeItem('authToken');
      }
    }
  } catch (error) {
    logger.error('secureStorage', 'Error durante la migración:', error);
  }
};

/**
 * Valida la integridad de un token JWT
 */
const isValidJWT = (token: string): boolean => {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return false;
    
    // Validar que cada parte sea base64 válido
    parts.forEach(part => {
      atob(part.replace(/-/g, '+').replace(/_/g, '/'));
    });
    
    return true;
  } catch {
    return false;
  }
};

export default secureStorage;
