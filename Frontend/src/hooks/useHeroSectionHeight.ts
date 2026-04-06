import { useState } from 'react';

/**
 * Hook personalizado para manejar la altura de la sección Hero
 * Mantiene sincronizada la altura entre el carousel de banners y las categorías destacadas
 */
export const useHeroSectionHeight = () => {
  const [height, setHeight] = useState({
    mobile: '280px',
    sm: '350px', 
    md: '500px',
    lg: '550px'
  });

  // Clases de Tailwind CSS para la altura
  const heightClasses = `h-[${height.mobile}] sm:h-[${height.sm}] md:h-[${height.md}] lg:h-[${height.lg}]`;
  
  // También para min-height
  const minHeightClasses = `min-h-[${height.mobile}] sm:min-h-[${height.sm}] md:min-h-[${height.md}] lg:min-h-[${height.lg}]`;

  // Función para actualizar todas las alturas proporcionalmente
  const updateHeight = (newHeights: Partial<typeof height>) => {
    setHeight(prev => ({
      ...prev,
      ...newHeights
    }));
  };

  // Obtener la altura actual basada en el breakpoint
  const getCurrentHeight = () => {
    if (typeof window === 'undefined') return height.lg;
    
    const width = window.innerWidth;
    if (width < 640) return height.mobile;
    if (width < 768) return height.sm;
    if (width < 1024) return height.md;
    return height.lg;
  };

  return {
    height,
    heightClasses,
    minHeightClasses,
    updateHeight,
    getCurrentHeight
  };
};

export default useHeroSectionHeight;
