import React, { createContext, useContext, useState, ReactNode, useEffect, useRef, useCallback, useMemo } from 'react';
import { User } from './types/auth.types';
import { PendingApprovalModal } from '../components/auth';
import RejectedAccountModal from '../components/auth/RejectedAccountModal';
import logger from '../utils/logger';
// Nota: El bloqueo de scroll del body lo maneja AnimatedModal directamente
// para evitar duplicación de llamadas con modales anidados

interface ModalContextType {
  showPendingApprovalModal: (user: User) => void;
  hidePendingApprovalModal: () => void;
  showRejectedAccountModal: (user: User) => void;
  hideRejectedAccountModal: () => void;
  registerCustomModal: (id: string) => void;
  unregisterCustomModal: (id: string) => void;
}

export const ModalContext = createContext<ModalContextType | undefined>(undefined);

export const useModal = () => {
  const context = useContext(ModalContext);
  if (!context) {
    throw new Error('useModal debe ser usado dentro de un ModalProvider');
  }
  return context;
};

interface ModalProviderProps {
  children: ReactNode;
}

export const ModalProvider: React.FC<ModalProviderProps> = ({ children }) => {
  const [isPendingModalOpen, setIsPendingModalOpen] = useState(false);
  const [pendingUser, setPendingUser] = useState<User | null>(null);
  
  const [isRejectedModalOpen, setIsRejectedModalOpen] = useState(false);
  const [rejectedUser, setRejectedUser] = useState<User | null>(null);
  const initialThemeColorRef = useRef<string | null>(null);
  const [customModalsCount, setCustomModalsCount] = useState(0);
  const customModalIdsRef = useRef<Set<string>>(new Set());

  const showPendingApprovalModal = useCallback((user: User) => {
    logger.info('ModalContext', 'Mostrando modal de aprobación pendiente', { user });
    setPendingUser(user);
    setIsPendingModalOpen(true);
  }, []);

  const hidePendingApprovalModal = useCallback(() => {
    logger.info('ModalContext', 'Cerrando modal de aprobación pendiente');
    setIsPendingModalOpen(false);
  }, []);
  
  const showRejectedAccountModal = useCallback((user: User) => {
    logger.info('ModalContext', 'Mostrando modal de cuenta rechazada', { user });
    setRejectedUser(user);
    setIsRejectedModalOpen(true);
  }, []);

  const hideRejectedAccountModal = useCallback(() => {
    logger.info('ModalContext', 'Cerrando modal de cuenta rechazada');
    setIsRejectedModalOpen(false);
  }, []);

  const registerCustomModal = useCallback((id: string) => {
    setCustomModalsCount((prev) => {
      if (customModalIdsRef.current.has(id)) {
        return prev;
      }
      customModalIdsRef.current.add(id);
      return prev + 1;
    });
  }, []);

  const unregisterCustomModal = useCallback((id: string) => {
    setCustomModalsCount((prev) => {
      if (!customModalIdsRef.current.has(id)) {
        return prev;
      }
      customModalIdsRef.current.delete(id);
      return Math.max(0, prev - 1);
    });
  }, []);

  useEffect(() => {
    const anyOpen = isPendingModalOpen || isRejectedModalOpen || customModalsCount > 0;
    let meta = document.querySelector('meta[name="theme-color"]') as HTMLMetaElement | null;
    
    // Guardar el theme-color inicial solo una vez
    if (initialThemeColorRef.current === null) {
      initialThemeColorRef.current = meta?.getAttribute('content') ?? '';
    }
    
    if (anyOpen) {
      // Cambiar theme-color
      if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
      }
      meta.setAttribute('content', '#111827');
      
      logger.info('ModalContext', 'Modal abierto - theme-color actualizado');
    } else {
      // Restaurar theme-color original
      if (meta) {
        const original = initialThemeColorRef.current ?? '';
        if (original) {
          meta.setAttribute('content', original);
        } else {
          document.head.removeChild(meta);
        }
      }
      
      logger.info('ModalContext', 'Todos los modales cerrados - theme-color restaurado');
    }
  }, [isPendingModalOpen, isRejectedModalOpen, customModalsCount]);

  // Memoizar el valor del contexto para evitar re-renders innecesarios
  const contextValue = useMemo(() => ({
    showPendingApprovalModal,
    hidePendingApprovalModal,
    showRejectedAccountModal,
    hideRejectedAccountModal,
    registerCustomModal,
    unregisterCustomModal
  }), [
    showPendingApprovalModal,
    hidePendingApprovalModal,
    showRejectedAccountModal,
    hideRejectedAccountModal,
    registerCustomModal,
    unregisterCustomModal
  ]);

  return (
    <ModalContext.Provider value={contextValue}>
      {children}
      
      {/* Renderizar el modal de aprobación pendiente aquí, fuera de la jerarquía normal */}
      <PendingApprovalModal
        isOpen={isPendingModalOpen}
        onClose={hidePendingApprovalModal}
        user={pendingUser}
      />
      
      {/* Renderizar el modal de cuenta rechazada */}
      <RejectedAccountModal
        isOpen={isRejectedModalOpen}
        onClose={hideRejectedAccountModal}
        user={rejectedUser}
      />
    </ModalContext.Provider>
  );
};

export default ModalProvider;
