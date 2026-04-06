import React from 'react';
import { useTranslation } from 'react-i18next';
import { FiShield } from 'react-icons/fi';

interface DataVeracityCheckboxProps {
  accepted: boolean;
  setAccepted: (value: boolean) => void;
  disabled?: boolean;
}

/**
 * Componente de checkbox para confirmar veracidad de datos
 * Incluye referencia a la Ley de Habeas Data (Ley 1581 de 2012 - Colombia)
 */
const DataVeracityCheckbox: React.FC<DataVeracityCheckboxProps> = ({
  accepted,
  setAccepted,
  disabled = false
}) => {
  const { t } = useTranslation('registerForm');

  return (
    <div className={`rounded-lg border-2 overflow-hidden transition-all ${
      accepted ? 'border-primario shadow-sm' : 'border-gray-200'
    } ${disabled ? 'opacity-50' : ''}`}>
      {/* Header con icono y título */}
      <div className="bg-gradient-to-r from-gray-50 to-gray-100 px-3 py-2 border-b border-gray-200">
        <div className="flex items-center gap-2">
          <FiShield className="w-5 h-5 text-primario" />
          <span className="font-medium text-oscuro text-sm">{t('dataVeracity.title')}</span>
        </div>
      </div>
      
      {/* Body con checkbox y texto legal */}
      <label 
        className={`flex items-start space-x-3 p-3 cursor-pointer transition-all ${
          accepted ? 'bg-primario/5' : 'bg-white hover:bg-gray-50'
        } ${disabled ? 'cursor-not-allowed' : ''}`}
      >
        {/* Checkbox visual personalizado */}
        <div className="flex items-center pt-0.5">
          <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-all ${
            accepted ? 'border-primario bg-primario' : 'border-gray-300 bg-white'
          }`}>
            {accepted && (
              <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
              </svg>
            )}
          </div>
        </div>
        
        <input
          type="checkbox"
          name="dataVeracity"
          checked={accepted}
          onChange={(e) => setAccepted(e.target.checked)}
          disabled={disabled}
          className="sr-only"
        />
        
        <div className="flex-1 text-xs text-gray-500 select-none leading-relaxed">
          {t('dataVeracity.text')}
          <span className="text-red-500 ml-0.5">*</span>
        </div>
      </label>
    </div>
  );
};

export default DataVeracityCheckbox;
