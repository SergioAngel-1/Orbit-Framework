/**
 * HomeSectionsContext - Contexto para compartir datos de secciones del home
 * 
 * Evita múltiples llamadas al hook useHomeSections desde diferentes componentes
 * Centraliza la carga de secciones y las comparte via contexto
 */

import { createContext, useContext, useMemo, ReactNode } from 'react';
import { useHomeSections, Section } from '../hooks/useProductSection';

interface HomeSectionsContextType {
  sections: { [key: string]: Section };
  loading: boolean;
  error: string | null;
  hasTopSections: boolean;
  hasMiddleSections: boolean;
  hasBottomSections: boolean;
  topSections: Section[];
  middleSections: Section[];
  bottomSections: Section[];
}

const HomeSectionsContext = createContext<HomeSectionsContextType | undefined>(undefined);

/**
 * Hook para consumir el contexto de secciones
 */
export const useHomeSectionsContext = (): HomeSectionsContextType => {
  const context = useContext(HomeSectionsContext);
  if (context === undefined) {
    throw new Error('useHomeSectionsContext debe ser usado dentro de un HomeSectionsProvider');
  }
  return context;
};

interface HomeSectionsProviderProps {
  children: ReactNode;
}

/**
 * Provider que carga las secciones una sola vez y las comparte
 */
export const HomeSectionsProvider = ({ children }: HomeSectionsProviderProps) => {
  // Una sola llamada al hook
  const { 
    sections, 
    loading, 
    error, 
    hasTopSections, 
    hasMiddleSections, 
    hasBottomSections 
  } = useHomeSections();

  // Pre-calcular las secciones filtradas y ordenadas (memoizado para evitar re-renders innecesarios)
  const { topSections, middleSections, bottomSections } = useMemo(() => {
    const sectionsArray = Object.values(sections);
    return {
      topSections: sectionsArray
        .filter((section) => section.zone === 'top')
        .sort((a, b) => (a.order || 0) - (b.order || 0)),
      middleSections: sectionsArray
        .filter((section) => section.zone === 'middle')
        .sort((a, b) => (a.order || 0) - (b.order || 0)),
      bottomSections: sectionsArray
        .filter((section) => section.zone === 'bottom')
        .sort((a, b) => (a.order || 0) - (b.order || 0)),
    };
  }, [sections]);

  const value: HomeSectionsContextType = useMemo(() => ({
    sections,
    loading,
    error,
    hasTopSections,
    hasMiddleSections,
    hasBottomSections,
    topSections,
    middleSections,
    bottomSections,
  }), [sections, loading, error, hasTopSections, hasMiddleSections, hasBottomSections, topSections, middleSections, bottomSections]);

  return (
    <HomeSectionsContext.Provider value={value}>
      {children}
    </HomeSectionsContext.Provider>
  );
};

export default HomeSectionsContext;
