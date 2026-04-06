import logger from './logger';

/**
 * Banner Action System
 * 
 * Permite que los banners disparen acciones internas (abrir modales, etc.)
 * en lugar de navegar a URLs externas.
 * 
 * Convención de formato: "action:<target>:<param>"
 * 
 * Acciones soportadas:
 * - action:profile              → Abre ProfileModal en tab 'profile'
 * - action:profile:digitalCard  → Abre ProfileModal en tab 'digitalCard'
 * - action:profile:addresses    → Abre ProfileModal en tab 'addresses'
 * - action:profile:orders       → Abre ProfileModal en tab 'orders'
 * - action:profile:membership   → Abre ProfileModal en tab 'membership'
 * - action:profile:referrals    → Abre ProfileModal en tab 'referrals'
 * 
 * El sistema usa CustomEvents que ya están siendo escuchados por Header.tsx:
 * - 'openProfileModal' → { detail: { section: ProfileTab } }
 */

const ACTION_PREFIX = 'action:';

/**
 * Verifica si un string de link es una acción interna
 */
export function isBannerAction(link: string | undefined | null): boolean {
  return typeof link === 'string' && link.startsWith(ACTION_PREFIX);
}

/**
 * Ejecuta la acción definida en el link del banner.
 * Retorna true si la acción fue reconocida y ejecutada, false si no.
 */
export function executeBannerAction(link: string): boolean {
  if (!isBannerAction(link)) return false;

  // Parsear: "action:profile:digitalCard" → target="profile", param="digitalCard"
  const parts = link.substring(ACTION_PREFIX.length).split(':');
  const target = parts[0];
  const param = parts[1] || undefined;

  switch (target) {
    case 'profile': {
      const section = param || 'profile';
      logger.info('bannerActions', `Dispatching openProfileModal → section: ${section}`);
      window.dispatchEvent(new CustomEvent('openProfileModal', {
        detail: { section }
      }));
      return true;
    }

    default:
      logger.warn('bannerActions', `Acción desconocida: ${target}`);
      return false;
  }
}
