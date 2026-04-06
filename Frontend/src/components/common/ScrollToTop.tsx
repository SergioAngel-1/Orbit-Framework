import { useEffect, useRef } from 'react';
import { useLocation } from 'react-router-dom';

/**
 * Componente que hace scroll al inicio de la página cuando cambia la ruta
 * Se debe colocar dentro del Router pero fuera de las Routes
 * 
 * Modificado para ignorar cambios en parámetros de búsqueda cuando está en páginas de búsqueda o catálogo
 */
const ScrollToTop: React.FC = () => {
  const { pathname, hash, search } = useLocation();
  const prevPathname = useRef(pathname);
  
  useEffect(() => {
    // Si hay un hash en la URL (por ejemplo, #seccion), no hacemos nada
    // ya que queremos mantener el scroll a esa sección específica
    if (hash) return;
    
    // Verificar si estamos en una página donde no queremos hacer scroll al cambiar parámetros
    const isPaginatedPage = pathname === '/search' || pathname === '/shop' || pathname.includes('/category/');
    
    // Si estamos en una página paginada y solo cambió el search (parámetros), no hacemos scroll
    if (isPaginatedPage && prevPathname.current === pathname) {
      // No hacemos scroll si solo cambiaron los parámetros de búsqueda
      return;
    }
    
    // Actualizar la referencia del pathname anterior
    prevPathname.current = pathname;
    
    // Hacemos scroll al inicio de la página
    window.scrollTo({
      top: 0,
      behavior: 'instant' // Usamos 'instant' en lugar de 'smooth' para evitar efectos visuales extraños
    });
  }, [pathname, hash, search]); // Ahora monitoreamos también los cambios en search

  return null; // Este componente no renderiza nada
};

export default ScrollToTop;
