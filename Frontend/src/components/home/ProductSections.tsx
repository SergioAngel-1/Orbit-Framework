import React from 'react';
import ProductSectionItem from './ProductSectionItem';
import { useHomeSectionsContext } from '../../contexts/HomeSectionsContext';
import Loader from '../ui/Loader';

/**
 * Componente para las secciones superiores
 */
const TopProductSections: React.FC = () => {
  const { topSections, loading, error } = useHomeSectionsContext();

  if (loading) {
    return (
      <div className="py-8 text-center">
        <Loader size="small" text="" />
      </div>
    );
  }

  if (error || topSections.length === 0) {
    return null;
  }

  return (
    <div className="bg-white py-4">
      {topSections.map((section) => (
        <div key={section.id} className="mb-4">
          <ProductSectionItem 
            sectionId={section.id} 
          />
        </div>
      ))}
    </div>
  );
};

/**
 * Componente para las secciones medias
 */
const MiddleProductSections: React.FC = () => {
  const { middleSections, loading, error } = useHomeSectionsContext();

  if (loading) {
    return (
      <div className="py-8 text-center">
        <Loader size="small" text="" />
      </div>
    );
  }

  if (error || middleSections.length === 0) {
    return null;
  }

  return (
    <div className="bg-white py-4">
      {middleSections.map((section) => (
        <div key={section.id} className="mb-4">
          <ProductSectionItem 
            sectionId={section.id}
          />
        </div>
      ))}
    </div>
  );
};

/**
 * Componente para las secciones inferiores
 */
const BottomProductSections: React.FC = () => {
  const { bottomSections, loading, error } = useHomeSectionsContext();

  if (loading) {
    return (
      <div className="py-8 text-center">
        <Loader size="small" text="" />
      </div>
    );
  }

  if (error || bottomSections.length === 0) {
    return null;
  }

  return (
    <div className="bg-white py-4 w-full">
      <div className="container-fluid px-2 w-full">
        {bottomSections.map((section) => (
          <div key={section.id} className="mb-4">
            <ProductSectionItem 
              sectionId={section.id} 
              className="" 
            />
          </div>
        ))}
      </div>
    </div>
  );
};

// Exportamos un objeto con los tres componentes para mantener la compatibilidad
// con el código existente en HomePage.tsx
const ProductSections = {
  Top: TopProductSections,
  Middle: MiddleProductSections,
  Bottom: BottomProductSections
};

export default ProductSections;