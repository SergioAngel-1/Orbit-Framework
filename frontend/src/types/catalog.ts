// ============================================================================
//  Tipos del catálogo (forma de WooGraphQL ya normalizada para la UI).
// ============================================================================

export interface ProductImage {
  sourceUrl: string;
  altText: string;
}

export interface CatalogProduct {
  id:               string;
  databaseId:       number;
  name:             string;
  slug:             string;
  type:             string;
  onSale:           boolean;
  price:            string | null;
  regularPrice:     string | null;
  salePrice:        string | null;
  stockStatus:      string | null;
  shortDescription: string | null;
  image:            ProductImage | null;
}

export interface ProductAttributeOption {
  id:        string;
  name:      string;
  label:     string;
  variation: boolean;
  options:   string[];
}

export interface ProductVariationData {
  databaseId:   number;
  price:        string | null;
  regularPrice: string | null;
  salePrice:    string | null;
  stockStatus:  string | null;
  attributes:   { name: string; value: string }[];
}

export interface ProductReview {
  id:         string;
  content:    string;
  date:       string;
  rating:     number;
  authorName: string;
}

export interface ProductDetail extends CatalogProduct {
  description: string | null;
  attributes:  ProductAttributeOption[];
  variations:  ProductVariationData[];
  reviews:     { averageRating: number; items: ProductReview[] };
  related:     CatalogProduct[];
}

export interface ProductCategory {
  id:          string;
  databaseId:  number;
  name:        string;
  slug:        string;
  description: string | null;
}

export interface PageInfo {
  hasNextPage: boolean;
  endCursor:   string | null;
}

export interface ProductsPage {
  products: CatalogProduct[];
  pageInfo: PageInfo;
}
