import { useState, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../../contexts/AuthContext';
import ProfileSection from './sections/ProfileSection';
import AddressesSection from './sections/AddressesSection';
import OrdersSection from './sections/OrdersSection';
import ReferralsSection from './sections/ReferralsSection';
import MembershipSection from './sections/MembershipSection';
import DigitalCardSection from './sections/DigitalCardSection';
import alertService from '../../services/alertService';
import AnimatedModal from '../ui/AnimatedModal';
import ConfirmModal from '../ui/ConfirmModal';
import logger from '../../utils/logger';
import { useNavigate } from 'react-router-dom';
import Loader from '../ui/Loader';
import PasswordResetModal from '../auth/PasswordResetModal';
import AccessDeniedMessage from '../membership/AccessDeniedMessage';
import { useLanguage } from '../../contexts/LanguageContext';
import { useMembership } from '../../contexts/MembershipContext';
import { fluidSizing } from '../../utils/fluidSizing';

// Variable global para controlar el estado del modal y la sección activa
type ProfileTab = 'profile' | 'addresses' | 'orders' | 'referrals' | 'membership' | 'digitalCard';

let modalController: {
  openModal: (section?: ProfileTab) => void;
  closeModal: () => void;
} | null = null;

interface ProfileModalProps {
  isOpen: boolean;
  onClose: () => void;
  activeSection?: ProfileTab;
  addressesInitialShowAddForm?: boolean;
}

const ProfileModal = ({ isOpen, onClose, activeSection = 'profile', addressesInitialShowAddForm = false }: ProfileModalProps) => {
  const { logout, getCurrentUser, isAuthenticated } = useAuth();
  const { currentLevel, isActive } = useMembership();
  const navigate = useNavigate();
  const { t } = useTranslation('profileModal');
  const { localizedPath } = useLanguage();
  const [activeTab, setActiveTab] = useState(activeSection);
  const [loading, setLoading] = useState(false);
  const [isPasswordModalOpen, setIsPasswordModalOpen] = useState(false);
  const [isLogoutConfirmOpen, setIsLogoutConfirmOpen] = useState(false);
  
  // Ref para controlar el AbortController de peticiones
  const abortControllerRef = useRef<AbortController | null>(null);
  
  // Registrar el controlador del modal
  useEffect(() => {
    modalController = {
      openModal: (section = 'profile') => {
        setActiveTab(section);
        onClose(); // Primero cerramos para reiniciar la animación
        setTimeout(() => {
          onClose(); // Llamamos a onClose como toggle para abrir el modal
        }, 50);
      },
      closeModal: () => {
        onClose();
      }
    };
    
    return () => {
      modalController = null;
    };
  }, [onClose]);
  
  // Actualizar la pestaña activa cuando cambia activeSection
  useEffect(() => {
    if (activeSection) {
      setActiveTab(activeSection);
    }
  }, [activeSection]);
  
  // Cargar datos del usuario cuando se abre el modal
  useEffect(() => {
    if (isOpen) {
      // Crear nuevo AbortController para esta carga
      abortControllerRef.current = new AbortController();
      
      const loadUserData = async () => {
        setLoading(true);
        try {
          logger.info('ProfileModal', 'Cargando datos del usuario al abrir el modal');
          await getCurrentUser();
        } catch (error: any) {
          // Ignorar errores de cancelación
          if (error.name === 'AbortError' || error.name === 'CanceledError') {
            logger.info('ProfileModal', 'Carga de datos cancelada');
          } else {
            logger.error('ProfileModal', 'Error al cargar datos del usuario', error);
          }
        } finally {
          setLoading(false);
        }
      };
      
      loadUserData();
    }
    
    // Cleanup: cancelar peticiones pendientes cuando se cierra el modal
    return () => {
      if (abortControllerRef.current) {
        logger.info('ProfileModal', 'Cancelando peticiones pendientes al cerrar modal');
        abortControllerRef.current.abort();
        abortControllerRef.current = null;
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  const openChangePasswordModal = () => {
    setIsPasswordModalOpen(true);
  };

  const closeChangePasswordModal = () => {
    setIsPasswordModalOpen(false);
  };

  const handleLogout = () => {
    // Cancelar todas las peticiones pendientes ANTES de mostrar la confirmación
    if (abortControllerRef.current) {
      logger.info('ProfileModal', 'Cancelando peticiones pendientes antes de mostrar confirmación');
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }
    
    // Mostrar modal de confirmación (esto cierra ProfileModal automáticamente)
    setIsLogoutConfirmOpen(true);
  };

  const cancelLogout = () => {
    // Cerrar el modal de confirmación y reabrir ProfileModal
    setIsLogoutConfirmOpen(false);
  };

  const confirmLogout = () => {
    // Abort pending requests before logging out
    if (abortControllerRef.current) {
      logger.info('ProfileModal', 'Abortando peticiones pendientes antes de cerrar sesión');
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }

    // Cerrar modal de confirmación primero
    setIsLogoutConfirmOpen(false);
    
    // Cerrar ProfileModal también (llamar a onClose del padre)
    onClose();
    
    // Hacer logout
    logout();
    
    // Mostrar alerta de confirmación y redireccionar al home
    alertService.success(t('logout.successMessage'));
    setTimeout(() => {
      navigate(localizedPath('/'));
    }, 100);
  };

  // Si el usuario no está autenticado, mostrar mensaje de acceso denegado
  if (!isAuthenticated) {
    return (
      <AnimatedModal 
        isOpen={isOpen} 
        onClose={onClose} 
        className="max-w-lg"
        title={t('title')}
      >
        <AccessDeniedMessage
          title={t('accessDenied.title')}
          reason={t('accessDenied.reason')}
          description={t('accessDenied.description')}
          showCatalogButton={false}
          showMembershipButton={true}
          membershipButtonText={t('accessDenied.loginButton')}
          membershipButtonPath={localizedPath('/iniciar-sesion')}
          compact={true}
          onButtonClick={onClose}
        />
      </AnimatedModal>
    );
  }

  // Estilos fluidos para el modal
  const tabButtonStyle = {
    padding: fluidSizing.space.sm,
    gap: fluidSizing.space.xs,
    borderRadius: fluidSizing.modal.borderRadius,
  };

  const tabIconStyle = {
    width: fluidSizing.size.iconSm,
    height: fluidSizing.size.iconSm,
  };

  const tabTextStyle = {
    fontSize: fluidSizing.text.sm,
  };

  const sidebarStyle = {
    padding: fluidSizing.space.sm,
    gap: fluidSizing.space.xs,
  };

  const contentStyle = {
    padding: fluidSizing.space.sm,
    paddingBottom: fluidSizing.space.md,
  };

  const loaderContainerStyle = {
    height: fluidSizing.size.modalMd,
  };

  return (
    <>
      <AnimatedModal 
        isOpen={isOpen && !isLogoutConfirmOpen && !isPasswordModalOpen} 
        onClose={onClose} 
        className="max-w-4xl"
        title={t('title')}
        noPadding
      >
        <div className="flex flex-col md:flex-row rounded-lg h-full md:h-[70vh] min-h-0">
          {/* Barra lateral con tabs - en mobile se muestra arriba, fija */}
          <div 
            className="w-full md:w-1/4 bg-gray-50 border-b md:border-b-0 md:border-r border-gray-200 md:rounded-l-lg flex-shrink-0 md:h-full md:overflow-y-auto"
            style={sidebarStyle}
          >
            <div 
              className="flex flex-row md:flex-col justify-around md:justify-start items-center"
              style={{ gap: fluidSizing.space.xs }}
            >
              <button
                className={`md:w-full text-center md:text-left rounded-md flex flex-col md:flex-row items-center justify-center md:justify-start transition-colors ${
                  activeTab === 'profile' ? 'bg-primario text-white' : 'hover:bg-gray-200'
                } ${activeTab === 'profile' ? 'border-b-2 border-primario md:border-b-0' : ''}`}
                style={tabButtonStyle}
                onClick={() => setActiveTab('profile')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" style={tabIconStyle} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                <span className="hidden md:inline" style={tabTextStyle}>{t('tabs.profile')}</span>
              </button>
              
              <button
                className={`md:w-full text-center md:text-left rounded-md flex flex-col md:flex-row items-center justify-center md:justify-start transition-colors ${
                  activeTab === 'addresses' ? 'bg-primario text-white' : 'hover:bg-gray-200'
                } ${activeTab === 'addresses' ? 'border-b-2 border-primario md:border-b-0' : ''}`}
                style={tabButtonStyle}
                onClick={() => setActiveTab('addresses')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" style={tabIconStyle} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span className="hidden md:inline" style={tabTextStyle}>{t('tabs.addresses')}</span>
              </button>
              
              <button
                className={`md:w-full text-center md:text-left rounded-md flex flex-col md:flex-row items-center justify-center md:justify-start transition-colors ${
                  activeTab === 'orders' ? 'bg-primario text-white' : 'hover:bg-gray-200'
                } ${activeTab === 'orders' ? 'border-b-2 border-primario md:border-b-0' : ''}`}
                style={tabButtonStyle}
                onClick={() => setActiveTab('orders')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" style={tabIconStyle} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
                <span className="hidden md:inline" style={tabTextStyle}>{t('tabs.orders')}</span>
              </button>
              
              <button
                className={`md:w-full text-center md:text-left rounded-md flex flex-col md:flex-row items-center justify-center md:justify-start transition-colors ${
                  activeTab === 'referrals' ? 'bg-primario text-white' : 'hover:bg-gray-200'
                } ${activeTab === 'referrals' ? 'border-b-2 border-primario md:border-b-0' : ''}`}
                style={tabButtonStyle}
                onClick={() => setActiveTab('referrals')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" style={tabIconStyle} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <span className="hidden md:inline" style={tabTextStyle}>{t('tabs.referrals')}</span>
              </button>
              
              <button
                className={`md:w-full text-center md:text-left rounded-md flex flex-col md:flex-row items-center justify-center md:justify-start transition-colors ${
                  activeTab === 'membership' ? 'bg-primario text-white' : 'hover:bg-gray-200'
                } ${activeTab === 'membership' ? 'border-b-2 border-primario md:border-b-0' : ''}`}
                style={tabButtonStyle}
                onClick={() => setActiveTab('membership')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" style={tabIconStyle} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                </svg>
                <span className="hidden md:inline" style={tabTextStyle}>{t('tabs.membership')}</span>
              </button>

              {isActive && currentLevel > 0 && (
              <button
                className={`md:w-full text-center md:text-left rounded-md flex flex-col md:flex-row items-center justify-center md:justify-start transition-colors ${
                  activeTab === 'digitalCard' ? 'bg-primario text-white' : 'hover:bg-gray-200'
                } ${activeTab === 'digitalCard' ? 'border-b-2 border-primario md:border-b-0' : ''}`}
                style={tabButtonStyle}
                onClick={() => setActiveTab('digitalCard')}
              >
                <svg xmlns="http://www.w3.org/2000/svg" style={tabIconStyle} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <span className="hidden md:inline" style={tabTextStyle}>{t('tabs.digitalCard')}</span>
              </button>
              )}
              
              <button
                className="md:w-full text-center md:text-left rounded-md flex flex-col md:flex-row items-center justify-center md:justify-start text-red-600 hover:bg-red-50 transition-colors"
                style={tabButtonStyle}
                onClick={handleLogout}
              >
                <svg xmlns="http://www.w3.org/2000/svg" style={tabIconStyle} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                <span className="hidden md:inline" style={tabTextStyle}>{t('tabs.logout')}</span>
              </button>
            </div>
          </div>
          
          {/* Contenido de la tab activa - scrollable */}
          <div 
            className="w-full md:w-3/4 flex-1 min-h-0 overflow-y-auto modal-scrollable"
            style={{
              ...contentStyle,
              WebkitOverflowScrolling: 'touch',
            }}
            data-allow-scroll
          >
            {loading ? (
              <div className="flex justify-center items-center" style={loaderContainerStyle}>
                <Loader size="large" />
              </div>
            ) : (
              <>
                {activeTab === 'profile' && <ProfileSection onChangePassword={openChangePasswordModal} />}
                {activeTab === 'addresses' && <AddressesSection initialShowAddForm={addressesInitialShowAddForm} />}
                {activeTab === 'orders' && <OrdersSection onClose={onClose} />}
                {activeTab === 'referrals' && <ReferralsSection onClose={onClose} />}
                {activeTab === 'membership' && <MembershipSection onClose={onClose} />}
                {activeTab === 'digitalCard' && <DigitalCardSection onNavigateToProfile={() => setActiveTab('profile')} />}
              </>
            )}
          </div>
        </div>
      </AnimatedModal>
      
      {/* Modal de recuperación de contraseña: se renderiza fuera para evitar anidamientos */}
      <PasswordResetModal
        isOpen={isPasswordModalOpen}
        onClose={closeChangePasswordModal}
      />

      {/* Modal de confirmación de cierre de sesión - FUERA del AnimatedModal para evitar problemas de anidamiento */}
      <ConfirmModal
        isOpen={isLogoutConfirmOpen}
        onClose={cancelLogout}
        onConfirm={confirmLogout}
        title={t('logout.confirmTitle')}
        message={t('logout.confirmMessage')}
        confirmText={t('logout.confirmButton')}
        cancelText={t('logout.cancelButton')}
        variant="warning"
      />
    </>
  );
};

// Función para abrir el modal de perfil en una sección específica
export const openProfileModal = (section: ProfileTab = 'profile') => {
  if (modalController) {
    modalController.openModal(section);
  } else {
    logger.error('ProfileModal', 'No se pudo abrir el modal de perfil, el controlador no está disponible');
  }
};

export default ProfileModal;
