import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Product } from '../../types/woocommerce';
import { productService } from '../../services/api';
import logger from '../../utils/logger';
import { getVariablePriceRange } from '../../utils/formatters';
import { 
  AttributeSelector, 
  VariationQuantity,
  CartAddSection
} from './variations';

interface VariationSelectorProps {
  product: Product;
  onVariationSelect: (variationId: number, variationData: any) => void;
  quantity?: number;
  onQuantityChange?: (quantity: number) => void;
  onAddToCart?: () => void;
  addingToCart?: boolean;
  footerDescription?: string;
  preselectedVariationId?: number | null; // ID de variación preseleccionada desde la URL
  onLoadingChange?: (isLoading: boolean) => void; // Callback para notificar el estado de carga
}

interface AttributeSelection {
  [key: string]: string;
}

const VariationSelector: React.FC<VariationSelectorProps> = ({
  product,
  onVariationSelect,
  quantity = 1,
  onQuantityChange = () => { },
  onAddToCart = () => { },
  addingToCart = false,
  /* footerDescription = '', */
  preselectedVariationId = null,
  onLoadingChange = () => { }
}) => {
  const { t } = useTranslation('productDetailPage');
  const [selectedAttributes, setSelectedAttributes] = useState<AttributeSelection>({});
  const [variationsMap, setVariationsMap] = useState<Map<number, any>>(new Map());
  const [loading, setLoading] = useState(false);
  const [currentVariation, setCurrentVariation] = useState<number | null>(null);
  const [openDropdown, setOpenDropdown] = useState<string | null>(null);

  // Reiniciar el estado cuando cambia el producto
  useEffect(() => {
    // Limpiar completamente el estado
    setSelectedAttributes({});
    setVariationsMap(new Map());
    setCurrentVariation(null);
    setOpenDropdown(null);
    // Establecer estado de carga cuando se cambie el producto
    setLoading(true);
    onLoadingChange(true);

    // Registrar el cambio de producto para depuración
    logger.info('VariationSelector', `Beneficio cambiado: ${product.id} - ${product.name}`);
  }, [product.id, onLoadingChange]); // Dependencia en product.id para asegurar que se ejecute solo cuando cambia el producto

  // Cargar las variaciones del producto
  useEffect(() => {
    const loadVariations = async () => {
      if (product.type !== 'variable' || !product.variations || product.variations.length === 0) {
        // Asegurarse de que el loader se oculte incluso si no hay variaciones
        setLoading(false);
        onLoadingChange(false);
        return;
      }
      
      // Si hay una variación preseleccionada, registrarla para depuración
      if (preselectedVariationId) {
        logger.info('VariationSelector', `Variación preseleccionada: ${preselectedVariationId}`);
      }

      setLoading(true);
      try {
        // Crear un nuevo mapa para las variaciones
        const variationsMap = new Map();

        // Optimización: Cargar todas las variaciones en un solo request batch
        logger.info('VariationSelector', `Iniciando carga batch de ${product.variations.length} variaciones`);
        
        try {
          // Usar endpoint de variaciones del producto con filtro include
          const response = await productService.getVariations(product.id, {
            include: product.variations.join(','),
            per_page: 100
          });
          
          // Mapear las variaciones por ID
          if (response.data && Array.isArray(response.data)) {
            response.data.forEach((variation: any) => {
              variationsMap.set(variation.id, variation);
            });
            logger.info('VariationSelector', `Variaciones cargadas en batch: ${variationsMap.size}/${product.variations.length}`);
          }
        } catch (batchError) {
          // Fallback: Si el batch falla, cargar individualmente
          logger.warn('VariationSelector', 'Batch loading falló, usando fallback individual', batchError);
          
          const variationPromises = product.variations.map(variationId => {
            return productService.getById(variationId)
              .then(response => {
                if (response.data) {
                  variationsMap.set(variationId, response.data);
                }
                return { id: variationId, success: true };
              })
              .catch(error => {
                logger.error('VariationSelector', `Error al cargar variación ${variationId}:`, error);
                return { id: variationId, success: false, error };
              });
          });

          const results = await Promise.all(variationPromises);
          const successCount = results.filter(r => r.success).length;
          logger.info('VariationSelector', `Variaciones cargadas (fallback): ${successCount}/${results.length}`);
        }

        // Actualizar el estado con todas las variaciones cargadas
        setVariationsMap(variationsMap);
        logger.info('VariationSelector', `Total variaciones disponibles: ${variationsMap.size}`);

        // Si hay una variación preseleccionada, intentar seleccionarla
        if (preselectedVariationId && variationsMap.has(preselectedVariationId)) {
          // Obtener la variación preseleccionada
          const preselectedVariation = variationsMap.get(preselectedVariationId);
          
          // Extraer los atributos de la variación preseleccionada
          if (preselectedVariation && preselectedVariation.attributes) {
            const preselectedAttributes: AttributeSelection = {};
            
            // Mapear los atributos de la variación a nuestro formato de selección
            preselectedVariation.attributes.forEach((attr: any) => {
              // Normalizar el nombre del atributo (quitar 'pa_' si existe)
              const attrName = attr.name.startsWith('pa_') ? attr.name.substring(3) : attr.name;
              preselectedAttributes[attrName] = attr.option;
            });
            
            // Establecer los atributos preseleccionados
            setSelectedAttributes(preselectedAttributes);
            
            // Establecer la variación actual
            setCurrentVariation(preselectedVariationId);
            
            // Notificar al componente padre
            onVariationSelect(preselectedVariationId, preselectedVariation);
            
            logger.info('VariationSelector', `Variación preseleccionada aplicada: ${preselectedVariationId}`, preselectedAttributes);
          }
        }
      } catch (error) {
        logger.error('VariationSelector', 'Error al cargar variaciones:', error);
      } finally {
        setLoading(false);
        // Notificar al componente padre que las variaciones han terminado de cargar
        onLoadingChange(false);
      }
    };

    loadVariations();
  }, [product.variations, product.type, preselectedVariationId, onVariationSelect, onLoadingChange]);

  // Efecto para actualizar la variación seleccionada cuando cambian los atributos
  useEffect(() => {
    // Si no hay atributos seleccionados o no hay variaciones cargadas, no hacer nada
    if (Object.keys(selectedAttributes).length === 0 || variationsMap.size === 0) {
      setCurrentVariation(null);
      return;
    }

    // Buscar la variación que coincide con los atributos seleccionados
    const findMatchingVariation = () => {
      // Si no hay atributos seleccionados o no hay variaciones, no hacer nada
      if (Object.keys(selectedAttributes).length === 0 || variationsMap.size === 0) {
        // Si hay una variación actual, limpiarla
        if (currentVariation !== null) {
          setCurrentVariation(null);
          onVariationSelect(-1, null); // Usar -1 en lugar de null para mantener el tipo number
        }
        return;
      }

      // Verificar si tenemos todos los atributos necesarios seleccionados
      const variationAttributes = product.attributes.filter(attr => attr.variation);
      const allAttributesSelected = variationAttributes.every(attr => 
        selectedAttributes[attr.name] !== undefined
      );

      // Si no tenemos todos los atributos seleccionados, no podemos determinar la variación
      if (!allAttributesSelected) {
        if (currentVariation !== null) {
          setCurrentVariation(null);
          onVariationSelect(-1, null);
        }
        return;
      }

      // Buscar la variación que coincida con los atributos seleccionados
      for (const [variationId, variation] of variationsMap.entries()) {
        // Verificar si esta variación coincide con todos los atributos seleccionados
        let matchesAllAttributes = true;

        // Comprobar cada atributo seleccionado
        for (const [attrName, attrValue] of Object.entries(selectedAttributes)) {
          // Buscar este atributo en la variación
          const variationAttr = variation.attributes.find((attr: any) => 
            attr.name === attrName || attr.name === `pa_${attrName.toLowerCase()}`
          );

          // Si no encontramos el atributo o no coincide el valor, esta variación no es una coincidencia
          if (!variationAttr || variationAttr.option !== attrValue) {
            matchesAllAttributes = false;
            break;
          }
        }

        // Si encontramos una coincidencia completa, actualizar solo si es diferente
        if (matchesAllAttributes) {
          if (currentVariation !== variationId) {
            setCurrentVariation(variationId);
            onVariationSelect(variationId, variation);
            logger.info('VariationSelector', `Variación seleccionada: ${variationId}`);
          }
          return;
        }
      }

      // Si llegamos aquí, no hay coincidencia
      if (currentVariation !== null) {
        setCurrentVariation(null);
        onVariationSelect(-1, null);
      }
    };

    findMatchingVariation();
  }, [selectedAttributes, variationsMap, product.attributes, onVariationSelect, currentVariation]);

  // Manejar el cambio de atributo
  const handleAttributeChange = (attributeName: string, value: string | null) => {
    // Si no hay producto o no es variable, no hacer nada
    if (!product || product.type !== 'variable') {
      return;
    }
    
    // Actualizar el estado de forma inmutable
    setSelectedAttributes(prev => {
      const newAttributes = { ...prev };
      
      // Si el valor es null, eliminar la selección
      if (value === null) {
        delete newAttributes[attributeName];
        logger.info('VariationSelector', `Atributo eliminado: ${attributeName}`);
      } else {
        // Caso normal: actualizar el valor
        newAttributes[attributeName] = value;
        logger.info('VariationSelector', `Atributo cambiado: ${attributeName} = ${value}`);
      }
      
      return newAttributes;
    });
  };

  // Si no es un producto variable, no mostrar nada
  if (product.type !== 'variable') {
    return null;
  }

  // Log para depuración de atributos
  logger.info('VariationSelector', `Atributos del producto ${product.id}:`, {
    attributes: product.attributes,
    attributesLength: product.attributes?.length || 0,
    variations: product.variations?.length || 0
  });

  // Función para obtener las opciones disponibles para un atributo
  const getAvailableOptionsForAttribute = (attributeName: string, defaultOptions: string[]): string[] => {
    // Si no hay variaciones cargadas, devolver las opciones predeterminadas
    if (variationsMap.size === 0) {
      return defaultOptions;
    }

    // Obtener todas las opciones disponibles para este atributo en las variaciones cargadas
    const availableOptions = new Set<string>();

    // Filtrar las variaciones que coinciden con los atributos ya seleccionados
    const matchingVariations = Array.from(variationsMap.values()).filter(variation => {
      // Verificar si esta variación coincide con los atributos ya seleccionados
      let matchesSelectedAttributes = true;
      for (const [selectedAttrName, selectedAttrValue] of Object.entries(selectedAttributes)) {
        // Saltarse el atributo actual que estamos evaluando
        if (selectedAttrName === attributeName) continue;

        // Verificar si la variación coincide con los otros atributos seleccionados
        const variationAttr = variation.attributes.find((attr: any) =>
          attr.name === selectedAttrName || attr.name === `pa_${selectedAttrName.toLowerCase()}`
        );

        if (!variationAttr || variationAttr.option !== selectedAttrValue) {
          matchesSelectedAttributes = false;
          break;
        }
      }
      return matchesSelectedAttributes;
    });

    // Obtener todas las opciones disponibles para este atributo en las variaciones filtradas
    matchingVariations.forEach(variation => {
      const attr = variation.attributes.find((attr: any) =>
        attr.name === attributeName || attr.name === `pa_${attributeName.toLowerCase()}`
      );
      if (attr && attr.option) {
        availableOptions.add(attr.option);
      }
    });

    // Si no hay opciones disponibles, devolver las opciones predeterminadas
    if (availableOptions.size === 0) {
      return defaultOptions;
    }

    return Array.from(availableOptions);
  };

  // Función optimizada para calcular el subtotal
  const calculateSubtotal = (): number => {
    try {
      // Obtener el precio base (producto o variación)
      let basePrice = '0';

      if (currentVariation && variationsMap.get(currentVariation)) {
        // Si hay una variación seleccionada, usar su precio
        const variation = variationsMap.get(currentVariation);

        // Registrar para depuración
        logger.info('VariationSelector', `Calculando subtotal para variación ${currentVariation}: precio=${variation.price}, regular=${variation.regular_price}`);
        
        // Si tiene precio regular y es mayor que el precio de venta
        if (variation.regular_price && parseFloat(variation.regular_price) > parseFloat(variation.price || '0')) {
          basePrice = variation.price || '0'; // Usamos el precio con descuento, no el regular
          logger.info('VariationSelector', `Usando precio con descuento: ${basePrice} (regular: ${variation.regular_price})`);
        }
        // En cualquier otro caso, usar el precio normal
        else {
          basePrice = variation.price || '0';
          logger.info('VariationSelector', `Usando precio normal: ${basePrice}`);
        }
      }
      // Si no hay variación seleccionada, usar el precio del producto
      else {
        // Verificar si el producto tiene un descuento
        if (product.regular_price && product.price && parseFloat(product.regular_price) > parseFloat(product.price)) {
          basePrice = product.price || '0';
          logger.info('VariationSelector', `Usando precio con descuento del beneficio: ${basePrice} (regular: ${product.regular_price})`);
        }
        // Si no hay descuento, usar el precio normal
        else if (product.regular_price && parseFloat(product.regular_price) > parseFloat(product.price || '0')) {
          basePrice = product.price || '0'; // Usamos el precio con descuento, no el regular
          logger.info('VariationSelector', `Usando precio con descuento: ${basePrice} (regular: ${product.regular_price})`);
        }
        // En cualquier otro caso, usar el precio normal
        else {
          basePrice = product.price || '0';
          logger.info('VariationSelector', `Usando precio normal: ${basePrice}`);
        }
      }

      // Convertir a número y multiplicar por la cantidad (limitando a 2 decimales)
      const price = parseFloat(basePrice);
      if (isNaN(price)) return 0;

      // Limitar la cantidad a un valor razonable para evitar problemas de rendimiento
      const safeQuantity = Math.min(quantity, 9999);

      // Convertir a número para asegurarnos de que siempre devolvemos un número
      return parseFloat((price * safeQuantity).toFixed(2));
    } catch (error) {
      logger.error('VariationSelector:','Error calculando subtotal', error);
      return 0;
    }
  };

  // Filtrar solo los atributos que se usan para variaciones
  // Si no hay atributos definidos en el producto, intentar extraerlos de las variaciones cargadas
  let variationAttributes = product.attributes?.filter(attr => attr.variation) || [];
  
  // Si no hay atributos de variación pero sí hay variaciones cargadas, extraer los atributos de ellas
  if (variationAttributes.length === 0 && variationsMap.size > 0) {
    const extractedAttributes = new Map<string, Set<string>>();
    
    // Recorrer todas las variaciones para extraer sus atributos
    for (const variation of variationsMap.values()) {
      if (variation.attributes && Array.isArray(variation.attributes)) {
        for (const attr of variation.attributes) {
          const attrName = attr.name.startsWith('pa_') ? attr.name.substring(3) : attr.name;
          if (!extractedAttributes.has(attrName)) {
            extractedAttributes.set(attrName, new Set());
          }
          if (attr.option) {
            extractedAttributes.get(attrName)!.add(attr.option);
          }
        }
      }
    }
    
    // Convertir a formato de atributos del producto
    variationAttributes = Array.from(extractedAttributes.entries()).map(([name, options], index) => ({
      id: index,
      name,
      options: Array.from(options),
      variation: true,
      position: index,
      visible: true
    }));
    
    logger.info('VariationSelector', `Atributos extraídos de variaciones: ${variationAttributes.length}`, variationAttributes);
  }
  
  // Log para depuración
  logger.info('VariationSelector', `Atributos de variación finales: ${variationAttributes.length}`, variationAttributes);
  
  // Determinar si debe mostrarse el selector de cantidad: solo si hay variación seleccionada con stock
  const selectedVar = currentVariation ? variationsMap.get(currentVariation) : null;
  const showQuantity = !!selectedVar && selectedVar.stock_status === 'instock' && (typeof selectedVar.stock_quantity !== 'number' || selectedVar.stock_quantity > 0);
  
  // Verificar si TODAS las variaciones están agotadas
  const allVariationsOutOfStock = variationsMap.size > 0 && Array.from(variationsMap.values()).every(
    variation => variation.stock_status === 'outofstock' || 
    (variation.stock_status !== 'instock' && variation.stock_quantity !== undefined && variation.stock_quantity <= 0)
  );
  
  // También verificar el stock_status del producto padre
  const productOutOfStock = product.stock_status === 'outofstock' || allVariationsOutOfStock;

  return (
    <div className="mb-4 md:mb-6 p-4 relative z-10">
      {loading ? null : (
        <>
          {/* Selector de variaciones y cantidad en fila */}
          <div className="flex flex-col md:flex-row md:gap-6 md:items-start">
            {/* Columna izquierda: Selector de variaciones */}
            <div className="md:flex-1">
              <div className="flex flex-col md:flex-row md:flex-wrap md:gap-4">
                {variationAttributes.map(attribute => (
                  <AttributeSelector
                    key={attribute.id}
                    attribute={attribute}
                    selectedAttributes={selectedAttributes}
                    openDropdown={openDropdown}
                    setOpenDropdown={setOpenDropdown}
                    handleAttributeChange={handleAttributeChange}
                    getAvailableOptionsForAttribute={getAvailableOptionsForAttribute}
                    variationsMap={variationsMap}
                  />
                ))}
              </div>
              
              {variationAttributes.length > 0 && Object.keys(selectedAttributes).length === variationAttributes.length && !currentVariation && (
                <div className="mt-2 text-sm text-amber-500">
                  {t('variations.noMatch')}
                </div>
              )}
            </div>
            
            {/* Columna derecha: Selector de cantidad (solo si la variación seleccionada tiene stock) */}
            {showQuantity && (
              <div className="mt-4 md:mt-0 md:w-1/4">
                <VariationQuantity
                  productId={currentVariation || product.id}
                  quantity={quantity}
                  onQuantityChange={onQuantityChange}
                  hasVariation={!!currentVariation}
                  maxQuantity={selectedVar?.stock_quantity ?? undefined}
                />
              </div>
            )}
          </div>

          {/* Usar el componente compartido para botón de agregar al carrito y subtotal */}
          <CartAddSection 
            subtotal={calculateSubtotal()}
            regularSubtotal={currentVariation && variationsMap.get(currentVariation)?.regular_price ? 
              parseFloat(variationsMap.get(currentVariation)?.regular_price || '0') * quantity : undefined}
            hasDiscount={currentVariation && 
              variationsMap.get(currentVariation)?.regular_price && 
              parseFloat(variationsMap.get(currentVariation)?.regular_price) > 0 &&
              parseFloat(variationsMap.get(currentVariation)?.price || '0') > 0 &&
              parseFloat(variationsMap.get(currentVariation)?.regular_price) > parseFloat(variationsMap.get(currentVariation)?.price || '0')}
            onAddToCart={onAddToCart}
            loading={addingToCart}
            disabled={!currentVariation && !productOutOfStock}
            disabledText={t('cart.selectOptions')}
            stockQuantity={currentVariation ? variationsMap.get(currentVariation)?.stock_quantity ?? undefined : undefined}
            stockStatus={productOutOfStock ? 'outofstock' : (currentVariation ? variationsMap.get(currentVariation)?.stock_status as 'instock' | 'outofstock' | 'onbackorder' | undefined : undefined)}
            priceRange={!currentVariation ? getVariablePriceRange(product) : null}
          />
        </>
      )}
    </div>
  );
};

export default VariationSelector;