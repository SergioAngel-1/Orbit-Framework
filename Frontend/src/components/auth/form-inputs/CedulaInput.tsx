import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCreditCard } from 'react-icons/fi';
import authApiService from '../../../services/auth/authApiService';
import logger from '../../../utils/logger';
import Input, { ValidationStatus } from './Input';

interface CedulaInputProps {
  cedula: string;
  setCedula: (value: string) => void;
  disabled?: boolean;
  showLabel?: boolean;
  onValidationChange?: (isValid: boolean | null, isUnique: boolean | null) => void;
}

/**
 * Componente de input para cédula con validación de formato y unicidad
 */
const CedulaInput: React.FC<CedulaInputProps> = ({
  cedula,
  setCedula,
  disabled = false,
  showLabel = true,
  onValidationChange
}) => {
  const { t } = useTranslation('registerForm');
  const [validationStatus, setValidationStatus] = useState<ValidationStatus>('none');
  const [validationMessage, setValidationMessage] = useState<string>('');
  const [isValidatingUnique, setIsValidatingUnique] = useState(false);
  const [formatIsValid, setFormatIsValid] = useState<boolean | null>(null);
  const [isUnique, setIsUnique] = useState<boolean | null>(null);
  
  // Ref para debounce de validación de unicidad
  const debounceTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const lastValidatedCedula = useRef<string>('');

  /**
   * Validar formato de cédula colombiana
   * - Entre 6 y 10 dígitos
   * - Solo números (se permiten puntos y guiones que se normalizan)
   */
  const validateFormat = useCallback((value: string): boolean => {
    if (!value) return false;
    
    // Normalizar: eliminar puntos, guiones y espacios
    const normalized = value.replace(/[^0-9]/g, '');
    
    // La cédula colombiana tiene entre 6 y 10 dígitos
    if (normalized.length < 6 || normalized.length > 10) {
      return false;
    }
    
    return true;
  }, []);

  /**
   * Validar unicidad contra el backend
   */
  const validateUniqueness = useCallback(async (value: string) => {
    if (!value || !validateFormat(value)) {
      setIsUnique(null);
      return;
    }
    
    // Evitar validar el mismo valor repetidamente
    const normalized = value.replace(/[^0-9]/g, '');
    if (lastValidatedCedula.current === normalized) {
      return;
    }
    
    lastValidatedCedula.current = normalized;
    setIsValidatingUnique(true);
    setValidationStatus('validating');
    setValidationMessage(t('cedula.verifying'));
    
    try {
      const result = await authApiService.validateCedulaUnique(value);
      
      if (result.is_unique) {
        setIsUnique(true);
        setValidationStatus('valid');
        setValidationMessage(t('cedula.validAndAvailable'));
      } else {
        setIsUnique(false);
        setValidationStatus('invalid');
        setValidationMessage(result.message || t('cedula.alreadyRegistered'));
      }
    } catch (error) {
      logger.error('CedulaInput', 'Error validando unicidad:', error);
      // En caso de error, no bloqueamos pero advertimos
      setIsUnique(null);
      setValidationStatus('valid');
      setValidationMessage(t('cedula.couldNotVerify'));
    } finally {
      setIsValidatingUnique(false);
    }
  }, [validateFormat]);

  // Efecto para validar formato y disparar validación de unicidad con debounce
  useEffect(() => {
    // Limpiar timeout anterior
    if (debounceTimeoutRef.current) {
      clearTimeout(debounceTimeoutRef.current);
    }
    
    if (!cedula || cedula.length === 0) {
      setValidationStatus('none');
      setValidationMessage('');
      setFormatIsValid(null);
      setIsUnique(null);
      lastValidatedCedula.current = '';
      return;
    }
    
    // Normalizar para validación
    const normalized = cedula.replace(/[^0-9]/g, '');
    
    // Validar formato primero
    if (normalized.length < 6) {
      setValidationStatus('invalid');
      setValidationMessage(t('cedula.minDigits'));
      setFormatIsValid(false);
      setIsUnique(null);
      return;
    }
    
    if (normalized.length > 10) {
      setValidationStatus('invalid');
      setValidationMessage(t('cedula.maxDigits'));
      setFormatIsValid(false);
      setIsUnique(null);
      return;
    }
    
    // Formato válido
    setFormatIsValid(true);
    setValidationStatus('valid');
    setValidationMessage(t('cedula.validFormat'));
    
    // Debounce para validación de unicidad (800ms)
    debounceTimeoutRef.current = setTimeout(() => {
      validateUniqueness(cedula);
    }, 800);
    
    return () => {
      if (debounceTimeoutRef.current) {
        clearTimeout(debounceTimeoutRef.current);
      }
    };
  }, [cedula, validateUniqueness]);

  // Notificar cambios de validación al componente padre
  useEffect(() => {
    if (onValidationChange) {
      onValidationChange(formatIsValid, isUnique);
    }
  }, [formatIsValid, isUnique, onValidationChange]);

  // Manejar cambio de input - solo permitir números
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value;
    // Permitir solo números, puntos y guiones (para formato visual)
    const sanitized = value.replace(/[^0-9.-]/g, '');
    setCedula(sanitized);
  };

  return (
    <Input
      id="reg-cedula"
      name="cedula"
      type="text"
      value={cedula}
      onChange={handleChange}
      placeholder={t('cedula.placeholder')}
      required={true}
      disabled={disabled || isValidatingUnique}
      autoComplete="off"
      icon={FiCreditCard}
      label={showLabel ? t('cedula.label') : ""}
      labelRequired={showLabel}
      validationStatus={validationStatus}
      validationMessage={validationMessage}
    />
  );
};

export default CedulaInput;
