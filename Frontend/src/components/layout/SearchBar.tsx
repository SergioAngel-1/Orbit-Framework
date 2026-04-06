import { FC, useState, useRef, useEffect, ChangeEvent, KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSearchProducts } from '../../hooks/useWooCommerce';
import Loader from '../ui/Loader';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { getVariablePriceRange } from '../../utils/formatters';
import { fluidSizing } from '../../utils/fluidSizing';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildProductUrl } from '../../utils/membershipRouteUtils';

interface SearchBarProps {
  searchTerm: string;
  setSearchTerm: (term: string) => void;
  showResults?: boolean;
  setShowResults?: (show: boolean) => void;
}

const SearchBar: FC<SearchBarProps> = ({ 
  searchTerm, 
  setSearchTerm,
  showResults: externalShowResults,
  setShowResults: externalSetShowResults
}) => {
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const navigate = useNavigate();
  const [internalShowResults, setInternalShowResults] = useState(false);
  const [dropdownPosition, setDropdownPosition] = useState({ top: 0, left: 0, width: 0 });
  const searchRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const { t } = useTranslation('searchBar');
  const { data: searchResults, loading: searchLoading } = useSearchProducts(searchTerm, 1, 12, false); // Parámetro showOutOfStock en false
  
  // Usar el estado externo si está disponible, de lo contrario usar el interno
  const showResults = externalShowResults !== undefined ? externalShowResults : internalShowResults;
  const setShowResults = externalSetShowResults || setInternalShowResults;

  // Ya no cerramos el modal con clics fuera para permitir mejor interacción

  // Mostrar resultados cuando hay un término de búsqueda
  useEffect(() => {
    // Solo mostrar resultados si hay al menos 2 caracteres
    if (searchTerm && searchTerm.trim().length >= 2) {
      setShowResults(true);
    } else if (searchTerm.trim().length === 0) {
      setShowResults(false);
    }
  }, [searchTerm, setShowResults]);

  // Actualizar posición del dropdown cuando se muestra y durante scroll
  useEffect(() => {
    const updatePosition = () => {
      if (showResults && searchRef.current) {
        const rect = searchRef.current.getBoundingClientRect();
        setDropdownPosition({
          top: rect.bottom + 4,
          left: rect.left,
          width: rect.width
        });
      }
    };

    updatePosition();

    // Actualizar posición durante scroll para mantener sincronizado con header sticky
    if (showResults) {
      window.addEventListener('scroll', updatePosition, { passive: true });
      window.addEventListener('resize', updatePosition, { passive: true });
      return () => {
        window.removeEventListener('scroll', updatePosition);
        window.removeEventListener('resize', updatePosition);
      };
    }
  }, [showResults, searchTerm]);

  const handleInputChange = (e: ChangeEvent<HTMLInputElement>) => {
    setSearchTerm(e.target.value);
  };

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    // Si pulsa Escape, cerrar los resultados
    if (e.key === 'Escape') {
      setShowResults(false);
    }
    // Si pulsa Enter, ir a la página de búsqueda
    else if (e.key === 'Enter' && searchTerm.trim().length > 0) {
      e.preventDefault();
      navigateToSearchPage();
    }
  };
  
  const navigateToSearchPage = () => {
    setShowResults(false);
    navigate(localizedPath(`/catalogo/buscar`) + `?q=${encodeURIComponent(searchTerm)}`);
  };

  return (
    <div className="w-full relative" ref={searchRef}>
      <div className="relative">
        <input 
          ref={inputRef}
          type="text" 
          placeholder={t('placeholder')} 
          className="w-full border border-primario rounded-md focus:outline-none focus:ring-2 focus:ring-primario focus:border-transparent"
          style={{ 
            height: fluidSizing.size.inputHeight,
            paddingLeft: fluidSizing.space.md,
            paddingRight: fluidSizing.space.xl,
            fontSize: '16px' // Mínimo 16px para evitar auto-zoom en iOS Safari
          }}
          value={searchTerm}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          onFocus={() => {
            if (searchTerm.trim().length >= 2) {
              setShowResults(true);
            }
          }}
        />
        
        {/* Icono de búsqueda que también funciona como botón */}
        <button 
          type="button"
          onClick={() => {
            if (searchTerm.trim().length > 0) {
              navigateToSearchPage();
            }
          }}
          className="search-icon-btn absolute inset-y-0 right-0 flex items-center pr-3 text-oscuro hover:text-primario"
          style={{ outline: 'none', border: 'none', background: 'transparent', boxShadow: 'none' }}
          aria-label={t('searchAriaLabel')}
        >
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }}>
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
        </button>
        
        {/* Botón para limpiar la búsqueda */}
        {searchTerm && (
          <button
            type="button"
            onClick={() => {
              setSearchTerm('');
              setShowResults(false);
              inputRef.current?.focus();
            }}
            className="search-icon-btn absolute inset-y-0 right-8 flex items-center pr-3 text-gray-500 hover:text-oscuro"
            style={{ outline: 'none', border: 'none', background: 'transparent', boxShadow: 'none' }}
            aria-label={t('clearAriaLabel')}
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }}>
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        )}
      </div>
      
      {/* Resultados de búsqueda - usando React Portal para salir del stacking context */}
      {(showResults) && searchTerm && searchTerm.trim().length >= 2 && createPortal(
        <div 
          className="search-results-portal bg-white border border-gray-300 rounded-md shadow-xl max-h-96 overflow-y-auto"
          style={{ 
            position: 'fixed',
            zIndex: 9999,
            top: dropdownPosition.top,
            left: dropdownPosition.left,
            width: dropdownPosition.width
          }}
          onClick={(e) => e.stopPropagation()} // Evitar que los clics dentro del modal se propaguen
        >
          {searchLoading ? (
            <div className="text-center" style={{ padding: fluidSizing.space.md }}>
              <Loader text={t('loading')} size="small" />
            </div>
          ) : searchResults && searchResults.length > 0 ? (
            <div>
              <div className="border-b border-gray-200" style={{ padding: fluidSizing.space.xs }}>
                <p className="text-gray-600" style={{ fontSize: fluidSizing.text.xs }}>{t('resultsFor', { term: searchTerm })}</p>
              </div>
              <ul>
                {searchResults.slice(0, 12).map((product) => (
                  <li key={product.id} className="border-b border-gray-100 last:border-b-0">
                    <button 
                      type="button"
                      onClick={() => {
                        setShowResults(false);
                        setSearchTerm('');
                        navigate(localizedPath(buildProductUrl(product.categories && product.categories.length > 0 ? product.categories[0].slug : undefined, product.slug, product.categories && product.categories.length > 0 ? product.categories[0].min_membership_level ?? 0 : 0, levels)));
                      }}
                      className="flex items-center hover:bg-gray-50 transition-colors duration-150 cursor-pointer w-full text-left search-result-item !border-transparent hover:!border-transparent !outline-none !shadow-none !rounded-none"
                      style={{ padding: fluidSizing.space.sm }}
                    >
                      {product.images && product.images[0] && (
                        <img 
                          src={product.images[0].src} 
                          alt={product.name} 
                          className="object-cover rounded-md"
                          style={{ width: '3rem', height: '3rem', marginRight: fluidSizing.space.sm }}
                        />
                      )}
                      <div className="flex-grow">
                        <h4 className="font-medium text-gray-900" style={{ fontSize: fluidSizing.text.sm }}>{product.name}</h4>
                        {(() => {
                          const priceRange = getVariablePriceRange(product);
                          if (priceRange) {
                            return (
                              <div className="flex items-center gap-1 flex-wrap">
                                {!priceRange.max && <span className="text-2xs text-gray-500">{t('from')}</span>}
                                <VirtualCoinPrice amount={priceRange.min} size="xs" />
                                {priceRange.max && (
                                  <>
                                    <span className="text-2xs text-gray-500">–</span>
                                    <VirtualCoinPrice amount={priceRange.max} size="xs" />
                                  </>
                                )}
                              </div>
                            );
                          }
                          return (
                            <div className="flex items-center gap-1.5">
                              {product.on_sale && product.regular_price && (
                                <span className="line-through text-gray-400">
                                  <VirtualCoinPrice amount={parseFloat(product.regular_price)} size="xs" />
                                </span>
                              )}
                              <VirtualCoinPrice amount={parseFloat(product.price)} size="xs" />
                            </div>
                          );
                        })()}
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
              <div className="border-t border-gray-200" style={{ padding: fluidSizing.space.xs }}>
                <button 
                  type="button"
                  onClick={navigateToSearchPage}
                  className="block w-full text-center text-primario hover:text-primario-dark font-medium cursor-pointer search-result-item !border-transparent hover:!border-transparent !outline-none !shadow-none !rounded-none"
                  style={{ fontSize: fluidSizing.text.sm, padding: fluidSizing.space.xs }}
                >
                  {t('viewAll')}
                </button>
              </div>
            </div>
          ) : (
            <div className="text-center text-gray-600" style={{ padding: fluidSizing.space.md }}>
              <p style={{ fontSize: fluidSizing.text.sm }}>{t('noResults', { term: searchTerm })}</p>
            </div>
          )}
        </div>,
        document.body
      )}
    </div>
  );
};

export default SearchBar;
