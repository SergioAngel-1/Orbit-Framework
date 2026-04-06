import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { FiPlus, FiMapPin } from 'react-icons/fi';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';
import { useLanguage } from '../../contexts/LanguageContext';

// Import types
interface User {
  id: number;
  name: string;
  email: string;
  avatar?: string;
  addresses: Address[];
  defaultAddress: Address | null;
  pending: boolean;
  firstName?: string;
  lastName?: string;
  phone?: string;
  birthDate?: string;
  gender?: string;
  newsletter?: boolean;
  active?: boolean;
  emailChangePending?: boolean;
  newEmail?: string;
}

interface Address {
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

interface ShippingAddressProps {
  isAuthenticated: boolean;
  user: User | null;
  selectedAddressId: number | null;
  onAddressSelect: (addressId: number) => void;
  onOpenProfileModal: () => void;
}

const ShippingAddress: React.FC<ShippingAddressProps> = ({
  isAuthenticated,
  user,
  selectedAddressId,
  onAddressSelect,
  onOpenProfileModal
}) => {
  const { t } = useTranslation('checkoutPage');
  const navigate = useNavigate();
  const { localizedPath } = useLanguage();

  return (
    <CollapsibleSection
      title={t('shipping.title')}
      icon={FiMapPin}
      variant="soft"
      collapsible={true}
      defaultExpanded={true}
      showCollapseButton={false}
      headerExtra={
        isAuthenticated && user?.addresses && user.addresses.length > 0 ? (
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onOpenProfileModal();
            }}
            className="bg-primario hover:bg-hover text-white hover:text-white rounded-md transition-colors font-medium flex items-center"
            style={{ 
              padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
              fontSize: fluidSizing.text.xs,
              gap: fluidSizing.space.xs
            }}
          >
            <FiPlus style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
            <span className="hidden sm:inline">{t('shipping.addAddressFull')}</span>
            <span className="sm:hidden">{t('shipping.addAddressShort')}</span>
          </button>
        ) : undefined
      }
    >
      {isAuthenticated && user?.addresses && user.addresses.length > 0 ? (
        <div 
          className="grid grid-cols-1 sm:grid-cols-2"
          style={{ gap: fluidSizing.space.md }}
        >
          {user.addresses.map((address: Address) => (
            <div
              key={address.id}
              className={`rounded-lg cursor-pointer transition-all ${
                selectedAddressId === address.id
                  ? 'border-2 border-primario bg-primario/5'
                  : 'border border-secundario/30 hover:border-primario/50'
              }`}
              style={{ padding: fluidSizing.space.md }}
              onClick={() => onAddressSelect(address.id)}
            >
              <input
                type="radio"
                name="addressId"
                checked={selectedAddressId === address.id}
                onChange={() => onAddressSelect(address.id)}
                className="sr-only"
              />
              <div>
                <p className="font-medium text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{address.address}</p>
                <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                  {address.city}, {address.state} {address.postalCode}
                </p>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div 
          className="bg-secundario/10 rounded-lg text-center"
          style={{ padding: fluidSizing.space.xl }}
        >
          {isAuthenticated ? (
            <>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.md }}>
                {t('shipping.noAddresses')}
              </p>
              <button
                type="button"
                onClick={onOpenProfileModal}
                className="inline-flex items-center bg-primario hover:bg-hover text-white rounded-md transition-colors"
                style={{ 
                  padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`,
                  fontSize: fluidSizing.text.sm,
                  gap: fluidSizing.space.xs
                }}
              >
                <FiPlus style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                {t('shipping.addAddressFull')}
              </button>
            </>
          ) : (
            <>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.md }}>
                {t('shipping.loginRequired')}
              </p>
              <div className="flex flex-col sm:flex-row justify-center" style={{ gap: fluidSizing.space.sm }}>
                <button
                  type="button"
                  onClick={() => navigate(localizedPath('/iniciar-sesion') + '?redirect=' + encodeURIComponent(localizedPath('/finalizar-retiro')))}
                  className="inline-flex items-center justify-center bg-primario hover:bg-hover text-white rounded-md transition-colors"
                  style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
                >
                  {t('shipping.login')}
                </button>
                <button
                  type="button"
                  onClick={() => navigate(localizedPath('/registrarse') + '?redirect=' + encodeURIComponent(localizedPath('/finalizar-retiro')))}
                  className="inline-flex items-center justify-center border border-secundario text-texto hover:bg-secundario/20 rounded-md transition-colors"
                  style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
                >
                  {t('shipping.register')}
                </button>
              </div>
            </>
          )}
        </div>
      )}
    </CollapsibleSection>
  );
};

export default ShippingAddress;
