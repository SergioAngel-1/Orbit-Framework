import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';

interface FormValidationProps {
  identifier: string;
  email: string;
  password: string;
  birthDate: string;
  phone: string;
  referralCode: string;
  emailFormatIsValid: boolean | null;
  isAdult: boolean | null;
  passwordStrength: { strength: number; message: string };
  referralCodeFormatIsValid: boolean | null;
  referralCodeIsValid: boolean | null;
  phoneIsValid?: boolean | null;
  /** Si hay código de referido válido, debe aceptarse el disclaimer */
  referralDisclaimerAccepted?: boolean;
}

interface FormValidationResult {
  formIsValid: boolean;
  invalidFormMessage: string;
}

const useFormValidation = ({
  identifier,
  email,
  password,
  birthDate,
  phone,
  referralCode,
  emailFormatIsValid,
  isAdult,
  passwordStrength,
  referralCodeFormatIsValid,
  referralCodeIsValid,
  phoneIsValid = null,
  referralDisclaimerAccepted = false
}: FormValidationProps): FormValidationResult => {
  const { t } = useTranslation('registerForm');
  const [formIsValid, setFormIsValid] = useState<boolean>(false);
  const [invalidFormMessage, setInvalidFormMessage] = useState<string>('');

  useEffect(() => {
    // Verificar todos los campos obligatorios
    const requiredFieldsValid = Boolean(identifier && email && password && birthDate && phone);
    
    // Verificar formato de correo
    // Exigimos una validación positiva del email
    const emailValid = emailFormatIsValid === true;
    
    // Verificar edad
    const ageValid = isAdult === true;
    
    // Verificar seguridad de contraseña
    const passwordValid = passwordStrength.strength >= 4;
    
    // Verificar teléfono (debe tener algún valor y ser válido si se proporcionó una validación)
    const phoneValid = Boolean(phone) && (phoneIsValid !== false);
    
    // Verificar código de referido (si existe)
    let referralValid = true;
    let referralDisclaimerValid = true;
    if (referralCode) {
      // Si hay un código de referido, debe ser válido en formato y validación
      if (referralCodeFormatIsValid === false || 
          (referralCodeFormatIsValid === true && referralCodeIsValid === false)) {
        referralValid = false;
      } else if (referralCodeFormatIsValid === null) {
        // Si aún no se ha validado el formato, no es válido
        referralValid = false;
      }
      
      // Si el código es válido, debe aceptarse el disclaimer
      if (referralValid && referralCodeIsValid === true && !referralDisclaimerAccepted) {
        referralDisclaimerValid = false;
      }
    }
    
    // Determinar si el formulario es válido
    const isValid = Boolean(requiredFieldsValid && emailValid && ageValid && passwordValid && referralValid && referralDisclaimerValid && phoneValid);
    setFormIsValid(isValid);
    
    // Establecer mensaje apropiado con prioridad clara
    let message = '';
    if (!isValid) {
      if (!requiredFieldsValid) {
        message = t('formValidation.requiredFields');
      } else if (!emailValid) {
        message = t('formValidation.invalidEmail');
      } else if (!ageValid) {
        message = t('formValidation.underage');
      } else if (!passwordValid) {
        message = t('formValidation.weakPassword');
      } else if (!phoneValid) {
        message = t('formValidation.invalidPhone');
      } else if (!referralValid) {
        if (referralCode && !referralCodeFormatIsValid) {
          message = t('formValidation.invalidReferralFormat');
        } else {
          message = t('formValidation.validateReferral');
        }
      } else if (!referralDisclaimerValid) {
        message = t('formValidation.acceptReferralDisclaimer');
      }
    }
    setInvalidFormMessage(message);
  }, [
    identifier, 
    email, 
    emailFormatIsValid, 
    password, 
    passwordStrength.strength, 
    birthDate, 
    isAdult, 
    phone,
    phoneIsValid,
    referralCode, 
    referralCodeIsValid, 
    referralCodeFormatIsValid,
    referralDisclaimerAccepted
  ]);
  
  return { formIsValid, invalidFormMessage };
};

export default useFormValidation;
