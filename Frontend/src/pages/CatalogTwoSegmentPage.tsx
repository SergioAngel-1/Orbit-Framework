import { FC } from 'react';
import { useParams } from 'react-router-dom';
import useMembershipLevels from '../hooks/useMembershipLevels';
import { isKnownMembershipSlug } from '../utils/membershipRouteUtils';
import ShopPage from './ShopPage';
import ProductDetailPage from './ProductDetailPage';
import Loader from '../components/ui/Loader';

/**
 * Resolver para rutas de 2 segmentos en el catálogo.
 *
 * El problema: `/catalogo/{membership}/{category}` y `/catalogo/{category}/{product}`
 * tienen el mismo score de ruta (2 segmentos dinámicos), así que React Router
 * no puede distinguirlas. Este componente desambigua consultando si el primer
 * segmento es un slug de membresía conocido.
 *
 * - seg1 = slug de membresía → ShopPage (categoría restringida)
 * - seg1 = slug de categoría → ProductDetailPage (producto público)
 *
 * useMembershipLevels usa un módulo-level cache, así que el loader solo
 * aparece en la primera carga antes de que la API responda (~200ms).
 * Navegaciones posteriores son instantáneas.
 */
const CatalogTwoSegmentPage: FC = () => {
  const { seg1, seg2 } = useParams<{ seg1: string; seg2: string }>();
  const { levels, loading } = useMembershipLevels();

  // Mientras los niveles cargan, mostrar loader para evitar renderizar la página incorrecta
  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[60vh]">
        <Loader size="large" />
      </div>
    );
  }

  const isMembership = isKnownMembershipSlug(seg1 ?? '', levels);

  if (isMembership) {
    return <ShopPage _membershipSlug={seg1} _categorySlug={seg2} />;
  }
  return <ProductDetailPage _categorySlug={seg1} _productSlug={seg2} />;
};

export default CatalogTwoSegmentPage;
