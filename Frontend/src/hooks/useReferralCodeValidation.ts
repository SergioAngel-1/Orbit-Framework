/**
 * Hook personalizado para validación de códigos de referido
 * 
 * Maneja la validación con debouncing, caché y prevención de race conditions
 * 
 * @package Starter
 * @version 1.0.0
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import DOMPurify from 'dompurify';
import { pointsService } from '../services/api';
import { ReferrerInfo } from '../services/points/pointsApiService';
import logger from '../utils/logger';
import alertService from '../services/alertService';
import i18n from '../config/i18n';
import errorHandler from '../utils/errorHandler';

// Caché en memoria para resultados de validación con límite de tamaño
const validationCache = new Map<string, { result: ReferrerInfo | null; timestamp: number }>();
const CACHE_DURATION = 5 * 60 * 1000; // 5 minutos
const MAX_CACHE_SIZE = 100; // Máximo 100 códigos en caché para prevenir memory leak

interface UseReferralCodeValidationOptions {
  autoValidate?: boolean; // Si debe validar automáticamente al cambiar el código
  debounceMs?: number; // Tiempo de debounce en milisegundos
  showAlerts?: boolean; // Si debe mostrar alertas automáticamente
}

interface ValidationResult {
  isValid: boolean;
  referrerName: string;
  isValidating: boolean;
  error: string | null;
}

export const useReferralCodeValidation = (
  initialCode: string = '',
  options: UseReferralCodeValidationOptions = {}
) => {
  const {
    autoValidate = false,
    debounceMs = 800,
    showAlerts = true
  } = options;

  const [code, setCode] = useState(initialCode);
  const [referrerName, setReferrerName] = useState('');
  const [isValidating, setIsValidating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isValid, setIsValid] = useState(false);

  // Ref para controlar el timeout del debounce
  const debounceTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  
  // Ref para controlar si hay una validación en curso
  const validationInProgressRef = useRef(false);
  
  // Ref para almacenar validaciones pendientes (cola con array para múltiples requests)
  const pendingValidationsRef = useRef<Array<{
    code: string;
    silent: boolean;
    resolve: (result: ValidationResult) => void;
  }>>([]);

  /**
   * Limpia el caché de validaciones antiguas
   */
  const cleanCache = useCallback(() => {
    const now = Date.now();
    for (const [key, value] of validationCache.entries()) {
      if (now - value.timestamp > CACHE_DURATION) {
        validationCache.delete(key);
      }
    }
  }, []);

  /**
   * Obtiene un resultado del caché si existe y es válido
   */
  const getCachedResult = useCallback((codeToCheck: string): ReferrerInfo | null | undefined => {
    cleanCache();
    const cached = validationCache.get(codeToCheck);
    if (cached && Date.now() - cached.timestamp < CACHE_DURATION) {
      logger.debug('useReferralCodeValidation', `Usando resultado en caché para: ${codeToCheck}`);
      return cached.result;
    }
    return undefined;
  }, [cleanCache]);

  /**
   * Guarda un resultado en el caché con límite de tamaño (LRU)
   */
  const cacheResult = useCallback((codeToCache: string, result: ReferrerInfo | null) => {
    // Si el caché está lleno, eliminar la entrada más antigua (LRU)
    if (validationCache.size >= MAX_CACHE_SIZE) {
      const oldestKey = validationCache.keys().next().value;
      if (oldestKey) {
        validationCache.delete(oldestKey);
        logger.debug('useReferralCodeValidation', `Caché lleno (${MAX_CACHE_SIZE}), eliminando entrada más antigua`);
      }
    }
    
    validationCache.set(codeToCache, {
      result,
      timestamp: Date.now()
    });
  }, []);

  /**
   * Valida un código de referido
   */
  const validateCode = useCallback(async (codeToValidate: string, silent: boolean = false): Promise<ValidationResult> => {
    // Si hay una validación en progreso, encolar esta validación
    if (validationInProgressRef.current) {
      logger.info('useReferralCodeValidation', 'Validación en progreso, encolando nueva validación');
      
      // Crear una promesa que se resolverá cuando la validación actual termine
      return new Promise<ValidationResult>((resolve) => {
        // Agregar a la cola de validaciones pendientes
        pendingValidationsRef.current.push({
          code: codeToValidate,
          silent,
          resolve
        });
        
        logger.debug('useReferralCodeValidation', `Validación encolada (${pendingValidationsRef.current.length} en cola)`);
      });
    }

    // Validar formato básico
    if (!codeToValidate || codeToValidate.length < 5) {
      setIsValid(false);
      setReferrerName('');
      setError(i18n.t('errors:referral.invalidCode'));
      setIsValidating(false); // IMPORTANTE: Asegurar que no quede en loading
      return {
        isValid: false,
        referrerName: '',
        isValidating: false,
        error: i18n.t('errors:referral.invalidCode')
      };
    }

    // Verificar caché primero
    const cachedResult = getCachedResult(codeToValidate);
    if (cachedResult !== undefined) {
      const isValidCached = cachedResult !== null;
      const nameCached = cachedResult?.name || '';
      
      setIsValid(isValidCached);
      setReferrerName(nameCached);
      setError(isValidCached ? null : i18n.t('errors:referral.invalidCode'));
      setIsValidating(false); // IMPORTANTE: Asegurar que no quede en loading
      
      return {
        isValid: isValidCached,
        referrerName: nameCached,
        isValidating: false,
        error: isValidCached ? null : i18n.t('errors:referral.invalidCode')
      };
    }

    // Marcar validación en progreso
    validationInProgressRef.current = true;
    setIsValidating(true);
    setError(null);

    try {
      logger.info('useReferralCodeValidation', `Validando código: ${codeToValidate}`);
      
      const result = await pointsService.getReferrerByCode(codeToValidate);
      
      if (result && result.name) {
        // SANITIZAR nombre del referido antes de usar
        const sanitizedName = DOMPurify.sanitize(result.name, {
          ALLOWED_TAGS: [],
          ALLOWED_ATTR: []
        }).trim();
        
        // Código válido
        setIsValid(true);
        setReferrerName(sanitizedName);
        setError(null);
        
        // Guardar en caché con nombre sanitizado
        cacheResult(codeToValidate, { ...result, name: sanitizedName });
        
        // Generar token de validación para prevenir eventos falsos
        const validationToken = crypto.randomUUID();
        sessionStorage.setItem('lastValidationToken', validationToken);
        
        // Disparar evento personalizado para ReferralCodeInput
        const form = document.querySelector('form');
        if (form) {
          const event = new CustomEvent('referralCodeValidated', {
            detail: {
              isValid: true,
              referrerName: sanitizedName,
              token: validationToken
            }
          });
          form.dispatchEvent(event);
          logger.debug('useReferralCodeValidation', 'Evento referralCodeValidated disparado (válido) con token');
        }
        
        // Mostrar alerta si está habilitado y no es silencioso
        if (showAlerts && !silent) {
          alertService.success(i18n.t('alerts:wallet.recipientValid'));
        }
        
        return {
          isValid: true,
          referrerName: sanitizedName,
          isValidating: false,
          error: null
        };
      } else {
        // Código inválido - respuesta vacía
        setIsValid(false);
        setReferrerName('');
        setError(i18n.t('errors:referral.invalidReferralCode'));
        
        // Guardar en caché como inválido
        cacheResult(codeToValidate, null);
        
        // Disparar evento personalizado para ReferralCodeInput
        const form = document.querySelector('form');
        if (form) {
          const event = new CustomEvent('referralCodeValidated', {
            detail: {
              isValid: false,
              errorMessage: i18n.t('errors:referral.invalidReferralCode')
            }
          });
          form.dispatchEvent(event);
          logger.debug('useReferralCodeValidation', 'Evento referralCodeValidated disparado (inválido)');
        }
        
        if (showAlerts && !silent) {
          alertService.error(i18n.t('alerts:referral.codeInvalid'));
        }
        
        return {
          isValid: false,
          referrerName: '',
          isValidating: false,
          error: i18n.t('errors:referral.invalidReferralCode')
        };
      }
    } catch (err: any) {
      logger.error('useReferralCodeValidation', 'Error validando código:', err);
      
      // Manejar el error y obtener mensaje amigable
      const errorMessage = errorHandler.handleReferralCodeError(err);
      
      // Actualizar estados - CRÍTICO: asegurar que isValidating se ponga en false
      setIsValid(false);
      setReferrerName('');
      setError(errorMessage);
      setIsValidating(false); // IMPORTANTE: Forzar a false en catch
      
      // Guardar en caché como inválido para evitar reintentos inmediatos
      cacheResult(codeToValidate, null);
      
      // Disparar evento personalizado para ReferralCodeInput
      const form = document.querySelector('form');
      if (form) {
        const event = new CustomEvent('referralCodeValidated', {
          detail: {
            isValid: false,
            errorMessage: errorMessage
          }
        });
        form.dispatchEvent(event);
        logger.debug('useReferralCodeValidation', 'Evento referralCodeValidated disparado (error)');
      }
      
      if (showAlerts && !silent) {
        alertService.error(errorMessage);
      }
      
      return {
        isValid: false,
        referrerName: '',
        isValidating: false,
        error: errorMessage
      };
    } finally {
      // CRÍTICO: Siempre limpiar estados en finally
      setIsValidating(false);
      validationInProgressRef.current = false;
      logger.debug('useReferralCodeValidation', 'Validación finalizada, estados limpiados');
      
      // Procesar TODAS las validaciones pendientes en la cola
      if (pendingValidationsRef.current.length > 0) {
        const pending = pendingValidationsRef.current.shift()!;
        
        logger.info('useReferralCodeValidation', `Procesando validación encolada: ${pending.code} (${pendingValidationsRef.current.length} restantes)`);
        
        // Ejecutar la validación pendiente y resolver su promesa
        validateCode(pending.code, pending.silent).then(pending.resolve);
      }
    }
  }, [getCachedResult, cacheResult, showAlerts]);

  /**
   * Efecto para validación automática con debouncing
   */
  useEffect(() => {
    if (!autoValidate || !code) {
      return;
    }

    // Limpiar timeout anterior
    if (debounceTimeoutRef.current) {
      clearTimeout(debounceTimeoutRef.current);
    }

    // Configurar nuevo timeout
    debounceTimeoutRef.current = setTimeout(() => {
      validateCode(code, true); // Silent = true para auto-validación
    }, debounceMs);

    // Cleanup
    return () => {
      if (debounceTimeoutRef.current) {
        clearTimeout(debounceTimeoutRef.current);
      }
    };
  }, [code, autoValidate, debounceMs, validateCode]);

  /**
   * Función para actualizar el código manualmente
   */
  const updateCode = useCallback((newCode: string) => {
    setCode(newCode);
    
    // Limpiar estado si el código está vacío
    if (!newCode) {
      setIsValid(false);
      setReferrerName('');
      setError(null);
      setIsValidating(false); // IMPORTANTE: Limpiar estado de carga
    }
  }, []);

  /**
   * Función para validar manualmente (sin debounce)
   * @param codeOverride - Código a validar (opcional, usa el estado actual si no se proporciona)
   */
  const validateNow = useCallback((codeOverride?: string) => {
    const codeToValidate = codeOverride || code;
    return validateCode(codeToValidate, false);
  }, [code, validateCode]);

  /**
   * Función para resetear el estado
   */
  const reset = useCallback(() => {
    setCode('');
    setReferrerName('');
    setIsValid(false);
    setError(null);
    setIsValidating(false);
  }, []);

  return {
    code,
    updateCode,
    referrerName,
    isValid,
    isValidating,
    error,
    validateNow,
    reset
  };
};

export default useReferralCodeValidation;
