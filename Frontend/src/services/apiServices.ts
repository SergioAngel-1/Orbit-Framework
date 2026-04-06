import { api, wooCommerceApi } from './apiConfig';
import logger from '../utils/logger';

// Importar servicios modularizados
import authApiService from './auth';
import productApiService from './products';
import categoryApiService from './categories';
import { userCartApiService, hybridCartService } from './cart';
import orderApiService from './orders';
import pointsApiService from './points';

import bannerApiService from './banners';
import homeSectionApiService from './home';
import legalApiService from './legal';
import { systemApiService } from './system';
import { reviewApiService } from './reviews';

// Imprimir información de configuración
logger.info('api', `API configurada con URL base: ${api.defaults.baseURL}`);

// Exportar los servicios y la API
export {
  api,
  wooCommerceApi,
  authApiService as authService,
  productApiService as productService,
  categoryApiService as categoryService,
  userCartApiService as userCartService,
  hybridCartService,
  orderApiService as orderService,
  pointsApiService as pointsService,

  bannerApiService as bannerService,
  homeSectionApiService as homeSectionService,
  legalApiService as legalService,
  systemApiService as systemService,
  reviewApiService as reviewService
};
