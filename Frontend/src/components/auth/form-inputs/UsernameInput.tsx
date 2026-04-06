import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FaUser } from 'react-icons/fa';
import Input, { ValidationStatus } from './Input';

interface UsernameInputProps {
  identifier: string;
  setIdentifier: (value: string) => void;
  disabled?: boolean;
}

const UsernameInput: React.FC<UsernameInputProps> = ({ 
  identifier, 
  setIdentifier, 
  disabled = false 
}) => {
  const { t } = useTranslation('registerForm');
  const [validationStatus, setValidationStatus] = useState<ValidationStatus>('none');
  const [validationMessage, setValidationMessage] = useState<string>('');

  // Validación de nombre de usuario
  useEffect(() => {
    if (!identifier) {
      setValidationStatus('none');
      setValidationMessage('');
      return;
    }

    // Validar longitud mínima
    if (identifier.length < 3) {
      setValidationStatus('invalid');
      setValidationMessage(t('username.minLength'));
      return;
    }

    // Validar caracteres permitidos: letras, números, guion (-) y guion bajo (_)
    const validUsernameRegex = /^[a-zA-Z0-9_-]+$/;
    if (!validUsernameRegex.test(identifier)) {
      setValidationStatus('invalid');
      setValidationMessage(t('username.invalidChars'));
      return;
    }

    // Todo válido
    setValidationStatus('valid');
    setValidationMessage(t('username.valid'));
  }, [identifier]);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setIdentifier(e.target.value);
  };

  return (
    <Input
      id="reg-username"
      name="username"
      type="text"
      value={identifier}
      onChange={handleChange}
      placeholder={t('username.placeholder')}
      autoComplete="username"
      required={true}
      disabled={disabled}
      icon={<FaUser />}
      label={t('username.label')}
      labelRequired={true}
      validationStatus={validationStatus}
      validationMessage={validationMessage}
    />
  );
};

export default UsernameInput;
