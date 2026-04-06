import React, { useState, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import AnimatedModal from '../ui/AnimatedModal';
import { FiCheck } from 'react-icons/fi';
import { COUNTRIES, Country } from '../../data/countries';

interface CountrySelectorModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSelectCountry: (country: Country) => void;
  selectedCountryCode: string;
}

const CountrySelectorModal: React.FC<CountrySelectorModalProps> = ({
  isOpen,
  onClose,
  onSelectCountry,
  selectedCountryCode
}) => {
  const { t } = useTranslation('commonComponents');
  const [searchQuery, setSearchQuery] = useState('');

  // Filtrar países según búsqueda
  const filteredCountries = useMemo(() => {
    if (!searchQuery.trim()) return COUNTRIES;
    
    const query = searchQuery.toLowerCase();
    return COUNTRIES.filter(country => 
      country.name.toLowerCase().includes(query) ||
      country.dialCode.includes(query)
    );
  }, [searchQuery]);

  const handleSelectCountry = (country: Country) => {
    onSelectCountry(country);
    setSearchQuery(''); // Limpiar búsqueda
    onClose();
  };

  const handleClose = () => {
    setSearchQuery(''); // Limpiar búsqueda al cerrar
    onClose();
  };

  return (
    <AnimatedModal 
      isOpen={isOpen} 
      onClose={handleClose}
      title={t('countrySelector.title')}
      maxWidth="md"
    >
      <div className="p-4 bg-gray-50">
        {/* Buscador simple */}
        <div className="mb-4 relative">
          <input
            type="text"
            placeholder={t('countrySelector.searchPlaceholder')}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-primario"
            autoFocus
          />
          <svg 
            xmlns="http://www.w3.org/2000/svg" 
            className="h-5 w-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400" 
            fill="none" 
            viewBox="0 0 24 24" 
            stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
        </div>

        {/* Lista de países */}
        <div className="max-h-96 overflow-y-auto pr-2">
          {filteredCountries.length > 0 ? (
            <div className="space-y-2">
              {filteredCountries.map((country) => {
                const isSelected = country.code === selectedCountryCode;
                
                return (
                  <button
                    key={country.code}
                    onClick={() => handleSelectCountry(country)}
                    className={`w-full flex items-center justify-between px-4 py-3 rounded-lg transition-all duration-200 ${
                      isSelected 
                        ? 'bg-green-50 border-2 border-green-500 shadow-sm' 
                        : 'bg-white border-2 border-gray-200 hover:border-primario hover:shadow-md'
                    }`}
                  >
                    <div className="flex items-center space-x-3">
                      <div className="w-8 h-8 flex items-center justify-center">
                        <span 
                          className={`fi fi-${country.code} fis`}
                          title={country.name}
                          style={{ fontSize: '2em', lineHeight: 1 }}
                        />
                      </div>
                      <div className="text-left">
                        <p className={`font-semibold ${
                          isSelected ? 'text-green-700' : 'text-gray-900'
                        }`}>
                          {country.name}
                        </p>
                        <p className="text-sm text-gray-500">+{country.dialCode}</p>
                      </div>
                    </div>
                    
                    {isSelected && (
                      <div className="bg-green-500 text-white rounded-full p-1">
                        <FiCheck className="w-4 h-4" />
                      </div>
                    )}
                  </button>
                );
              })}
            </div>
          ) : (
            <div className="text-center py-12 text-gray-500">
              <svg 
                xmlns="http://www.w3.org/2000/svg" 
                className="h-16 w-16 mx-auto mb-3 text-gray-300" 
                fill="none" 
                viewBox="0 0 24 24" 
                stroke="currentColor"
              >
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              <p className="font-medium">{t('countrySelector.noResults')}</p>
              <p className="text-sm mt-1">{t('countrySelector.tryAnother')}</p>
            </div>
          )}
        </div>
      </div>
    </AnimatedModal>
  );
};

export default CountrySelectorModal;
