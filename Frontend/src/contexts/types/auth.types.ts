/**
 * Tipos para el contexto de autenticación
 */

/**
 * Tipo para las direcciones de usuario
 */
export interface Address {
  id: number;
  name: string;
  address: string;
  city: string;
  state: string;
  postalCode: string;
  country: string;
  phone: string;
  isDefault: boolean;
}

/**
 * Tipo para el usuario
 */
export interface User {
  id: number;
  name: string;
  email: string;
  avatar?: string;
  customAvatar?: boolean;
  addresses: Address[];
  defaultAddress: Address | null;
  pending: boolean;
  rejected?: boolean;
  firstName?: string;
  lastName?: string;
  phone?: string;
  birthDate?: string;
  gender?: string;
  newsletter?: boolean;
  active?: boolean;
  emailChangePending?: boolean;
  newEmail?: string;
  documentId?: string;
  acceptedTerms?: boolean;
  acceptedTermsDate?: string | null;
  acceptedDataVeracity?: boolean;
  acceptedDataVeracityDate?: string | null;
}

/**
 * Resultado de la operación de login
 */
export interface LoginResult {
  success: boolean;
  error?: string;
  pendingApproval?: boolean;
  rejected?: boolean;
  message?: string;
  rejectedUser?: User;
}

/**
 * Tipo para el contexto de autenticación
 */
export interface AuthContextType {
  // Estado
  isAuthenticated: boolean;
  user: User | null;
  loading: boolean;
  error: string | null;
  isPending: boolean;
  
  // Modales
  showLoginModal: boolean;
  setShowLoginModal: (show: boolean) => void;
  showRegisterModal: boolean;
  setShowRegisterModal: (show: boolean) => void;
  showRejectedModal: boolean;
  setShowRejectedModal: (show: boolean) => void;
  
  // Autenticación
  login: (identifier: string, password: string) => Promise<LoginResult>;
  logout: () => void;
  getCurrentUser: () => Promise<User | null>;
  
  // Gestión de direcciones
  saveAddress: (address: Partial<Address>) => Promise<Address>;
  deleteAddress: (addressId: number) => Promise<void>;
  setDefaultAddress: (addressId: number) => Promise<void>;
  
  // Perfil
  updateProfile: (profileData: Partial<User>) => Promise<void>;
}
