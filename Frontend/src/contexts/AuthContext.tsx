// Archivo refactorizado: AuthContext.tsx

import { createContext, useContext, useState, useEffect, useRef, ReactNode, useMemo, useCallback } from 'react';
import { api } from '../services/apiConfig';
import { authService } from '../services/api';

import logger from '../utils/logger';
import errorHandler from '../utils/errorHandler';
import secureStorage from '../utils/secureStorage';
import { clearMembershipLevelsCache } from '../hooks/useMembershipLevels';
import { cacheManager } from '../services/query/cacheManager';
import i18n from '../config/i18n';
import { 
  Address, 
  User, 
  LoginResult, 
  AuthContextType 
} from './types/auth.types';
import { 
  mapApiUserToUser, 
  isUserPendingApproval, 
  isUserRejected, 
  getAuthToken, 
  removeAuthToken 
} from './utils/auth.utils';
import {
  saveUserAddress,
  deleteUserAddress,
  setUserDefaultAddress,
  updateUserWithAddresses
} from './utils/address.utils';
import {
  updateUserProfile,
  fetchCurrentUser,
  updateUserWithProfileData
} from './utils/profile.utils';

// Crear el contexto
const AuthContext = createContext<AuthContextType | undefined>(undefined);

/**
 * Hook personalizado para acceder al contexto de autenticación
 */
export const useAuth = () => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth debe ser usado dentro de un AuthProvider');
  }
  return context;
};

interface AuthProviderProps {
  children: ReactNode;
}

/**
 * Proveedor del contexto de autenticación
 */
export const AuthProvider = ({ children }: AuthProviderProps) => {
  // Estados
  const [isAuthenticated, setIsAuthenticated] = useState<boolean>(false);
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [isPending, setIsPending] = useState<boolean>(false);
  
  // Estados para modales
  const [showLoginModal, setShowLoginModal] = useState<boolean>(false);
  const [showRegisterModal, setShowRegisterModal] = useState<boolean>(false);
  const [showRejectedModal, setShowRejectedModal] = useState<boolean>(false);
  
  // Flag para prevenir race conditions en llamadas concurrentes
  const [isFetchingUser, setIsFetchingUser] = useState<boolean>(false);
  
  // Ref para controlar el AbortController de peticiones globales
  const abortControllerRef = useRef<AbortController | null>(null);
  
  // Refs para romper dependencias circulares entre useCallbacks
  const logoutRef = useRef<() => void>(() => {});
  const userRef = useRef<User | null>(null);
  const isFetchingUserRef = useRef<boolean>(false);
  
  // Mantener refs sincronizadas con el estado
  useEffect(() => { userRef.current = user; }, [user]);
  useEffect(() => { isFetchingUserRef.current = isFetchingUser; }, [isFetchingUser]);

  /**
   * Efecto para escuchar eventos de sesión expirada desde otros contextos
   * Esto permite que MembershipContext notifique cuando detecta un 401
   */
  useEffect(() => {
    const handleSessionExpired = (event: Event) => {
      const customEvent = event as CustomEvent;
      logger.warn('AuthContext', 'Sesión expirada detectada', customEvent.detail);
      
      // Ejecutar logout para limpiar el estado
      logoutRef.current();
    };
    
    window.addEventListener('auth:sessionExpired', handleSessionExpired);
    
    return () => {
      window.removeEventListener('auth:sessionExpired', handleSessionExpired);
    };
  }, []);

  /**
   * SINCRONIZACIÓN CROSS-TAB
   * Registra callback para recibir notificaciones cuando otra pestaña haga logout.
   * Esto permite que todas las pestañas del mismo navegador cierren sesión simultáneamente.
   */
  useEffect(() => {
    cacheManager.onLogout(() => {
      logger.info('AuthContext', '📡 Cross-tab: Logout detectado desde otra pestaña');
      
      // Limpiar estado de autenticación sin notificar a otras pestañas (ya lo saben)
      removeAuthToken();
      secureStorage.removeItem('csrfToken');
      clearMembershipLevelsCache();
      
      setUser(null);
      setIsAuthenticated(false);
      setIsPending(false);
      setIsFetchingUser(false);
      
      logger.info('AuthContext', '📡 Cross-tab: Logout sincronizado completado');
    });
    
    // No hay cleanup necesario - el callback se sobrescribe si se re-registra
  }, []);

  /**
   * Efecto para verificar la autenticación al cargar la página
   */
  useEffect(() => {
    const checkAuth = async () => {
      try {
        setLoading(true);
        
        // Verificar si hay un token almacenado
        const token = getAuthToken();
        
        if (token) {
          // Intentar obtener los datos del usuario
          const userData = await fetchCurrentUser();
          
          if (userData) {
            // Verificar si el usuario está pendiente de aprobación
            if (userData.pending) {
              setUser({...userData, pending: true});
              setIsPending(true);
              // Ya no autenticamos a usuarios pendientes
              setIsAuthenticated(false);
              logger.warn('AuthContext', 'Usuario pendiente de aprobación');
            } else {
              // Usuario normal
              setUser(userData);
              setIsAuthenticated(true);
              setIsPending(false);
              logger.info('AuthContext', 'Sesión recuperada correctamente');
            }
          }
        }
      } catch (error) {
        logger.error('AuthContext', 'Error al verificar autenticación', error);
      } finally {
        setLoading(false);
      }
    };
    
    checkAuth();
  }, []);

  /**
   * Cierra la sesión del usuario
   */
  const logout = useCallback(() => {
    logger.info('AuthContext', 'Iniciando logout - Cancelando peticiones pendientes');
    
    // Cancelar todas las peticiones pendientes
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
      logger.info('AuthContext', 'Peticiones pendientes canceladas');
    }
    
    // Limpiar estado de autenticación
    removeAuthToken();
    
    // Limpiar CSRF token
    secureStorage.removeItem('csrfToken');
    logger.info('AuthContext', 'CSRF token eliminado');
    
    // Limpiar cache de niveles de membresía para evitar datos stale en próximo login
    clearMembershipLevelsCache();
    logger.info('AuthContext', 'Cache de niveles de membresía limpiado');
    
    // Notificar a otras pestañas que el usuario hizo logout (cross-tab sync)
    cacheManager.notifyLogout();
    logger.info('AuthContext', 'Notificación de logout enviada a otras pestañas');
    
    setUser(null);
    setIsAuthenticated(false);
    setIsPending(false);
    setIsFetchingUser(false); // Resetear flag de fetching
    
    logger.info('AuthContext', 'Logout completado');
  }, []);
  
  // Mantener logoutRef sincronizada
  useEffect(() => { logoutRef.current = logout; }, [logout]);

  /**
   * Obtiene los datos del usuario actual
   * Previene race conditions con flag isFetchingUser
   */
  const getCurrentUser = useCallback(async (): Promise<User | null> => {
    // Prevenir llamadas concurrentes (usar ref para valor fresco)
    if (isFetchingUserRef.current) {
      logger.warn('AuthContext', 'Ya hay una petición de usuario en curso, ignorando llamada duplicada');
      return userRef.current; // Devolver usuario actual si existe
    }
    
    try {
      setIsFetchingUser(true);
      
      const token = getAuthToken();
      
      if (!token) {
        logger.warn('AuthContext', 'No hay token para obtener usuario');
        return null;
      }
      
      const response = await api.get('/wp/v2/users/me');
      
      if (response && response.data) {
        // Verificar si la cuenta está pendiente de aprobación
        const isPendingApproval = isUserPendingApproval(response.data);
        
        // Verificar si el usuario ha sido rechazado
        const isRejected = isUserRejected(response.data);
        
        if (isPendingApproval) {
          const userData = mapApiUserToUser({
            ...response.data,
            pending: true
          });
          
          setUser(userData);
          return userData;
        }
        
        if (isRejected) {
          logger.warn('AuthContext', 'Usuario rechazado, bloqueando acceso');
          logoutRef.current(); // Limpiar token para usuarios rechazados
          setError(i18n.t('errors:auth.accountRejected'));
          return null;
        }
        
        // Usuario normal, proceder con la autenticación
        const userData = mapApiUserToUser(response.data);
        
        // Obtener avatar personalizado del perfil (sobreescribe Gravatar si existe)
        try {
          const profileRes = await api.get('/starter/v1/user/profile?fields=avatar');
          if (profileRes.data?.avatar) {
            userData.avatar = profileRes.data.avatar;
            userData.customAvatar = true;
          }
        } catch {
          // Si falla, mantener el avatar de Gravatar
        }
        
        setUser(userData);
        setIsAuthenticated(true);
        setIsPending(false);
        
        return userData;
      }
      
      return null;
    } catch (error) {
      logger.error('AuthContext', 'Error al obtener usuario actual', error);
      logoutRef.current(); // Limpiar token si hay error
      return null;
    } finally {
      setIsFetchingUser(false);
    }
  }, []);

  /**
   * Maneja el caso de un usuario pendiente de aprobación
   */
  const handlePendingUser = useCallback(async (user: User): Promise<LoginResult> => {
    logger.warn('AuthContext', 'Usuario pendiente de aprobación', { user });
    
    // Ya no actualizamos los estados del contexto porque no vamos a autenticar al usuario
    // Solo devolvemos la información de que está pendiente para que la página de login
    // pueda mostrar el modal correspondiente
    
    const errorMsg = i18n.t('errors:auth.pendingApproval');
    setError(errorMsg);
    
    // Es importante devolver pendingApproval: true para que el componente LandingPage
    // pueda detectar este caso y mostrar el modal
    return { success: false, pendingApproval: true, message: errorMsg };
  }, []);

  /**
   * Inicia sesión con credenciales
   */
  const login = useCallback(async (identifier: string, password: string): Promise<LoginResult> => {
    try {
      setLoading(true);
      setError(null);
      logger.info('AuthContext', 'Iniciando sesión con:', { identifier });
      
      try {
        // Intentar el login normal
        // Nota: authService.login ahora maneja internamente las cuentas rechazadas y pendientes
        // y lanza errores personalizados con propiedades especiales
        const response = await authService.login(identifier, password);
        
        // Si llegamos aquí, es un login exitoso normal
        // Verificar que el token esté configurado correctamente
        const token = getAuthToken();
        if (!token) {
          logger.error('AuthContext', 'No se encontró token después del login');
          const errorMsg = i18n.t('errors:auth.tokenNotAvailable');
          setError(errorMsg);
          return { success: false, error: errorMsg };
        }
        
        // Actualizar el estado con los datos del usuario
        if (response && response.id) {
          // Obtener datos completos del usuario para verificar su estado
          const userResponse = await api.get('/wp/v2/users/me');
          
          if (userResponse && userResponse.data) {
            // Verificar si el usuario está pendiente de aprobación
            const isPendingApproval = isUserPendingApproval(userResponse.data);
            
            // Verificar si el usuario ha sido rechazado
            const isRejected = isUserRejected(userResponse.data);
            
            if (isPendingApproval) {
              // Convertir el objeto de usuario de la API al formato esperado por el contexto
              const mappedUser = mapApiUserToUser(response);
              return await handlePendingUser(mappedUser);
            }
            
            if (isRejected) {
              logger.warn('AuthContext', 'Intento de login con cuenta rechazada');
              logoutRef.current(); // Limpiar token para usuarios rechazados
              const errorMsg = i18n.t('errors:auth.accountRejected');
              setError(errorMsg);
              // Devolver rejected: true para que la página de login pueda mostrar el modal
              return { success: false, rejected: true, error: errorMsg };
            }
            
            // Usuario normal, proceder con la autenticación
            const userData = mapApiUserToUser(response);
            
            setUser(userData);
            setIsAuthenticated(true);
            setIsPending(false);
            setError(null);
            
            return { success: true };
          }
        }
        
        // Si llegamos aquí, algo salió mal
        const errorMsg = i18n.t('errors:auth.getUserDataError');
        setError(errorMsg);
        return { success: false, error: errorMsg };
      } catch (error: any) {
        // Verificar si es un error especial de cuenta pendiente
        if (error.isPendingAccount && error.pendingUser) {
          logger.info('AuthContext', 'Detectado error especial de cuenta pendiente');
          // Convertir el objeto de usuario de la API al formato esperado por el contexto
          const mappedUser = mapApiUserToUser(error.pendingUser);
          const result = await handlePendingUser(mappedUser);
          // Asegurarnos de que pendingApproval se propague correctamente
          return { ...result, pendingApproval: true };
        }
        
        // Verificar si es un error especial de cuenta rechazada
        if (error.isRejectedAccount && error.rejectedUser) {
          logger.warn('AuthContext', 'Error en login: Cuenta rechazada', error);
          const errorMsg = error.message || i18n.t('errors:auth.accountRejected');
          setError(errorMsg);
          
          // Actualizar el usuario rechazado y mostrar el modal
          setUser(mapApiUserToUser(error.rejectedUser));
          setShowRejectedModal(true);
          
          // Devolver rejected: true para que la página de login pueda mostrar el modal
          return { success: false, rejected: true, error: errorMsg, rejectedUser: error.rejectedUser };
        }
        
        // Propagar otros errores
        throw error;
      }
    } catch (error) {
      logger.error('AuthContext', 'Error en login:', error);
      const rateMsg = errorHandler.getRateLimitMessage(error);
      const errorMsg = rateMsg || (error instanceof Error ? error.message : i18n.t('errors:auth.unknownLoginError'));
      setError(errorMsg);
      return { success: false, error: errorMsg };
    } finally {
      setLoading(false);
    }
  }, [handlePendingUser]);

  /**
   * Guarda o actualiza una dirección
   */
  const saveAddress = useCallback(async (addressData: Partial<Address>): Promise<Address> => {
    try {
      setLoading(true);
      setError(null);
      
      // Verificar que el usuario exista (usar ref para valor fresco)
      const currentUser = userRef.current;
      if (!currentUser) {
        throw new Error(i18n.t('errors:auth.loginRequired'));
      }
      
      const response = await saveUserAddress(addressData, currentUser.addresses);
      
      if (response.success) {
        // Actualizar el usuario con las nuevas direcciones
        setUser(prev => prev ? updateUserWithAddresses(prev, response.addresses) : null);
        
        return response.address;
      } else {
        throw new Error(i18n.t('errors:auth.saveAddressError'));
      }
    } catch (error: any) {
      logger.error('AuthContext', 'Error al guardar dirección', error);
      setError(error.message || i18n.t('errors:auth.saveAddressError'));
      throw error;
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Elimina una dirección
   */
  const deleteAddress = useCallback(async (addressId: number): Promise<void> => {
    try {
      setLoading(true);
      setError(null);
      
      const response = await deleteUserAddress(addressId);
      
      if (response.success) {
        // Actualizar el usuario con las direcciones actualizadas
        setUser(prev => updateUserWithAddresses(prev!, response.addresses));
      } else {
        throw new Error(i18n.t('errors:auth.deleteAddressError'));
      }
    } catch (error: any) {
      logger.error('AuthContext', 'Error al eliminar dirección', error);
      setError(error.message || i18n.t('errors:auth.deleteAddressError'));
      throw error;
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Establece una dirección como predeterminada
   */
  const setDefaultAddress = useCallback(async (addressId: number): Promise<void> => {
    try {
      setLoading(true);
      setError(null);
      
      const response = await setUserDefaultAddress(addressId);
      
      if (response.success) {
        // Actualizar el usuario con las direcciones actualizadas
        setUser(prev => updateUserWithAddresses(prev!, response.addresses));
      } else {
        throw new Error(i18n.t('errors:auth.setDefaultAddressError'));
      }
    } catch (error: any) {
      logger.error('AuthContext', 'Error al establecer dirección predeterminada', error);
      setError(error.message || i18n.t('errors:auth.setDefaultAddressError'));
      throw error;
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Actualiza el perfil del usuario
   */
  const updateProfile = useCallback(async (profileData: Partial<User>): Promise<void> => {
    try {
      // NO usar setLoading(true) aquí: el loading global controla el render de AppContent
      // y activarlo desmonta toda la ruta (CheckoutPage, etc.), destruyendo el estado de los hooks.
      setError(null);
      
      const response = await updateUserProfile(profileData);
      
      if (response.success) {
        // Si el servidor devuelve los datos del usuario, usarlos directamente
        if (response.user) {
          setUser(prev => {
            if (!prev) return null;
            
            logger.info('AuthContext', 'Actualizando usuario con datos del servidor:', response.user);
            
            return {
              ...prev,
              firstName: response.user.firstName,
              lastName: response.user.lastName,
              email: response.user.email,
              phone: response.user.phone,
              birthDate: response.user.birthDate,
              gender: response.user.gender,
              newsletter: response.user.newsletter,
              active: response.user.active,
              emailChangePending: response.user.emailChangePending,
              newEmail: response.user.newEmail,
              documentId: response.user.documentId,
              acceptedTerms: response.user.acceptedTerms,
              acceptedTermsDate: response.user.acceptedTermsDate,
              acceptedDataVeracity: response.user.acceptedDataVeracity,
              acceptedDataVeracityDate: response.user.acceptedDataVeracityDate,
              avatar: response.user.avatar || prev.avatar,
            };
          });
        } else {
          // Si no hay datos del usuario en la respuesta, intentar recargar
          try {
            const userData = await fetchCurrentUser();
            
            if (userData) {
              setUser(userData);
              
              // Si la cuenta está pendiente, no establecer como autenticado
              if (userData.pending) {
                logger.warn('AuthContext', 'Sesión recuperada pero usuario pendiente de aprobación');
                setIsAuthenticated(false);
                setIsPending(true);
              } else {
                setIsAuthenticated(true);
                setIsPending(false);
                logger.info('AuthContext', 'Sesión recuperada correctamente');
              }
            } else {
              // Si no podemos recargar los datos, al menos actualizamos con lo que enviamos
              setUser(prev => updateUserWithProfileData(prev, profileData));
            }
          } catch (error) {
            logger.error('AuthContext', 'Error al recargar datos del usuario', error);
            // Si falla la recarga, actualizamos con los datos enviados
            setUser(prev => updateUserWithProfileData(prev, profileData));
          }
        }
      } else {
        throw new Error(i18n.t('errors:auth.updateProfileError'));
      }
    } catch (error: any) {
      logger.error('AuthContext', 'Error al actualizar perfil', error);
      setError(error.message || i18n.t('errors:auth.updateProfileError'));
      throw error;
    }
  }, []);

  // Memoizar el valor del contexto para evitar re-renders innecesarios en consumidores
  const contextValue = useMemo(() => ({
    isAuthenticated,
    user,
    login,
    logout,
    loading,
    error,
    isPending,
    showLoginModal,
    setShowLoginModal,
    showRegisterModal,
    setShowRegisterModal,
    showRejectedModal,
    setShowRejectedModal,
    saveAddress,
    deleteAddress,
    setDefaultAddress,
    updateProfile,
    getCurrentUser
  }), [
    isAuthenticated,
    user,
    login,
    logout,
    loading,
    error,
    isPending,
    showLoginModal,
    showRegisterModal,
    showRejectedModal,
    saveAddress,
    deleteAddress,
    setDefaultAddress,
    updateProfile,
    getCurrentUser
  ]);

  return (
    <AuthContext.Provider value={contextValue}>
      {children}
    </AuthContext.Provider>
  );
};

export default AuthContext;