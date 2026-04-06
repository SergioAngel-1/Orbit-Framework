// Estructura de datos para las categorías del menú principal
export interface SubCategory {
  id: number;
  name: string;
  slug: string;
}

export interface Category {
  id: number;
  name: string;
  slug: string;
  subcategories?: SubCategory[];
}

// Datos de categorías y subcategorías para el menú principal
// NOTA: Fallback vacío intencionalmente - cuando no hay backend disponible,
// el menú solo muestra "Catálogo" y "Contacto" sin categorías de productos
const menuCategories: Category[] = [];

export default menuCategories;
