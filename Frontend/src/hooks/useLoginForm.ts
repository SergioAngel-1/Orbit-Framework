/**
 * Hook personalizado para manejar la lógica del formulario de login
 * 
 * Encapsula toda la lógica de autenticación, manejo de errores y estados
 * para mantener el componente LandingPage limpio y reutilizable.
 * 
 * @package Starter
 */

import { useState, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { useModal } from '../contexts/ModalContext';
import { User } from '../contexts/types/auth.types';
import alertService from '../services/alertService';
import i18n from '../config/i18n';
import errorHandler from '../utils/errorHandler';
import logger from '../utils/logger';

interface UseLoginFormOptions {
  onSuccess?: () => void;
}

interface UseLoginFormReturn {
  // Estados del formulario
  identifier: string;
  setIdentifier: (value: string) => void;
  password: string;
  setPassword: (value: string) => void;
  loading: boolean;
  
  // Acciones
  handleLogin: (e: React.FormEvent) => Promise<void>;
  resetForm: () => void;
}

export const useLoginForm = (options: UseLoginFormOptions = {}): UseLoginFormReturn => {
  const { login } = useAuth();
  const { showPendingApprovalModal, showRejectedAccountModal } = useModal();
  
  // Estados del formulario
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  /**
   * Crea objeto de usuario pendiente con datos actuales
   */
  const createPendingUserData = useCallback((): User => ({
    id: 0,
    name: '',
    email: identifier,
    addresses: [],
    defaultAddress: null,
    pending: true
  }), [identifier]);

  /**
   * Crea objeto de usuario rechazado con datos actuales
   */
  const createRejectedUserData = useCallback((): User => ({
    id: 0,
    name: '',
    email: identifier,
    addresses: [],
    defaultAddress: null,
    pending: false,
    rejected: true
  }), [identifier]);

  /**
   * Maneja el caso de usuario pendiente de aprobación
   */
  const handlePendingUser = useCallback((userData?: User) => {
    sessionStorage.removeItem('just_logged_in');
    alertService.warning(i18n.t('alerts:auth.pendingApproval'));
    
    const pendingUserData = userData || createPendingUserData();
    logger.info('useLoginForm', 'Mostrando modal de aprobación pendiente', pendingUserData);
    showPendingApprovalModal(pendingUserData);
  }, [createPendingUserData, showPendingApprovalModal]);

  /**
   * Maneja el caso de cuenta rechazada
   */
  const handleRejectedUser = useCallback((userData?: User) => {
    sessionStorage.removeItem('just_logged_in');
    alertService.error(i18n.t('alerts:auth.accountRejected'));
    
    const rejectedUserData = userData || createRejectedUserData();
    logger.info('useLoginForm', 'Mostrando modal de cuenta rechazada', rejectedUserData);
    showRejectedAccountModal(rejectedUserData);
  }, [createRejectedUserData, showRejectedAccountModal]);

  /**
   * Maneja errores de login específicos
   */
  const handleLoginError = useCallback((loginError: string) => {
    if (loginError.includes('rechazada')) {
      alertService.error(i18n.t('alerts:auth.accountRejected'));
    } else if (loginError.includes('Correo no registrado')) {
      alertService.error(i18n.t('alerts:auth.emailNotRegistered'));
    } else if (loginError.includes('password field is empty')) {
      alertService.error(i18n.t('alerts:auth.emptyPassword'));
    } else {
      alertService.error(loginError);
    }
  }, []);

  /**
   * Maneja el submit del formulario de login
   */
  const handleLogin = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();

    if (!identifier || !password) {
      alertService.error(i18n.t('alerts:auth.emptyPassword'));
      return;
    }

    try {
      setLoading(true);
      
      // Marcar ANTES del login para evitar alertas en AuthenticatedRedirect
      sessionStorage.setItem('just_logged_in', 'true');
      
      const result = await login(identifier, password).catch(error => {
        logger.error('useLoginForm', 'Error capturado en login:', error);
        
        // Manejar error de contraseña incorrecta
        if (error?.response?.data?.code === 'incorrect_password') {
          alertService.error(i18n.t('alerts:auth.incorrectPassword'));
          throw error;
        }
        
        throw error;
      });

      const { success, pendingApproval, rejected, error: loginError } = result;

      if (pendingApproval) {
        handlePendingUser();
      } else if (rejected) {
        handleRejectedUser();
      } else if (success) {
        // Login exitoso - NO navegamos aquí
        // AuthenticatedRedirect se encargará de la redirección
        // El flag 'just_logged_in' ya está seteado para evitar la alerta
        logger.info('useLoginForm', 'Login exitoso, AuthenticatedRedirect manejará la redirección');
        options.onSuccess?.();
        // No llamamos navigate() - el cambio de isAuthenticated 
        // causará que se monte AuthenticatedRoutes y AuthenticatedRedirect
      } else {
        sessionStorage.removeItem('just_logged_in');
        
        if (loginError) {
          handleLoginError(loginError);
        } else {
          alertService.error(i18n.t('alerts:auth.loginError'));
        }
      }
    } catch (error: any) {
      sessionStorage.removeItem('just_logged_in');
      logger.error('useLoginForm', 'Error al iniciar sesión:', error);

      // Verificar si es usuario pendiente
      if (error?.isPendingAccount) {
        handlePendingUser(error.pendingUser);
        return;
      }
      
      // Verificar si es cuenta rechazada
      if (error?.isRejectedAccount || 
          error?.message?.includes('rechazada') || 
          error?.response?.data?.message?.includes('rechazada')) {
        handleRejectedUser(error.rejectedUser);
        return;
      }
      
      // Usar manejador de errores centralizado
      errorHandler.handleLoginError(error);
    } finally {
      setLoading(false);
    }
  }, [identifier, password, login, options, handlePendingUser, handleRejectedUser, handleLoginError]);

  /**
   * Resetea el formulario a su estado inicial
   */
  const resetForm = useCallback(() => {
    setIdentifier('');
    setPassword('');
  }, []);

  return {
    identifier,
    setIdentifier,
    password,
    setPassword,
    loading,
    handleLogin,
    resetForm
  };
};

export default useLoginForm;
