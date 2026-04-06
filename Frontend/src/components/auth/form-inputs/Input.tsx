import React, { ReactElement } from 'react';
import { FaCheck, FaTimes } from 'react-icons/fa';
import '../../../styles/formInputs.css';
import Loader from '../../ui/Loader';

// Definición de estados de validación
export type ValidationStatus = 'none' | 'valid' | 'invalid' | 'validating';

// Tipo para el componente de icono - puede ser un componente o un elemento React
type IconComponent = React.ComponentType<{
  className?: string;
}> | React.ReactElement;

interface InputProps {
  id: string;
  name: string;
  type: string;
  value: string;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  autoComplete?: string;
  icon: IconComponent;
  label: string;
  labelRequired?: boolean;
  validationStatus: ValidationStatus;
  validationMessage?: string;
  helpTooltip?: ReactElement;
  className?: string;
  rightElement?: ReactElement;
  customInput?: ReactElement; // Input personalizado que reemplaza al input estándar
}

/**
 * Componente base para inputs con validación estandarizada
 */
const Input: React.FC<InputProps> = ({
  id,
  name,
  type,
  value,
  onChange,
  placeholder = '',
  required = false,
  disabled = false,
  autoComplete,
  icon,
  label,
  labelRequired = false,
  validationStatus = 'none',
  validationMessage = '',
  helpTooltip,
  className = '',
  rightElement,
  customInput,
}) => {
  // Configuración de estilos para los diferentes estados de validación
  const validationStyles = {
    none: {
      border: 'border-gray-300',
      textColor: 'text-gray-400',
      iconColor: 'text-gray-400',
      icon: null
    },
    valid: {
      border: 'border-[#207a36]',
      textColor: 'text-[#207a36]',
      iconColor: 'text-[#207a36]',
      icon: <FaCheck className="ml-1 text-[#207a36]" />
    },
    invalid: {
      border: 'border-[#b9094c]',
      textColor: 'text-[#b9094c]',
      iconColor: 'text-[#b9094c]',
      icon: <FaTimes className="ml-1 text-[#b9094c]" />
    },
    validating: {
      border: 'border-primario',
      textColor: 'text-primario',
      iconColor: 'text-primario',
      icon: <div className="ml-1"><Loader text="" size="small" /></div>
    }
  };

  // Obtenemos los valores según el estado de validación
  const { border, textColor, iconColor, icon: validationIcon } = validationStyles[validationStatus];

  return (
    <div className="mb-4">
      <label htmlFor={id} className="block text-sm font-medium text-gray-700 mb-1">
        {label} {labelRequired && <span className="text-[#FF3B30]">*</span>}
        {helpTooltip}
      </label>
      <div className="form-input-container">
        <div className="input-icon-container">
          <span className={`input-icon ${iconColor}`}>
            {React.isValidElement(icon) ? icon : React.createElement(icon as React.ComponentType<any>, { className: iconColor })}
          </span>
        </div>
        {customInput ? (
          <div className={`custom-input-wrapper ${border === 'border-[#207a36]' ? 'input-valid' : border === 'border-[#b9094c]' ? 'input-invalid' : border === 'border-[#FFCC00]' ? 'input-validating' : ''}`}>
            {customInput}
          </div>
        ) : (
          <input
            id={id}
            name={name}
            type={type}
            autoComplete={autoComplete}
            required={required}
            disabled={disabled}
            className={`form-input ${border === 'border-[#207a36]' ? 'input-valid' : border === 'border-[#b9094c]' ? 'input-invalid' : border === 'border-[#FFCC00]' ? 'input-validating' : ''} ${rightElement ? 'pr-10' : ''} ${className}`}
            placeholder={placeholder}
            value={value}
            onChange={onChange}
          />
        )}
        {rightElement && (
          <div className="absolute inset-y-0 right-0 pr-2 flex items-center">
            {rightElement}
          </div>
        )}
      </div>
      {validationMessage && (
        <div className="validation-message">
          {validationStatus !== 'none' && validationStatus !== 'validating' && validationIcon}
          <p className={`text-sm ${textColor} ml-1`}>
            {validationMessage}
          </p>
        </div>
      )}
    </div>
  );
};

export default Input;
