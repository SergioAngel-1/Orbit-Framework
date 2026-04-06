/**
 * MembershipLevelsModal - Modal con resumen del sistema de membresías
 * Muestra todos los niveles de membresía con sus iconos y nombres
 * 
 * OPTIMIZADO: Soporta modo singleton para evitar múltiples instancias en el DOM
 * Cuando se usa como singleton, se monta una vez en el layout y todos los
 * MembershipBadge pueden abrirlo a través de la función registrada.
 */

import { FC, useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCheck, FiStar, FiArrowRight } from 'react-icons/fi';
import { HiLightBulb } from 'react-icons/hi';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';
import AnimatedModal from '../ui/AnimatedModal';
import { sanitizeInlineHtml } from '../../utils/sanitizeHtml';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { useMembership } from '../../contexts/MembershipContext';
import Loader from '../ui/Loader';
import { fluidSizing } from '../../utils/fluidSizing';
import { registerMembershipModalOpener, unregisterMembershipModalOpener } from '../common/MembershipBadge';

interface MembershipLevelsModalProps {
  /** Si es true, el modal es controlado externamente */
  isOpen?: boolean;
  /** Callback cuando se cierra (modo controlado) */
  onClose?: () => void;
  /** Si es true, el modal se maneja internamente como singleton */
  singleton?: boolean;
}

const MembershipLevelsModal: FC<MembershipLevelsModalProps> = ({ 
  isOpen: externalIsOpen, 
  onClose: externalOnClose,
  singleton = false 
}) => {
  const { t } = useTranslation('membershipComponents');
  const { localizedPath } = useLanguage();
  // Estado interno para modo singleton
  const [internalIsOpen, setInternalIsOpen] = useState(false);
  
  // Determinar si usar estado interno o externo
  const isOpen = singleton ? internalIsOpen : (externalIsOpen ?? false);
  const onClose = singleton ? () => setInternalIsOpen(false) : (externalOnClose ?? (() => {}));
  
  // Función para abrir el modal (usada por MembershipBadge)
  const openModal = useCallback(() => {
    setInternalIsOpen(true);
  }, []);
  
  // Registrar/desregistrar la función de apertura cuando es singleton
  useEffect(() => {
    if (singleton) {
      registerMembershipModalOpener(openModal);
      return () => {
        unregisterMembershipModalOpener();
      };
    }
  }, [singleton, openModal]);
  const { levels, loading } = useMembershipLevels();
  const { currentLevel } = useMembership();

  // Ordenar niveles de mayor a menor
  const sortedLevels = [...levels].sort((a, b) => b.id - a.id);

  return (
    <AnimatedModal
      isOpen={isOpen}
      onClose={onClose}
      title={t('levelsModal.title')}
      className="max-w-md"
    >
      <div>
        {loading ? (
          <div className="flex justify-center" style={{ padding: fluidSizing.space.xl }}>
            <Loader size="medium" />
          </div>
        ) : (
          <>
            <p className="text-gray-600" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.md }}>
              {t('levelsModal.description')}
            </p>

            <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>
              {sortedLevels.map((level, index) => {
                const isCurrentLevel = level.id === currentLevel;
                const isHigherLevel = level.id > currentLevel;
                
                return (
                  <div
                    key={level.id}
                    className={`flex items-center rounded-lg border transition-all ${
                      isCurrentLevel 
                        ? 'border-primario bg-primario/5 ring-2 ring-primario/20' 
                        : isHigherLevel
                          ? 'border-gray-200 bg-gray-50/50 opacity-75'
                          : 'border-gray-200 bg-white'
                    }`}
                    style={{ gap: fluidSizing.space.md, padding: fluidSizing.space.sm }}
                  >
                    {/* Icono de membresía */}
                    <div 
                      className="rounded-full overflow-hidden flex-shrink-0 shadow-sm"
                      style={{ 
                        width: fluidSizing.size.buttonMd, 
                        height: fluidSizing.size.buttonMd,
                        backgroundColor: level.color + '20' 
                      }}
                    >
                      {level.icon_url ? (
                        <img 
                          src={level.icon_url} 
                          alt={level.name}
                          className="w-full h-full object-cover"
                        />
                      ) : (
                        <span className="w-full h-full flex items-center justify-center" style={{ fontSize: fluidSizing.text.xl }}>
                          {level.icon}
                        </span>
                      )}
                    </div>

                    {/* Información del nivel */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center" style={{ gap: fluidSizing.space.xs }}>
                        <h3 
                          className={`font-medium ${isCurrentLevel ? 'text-primario' : 'text-gray-900'}`}
                          style={{ fontSize: fluidSizing.text.sm }}
                        >
                          {level.name}
                        </h3>
                      </div>
                      <p className="text-gray-500" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('levelsModal.level', { id: level.id })}
                      </p>
                    </div>

                    {/* Indicador de jerarquía */}
                    <div className="flex-shrink-0 flex items-center">
                      {isCurrentLevel && (
                        <FiCheck 
                          className="text-primario" 
                          style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd, marginRight: fluidSizing.space.xs }} 
                          title={t('levelsModal.currentLevel')} 
                        />
                      )}
                      {level.name.toLowerCase().includes('diamante') && (
                        <span 
                          className="text-yellow-600 bg-yellow-50 rounded-full border border-yellow-200 flex items-center"
                          style={{ fontSize: fluidSizing.text.xs, padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`, gap: fluidSizing.space.xs }}
                        >
                          <FiStar style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} /> {t('levelsModal.maximum')}
                        </span>
                      )}
                      {index === sortedLevels.length - 1 && level.id === 0 && (
                        <span 
                          className="text-gray-500 bg-gray-100 rounded-full"
                          style={{ fontSize: fluidSizing.text.xs, padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}` }}
                        >
                          {t('levelsModal.basic')}
                        </span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Nota informativa */}
            <div 
              className="bg-primario/10 border border-primario/20 rounded-lg"
              style={{ marginTop: fluidSizing.space.md, padding: fluidSizing.space.sm }}
            >
              <p 
                className="text-primario flex items-start"
                style={{ fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
              >
                <HiLightBulb 
                  className="flex-shrink-0" 
                  style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm, marginTop: '2px' }} 
                />
                <span dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('levelsModal.tip')) }} />
              </p>
            </div>

            {/* Botón saber más */}
            <Link
              to={localizedPath('/membresias')}
              onClick={onClose}
              className="flex items-center justify-center w-full bg-primario text-white rounded-lg hover:bg-primario hover:text-white transition-colors font-medium"
              style={{ marginTop: fluidSizing.space.md, marginBottom: fluidSizing.space.sm, padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
            >
              {t('levelsModal.learnMore')}
              <FiArrowRight style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            </Link>
          </>
        )}
      </div>
    </AnimatedModal>
  );
};

export default MembershipLevelsModal;

/**
 * Componente singleton para montar una sola vez en el layout
 * Uso: <MembershipLevelsModalSingleton /> en el layout principal
 */
export const MembershipLevelsModalSingleton: FC = () => {
  return <MembershipLevelsModal singleton />;
};
