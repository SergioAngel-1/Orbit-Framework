/**
 * Tipos e interfaces para el sistema de popups
 */

export type PopupType = 
  | 'membership_legacy'      // Popup para migración de membresía por antigüedad
  | 'membership_expiration'  // Recordatorio de expiración de membresía
  | 'membership_expired'     // Aviso de membresía expirada
  | 'referral_bonus'         // Notificación de mensualidad por ser referido
  | 'login_prompt'           // Invitación a iniciar sesión (usuarios anónimos)
  | 'general';               // Popup de propósito general


export interface MembershipExpirationData {
  days_remaining: number;
  end_date: string;
  level: number;
  level_name?: string;
  level_icon?: string;
  renewal_period?: string;
  renewal_period_label?: string;
}

export interface ReferralBonusData {
  referrer_id: number;
  referrer_name: string;
  bonus_type: string;
  bonus_duration: number;
  membership_level?: number;
  membership_level_name?: string;
  membership_level_icon?: string;
  granted_at?: string;
  pending_approval?: boolean;
}

export interface MembershipExpiredData {
  expired_level: number;
  expired_level_name: string;
  expired_level_icon: string;
  end_date: string;
  days_since_expiry: number;
}

export interface LoginPromptData {
  show: boolean;
}

export interface LegacyMembershipData {
  eligible: boolean;
}

export type PopupEligibilityData = 
  | MembershipExpirationData 
  | MembershipExpiredData
  | ReferralBonusData 
  | LegacyMembershipData 
  | LoginPromptData
  | { show: boolean };

export interface Popup {
  id: number;
  title: string;
  content: string;
  type: PopupType;
  image?: string;
  imageMobile?: string;
  imageUrl?: string;
  dismissible: boolean;
  showOverlay: boolean;
  /** Retraso en segundos antes de mostrar el popup */
  displayDelay?: number;
  eligibilityData?: PopupEligibilityData;
  priority: number;
}

export interface PopupInteractionPayload {
  action: 'viewed' | 'dismissed' | 'clicked';
}

export interface LegacyMembershipResponse {
  success: boolean;
  message: string;
  response: 'accepted' | 'rejected';
  membership?: {
    level: number;
    name: string;
    icon: string;
    color: string;
    start_date: string;
    end_date: string;
  };
}

export interface PopupsResponse {
  success?: boolean;
  data?: Popup[];
}
