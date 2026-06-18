// ============================================================================
//  Tipos del catálogo (forma de WooGraphQL ya normalizada para la UI).
// ============================================================================

export interface ProductImage {
  sourceUrl: string;
  altText: string;
}

export interface CatalogProduct {
  id: string;
  databaseId: number;
  name: string;
  slug: string;
  type: string;
  onSale: boolean;
  price: string | null;
  regularPrice: string | null;
  salePrice: string | null;
  stockStatus: string | null;
  shortDescription: string | null;
  image: ProductImage | null;
}

export interface ProductDetail extends CatalogProduct {
  description: string | null;
}

export interface ProductCategory {
  id: string;
  databaseId: number;
  name: string;
  slug: string;
  description: string | null;
}

export interface PageInfo {
  hasNextPage: boolean;
  endCursor: string | null;
}

export interface ProductsPage {
  products: CatalogProduct[];
  pageInfo: PageInfo;
}
