/**
 * Hook personalizado para manejar la lógica del formulario de registro
 * 
 * Encapsula toda la lógica de registro, validación de referidos y estados
 * para mantener el componente LandingPage limpio y reutilizable.
 * 
 * @package Starter
 */

import { useState, useCallback } from 'react';
import { useModal } from '../contexts/ModalContext';
import { User } from '../contexts/types/auth.types';
import { useReferralCodeValidation } from './useReferralCodeValidation';
import authApiService from '../services/auth/authApiService';
import alertService from '../services/alertService';
import i18n from '../config/i18n';
import errorHandler from '../utils/errorHandler';
import logger from '../utils/logger';

interface UseRegisterFormOptions {
  onSuccess?: () => void;
  initialReferralCode?: string;
}

interface UseRegisterFormReturn {
  // Estados del formulario
  username: string;
  setUsername: (value: string) => void;
  email: string;
  setEmail: (value: string) => void;
  password: string;
  setPassword: (value: string) => void;
  phone: string;
  setPhone: (value: string) => void;
  cedula: string;
  setCedula: (value: string) => void;
  birthDate: string;
  setBirthDate: (value: string) => void;
  acceptedDataVeracity: boolean;
  setAcceptedDataVeracity: (value: boolean) => void;
  acceptedTerms: boolean;
  setAcceptedTerms: (value: boolean) => void;
  loading: boolean;
  
  // Estados de código de referido
  referralCode: string;
  setReferralCode: (value: string) => void;
  referrerName: string;
  validatingReferralCode: boolean;
  handleReferralCodeChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  validateReferralCode: (codeOverride?: string) => void;
  
  // Acciones
  handleRegister: (e: React.FormEvent) => Promise<void>;
  resetForm: () => void;
}

export const useRegisterForm = (options: UseRegisterFormOptions = {}): UseRegisterFormReturn => {
  const { initialReferralCode = '' } = options;
  
  const { showPendingApprovalModal } = useModal();
  
  // Estados del formulario
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [phone, setPhone] = useState('');
  const [cedula, setCedula] = useState('');
  const [birthDate, setBirthDate] = useState('');
  const [acceptedDataVeracity, setAcceptedDataVeracity] = useState(false);
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [loading, setLoading] = useState(false);

  // Hook para validación de código de referido
  const {
    code: referralCode,
    updateCode: setReferralCode,
    referrerName,
    isValidating: validatingReferralCode,
    validateNow: validateReferralCodeNow,
    reset: resetReferralCode
  } = useReferralCodeValidation(initialReferralCode, {
    autoValidate: false,
    debounceMs: 800,
    showAlerts: true
  });

  /**
   * Crea objeto de usuario pendiente para el modal
   */
  const createPendingUserData = useCallback((): User => ({
    id: 0,
    name: username,
    email: email,
    addresses: [],
    defaultAddress: null,
    pending: true
  }), [username, email]);

  /**
   * Maneja cambio en el campo de código de referido
   */
  const handleReferralCodeChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    setReferralCode(e.target.value);
  }, [setReferralCode]);

  /**
   * Valida el código de referido manualmente
   * @param codeOverride - Código a validar (opcional, usa el estado actual si no se proporciona)
   */
  const validateReferralCode = useCallback((codeOverride?: string) => {
    // Usar el código proporcionado o el del estado
    const codeToValidate = codeOverride || referralCode;
    
    if (!codeToValidate) {
      alertService.warning(i18n.t('alerts:referral.codeRequired'));
      return;
    }
    
    logger.info('useRegisterForm', 'Validando código de referido:', codeToValidate);
    
    // Si se proporciona un código diferente al actual, actualizarlo primero
    if (codeOverride && codeOverride !== referralCode) {
      setReferralCode(codeOverride);
    }
    
    // Pasar el código directamente para evitar stale closure
    validateReferralCodeNow(codeToValidate);
  }, [referralCode, validateReferralCodeNow, setReferralCode]);

  /**
   * Maneja el submit del formulario de registro
   */
  const handleRegister = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();

    // Validar campos obligatorios
    if (!username || !email || !password || !phone || !cedula) {
      alertService.error(i18n.t('alerts:registration.fieldsRequired'));
      return;
    }

    // Validar confirmación de veracidad de datos
    if (!acceptedDataVeracity) {
      alertService.error(i18n.t('alerts:registration.dataVeracityRequired'));
      return;
    }

    // Validar aceptación de términos
    if (!acceptedTerms) {
      alertService.error(i18n.t('alerts:registration.termsRequired'));
      return;
    }

    try {
      setLoading(true);
      
      // Llamar al servicio de registro
      const response = await authApiService.register(
        username, 
        email, 
        password, 
        phone, 
        referralCode, 
        cedula,
        birthDate,
        acceptedTerms,
        acceptedDataVeracity
      );
      const result = response.data;
      
      logger.info('useRegisterForm', 'Respuesta de registro:', result);
      
      // Verificar si fue aprobación automática o pendiente
      if (result?.auto_approved || result?.approval_status === 'approved') {
        // APROBACIÓN AUTOMÁTICA: Usuario puede iniciar sesión inmediatamente
        alertService.success(i18n.t('alerts:registration.autoApproved'));
        logger.info('useRegisterForm', 'Registro con aprobación automática exitoso');
        
        options.onSuccess?.();
      } else {
        // APROBACIÓN MANUAL: Mostrar modal de pendiente (no navegar a ruta inexistente)
        alertService.success(i18n.t('alerts:registration.accountCreated'));
        logger.info('useRegisterForm', 'Registro con aprobación manual - pendiente');

        // Mostrar modal de aprobación pendiente en lugar de navegar
        const pendingUserData = createPendingUserData();
        showPendingApprovalModal(pendingUserData);
      }
    } catch (error: any) {
      logger.error('useRegisterForm', 'Error en registro:', error);
      
      // Usar el manejador de errores centralizado
      errorHandler.handleRegisterError(error, {
        setRegistrationForm: () => {} // No necesitamos cambiar formulario aquí
      });
    } finally {
      setLoading(false);
    }
  }, [username, email, password, phone, cedula, acceptedDataVeracity, acceptedTerms, referralCode, options, createPendingUserData, showPendingApprovalModal]);

  /**
   * Resetea el formulario a su estado inicial
   */
  const resetForm = useCallback(() => {
    setUsername('');
    setEmail('');
    setPassword('');
    setPhone('');
    setCedula('');
    setBirthDate('');
    setAcceptedDataVeracity(false);
    setAcceptedTerms(false);
    resetReferralCode();
  }, [resetReferralCode]);

  return {
    // Estados del formulario
    username,
    setUsername,
    email,
    setEmail,
    password,
    setPassword,
    phone,
    setPhone,
    cedula,
    setCedula,
    birthDate,
    setBirthDate,
    acceptedDataVeracity,
    setAcceptedDataVeracity,
    acceptedTerms,
    setAcceptedTerms,
    loading,
    
    // Estados de código de referido
    referralCode,
    setReferralCode,
    referrerName,
    validatingReferralCode,
    handleReferralCodeChange,
    validateReferralCode,
    
    // Acciones
    handleRegister,
    resetForm
  };
};

export default useRegisterForm;
