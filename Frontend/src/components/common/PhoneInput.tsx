import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FiChevronDown } from 'react-icons/fi';
import CountrySelectorModal from './CountrySelectorModal';
import { getCountryByCode, Country } from '../../data/countries';

interface CustomPhoneInputProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  inputClass?: string;
  inputId?: string;
  inputName?: string;
  required?: boolean;
  autoFocus?: boolean;
  inputProps?: any;
  noBorder?: boolean; // Para usar dentro de wrappers que ya tienen borde
}

const CustomPhoneInput: React.FC<CustomPhoneInputProps> = ({
  value,
  onChange,
  placeholder,
  disabled = false,
  inputClass = '',
  inputId = 'phone-input',
  inputName = 'phone',
  required = false,
  autoFocus = false,
  inputProps = {},
  noBorder = false
}) => {
  const { t } = useTranslation('alerts');
  // Estado para el código del país seleccionado y su indicativo
  const [selectedCountry, setSelectedCountry] = useState<Country>(
    getCountryByCode('co') || {
      code: 'co',
      dialCode: '57',
      name: 'Colombia',
      flag: '🇨🇴'
    }
  );
  
  // Estado para controlar el modal de selección de país
  const [isModalOpen, setIsModalOpen] = useState(false);

  // Cuando cambia el país, actualizar el valor para incluir el nuevo indicativo
  const updatePhoneWithDialCode = (phoneDigits: string, dialCode: string) => {
    // Si el valor original ya tenía un indicador y estamos en modo edición,
    // solo actualizamos el número sin cambiar el formato
    if (phoneDigits) {
      // Formato: +indicativo número (ej: +57 3001234567)
      const fullPhone = `+${dialCode} ${phoneDigits}`;
      onChange(fullPhone);
    } else {
      onChange('');
    }
  };

  // Filtrar solo caracteres numéricos en el input y actualizar con indicativo
  const handlePhoneInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Asegurarse de que solo se ingresen números
    const inputValue = e.target.value.replace(/[^0-9]/g, '');
    // Limitar a 10 dígitos para Colombia y otros países similares
    const maxLength = selectedCountry.code === 'es' ? 9 : 10;
    const trimmedValue = inputValue.substring(0, maxLength);
    updatePhoneWithDialCode(trimmedValue, selectedCountry.dialCode);
  };
  
  return (
    <div className="relative w-full">
      {/* Input de teléfono con botón de país integrado */}
      <div className={`flex w-full overflow-hidden transition-all ${noBorder ? '' : 'border border-gray-300 rounded-lg [&:has(input:focus)]:ring-2 [&:has(input:focus)]:ring-primario [&:has(input:focus)]:border-primario'}`}>
        {/* Botón selector de país */}
        <button
          type="button"
          onClick={() => setIsModalOpen(true)}
          disabled={disabled}
          className="flex items-center gap-1.5 px-2.5 bg-gray-50 outline-none border-none disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
          style={{ outline: 'none', boxShadow: 'none' }}
          aria-label={t('phoneInput.selectCountry')}
        >
          <span 
            className={`fi fi-${selectedCountry.code} fis`}
            title={selectedCountry.name}
            style={{ fontSize: '1.5em', lineHeight: 1 }}
          />
          <span className="text-sm font-medium text-gray-700">+{selectedCountry.dialCode}</span>
          <FiChevronDown className="text-gray-400 w-3.5 h-3.5" />
        </button>
        
        {/* Input para el número de teléfono */}
        <input
          id={inputId}
          name={inputName}
          type="tel"
          value={value.includes(' ') ? value.split(' ')[1] : value.replace(/[^0-9]/g, '')}
          onChange={handlePhoneInputChange}
          placeholder={placeholder || 'Número de teléfono'}
          disabled={disabled}
          required={required}
          autoFocus={autoFocus}
          className={`flex-1 px-4 py-2 border-0 outline-none disabled:bg-gray-50 disabled:cursor-not-allowed ${inputClass}`}
          autoComplete="tel-national"
          maxLength={selectedCountry.code === 'es' ? 9 : 10}
          {...inputProps}
        />
      </div>
      
      {/* Modal de selección de país */}
      <CountrySelectorModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        selectedCountryCode={selectedCountry.code}
        onSelectCountry={(country) => {
          setSelectedCountry(country);
          
          // Al cambiar el país, actualizar el valor completo
          const currentDigits = value.includes(' ') ? 
            value.split(' ')[1] : 
            value.replace(/[^0-9]/g, '');
          
          updatePhoneWithDialCode(currentDigits, country.dialCode);
        }}
      />
    </div>
  );
};

export default CustomPhoneInput;
