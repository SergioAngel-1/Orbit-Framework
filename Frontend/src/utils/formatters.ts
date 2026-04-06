import logger from './logger';
import i18n from '../config/i18n';

/**
 * Returns the appropriate locale string based on the current i18n language
 */
const getLocale = (): string => {
  const lang = i18n.language || 'es';
  return lang === 'en' ? 'en-US' : 'es-CO';
};

/**
 * Obtiene la configuración de moneda desde el caché de SiteConfig.
 * Se lee de localStorage para que funcione fuera de componentes React.
 */
function getCurrencyConfig() {
  try {
    const cached = localStorage.getItem('site_config_cache');
    if (cached) {
      const parsed = JSON.parse(cached);
      if (parsed?.data?.currency) return parsed.data.currency;
    }
  } catch { /* fallback below */ }
  return { currency_code: 'USD', currency_symbol: '$', currency_decimals: 2, currency_locale: 'en-US', currency_rounding_multiple: 1 };
}

/**
 * Redondea hacia arriba al múltiplo de redondeo configurado.
 * Ej con múltiplo 50: 7275 → 7300, 7250 → 7250, 7301 → 7350
 * Con múltiplo 1 (USD): devuelve Math.round(amount)
 */
export const roundCurrency = (amount: number): number => {
  const multiple = getCurrencyConfig().currency_rounding_multiple;
  if (!multiple || multiple <= 1) return Math.round(amount);
  return Math.ceil(amount / multiple) * multiple;
};

/**
 * @deprecated Usa roundCurrency() en su lugar. Mantiene compatibilidad.
 */
export const ceilTo50COP = roundCurrency;

/**
 * Extrae el rango de precios (min/max) de un producto variable usando price_html.
 * WooCommerce genera price_html con el formato: "<span>min</span> – <span>max</span>"
 * Retorna null si no es un producto variable o no tiene rango.
 */
export const getVariablePriceRange = (product: {
  type?: string;
  price?: string;
  price_html?: string;
}): { min: number; max: number | null } | null => {
  if (product.type !== 'variable') return null;

  let prices: number[] = [];

  if (product.price_html) {
    // Extraer todos los números del price_html (eliminar HTML tags)
    const stripped = product.price_html.replace(/<[^>]*>/g, '');
    // Formato colombiano: "$ 40.000" o "$40.000,50" — dígitos con puntos de miles y coma decimal opcional.
    // La regex requiere al menos un grupo "dígitos.dígitos" para evitar capturar números sueltos
    // como "50" que no son precios completos.
    const matches = stripped.match(/\d{1,3}(?:\.\d{3})+(?:,\d+)?/g);

    if (matches && matches.length > 0) {
      prices = matches
        .map(m => parseFloat(m.replace(/\./g, '').replace(',', '.')))
        .filter(n => !isNaN(n) && n > 0);
    }

    // Si no encontró precios con formato de miles, buscar números planos (ej: "40000")
    if (prices.length === 0) {
      const plainMatches = stripped.match(/\d{4,}/g);
      if (plainMatches && plainMatches.length > 0) {
        prices = plainMatches
          .map(m => parseFloat(m))
          .filter(n => !isNaN(n) && n > 0);
      }
    }
  }

  // Fallback al precio base del producto
  if (prices.length === 0 && product.price) {
    const basePrice = parseFloat(product.price);
    if (!isNaN(basePrice) && basePrice > 0) {
      prices = [basePrice];
    }
  }

  if (prices.length === 0) return null;

  const min = Math.min(...prices);
  const max = Math.max(...prices);

  return { min, max: min === max ? null : max };
};

/**
 * Formatea un número como moneda usando la configuración dinámica del sitio
 * @param amount - Cantidad a formatear
 * @param showPrefix - Indica si se debe mostrar el prefijo de la moneda, por defecto es true
 * @returns Cadena formateada como moneda
 */
export const formatCurrency = (amount: number | string, showPrefix: boolean = true): string => {
  const { currency_code, currency_symbol, currency_decimals, currency_locale } = getCurrencyConfig();
  const locale = currency_locale || getLocale();
  
  if (amount === undefined || amount === null) {
    return showPrefix ? `${currency_code} 0` : '0';
  }
  
  // Si es una cadena, primero la limpiamos de cualquier formato existente
  let numAmount: number;
  
  if (typeof amount === 'string') {
    // Eliminar prefijo de moneda, espacios y otros caracteres no numéricos
    let cleanAmount = amount.replace(new RegExp(`${currency_code}\\s*`, 'i'), '').trim();
    
    // Si tiene puntos como separadores de miles (formato Colombia)
    if (cleanAmount.includes('.') && cleanAmount.indexOf('.') < cleanAmount.lastIndexOf('.')) {
      cleanAmount = cleanAmount.replace(/\./g, '');
    }
    
    // Si usa comas como separadores de miles y hay al menos una
    if (cleanAmount.includes(',') && cleanAmount.indexOf(',') < cleanAmount.length - 3) {
      cleanAmount = cleanAmount.replace(/,/g, '');
    }
    
    numAmount = parseFloat(cleanAmount);
    
    // Si no se pudo convertir, devolver 0
    if (isNaN(numAmount)) {
      logger.warn('formatters', `No se pudo convertir "${amount}" a número`);
      return showPrefix ? `${currency_code} 0` : '0';
    }
  } else {
    numAmount = amount;
  }
  
  // Formatear el número con los decimales configurados
  const formattedNumber = numAmount.toLocaleString(locale, {
    minimumFractionDigits: currency_decimals,
    maximumFractionDigits: currency_decimals
  });
  
  // Devolver con o sin prefijo según el parámetro
  return showPrefix ? `${currency_symbol} ${formattedNumber}` : formattedNumber;
};

/**
 * Formatea una fecha en formato legible
 * @param dateString - Cadena de fecha en formato ISO
 * @returns Fecha formateada
 */
export const formatDate = (dateString: string): string => {
  const date = new Date(dateString);
  return new Intl.DateTimeFormat(getLocale(), {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  }).format(date);
};

/**
 * Calcula el porcentaje de descuento entre dos precios
 * @param regularPrice - Precio regular
 * @param salePrice - Precio de oferta
 * @returns Porcentaje de descuento redondeado
 */
export const calculateDiscountPercentage = (regularPrice: number, salePrice: number): number => {
  if (regularPrice <= 0 || salePrice <= 0 || salePrice >= regularPrice) {
    return 0;
  }
  
  return Math.round(((regularPrice - salePrice) / regularPrice) * 100);
};

/**
 * Trunca un texto a una longitud máxima y añade puntos suspensivos
 * @param text - Texto a truncar
 * @param maxLength - Longitud máxima
 * @returns Texto truncado
 */
export const truncateText = (text: string, maxLength: number): string => {
  if (!text || text.length <= maxLength) {
    return text;
  }
  
  return text.substring(0, maxLength) + '...';
};

/**
 * Genera un slug a partir de un nombre para usar en URLs
 * @param name - Nombre a convertir en slug
 * @returns Slug para URL
 */
export const generateSlug = (name: string): string => {
  if (!name) return '';
  
  logger.debug('formatters', `Generando slug para: ${name}`);
  
  const slug = name
    .toLowerCase()
    .normalize('NFD') // Normaliza caracteres acentuados
    .replace(/[\u0300-\u036f]/g, '') // Elimina diacríticos
    .replace(/[^\w\s-]/g, '') // Elimina caracteres especiales
    .replace(/\s+/g, '-') // Reemplaza espacios con guiones
    .replace(/-+/g, '-') // Elimina guiones múltiples
    .trim(); // Elimina espacios al inicio y final
    
  logger.debug('formatters', `Slug generado: ${slug}`);
  return slug;
};

/**
 * Valida y formatea una URL de imagen para asegurar que sea utilizable
 * @param url La URL de la imagen a validar
 * @returns Una URL válida o null si no es posible formatearla
 */
export const getValidImageUrl = (url: string | null | undefined): string | null => {
  if (!url) return null;
  
  try {
    // Limpiamos cualquier carácter escapado en la URL
    let cleanUrl = url.replace(/\\\//g, '/');
    
    // Eliminar comillas al principio y al final si existen
    cleanUrl = cleanUrl.replace(/^["']|["']$/g, '');
    
    // Eliminar espacios en blanco
    cleanUrl = cleanUrl.trim();
    
    // Registrar para debugging
    logger.debug('formatters', `getValidImageUrl: URL original: ${url}, URL limpia: ${cleanUrl}`);
    
    // Si es 'false' (como string), retornar null
    if (cleanUrl === 'false') {
      return null;
    }
    
    // Si la URL ya está bien formada con http o https, devolverla tal cual
    if (cleanUrl.match(/^https?:\/\//i)) {
      return cleanUrl;
    }
    
    // Manejar URLs con dominio local pero sin protocolo (flores.local)
    if (cleanUrl.match(/^[\w.-]+\.local\//i)) {
      return `http://${cleanUrl}`;
    }
    
    // Si es una URL relativa, convertirla a absoluta usando el origen actual
    if (cleanUrl.startsWith('/')) {
      const baseUrl = window.location.origin;
      return `${baseUrl}${cleanUrl}`;
    }
    
    // Si parece ser una ruta sin protocolo, añadir http://
    if (cleanUrl.includes('.') && !cleanUrl.startsWith('http')) {
      return `http://${cleanUrl}`;
    }
    
    // En caso de duda, devolver la URL limpia
    return cleanUrl;
  } catch (error) {
    // Corregir el error de lint pasando el mensaje correcto
    logger.error('formatters', `Error al formatear URL de imagen: ${String(error)}. URL: ${url}`);
    return null;
  }
};

/**
 * Procesa una URL de imagen secundaria que puede venir con comillas o como parte de un array serializado
 * @param imageUrl URL de la imagen que puede contener comillas o ser parte de un array
 * @returns URL limpia y válida o imagen por defecto
 */
export const processSecondaryImage = (imageUrl: string | undefined | null | boolean): string => {
  // Si la imagen es false, undefined o null, devolver imagen por defecto
  if (imageUrl === false || imageUrl === undefined || imageUrl === null) {
    logger.debug('formatters', 'processSecondaryImage: URL es false, undefined o null');
    return '/wp-content/themes/Starter/assets/img/no-image.svg';
  }
  
  // Si la imagen es 'false' (string), devolver imagen por defecto
  if (imageUrl === 'false') {
    logger.debug('formatters', 'processSecondaryImage: URL es string "false"');
    return '/wp-content/themes/Starter/assets/img/no-image.svg';
  }
  
  try {
    // En este punto, imageUrl debe ser un string
    const strImageUrl = String(imageUrl);
    logger.debug('formatters', `processSecondaryImage: Procesando imagen original: ${strImageUrl}`);
    
    // Verificar si es una URL válida directamente
    const validUrl = getValidImageUrl(strImageUrl);
    if (validUrl) {
      logger.debug('formatters', `processSecondaryImage: URL válida directa: ${validUrl}`);
      return validUrl;
    }
    
    // Obtener una URL válida o usar la imagen por defecto
    return '/wp-content/themes/Starter/assets/img/no-image.svg';
  } catch (error) {
    logger.error('formatters', `processSecondaryImage: Error al procesar imagen secundaria:`, error);
    return '/wp-content/themes/Starter/assets/img/no-image.svg';
  }
};

/**
 * Limpia una URL de imagen para guardarla en la base de datos
 * @param imageUrl URL de la imagen a limpiar
 * @returns URL limpia sin comillas ni caracteres especiales
 */
export const cleanImageUrlForStorage = (imageUrl: string | undefined | null | boolean): string => {
  // Si la imagen es false, undefined o null, devolver string vacío
  if (imageUrl === false || imageUrl === undefined || imageUrl === null || imageUrl === 'false') {
    logger.debug('formatters', 'cleanImageUrlForStorage: URL es false, undefined, null o "false"');
    return '';
  }
  
  logger.debug('formatters', `cleanImageUrlForStorage: Limpiando URL original: ${String(imageUrl)}`);
  
  // Eliminar comillas y espacios en blanco
  const cleanUrl = String(imageUrl).replace(/^["'\s]+|["'\s]+$/g, '').trim();
  
  logger.debug('formatters', `cleanImageUrlForStorage: URL limpia: ${cleanUrl}`);
  return cleanUrl;
};
