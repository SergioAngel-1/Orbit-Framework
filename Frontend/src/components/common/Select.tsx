import React, { useState, useRef, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { IoIosArrowDown } from 'react-icons/io';
import { fluidSizing } from '../../utils/fluidSizing';

export interface SelectOption {
  value: string;
  label: string;
}

interface SelectProps {
  id?: string;
  name?: string;
  value: string;
  onChange: (value: string) => void;
  options: SelectOption[];
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
  className?: string;
  error?: string;
}

/**
 * Componente Select reutilizable con estilo personalizado
 * Sigue la línea visual del proyecto con fluidSizing
 */
const Select: React.FC<SelectProps> = ({
  id,
  name,
  value,
  onChange,
  options,
  placeholder,
  disabled = false,
  required = false,
  className = '',
  error
}) => {
  const { t } = useTranslation('uiComponents');
  const resolvedPlaceholder = placeholder ?? t('select.placeholder');

  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Encontrar la opción seleccionada
  const selectedOption = options.find(opt => opt.value === value);
  const displayText = selectedOption?.label || resolvedPlaceholder;

  // Cerrar al hacer clic fuera
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleSelect = (optionValue: string) => {
    onChange(optionValue);
    setIsOpen(false);
  };

  return (
    <div ref={containerRef} className={`relative ${className}`}>
      {/* Input oculto para formularios */}
      <input
        type="hidden"
        id={id}
        name={name}
        value={value}
        required={required}
      />
      
      {/* Botón del select */}
      <div
        onClick={() => !disabled && setIsOpen(!isOpen)}
        className={`w-full border rounded-md cursor-pointer flex justify-between items-center transition-all ${
          disabled 
            ? 'bg-gray-100 cursor-not-allowed border-gray-200' 
            : error
              ? 'border-red-400 bg-white'
              : isOpen
                ? 'border-primario bg-white'
                : 'border-gray-300 bg-white hover:border-gray-400'
        }`}
        style={{ 
          padding: '0.5rem 0.75rem',
          fontSize: '0.875rem',
          minHeight: '42px'
        }}
      >
        <span className={!selectedOption?.value ? 'text-gray-400' : 'text-gray-900'}>
          {displayText}
        </span>
        <IoIosArrowDown
          className={`text-gray-400 transition-transform flex-shrink-0 ${isOpen ? 'rotate-180' : ''}`}
          size={16}
        />
      </div>

      {/* Dropdown de opciones */}
      {isOpen && !disabled && (
        <div 
          className="absolute w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg overflow-y-auto select-none"
          style={{
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
            maxHeight: 'calc(6 * 40px)',
            zIndex: 50
          }}
        >
          {options
            .filter(option => option.value !== '') // Filtrar la opción placeholder
            .map((option) => (
              <div
                key={option.value}
                onClick={() => handleSelect(option.value)}
                className={`cursor-pointer transition-colors ${
                  option.value === value 
                    ? 'bg-primario/10 text-primario font-medium' 
                    : 'hover:bg-gray-50 text-texto'
                }`}
                style={{ 
                  padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`,
                  fontSize: fluidSizing.text.sm
                }}
              >
                {option.label}
              </div>
            ))}
        </div>
      )}

      {/* Mensaje de error */}
      {error && (
        <p className="text-red-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
          {error}
        </p>
      )}
    </div>
  );
};

export default Select;
