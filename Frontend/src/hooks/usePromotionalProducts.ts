import { useState, useEffect } from 'react';
import { api } from '../services/apiConfig';
import { useMembership } from '../contexts/MembershipContext';
import logger from '../utils/logger';
import i18n from '../config/i18n';

// Interfaces para tipos de datos
export interface PromotionalProduct {
  id: number;
  name: string;
  price: string;
  regular_price: string;
  sale_price: string;
  images: { src: string }[];
  permalink: string;
  slug: string;
  membership_required?: number;
  short_description?: string;
  description?: string;
  type?: string;
  stock_quantity?: number | null;
  stock_status?: string;
  categories?: {
    id: number;
    name: string;
    slug: string;
    min_membership_level?: number;
  }[];
}

// Interfaz para la respuesta del backend
interface ApiPromotionalProduct {
  id: number;
  name: string;
  price?: string;
  regularPrice?: string;
  salePrice?: string;
  onSale?: boolean;
  permalink: string;
  slug?: string;
  image?: string;
  imageLarge?: string;
  images?: { src: string }[];
  short_description?: string;
  description?: string;
  type?: string;
  membership_required?: number;
  categories?: {
    id: number;
    name: string;
    slug: string;
    min_membership_level?: number;
  }[];
}

interface MembershipInfo {
  minLevel: number;
  mode: string;
  levelName?: string;
  levelIcon?: string;
  levelColor?: string;
}

interface ApiResponse {
  success?: boolean;
  gridId?: number;
  title?: string;
  categoryId?: number;
  categoryName?: string;
  products?: ApiPromotionalProduct[];
  isConfiguredGrid?: boolean;
  noConfiguredGrid?: boolean;
  usingSpecific?: boolean;
  isDefaultGrid?: boolean;
  productsCount?: number;
  defaultGridType?: 'wordpress' | 'specific';
  membershipInfo?: MembershipInfo;
  debug?: {
    gridId?: number;
    usingSpecific?: boolean;
    isConfiguredGrid?: boolean;
    originalIsDefaultGrid?: boolean;
    userMembershipLevel?: number;
  };
}

// Interfaz para los metadatos de la grilla
export interface GridMetadata {
  isConfiguredGrid?: boolean;
  noConfiguredGrid?: boolean;
  usingSpecific?: boolean;
  isDefaultGrid?: boolean;
  gridId?: number;
  productsCount?: number;
  categoryId?: number;
  categoryName?: string;
  membershipInfo?: MembershipInfo;
}

interface UsePromotionalProductsResult {
  products: PromotionalProduct[];
  loading: boolean;
  error: string | null;
  gridTitle: string;
  gridMetadata: GridMetadata;
}

/**
 * Hook personalizado mejorado para obtener productos promocionales
 * @param categoryId - ID opcional de la categoría para filtrar productos
 * @returns Objeto con los productos, estado de carga y errores
 */
const usePromotionalProducts = (categoryId?: number): UsePromotionalProductsResult => {
  // Obtener membershipVersion para recargar cuando cambie la membresía
  const { currentLevel, membershipVersion } = useMembership();
  
  const [products, setProducts] = useState<PromotionalProduct[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [gridTitle, setGridTitle] = useState<string>(i18n.t('errors:generic.loadingProducts'));
  const [gridMetadata, setGridMetadata] = useState<GridMetadata>({});

  useEffect(() => {
    const fetchPromotionalProducts = async () => {
      try {
        setLoading(true);
        setError(null);
        setProducts([]); // Limpiar productos anteriores
        
        // Determinar el endpoint basado en la categoría
        let endpoint = '/starter/v1/promotional-grid';
        
        // Si se proporciona un ID de categoría, usar el endpoint específico de categoría
        if (categoryId !== undefined && categoryId !== null && !isNaN(categoryId)) {
          endpoint = `/starter/v1/promotional-grid/category/${categoryId}`;
          logger.info('usePromotionalProducts', `Cargando grilla promocional para categoría ID: ${categoryId}`);
        } else {
          logger.info('usePromotionalProducts', 'Cargando grilla promocional por defecto');
        }
        
        logger.info('usePromotionalProducts', `Llamando a endpoint: ${endpoint}`);
        const response = await api.get<ApiResponse>(endpoint);
        
        // Validar la respuesta
        const apiData: ApiResponse = response.data;
        if (!apiData) {
          throw new Error(i18n.t('errors:products.apiResponseEmpty'));
        }
        
        // Registrar información básica de la respuesta para depuración
        // Log detallado de la respuesta para depuración
        logger.info('usePromotionalProducts', 'Respuesta de la API:', {
          success: apiData.success,
          gridId: apiData.gridId,
          title: apiData.title,
          categoryId: apiData.categoryId,
          categoryName: apiData.categoryName,
          productsCount: apiData.products?.length || 0
        });
        
        // Convertir isDefaultGrid a booleano explícitamente si existe
        if (apiData.isDefaultGrid !== undefined) {
          apiData.isDefaultGrid = Boolean(apiData.isDefaultGrid);
        }
        
        // Información más detallada sobre los productos
        if (apiData.products && apiData.products.length > 0) {
          logger.debug('usePromotionalProducts', 'Productos en respuesta API:', 
            apiData.products.map(p => ({
              id: p.id,
              name: p.name,
              hasRegularPrice: !!p.regularPrice,
              hasSalePrice: !!p.salePrice,
              hasImage: !!(p.image || (p.images && p.images.length > 0)),
              categories: p.categories?.map(c => `${c.name} (${c.id})`) || []
            })));
        }
        
        // Actualizar el título si está disponible
        if (apiData.title) {
          setGridTitle(apiData.title);
        }
        
        // Extraer y procesar los metadatos de la grilla para pasarlos al componente
        const metadata: GridMetadata = {
          isConfiguredGrid: apiData.isConfiguredGrid,
          noConfiguredGrid: apiData.noConfiguredGrid,
          usingSpecific: apiData.usingSpecific,
          isDefaultGrid: apiData.isDefaultGrid,
          gridId: apiData.gridId,
          productsCount: apiData.products?.length || 0,
          categoryId: apiData.categoryId,
          categoryName: apiData.categoryName,
          membershipInfo: apiData.membershipInfo
        };
        
        // Actualizar el estado de los metadatos
        setGridMetadata(metadata);
        
        // Información más detallada del tipo de grilla
        logger.info('usePromotionalProducts', 'Información adicional de la grilla:', {
          ...metadata,
          endpointUsado: endpoint
        });
        
        // Validar y procesar productos
        if (!apiData.products || !Array.isArray(apiData.products)) {
          logger.warn('usePromotionalProducts', 'No se encontraron productos en la respuesta');
          logger.warn('usePromotionalProducts', 'Datos completos de la respuesta:', apiData);
          
          // Aunque no haya productos, podemos tener metadatos importantes
          const metadata: GridMetadata = {
            isConfiguredGrid: apiData.isConfiguredGrid,
            noConfiguredGrid: apiData.noConfiguredGrid || true,
            usingSpecific: apiData.usingSpecific || false,
            isDefaultGrid: apiData.isDefaultGrid,
            gridId: apiData.gridId || 0,
            productsCount: 0,
            categoryId: apiData.categoryId,
            categoryName: apiData.categoryName
          };
          
          setGridMetadata(metadata);
          setProducts([]);
          setLoading(false);
          return;
        } else if (apiData.products.length === 0) {
          // Si no hay productos pero tenemos isConfiguredGrid=true, esto puede indicar
          // que es la grilla por defecto pero no tiene productos configurados
          logger.warn('usePromotionalProducts', `La respuesta contiene un array vacío de productos. isConfiguredGrid=${apiData.isConfiguredGrid}, noConfiguredGrid=${apiData.noConfiguredGrid}`);
          
          // Actualizar los metadatos aunque no haya productos
          const metadata: GridMetadata = {
            isConfiguredGrid: apiData.isConfiguredGrid || false,
            noConfiguredGrid: apiData.noConfiguredGrid || true,
            usingSpecific: apiData.usingSpecific || false,
            isDefaultGrid: apiData.isDefaultGrid || false,
            gridId: apiData.gridId || 0,
            productsCount: 0,
            categoryId: apiData.categoryId,
            categoryName: apiData.categoryName
          };
          
          setGridMetadata(metadata);
          setProducts([]);
          setLoading(false);
          return;
        } else {
          logger.info('usePromotionalProducts', `Procesando ${apiData.products.length} beneficios de la grilla con ID: ${apiData.gridId}`);
          logger.debug('usePromotionalProducts', 'Revisando primer beneficio recibido:', {
            id: apiData.products[0]?.id,
            name: apiData.products[0]?.name,
            hasPrice: !!apiData.products[0]?.price,
            hasRegularPrice: !!apiData.products[0]?.regularPrice
          });
        }
        
        // Transformar productos al formato esperado por el frontend
        const processedProducts = apiData.products.map((product: ApiPromotionalProduct): PromotionalProduct => {
          // Normalizar las imágenes
          let normalizedImages: { src: string }[] = [];
          if (product.images && Array.isArray(product.images)) {
            normalizedImages = product.images;
          } else if (product.image) {
            normalizedImages = [{ src: product.image }, { src: product.imageLarge || product.image }];
          }
          
          // Generar slug si no existe
          let slug = product.slug || '';
          if (!slug && product.permalink) {
            const permalinkParts = product.permalink.split('/');
            slug = permalinkParts[permalinkParts.length - 2] || 
                   permalinkParts[permalinkParts.length - 1] || 
                   product.name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
          } else if (!slug) {
            slug = product.name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
          }
          
          // Normalización completa del producto
          return {
            id: product.id,
            name: product.name,
            price: product.price || product.regularPrice || '',
            regular_price: product.regularPrice || '',
            sale_price: product.salePrice || '',
            images: normalizedImages,
            permalink: product.permalink,
            slug: slug,
            membership_required: product.membership_required,
            short_description: product.short_description || '',
            description: product.description || '',
            type: product.type || 'simple',
            categories: product.categories?.map(category => ({
              id: category.id,
              name: category.name,
              slug: category.slug,
              min_membership_level: category.min_membership_level
            })) || []
          };
        });
        
        // Información de depuración sobre productos procesados
        if (processedProducts.length > 0) {
          logger.info('usePromotionalProducts', `Procesados ${processedProducts.length} productos:`, 
            processedProducts.map(p => ({ id: p.id, name: p.name })));
        } else {
          logger.warn('usePromotionalProducts', 'No se pudieron procesar productos');
        }
        
        setProducts(processedProducts);
        setLoading(false);
        
      } catch (err: any) {
        logger.error('usePromotionalProducts', 'Error al cargar productos promocionales:', err);
        setError(err.message || i18n.t('errors:generic.loadPromotionalError'));
        setProducts([]);
        setLoading(false);
      }
    };

    fetchPromotionalProducts();
  }, [categoryId, currentLevel, membershipVersion]); // Recargar cuando cambie la membresía

  return { products, loading, error, gridTitle, gridMetadata };
};

export default usePromotionalProducts;