import { Product, CartItem } from '../../types/woocommerce';

// Re-exportamos CartItem para mantener la coherencia
export type { CartItem };

/**
 * Interfaz para el estado del cupón
 */
export interface CouponState {
  applied: boolean;
  code: string;
  discount: number;
}

/**
 * Interfaz para el contexto del carrito
 */
export interface CartContextType {
  items: CartItem[];
  itemCount: number;
  subtotal: number;
  total: number;
  discount: number;
  shipping: number;
  couponApplied: boolean;
  couponCode: string;
  couponDiscount: number;
  addItem: (product: Product, quantity?: number, variation_id?: number, variation?: any) => Promise<void>;
  updateItemQuantity: (productId: number, quantity: number, variation_id?: number, showAlert?: boolean) => Promise<void>;
  removeItem: (productId: number, variation_id?: number, skipAlert?: boolean) => Promise<void>;
  clearCart: (silent?: boolean) => Promise<void>;
  applyCoupon: (code: string, discountPercentage: number) => void;
  removeCoupon: () => void;
  isLoading: boolean;
  isSyncing?: boolean;
  recoverCart?: () => Promise<void>;
  // Propiedades de pedido mínimo
  minimumAmount: number;
  meetsMinimum: boolean;
  missingAmount: number;
  minimumProgress: number;
}

/**
 * Propiedades para el proveedor del carrito
 */
export interface CartProviderProps {
  children: React.ReactNode;
}
