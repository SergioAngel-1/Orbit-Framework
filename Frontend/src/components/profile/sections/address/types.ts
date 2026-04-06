import { Address } from '../../../../contexts/types/auth.types';

/**
 * Tipo para los detalles de una dirección colombiana
 */
export interface AddressDetails {
  tipoVia: string;
  numeroVia: string;
  letraVia: string;
  bis: boolean;
  cardinal1: string;
  numero1: string;
  letra1: string;
  numero2: string;
  letra2: string;
  cardinal2: string;
  complemento: string;
}

/**
 * Tipo para los códigos telefónicos por país
 */
export type CountryPhoneCode = Record<string, string>;

/**
 * Props para el componente AddressCard
 */
export interface AddressCardProps {
  address: Address;
  onEdit: (address: Address) => void;
  onDelete: (id: number) => void;
  onSetDefault: (id: number) => void;
}
