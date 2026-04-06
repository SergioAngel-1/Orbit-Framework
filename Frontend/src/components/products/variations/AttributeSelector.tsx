import React, { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { IoIosArrowDown } from 'react-icons/io';
import VariationOption from './VariationOption';

interface AttributeSelectorProps {
  attribute: any;
  selectedAttributes: { [key: string]: string };
  openDropdown: string | null;
  setOpenDropdown: (dropdown: string | null) => void;
  handleAttributeChange: (attributeName: string, value: string | null) => void;
  getAvailableOptionsForAttribute: (attributeName: string, defaultOptions: string[]) => string[];
  variationsMap: Map<number, any>;
}

/**
 * Componente para seleccionar un atributo de variación
 */
const AttributeSelector: React.FC<AttributeSelectorProps> = ({
  attribute,
  selectedAttributes,
  openDropdown,
  setOpenDropdown,
  handleAttributeChange,
  getAvailableOptionsForAttribute,
  variationsMap
}) => {
  const { t } = useTranslation('productComponents');
  const containerRef = useRef<HTMLDivElement>(null);

  // Cerrar dropdown al hacer clic fuera
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (openDropdown === attribute.name && containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpenDropdown(null);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [openDropdown, attribute.name, setOpenDropdown]);

  return (
    <div className="mb-3 md:mb-4 md:flex-1 min-w-[180px]" ref={containerRef}>
      <label className="block text-sm font-medium text-gray-700 mb-1.5 md:mb-2">
        {attribute.name}
      </label>
      <div className="relative">
        <div
          className="w-full p-2.5 md:p-3 bg-white rounded-md shadow-sm cursor-pointer flex justify-between items-center select-none text-sm md:text-base"
          style={{ border: '1px solid #e5e7eb' }}
          onClick={() => setOpenDropdown(attribute.name === openDropdown ? null : attribute.name)}
        >
          <span>
            {selectedAttributes[attribute.name] || t('attributeSelector.selectAttribute', { name: attribute.name })}
          </span>
          <IoIosArrowDown
            className={`text-gray-500 transition-transform ${openDropdown === attribute.name ? 'rotate-180' : ''}`}
            size={18}
          />
        </div>
        {openDropdown === attribute.name && (
          <div className="absolute w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg overflow-y-auto select-none"
            style={{
              boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
              maxHeight: 'calc(5 * 48px)', // Altura para 5 opciones
              zIndex: 9999
            }}>
            {/* Opción para eliminar la selección - solo si hay algo seleccionado */}
            {selectedAttributes[attribute.name] && (
              <div
                className="p-2.5 md:p-3 cursor-pointer hover:bg-gray-50 text-red-600 flex justify-between items-center h-10 md:h-12 text-sm md:text-base border-b border-gray-100"
                onClick={() => {
                  handleAttributeChange(attribute.name, null);
                  setOpenDropdown(null);
                }}
              >
                <span>{t('attributeSelector.clearSelection')}</span>
              </div>
            )}
            
            {/* Opciones disponibles para este atributo */}
            {getAvailableOptionsForAttribute(attribute.name, attribute.options).map((option, index) => {
              // Buscar la variación que corresponde a esta opción
              const matchingVariations = Array.from(variationsMap.values()).filter(variation => {
                // Verificar si esta variación coincide con los atributos ya seleccionados
                let matchesSelectedAttributes = true;
                for (const [selectedAttrName, selectedAttrValue] of Object.entries(selectedAttributes)) {
                  // Saltarse el atributo actual que estamos evaluando
                  if (selectedAttrName === attribute.name) continue;

                  // Verificar si la variación coincide con los otros atributos seleccionados
                  const variationAttr = variation.attributes.find((attr: any) =>
                    attr.name === selectedAttrName || attr.name === `pa_${selectedAttrName.toLowerCase()}`
                  );

                  if (!variationAttr || variationAttr.option !== selectedAttrValue) {
                    matchesSelectedAttributes = false;
                    break;
                  }
                }

                // Si coincide con los atributos seleccionados, verificar si tiene esta opción
                if (matchesSelectedAttributes) {
                  return variation.attributes && variation.attributes.some((attr: { name: string, option: string }) =>
                    (attr.name === attribute.name || attr.name === `pa_${attribute.name.toLowerCase()}`) && attr.option === option
                  );
                }

                return false;
              });

              // Tomar la primera variación que coincida para mostrar el precio
              const variationToShow = matchingVariations.length > 0 ? matchingVariations[0] : null;

              return (
                <VariationOption
                  key={index}
                  option={option}
                  isSelected={selectedAttributes[attribute.name] === option}
                  variationToShow={variationToShow}
                  onClick={() => {
                    handleAttributeChange(attribute.name, option);
                    setOpenDropdown(null);
                  }}
                />
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
};

export default AttributeSelector;
