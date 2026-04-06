import productApiService, { ProductFilters } from './productApiService';

// Re-exportar los tipos para que puedan ser importados desde './products'
export type { ProductFilters };

// Exportar el servicio como default
export default productApiService;
