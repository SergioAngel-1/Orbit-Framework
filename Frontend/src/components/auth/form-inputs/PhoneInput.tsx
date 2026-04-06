import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import Input, { ValidationStatus } from './Input';
import CustomPhoneInput from '../../common/PhoneInput';
import authApiService from '../../../services/auth/authApiService';
import logger from '../../../utils/logger';

interface PhoneInputProps {
  phone: string;
  setPhone: (value: string) => void;
  disabled?: boolean;
  showLabel?: boolean;
  onValidationChange?: (isValid: boolean | null, isUnique: boolean | null) => void;
  skipUniqueValidation?: boolean; // Para deshabilitar validación de unicidad (ej: checkout, contacto)
}

const PhoneInput: React.FC<PhoneInputProps> = ({
  phone,
  setPhone,
  disabled = false,
  showLabel = true,
  onValidationChange,
  skipUniqueValidation = false
}) => {
  const { t } = useTranslation('phoneInput');
  const [validationStatus, setValidationStatus] = useState<ValidationStatus>('none');
  const [validationMessage, setValidationMessage] = useState<string>('');
  const [formatIsValid, setFormatIsValid] = useState<boolean | null>(null);
  const [isUnique, setIsUnique] = useState<boolean | null>(null);
  const [_isValidatingUnique, setIsValidatingUnique] = useState(false);
  
  // Refs para debounce y tracking
  const debounceTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const lastValidatedPhone = useRef<string>('');

  /**
   * Validar formato del teléfono según país
   */
  const validatePhoneFormat = useCallback((phoneValue: string): { isValid: boolean; message: string } => {
    if (!phoneValue || phoneValue.length === 0) {
      return { isValid: false, message: '' };
    }

    let numberPart = phoneValue;
    let countryCode = '+57';
    
    if (phoneValue.includes('+') && phoneValue.includes(' ')) {
      const parts = phoneValue.split(' ');
      countryCode = parts[0];
      numberPart = parts[1] || '';
    }
    
    numberPart = numberPart.replace(/[^0-9]/g, '');
    
    if (countryCode === '+57') {
      if (numberPart.length === 10) return { isValid: true, message: t('validation.validColombia') };
      if (numberPart.length > 0) return { isValid: false, message: t('validation.invalidColombia') };
    } else if (countryCode === '+52') {
      if (numberPart.length === 10) return { isValid: true, message: t('validation.validMexico') };
      if (numberPart.length > 0) return { isValid: false, message: t('validation.invalidMexico') };
    } else if (countryCode === '+34') {
      if (numberPart.length === 9) return { isValid: true, message: t('validation.validSpain') };
      if (numberPart.length > 0) return { isValid: false, message: t('validation.invalidSpain') };
    } else if (countryCode === '+1') {
      if (numberPart.length === 10) return { isValid: true, message: t('validation.validUSA') };
      if (numberPart.length > 0) return { isValid: false, message: t('validation.invalidUSA') };
    } else {
      if (numberPart.length >= 6) return { isValid: true, message: t('validation.validGeneric') };
      if (numberPart.length > 0) return { isValid: false, message: t('validation.invalidGeneric') };
    }
    
    return { isValid: false, message: '' };
  }, []);

  /**
   * Validar unicidad del teléfono contra el backend
   */
  const validateUniqueness = useCallback(async (phoneValue: string) => {
    if (!phoneValue || !validatePhoneFormat(phoneValue).isValid) {
      setIsUnique(null);
      return;
    }
    
    // Evitar validar el mismo valor repetidamente
    if (lastValidatedPhone.current === phoneValue) {
      return;
    }
    
    lastValidatedPhone.current = phoneValue;
    setIsValidatingUnique(true);
    setValidationStatus('validating');
    setValidationMessage(t('validation.checking'));
    
    try {
      const result = await authApiService.validatePhoneUnique(phoneValue);
      
      if (result.is_unique) {
        setIsUnique(true);
        setValidationStatus('valid');
        setValidationMessage(t('validation.available'));
      } else {
        setIsUnique(false);
        setValidationStatus('invalid');
        setValidationMessage(result.message || t('validation.alreadyRegistered'));
      }
    } catch (error) {
      logger.error('PhoneInput', 'Error validando unicidad:', error);
      setIsUnique(null);
      const formatResult = validatePhoneFormat(phoneValue);
      setValidationStatus(formatResult.isValid ? 'valid' : 'invalid');
      setValidationMessage(formatResult.message);
    } finally {
      setIsValidatingUnique(false);
    }
  }, [validatePhoneFormat]);

  // Validación de formato y disparar validación de unicidad con debounce
  useEffect(() => {
    if (debounceTimeoutRef.current) {
      clearTimeout(debounceTimeoutRef.current);
    }
    
    if (!phone || phone.length === 0) {
      setValidationStatus('none');
      setValidationMessage('');
      setFormatIsValid(null);
      setIsUnique(null);
      lastValidatedPhone.current = '';
      return;
    }

    const formatResult = validatePhoneFormat(phone);
    setFormatIsValid(formatResult.isValid);
    
    if (!formatResult.isValid) {
      setValidationStatus(formatResult.message ? 'invalid' : 'none');
      setValidationMessage(formatResult.message);
      setIsUnique(null);
      return;
    }
    
    // Formato válido - mostrar temporalmente
    setValidationStatus('valid');
    setValidationMessage(formatResult.message);
    
    // Solo validar unicidad si no está deshabilitado (skipUniqueValidation)
    if (!skipUniqueValidation) {
      // Debounce para validación de unicidad (800ms)
      debounceTimeoutRef.current = setTimeout(() => {
        validateUniqueness(phone);
      }, 800);
    }
    
    return () => {
      if (debounceTimeoutRef.current) {
        clearTimeout(debounceTimeoutRef.current);
      }
    };
  }, [phone, validatePhoneFormat, validateUniqueness, skipUniqueValidation]);

  // Notificar cambios de validación al componente padre
  useEffect(() => {
    if (onValidationChange) {
      onValidationChange(formatIsValid, isUnique);
    }
  }, [formatIsValid, isUnique, onValidationChange]);

  // Componente personalizado de teléfono como contenido personalizado
  const PhoneInputComponent = (
    <div className="w-full flex-1">
      <CustomPhoneInput
        value={phone}
        onChange={(value) => setPhone(value)}
        disabled={disabled}
        placeholder={t('placeholder')}
        inputClass="w-full flex-grow"
        inputId="reg-phone"
        noBorder={true} // El wrapper ya tiene borde
      />
    </div>
  );

  return (
    <Input
      id="reg-phone"
      name="phone"
      type="tel"
      value={phone}
      onChange={() => {}} // CustomPhoneInput maneja esto directamente
      placeholder={t('placeholder')}
      required={true}
      disabled={disabled}
      icon={<span className="hidden"></span>} // Icono invisible para cumplir con la prop requerida
      label={showLabel ? t('label') : ""}
      labelRequired={showLabel}
      validationStatus={validationStatus}
      validationMessage={validationMessage}
      // Reemplazamos el input estándar con nuestro componente personalizado
      customInput={PhoneInputComponent}
    />
  );
};

export default PhoneInput;
