/**
 * RequireMembership - Componente para proteger rutas/contenido por nivel de membresía
 */

import { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useMembership } from '../../contexts/MembershipContext';
import { useAuth } from '../../contexts/AuthContext';
import { useLanguage } from '../../contexts/LanguageContext';
import MembershipUpgradePrompt from './MembershipUpgradePrompt';
import Loader from '../ui/Loader';

interface RequireMembershipProps {
  /** Nivel de membresía requerido (0-5) */
  level: number;
  
  /** Contenido a mostrar si el usuario tiene acceso */
  children: ReactNode;
  
  /** Componente/contenido alternativo si no tiene acceso */
  fallback?: ReactNode;
  
  /** Si true, redirige a login si no está autenticado */
  requireAuth?: boolean;
  
  /** Ruta a la que redirigir si no tiene acceso (opcional) */
  redirectTo?: string;
  
  /** Mostrar prompt de upgrade en lugar de fallback */
  showUpgradePrompt?: boolean;
}

/**
 * Componente para proteger contenido basado en nivel de membresía
 * 
 * @example
 * // Proteger una ruta completa
 * <RequireMembership level={2}>
 *   <PremiumContent />
 * </RequireMembership>
 * 
 * @example
 * // Con fallback personalizado
 * <RequireMembership level={3} fallback={<LockedMessage />}>
 *   <ExclusiveContent />
 * </RequireMembership>
 * 
 * @example
 * // Con prompt de upgrade
 * <RequireMembership level={2} showUpgradePrompt>
 *   <PremiumFeature />
 * </RequireMembership>
 */
const RequireMembership = ({
  level,
  children,
  fallback,
  requireAuth = false,
  redirectTo,
  showUpgradePrompt = true,
}: RequireMembershipProps) => {
  const { hasAccessToLevel, currentLevel, loading, membershipName, membershipIcon } = useMembership();
  const { isAuthenticated } = useAuth();
  const { localizedPath } = useLanguage();
  const location = useLocation();

  // Mostrar loading mientras se carga la membresía
  if (loading) {
    return (
      <div className="flex justify-center items-center p-8">
        <Loader size="small" text="" />
      </div>
    );
  }

  // Si requiere autenticación y el usuario no está autenticado
  if (requireAuth && !isAuthenticated) {
    return <Navigate to={localizedPath('/iniciar-sesion')} state={{ from: location }} replace />;
  }

  // Verificar si el usuario tiene acceso al nivel requerido
  const hasAccess = hasAccessToLevel(level);

  if (hasAccess) {
    // Usuario tiene acceso, mostrar contenido
    return <>{children}</>;
  }

  // Usuario no tiene acceso
  
  // Si hay una ruta de redirección, redirigir
  if (redirectTo) {
    return <Navigate to={redirectTo} replace />;
  }

  // Si hay un fallback personalizado, mostrarlo
  if (fallback) {
    return <>{fallback}</>;
  }

  // Si se debe mostrar el prompt de upgrade, mostrarlo
  if (showUpgradePrompt) {
    return (
      <MembershipUpgradePrompt
        requiredLevel={level}
        currentLevel={currentLevel}
        currentMembershipName={membershipName}
        currentMembershipIcon={membershipIcon}
      />
    );
  }

  // Por defecto, no mostrar nada
  return null;
};

export default RequireMembership;
