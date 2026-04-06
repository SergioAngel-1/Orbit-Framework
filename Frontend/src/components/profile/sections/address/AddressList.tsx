import React, { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Address } from '../../../../contexts/types/auth.types';
import AddressCard from './AddressCard';
import { FiMapPin } from 'react-icons/fi';
import { fluidSizing } from '../../../../utils/fluidSizing';

interface AddressListProps {
  addresses: Address[];
  onEdit: (address: Address) => void;
  onDelete: (id: number) => void;
  onSetDefault: (id: number) => void;
  onAddNew: () => void;
}

/**
 * Componente que muestra la lista de direcciones del usuario
 */
const AddressList: React.FC<AddressListProps> = ({ 
  addresses, 
  onEdit, 
  onDelete, 
  onSetDefault,
  onAddNew
}) => {
  const { t } = useTranslation('addressesSection');
  if (addresses.length === 0) {
    return (
      <div 
        className="text-center bg-secundario/20 rounded-lg"
        style={{ padding: fluidSizing.space.lg }}
      >
        <div 
          className="mx-auto bg-secundario/30 rounded-full flex items-center justify-center"
          style={{ width: fluidSizing.size.avatar, height: fluidSizing.size.avatar, marginBottom: fluidSizing.space.md }}
        >
          <FiMapPin className="text-primario" style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg }} />
        </div>
        <p className="text-texto" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.sm }}>
          {t('form.noAddresses')}
        </p>
        <button
          onClick={onAddNew}
          className="text-primario hover:text-hover font-medium transition-colors"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('form.addFirstAddress')}
        </button>
      </div>
    );
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }} role="list" aria-label={t('form.addressListAria')}>
      {addresses.map((address) => (
        <AddressCard
          key={address.id}
          address={address}
          onEdit={onEdit}
          onDelete={onDelete}
          onSetDefault={onSetDefault}
        />
      ))}
    </div>
  );
};

// Usar memo para evitar renderizados innecesarios
export default memo(AddressList, (prevProps, nextProps) => {
  // Solo renderizar si cambia la lista de direcciones o si cambia su longitud
  if (prevProps.addresses.length !== nextProps.addresses.length) {
    return false;
  }
  
  // Comparar cada dirección para ver si algo cambió
  for (let i = 0; i < prevProps.addresses.length; i++) {
    const prevAddress = prevProps.addresses[i];
    const nextAddress = nextProps.addresses[i];
    
    if (
      prevAddress.id !== nextAddress.id ||
      prevAddress.isDefault !== nextAddress.isDefault ||
      prevAddress.name !== nextAddress.name ||
      prevAddress.address !== nextAddress.address ||
      prevAddress.city !== nextAddress.city ||
      prevAddress.state !== nextAddress.state ||
      prevAddress.postalCode !== nextAddress.postalCode ||
      prevAddress.country !== nextAddress.country ||
      prevAddress.phone !== nextAddress.phone
    ) {
      return false;
    }
  }
  
  return true;
});
