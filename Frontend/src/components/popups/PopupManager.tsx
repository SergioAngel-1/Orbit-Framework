import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { popupApiService } from '../../services/popups';
import type { Popup, PopupType } from '../../services/popups/popupTypes';
import MembershipLegacyPopup from './MembershipLegacyPopup';
import ExpirationReminderPopup from './ExpirationReminderPopup';
import MembershipExpiredPopup from './MembershipExpiredPopup';
import ReferralBonusPopup from './ReferralBonusPopup';
import LoginPromptPopup from './LoginPromptPopup';
import GeneralPopup from './GeneralPopup';
import logger from '../../utils/logger';

const POPUP_STORAGE_KEY = 'starter_popup_state';

interface PopupState {
  dismissed: Record<number, string>; // popupId -> timestamp
  viewedToday: Record<number, string>; // popupId -> date
  viewedSession: number[]; // popupIds viewed this session
}

type DisplayFrequency = 'always' | 'once_per_session' | 'once_per_day' | 'once' | 'until_criteria';

const POPUP_DISPLAY_RULES: Record<PopupType, DisplayFrequency> = {
  membership_legacy: 'until_criteria',
  membership_expiration: 'until_criteria',
  membership_expired: 'until_criteria',
  referral_bonus: 'until_criteria',
  login_prompt: 'until_criteria',
  general: 'once_per_session',
};

const getStoredState = (): PopupState => {
  try {
    const stored = localStorage.getItem(POPUP_STORAGE_KEY);
    if (stored) {
      return JSON.parse(stored);
    }
  } catch (error) {
    logger.warn('PopupManager', 'Error reading popup state from storage');
  }
  return { dismissed: {}, viewedToday: {}, viewedSession: [] };
};

const saveState = (state: PopupState) => {
  try {
    localStorage.setItem(POPUP_STORAGE_KEY, JSON.stringify(state));
  } catch (error) {
    logger.warn('PopupManager', 'Error saving popup state to storage');
  }
};

// Tipos de popup que son obligatorios (no se pueden cerrar sin completar acción)
const MANDATORY_POPUP_TYPES: PopupType[] = ['membership_legacy'];

const shouldShowPopup = (popup: Popup, state: PopupState): boolean => {
  const today = new Date().toISOString().split('T')[0];
  const frequency = POPUP_DISPLAY_RULES[popup.type] || 'once_per_session';
  
  switch (frequency) {
    case 'until_criteria':
      // El backend ya filtra por criterio (elegibilidad)
      // Para popups obligatorios, SIEMPRE mostrar (el backend decide si es elegible)
      if (MANDATORY_POPUP_TYPES.includes(popup.type) || popup.dismissible === false) {
        return true;
      }
      // Para otros, evitar mostrar múltiples veces en la misma sesión
      return !state.viewedSession.includes(popup.id);
      
    case 'once':
      return !state.dismissed[popup.id];
      
    case 'once_per_day':
      return state.viewedToday[popup.id] !== today;
      
    case 'once_per_session':
      return !state.viewedSession.includes(popup.id);
      
    case 'always':
    default:
      return true;
  }
};

const markPopupShown = (
  popup: Popup, 
  state: PopupState
): PopupState => {
  const today = new Date().toISOString().split('T')[0];
  const frequency = POPUP_DISPLAY_RULES[popup.type] || 'once_per_session';
  const newState = { ...state };
  
  // Para popups obligatorios, NO marcar como visto (el backend controla elegibilidad)
  if (MANDATORY_POPUP_TYPES.includes(popup.type) || popup.dismissible === false) {
    // No modificar el estado - el popup seguirá apareciendo hasta que se complete la acción
    return state;
  }
  
  // Para 'once' guardamos en dismissed permanentemente
  // Para 'until_criteria' NO guardamos en dismissed porque el backend controla la elegibilidad
  if (frequency === 'once') {
    newState.dismissed = { ...newState.dismissed, [popup.id]: new Date().toISOString() };
  }
  
  newState.viewedToday = { ...newState.viewedToday, [popup.id]: today };
  newState.viewedSession = [...newState.viewedSession, popup.id];
  
  return newState;
};

// Rutas donde NO se debe mostrar el popup de login
const LOGIN_PROMPT_EXCLUDED_ROUTES = ['/iniciar-sesion', '/login', '/registrarse', '/register', '/recuperar-contrasena'];

const PopupManager: React.FC = () => {
  const { isAuthenticated, loading: authLoading } = useAuth();
  const location = useLocation();
  const [popups, setPopups] = useState<Popup[]>([]);
  const [currentPopup, setCurrentPopup] = useState<Popup | null>(null);
  const [isPopupOpen, setIsPopupOpen] = useState(false);
  const [popupState, setPopupState] = useState<PopupState>(getStoredState);
  const [isLoading, setIsLoading] = useState(false);
  
  // Refs para evitar dependencias inestables en useCallback
  const isShowingPopupRef = useRef(false);
  const delayTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const hasLoadedRef = useRef(false);
  const popupStateRef = useRef(popupState);
  
  // Verificar si estamos en una ruta excluida para login_prompt
  const isLoginPromptExcludedRoute = LOGIN_PROMPT_EXCLUDED_ROUTES.some(
    route => location.pathname.startsWith(route)
  );
  
  // Mantener ref sincronizado con estado
  useEffect(() => {
    popupStateRef.current = popupState;
  }, [popupState]);

  // Función para mostrar un popup con su delay configurado
  const showPopupWithDelay = useCallback((popup: Popup) => {
    // Evitar mostrar múltiples popups simultáneamente
    if (isShowingPopupRef.current) {
      logger.warn('PopupManager', 'Ya hay un popup en proceso de mostrar, ignorando');
      return;
    }
    
    isShowingPopupRef.current = true;
    const delay = popup.displayDelay || 0;
    
    if (delay > 0) {
      logger.info('PopupManager', `Esperando ${delay} segundos antes de mostrar popup "${popup.title}"`);
      
      delayTimeoutRef.current = setTimeout(() => {
        setCurrentPopup(popup);
        setIsPopupOpen(true);
        delayTimeoutRef.current = null;
      }, delay * 1000);
    } else {
      setCurrentPopup(popup);
      setIsPopupOpen(true);
    }
  }, []);

  // Cargar popups - solo para usuarios autenticados (excepto login_prompt)
  useEffect(() => {
    // Limpiar timeout pendiente si cambia el estado de auth
    if (delayTimeoutRef.current) {
      clearTimeout(delayTimeoutRef.current);
      delayTimeoutRef.current = null;
    }
    
    // No cargar si auth está cargando
    if (authLoading) {
      return;
    }
    
    // Si el usuario cerró sesión, limpiar popups y resetear estado
    if (!isAuthenticated) {
      setPopups([]);
      setCurrentPopup(null);
      setIsPopupOpen(false);
      isShowingPopupRef.current = false;
      hasLoadedRef.current = false;
      logger.info('PopupManager', 'Usuario no autenticado, popups limpiados');
      return;
    }
    
    // Evitar cargar múltiples veces en la misma sesión de autenticación
    if (hasLoadedRef.current) {
      return;
    }
    
    const loadPopups = async () => {
      setIsLoading(true);
      hasLoadedRef.current = true;
      
      try {
        logger.info('PopupManager', 'Cargando popups para usuario autenticado...');
        const activePopups = await popupApiService.getActivePopups();
        
        // Filtrar popups según el estado local (frecuencia de visualización)
        // y excluir login_prompt si estamos en rutas de autenticación
        const currentState = popupStateRef.current;
        const filteredPopups = activePopups.filter(popup => {
          // Excluir login_prompt en rutas de login/register
          if (popup.type === 'login_prompt' && isLoginPromptExcludedRoute) {
            logger.info('PopupManager', 'login_prompt excluido en ruta de autenticación');
            return false;
          }
          return shouldShowPopup(popup, currentState);
        });
        
        logger.info('PopupManager', `Recibidos ${activePopups.length} popups, ${filteredPopups.length} para mostrar`);
        
        setPopups(filteredPopups);
        
        // Mostrar el primer popup de la cola (ya vienen ordenados por prioridad)
        if (filteredPopups.length > 0) {
          showPopupWithDelay(filteredPopups[0]);
        }
      } catch (error) {
        logger.error('PopupManager', 'Error al cargar popups:', error);
        hasLoadedRef.current = false; // Permitir reintentar en caso de error
      } finally {
        setIsLoading(false);
      }
    };
    
    loadPopups();
  }, [isAuthenticated, authLoading, showPopupWithDelay, isLoginPromptExcludedRoute]);

  const handleClosePopup = useCallback(() => {
    logger.info('PopupManager', `handleClosePopup called for popup: ${currentPopup?.type} (id: ${currentPopup?.id})`);
    
    if (!currentPopup) {
      logger.warn('PopupManager', 'handleClosePopup called but no currentPopup');
      return;
    }
    
    // Cerrar el popup actual
    setIsPopupOpen(false);
    
    // Actualizar estado
    const newState = markPopupShown(currentPopup, popupState);
    setPopupState(newState);
    saveState(newState);
    
    // Remover popup actual de la cola y mostrar siguiente después de la animación
    setTimeout(() => {
      const remainingPopups = popups.filter(p => p.id !== currentPopup.id);
      setPopups(remainingPopups);
      
      // Resetear flag para permitir mostrar el siguiente popup
      isShowingPopupRef.current = false;
      
      if (remainingPopups.length > 0) {
        // Mostrar siguiente popup
        showPopupWithDelay(remainingPopups[0]);
      } else {
        setCurrentPopup(null);
      }
    }, 350); // Esperar a que termine la animación de cierre
  }, [currentPopup, popups, popupState, showPopupWithDelay]);

  const renderPopup = () => {
    if (!currentPopup) return null;
    
    const commonProps = {
      popup: currentPopup,
      isOpen: isPopupOpen,
      onClose: handleClosePopup,
    };
    
    switch (currentPopup.type) {
      case 'membership_legacy':
        return <MembershipLegacyPopup {...commonProps} />;
        
      case 'membership_expiration':
        return <ExpirationReminderPopup {...commonProps} />;
        
      case 'membership_expired':
        return <MembershipExpiredPopup {...commonProps} />;
        
      case 'referral_bonus':
        return <ReferralBonusPopup {...commonProps} />;
        
      case 'login_prompt':
        return <LoginPromptPopup {...commonProps} />;
        
      case 'general':
      default:
        return <GeneralPopup {...commonProps} />;
    }
  };

  // No renderizar nada si está cargando auth
  if (authLoading || isLoading) {
    return null;
  }

  return renderPopup();
};

export default PopupManager;
