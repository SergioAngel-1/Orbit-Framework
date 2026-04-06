import { useState, useEffect, useCallback } from 'react';
import menuService from '../services/menuService';
import { generateSlug } from '../utils/formatters';
import { MenuCategory } from '../types/menu';
import logger from '../utils/logger';
import i18n from '../config/i18n';
import { useAuth } from '../contexts/AuthContext';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * Hook personalizado para obtener y gestionar el menú principal desde WordPress
 * Refresca automáticamente cuando cambia el estado de autenticación
 */
export const useWordPressMenu = () => {
  const [menuCategories, setMenuCategories] = useState<MenuCategory[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const { isAuthenticated, user } = useAuth();
  const { currentLang } = useLanguage();

  // Función para cargar el menú
  const fetchMenu = useCallback(async (forceRefresh: boolean = false) => {
    try {
      setLoading(true);
      setError(null);
      
      logger.info('useWordPressMenu', `Cargando menú (auth: ${isAuthenticated}, user: ${user?.id || 'none'}, lang: ${currentLang}, refresh: ${forceRefresh})`);
      
      // Obtener categorías del menú desde WordPress
      const categories = await menuService.getMainMenu(forceRefresh);
      
      // Asegurarse de que todas las categorías y subcategorías tengan un slug válido
      const processedCategories = categories.map(category => {
        // Asegurar que la categoría principal tenga un slug
        const mainCategory = {
          ...category,
          slug: category.slug || generateSlug(category.name)
        };
        
        // Si hay subcategorías, asegurar que cada una tenga un slug
        if (mainCategory.subcategories && mainCategory.subcategories.length > 0) {
          mainCategory.subcategories = mainCategory.subcategories.map(subcat => ({
            ...subcat,
            slug: subcat.slug || generateSlug(subcat.name)
          }));
        }
        
        return mainCategory;
      });
      
      setMenuCategories(processedCategories);
    } catch (err) {
      logger.error('useWordPressMenu', 'Error al cargar el menú:', err);
      setError(i18n.t('errors:menu.loadError'));
      
      // En caso de error, usar las categorías estáticas como fallback
      try {
        const staticMenuModule = await import('../data/menuCategories');
        // Convertir las categorías estáticas al formato MenuCategory
        const staticCategories: MenuCategory[] = staticMenuModule.default.map((cat: any) => ({
          id: cat.id,
          name: cat.name,
          slug: cat.slug,
          subcategories: cat.subcategories?.map((sub: any) => ({
            id: sub.id,
            name: sub.name,
            slug: sub.slug
          }))
        }));
        logger.info('useWordPressMenu', 'Cargando categorías de menú estáticas como fallback');
        setMenuCategories(staticCategories);
      } catch (importError) {
        logger.error('useWordPressMenu', 'Error al cargar el menú estático de fallback:', importError);
      }
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated, user?.id, currentLang]);

  // Cargar menú al montar y cuando cambie la autenticación
  useEffect(() => {
    fetchMenu();
  }, [fetchMenu]);

  // Función para refrescar manualmente el menú (fuerza recarga del caché)
  const refreshMenu = useCallback(() => {
    logger.info('useWordPressMenu', 'Refrescando menú manualmente (forzando recarga)');
    fetchMenu(true); // forceRefresh = true
  }, [fetchMenu]);

  return { menuCategories, loading, error, refreshMenu };
};

export default useWordPressMenu;
