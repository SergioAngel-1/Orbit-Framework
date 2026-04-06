import React, { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { AddressCardProps } from './types';
import { FiMapPin, FiPhone, FiEdit2, FiTrash2, FiStar } from 'react-icons/fi';
import { fluidSizing } from '../../../../utils/fluidSizing';

/**
 * Componente que muestra una tarjeta de dirección con opciones para editar, eliminar y establecer como predeterminada
 */
const AddressCard: React.FC<AddressCardProps> = ({ address, onEdit, onDelete, onSetDefault }) => {
  const { t } = useTranslation('addressCard');
  return (
    <div 
      className={`rounded-lg transition-all ${
        address.isDefault 
          ? 'bg-primario/5 border-2 border-primario' 
          : 'bg-gray-50 border border-gray-200 hover:border-gray-300'
      }`}
      style={{ padding: fluidSizing.space.sm }}
      aria-label={t('addressLabel', { name: address.name })}
    >
      <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
        {/* Icono */}
        <div 
          className={`flex-shrink-0 rounded-full flex items-center justify-center ${
            address.isDefault ? 'bg-primario text-white' : 'bg-gray-200 text-gray-600'
          }`}
          style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
        >
          <FiMapPin style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
        </div>

        {/* Contenido */}
        <div className="flex-1 min-w-0">
          {/* Header con nombre y badge */}
          <div className="flex items-center justify-between" style={{ marginBottom: fluidSizing.space.xs }}>
            <h3 className="font-semibold text-oscuro truncate" style={{ fontSize: fluidSizing.text.sm }}>
              {address.name}
            </h3>
            {address.isDefault && (
              <span 
                className="flex items-center bg-primario text-white rounded-full flex-shrink-0"
                style={{ padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
              >
                <FiStar style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
                {t('badge')}
              </span>
            )}
          </div>

          {/* Dirección */}
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
            {address.address}
          </p>
          <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
            {address.city}, {address.state} {address.postalCode}
          </p>
          
          {/* Teléfono */}
          <div 
            className="flex items-center text-texto"
            style={{ marginTop: fluidSizing.space.xs, gap: fluidSizing.space.xs }}
          >
            <FiPhone style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
            <span dir="ltr" style={{ fontSize: fluidSizing.text.xs }}>{address.phone}</span>
          </div>
        </div>
      </div>
      
      {/* Botones de acción */}
      <div 
        className="flex justify-end border-t border-gray-200/50"
        style={{ marginTop: fluidSizing.space.sm, paddingTop: fluidSizing.space.sm, gap: fluidSizing.space.xs }}
      >
        {!address.isDefault && (
          <button
            onClick={() => onSetDefault(address.id)}
            className="flex items-center text-primario hover:bg-primario/10 rounded-md transition-colors"
            style={{ padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
            aria-label={t('buttons.setDefaultAria', { name: address.name })}
          >
            <FiStar style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
            <span>{t('buttons.setDefault')}</span>
          </button>
        )}
        <button
          onClick={() => onEdit(address)}
          className="flex items-center text-blue-600 hover:bg-blue-50 rounded-md transition-colors"
          style={{ padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
          aria-label={t('buttons.editAria', { name: address.name })}
        >
          <FiEdit2 style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
          <span>{t('buttons.edit')}</span>
        </button>
        <button
          onClick={() => onDelete(address.id)}
          className="flex items-center text-red-600 hover:bg-red-50 rounded-md transition-colors"
          style={{ padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
          aria-label={t('buttons.deleteAria', { name: address.name })}
        >
          <FiTrash2 style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
          <span>{t('buttons.delete')}</span>
        </button>
      </div>
    </div>
  );
};

// Usar memo para evitar renderizados innecesarios
export default memo(AddressCard, (prevProps, nextProps) => {
  // Solo renderizar si cambia alguna propiedad relevante
  return (
    prevProps.address.id === nextProps.address.id &&
    prevProps.address.isDefault === nextProps.address.isDefault &&
    prevProps.address.name === nextProps.address.name &&
    prevProps.address.address === nextProps.address.address &&
    prevProps.address.city === nextProps.address.city &&
    prevProps.address.state === nextProps.address.state &&
    prevProps.address.postalCode === nextProps.address.postalCode &&
    prevProps.address.country === nextProps.address.country &&
    prevProps.address.phone === nextProps.address.phone
  );
});
