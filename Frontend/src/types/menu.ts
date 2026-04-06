/**
 * Tipos para el menú de WordPress
 */

export interface SubCategory {
  id: number;
  name: string;
  slug: string;
  parentId?: number;
  count?: number;
  description?: string;
  image?: string;
  min_membership_level?: number;
  children?: SubCategory[]; // Permite múltiples niveles
}

export interface MenuCategory {
  id: number;
  name: string;
  slug: string;
  subcategories?: SubCategory[];
  count?: number;
  description?: string;
  image?: string;
  min_membership_level?: number;
}
