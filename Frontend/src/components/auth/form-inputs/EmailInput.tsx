import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaEnvelope } from 'react-icons/fa';
import authApiService from '../../../services/auth/authApiService';
import Input, { ValidationStatus } from './Input';
import { validateEmailFormat } from './validationUtils';

interface EmailInputProps {
  email: string;
  setEmail: (value: string) => void;
  disabled?: boolean;
}

const EmailInput: React.FC<EmailInputProps> = ({ 
  email, 
  setEmail, 
  disabled = false 
}) => {
  const { t } = useTranslation('registerForm');
  const [validationStatus, setValidationStatus] = useState<ValidationStatus>('none');
  const [validationMessage, setValidationMessage] = useState<string>('');
  const [isValidatingUnique, setIsValidatingUnique] = useState(false);
  
  // Ref para debounce de validación de unicidad
  const debounceTimerRef = useRef<NodeJS.Timeout | null>(null);
  // Cache de emails ya validados
  const validatedEmailsRef = useRef<Map<string, boolean>>(new Map());
  
  // Función para validar unicidad contra el backend
  const validateUniqueness = useCallback(async (emailToValidate: string) => {
    // Verificar si ya está en cache
    if (validatedEmailsRef.current.has(emailToValidate)) {
      const isUnique = validatedEmailsRef.current.get(emailToValidate);
      if (isUnique) {
        setValidationStatus('valid');
        setValidationMessage(t('email.available'));
      } else {
        setValidationStatus('invalid');
        setValidationMessage(t('email.alreadyRegistered'));
      }
      return;
    }
    
    setIsValidatingUnique(true);
    setValidationStatus('validating');
    setValidationMessage(t('email.verifying'));
    
    try {
      const result = await authApiService.validateEmailUnique(emailToValidate);
      
      // Guardar en cache
      validatedEmailsRef.current.set(emailToValidate, result.is_unique);
      
      if (result.is_unique) {
        setValidationStatus('valid');
        setValidationMessage(t('email.available'));
      } else {
        setValidationStatus('invalid');
        setValidationMessage(result.message || t('email.alreadyRegistered'));
      }
    } catch (error) {
      // En caso de error, solo validar formato
      setValidationStatus('valid');
      setValidationMessage(t('email.validFormat'));
    } finally {
      setIsValidatingUnique(false);
    }
  }, []);
  
  // Validar formato y unicidad de email
  useEffect(() => {
    // Limpiar timer anterior
    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
    }
    
    if (!email) {
      setValidationStatus('none');
      setValidationMessage('');
      return;
    }

    // Validar formato de email usando función centralizada
    const isValidFormat = validateEmailFormat(email);
    
    if (!isValidFormat) {
      setValidationStatus('invalid');
      setValidationMessage(t('email.invalidFormat'));
      return;
    }
    
    // Si el formato es válido, validar unicidad con debounce
    setValidationStatus('validating');
    setValidationMessage(t('email.checking'));
    
    debounceTimerRef.current = setTimeout(() => {
      validateUniqueness(email);
    }, 500);
    
    return () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current);
      }
    };
  }, [email, validateUniqueness]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setEmail(e.target.value);
  };

  return (
    <Input
      id="reg-email"
      name="email"
      type="email" 
      value={email}
      onChange={handleChange}
      placeholder={t('email.placeholder')}
      autoComplete="email"
      required={true}
      disabled={disabled || isValidatingUnique}
      icon={<FaEnvelope />}
      label={t('email.label')}
      labelRequired={true}
      validationStatus={validationStatus}
      validationMessage={validationMessage}
    />
  );
};

export default EmailInput;
