import { useState, useEffect, useCallback, useRef } from 'react';
import { useLocation } from 'react-router-dom';
import { productService } from '../services/api';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import { useLanguage } from '../contexts/LanguageContext';
import { useMembership } from '../contexts/MembershipContext';

interface ProductDetailHookParams {
  productSlug?: string;
  categorySlug?: string;
}

/**
 * Hook personalizado para manejar toda la lógica de la página de detalle de producto
 * Incluye carga del producto, variaciones, categorías
 */
const useProductDetail = ({ productSlug, categorySlug }: ProductDetailHookParams) => {
  const location = useLocation();
  const { currentLang } = useLanguage();
  const { currentLevel: membershipLevel, membershipVersion } = useMembership();
  
  // Estados principales
  const [product, setProduct] = useState<any>(null);
  const [displayProduct, setDisplayProduct] = useState<any>(null);
  const [categories, setCategories] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  
  // Ref para detectar cambios de membresía y resetear estado síncronamente durante render.
  // Sin esto, un error stale de un fetch anterior (ej: 404 sin membresía) causa que
  // ProductDetailPage navegue a /404 antes de que el useEffect de re-fetch se ejecute.
  const prevMembershipRef = useRef({ level: membershipLevel, version: membershipVersion });
  if (
    prevMembershipRef.current.level !== membershipLevel ||
    prevMembershipRef.current.version !== membershipVersion
  ) {
    prevMembershipRef.current = { level: membershipLevel, version: membershipVersion };
    // setState durante render es seguro en React 18 (pattern "derive state from props")
    // React descartará el render en curso y reiniciará con el nuevo estado
    if (error !== null || !loading) {
      setLoading(true);
      setError(null);
      setProduct(null);
    }
  }
  
  // Estado para cantidad y variaciones
  const [quantity, setQuantity] = useState(1);
  const [selectedVariation, setSelectedVariation] = useState<any>(null);
  const [variationData, setVariationData] = useState<any>(null);
  
  const [preselectedVariationId, setPreselectedVariationId] = useState<number | null>(null);

  // Extraer el ID de variación de la URL si existe
  useEffect(() => {
    // Registrar la URL completa para depuración
    logger.info('useProductDetail', `URL actual: ${location.pathname}${location.search}`);

    const searchParams = new URLSearchParams(location.search);
    const variationParam = searchParams.get('variation');
    
    // Registrar todos los parámetros de la URL para depuración
    logger.info('useProductDetail', 'Parámetros de la URL:', {
      params: Object.fromEntries(searchParams.entries()),
      variationParam,
      navigationState: location.state
    });

    if (variationParam) {
      const variationId = parseInt(variationParam, 10);
      if (!isNaN(variationId)) {
        setPreselectedVariationId(variationId);
        logger.info('useProductDetail', `Variación preseleccionada desde URL: ${variationId}`);

        // Si tenemos un ID de variación válido, hacer scroll a la sección de variaciones
        setTimeout(() => {
          const variationSection = document.getElementById('product-variations');
          if (variationSection) {
            variationSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }, 500); // Esperar a que se cargue el contenido
      } else {
        logger.warn('useProductDetail', `El parámetro de variación no es un número válido: ${variationParam}`);
        setPreselectedVariationId(null);
      }
    } else {
      logger.info('useProductDetail', 'No se encontró parámetro de variación en la URL');
      setPreselectedVariationId(null);
    }
  }, [location.search, location.state]);

  // Reiniciar el estado cuando cambia el slug del producto
  useEffect(() => {
    // Limpiar completamente el estado cuando cambia el productSlug
    setProduct(null);
    setDisplayProduct(null);
    setCategories([]);
    setLoading(true);
    setError(null);
    setQuantity(1);
    setSelectedVariation(null);
    setVariationData(null);

    // Registrar el cambio de producto para depuración
    logger.info('useProductDetail', `Slug cambiado: ${productSlug}`);
  }, [productSlug]);

  // Cargar producto
  useEffect(() => {
    const fetchProduct = async () => {
      if (!productSlug || productSlug === 'undefined' || productSlug === 'null') return;

      try {
        // Utilizamos categorySlug para registro y posible uso futuro en filtrado
        logger.info('useProductDetail', `Cargando beneficio: ${productSlug} de categoría: ${categorySlug || 'desconocida'}`);
        const response = await productService.getBySlug(productSlug);

        // La API devuelve un array cuando se busca por slug
        if (response.data && Array.isArray(response.data) && response.data.length > 0) {
          // Tomamos el primer producto que coincida con el slug
          const productData = response.data[0];
          setProduct(productData);

          if (productData.categories && productData.categories.length > 0) {
            setCategories(productData.categories);
          }

          // Establecer el producto para mostrar
          setDisplayProduct(productData);
        } else if (!isNaN(parseInt(productSlug))) {
          // Si el productSlug es numérico, intentar buscar por ID como fallback
          const idResponse = await productService.getById(parseInt(productSlug));
          const productData = idResponse.data;
          setProduct(productData);

          if (productData.categories && productData.categories.length > 0) {
            setCategories(productData.categories);
          }

          // Establecer el producto para mostrar
          setDisplayProduct(productData);
        } else {
          throw new Error(i18n.t('errors:products.benefitNotFound'));
        }

        setLoading(false);
      } catch (err) {
        logger.error('useProductDetail', 'Error al cargar el beneficio:', err);
        setError(i18n.t('errors:products.loadBenefitError'));
        setLoading(false);
      }
    };

    fetchProduct();

    // Limpiar estados al cambiar de producto
    return () => {
      setSelectedVariation(null);
      setVariationData(null);
    };
  }, [productSlug, categorySlug, currentLang, membershipLevel, membershipVersion]);

  // Cargar variaciones
  useEffect(() => {
    const loadVariations = async () => {
      if (!product || product.type !== 'variable' || !product.variations || product.variations.length === 0) {
        return;
      }

      logger.info('useProductDetail', 'Cargando variaciones...');
    };

    if (!loading && product) {
      loadVariations();
    }
  }, [product, loading]);

  // Función para manejar el cambio de variación
  const handleVariationSelect = useCallback((variationId: number, variationData: any) => {
    setSelectedVariation(variationId);
    setVariationData(variationData);

    // Si hay una variación seleccionada, actualizar el precio mostrado
    if (variationId > 0 && variationData) {
      // Mostrar directamente los datos de la variación seleccionada
      setDisplayProduct(variationData);
    } else {
      // Si no hay variación seleccionada, volver al producto original
      setDisplayProduct(product);
    }
  }, [product]);

  // Función para manejar el cambio de cantidad
  const handleQuantityChange = useCallback((newQuantity: number) => {
    setQuantity(newQuantity);
  }, []);

  return {
    // Estado del producto
    product,
    displayProduct,
    categories,
    loading,
    error,
    
    // Estado de variación y cantidad
    quantity,
    selectedVariation,
    variationData,
    
    preselectedVariationId,
    
    // Métodos
    handleQuantityChange,
    handleVariationSelect,
    setQuantity,
    setSelectedVariation,
  };
};

export default useProductDetail;
