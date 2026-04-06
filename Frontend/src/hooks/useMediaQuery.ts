import { useState, useEffect } from 'react';

/**
 * Hook personalizado para detectar si una media query coincide con el viewport actual
 * @param query La media query a evaluar (ej: '(max-width: 768px)')
 * @returns boolean que indica si la media query coincide
 */
export function useMediaQuery(query: string): boolean {
  // Estado para almacenar si la media query coincide
  const [matches, setMatches] = useState<boolean>(false);

  useEffect(() => {
    // Crear el media query
    const mediaQuery = window.matchMedia(query);
    
    // Establecer el estado inicial
    setMatches(mediaQuery.matches);

    // Función para actualizar el estado cuando cambia la media query
    const handleChange = (event: MediaQueryListEvent) => {
      setMatches(event.matches);
    };

    // Agregar el listener para detectar cambios
    mediaQuery.addEventListener('change', handleChange);

    // Limpiar el listener cuando el componente se desmonta
    return () => {
      mediaQuery.removeEventListener('change', handleChange);
    };
  }, [query]);

  return matches;
}
