/**
 * CategoryCarousel - Carousel infinito de categorías accesibles por membresía
 * 
 * Muestra TODAS las categorías que el usuario puede acceder según su nivel
 * de membresía, con imagen/icono y nombre en un formato compacto y minimalista.
 * 
 * Obtiene las categorías directamente desde useCategories() (igual que ShopPage)
 * para asegurar que muestra todas las categorías disponibles.
 * 
 * @package Starter
 */

import { FC, useMemo, memo, useState, useCallback, useRef, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { Swiper, SwiperSlide } from 'swiper/react';
import { Autoplay, FreeMode, Navigation } from 'swiper/modules';
import type { Swiper as SwiperType } from 'swiper';
import { Category } from '../../types/woocommerce';
import { useCategoriesContext } from '../../contexts/CategoriesContext';
import { useCategoryAccess } from '../../hooks/useCategoryAccess';
import { fluidSizing } from '../../utils/fluidSizing';
import MembershipBadge from '../common/MembershipBadge';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';
import useMembershipLevels from '../../hooks/useMembershipLevels';
import { buildCatalogUrl } from '../../utils/membershipRouteUtils';

// Importar estilos de Swiper
import 'swiper/swiper-bundle.css';
import './CategoryCarousel.css';

const GridIcon: FC<{ className?: string; style?: React.CSSProperties }> = memo(({ className, style }) => (
  <svg 
    xmlns="http://www.w3.org/2000/svg" 
    viewBox="0 0 24 24" 
    fill="none" 
    stroke="currentColor" 
    strokeWidth="2" 
    strokeLinecap="round" 
    strokeLinejoin="round"
    className={className}
    style={style}
  >
    <rect x="3" y="3" width="7" height="7" />
    <rect x="14" y="3" width="7" height="7" />
    <rect x="14" y="14" width="7" height="7" />
    <rect x="3" y="14" width="7" height="7" />
  </svg>
));

const PackageIcon: FC<{ className?: string; style?: React.CSSProperties }> = memo(({ className, style }) => (
  <svg 
    xmlns="http://www.w3.org/2000/svg" 
    viewBox="0 0 24 24" 
    fill="none" 
    stroke="currentColor" 
    strokeWidth="2" 
    strokeLinecap="round" 
    strokeLinejoin="round"
    className={className}
    style={style}
  >
    <path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
    <line x1="12" y1="22.08" x2="12" y2="12" />
  </svg>
));

/**
 * Formatea texto a Title Case
 */
const formatTitleCase = (text: string): string => {
  if (!text) return '';
  return text
    .toLowerCase()
    .split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

/**
 * Icono de flecha hacia abajo para indicar subcategorías
 */
const ChevronDownIcon: FC<{ className?: string; style?: React.CSSProperties }> = memo(({ className, style }) => (
  <svg 
    xmlns="http://www.w3.org/2000/svg" 
    viewBox="0 0 24 24" 
    fill="none" 
    stroke="currentColor" 
    strokeWidth="2" 
    strokeLinecap="round" 
    strokeLinejoin="round"
    className={className}
    style={style}
  >
    <polyline points="6 9 12 15 18 9" />
  </svg>
));

/**
 * Componente para cada item del carousel - Diseño rectangular
 */
interface CategoryItemProps {
  category: Category | { id: number; name: string; slug: string; image?: { src: string } | null; min_membership?: number; parent?: number };
  isActive: boolean;
  isAll?: boolean;
  minMembership?: number;
  hasSubcategories?: boolean;
  onMouseEnter?: () => void;
  onMouseLeave?: () => void;
}

const CategoryItem: FC<CategoryItemProps> = memo(({ category, isActive, isAll = false, minMembership = 0, hasSubcategories = false, onMouseEnter, onMouseLeave }) => {
  const { t } = useTranslation('layoutCategoryCarousel');
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const imageUrl = category.image?.src;
  
  // Estilos especiales para "Todos" - diseño horizontal
  if (isAll) {
    return (
      <Link
        to={localizedPath('/catalogo')}
        className={`
          flex flex-row items-center gap-2 group
          rounded-xl border-2
          ${isActive 
            ? 'border-primario bg-primario/10 shadow-md' 
            : 'border-primario/30 bg-primario/5 hover:border-primario hover:bg-primario/10'
          }
        `}
        style={{
          padding: '8px 12px',
          height: '48px',
          transition: 'all 300ms ease'
        }}
      >
        {/* Icono de grid */}
        <div 
          className={`
            flex-shrink-0 rounded-lg flex items-center justify-center
            ${isActive ? 'bg-primario/20' : 'bg-primario/10 group-hover:bg-primario/15'}
          `}
          style={{
            width: '28px',
            height: '28px',
            transition: 'all 300ms ease'
          }}
        >
          <GridIcon 
            className="text-primario" 
            style={{ width: '20px', height: '20px' }}
          />
        </div>
        
        {/* Texto "Todos" */}
        <span 
          className="font-semibold text-primario whitespace-nowrap"
          style={{
            fontSize: fluidSizing.text.xs,
            transition: 'color 300ms ease'
          }}
        >
          {t('allLabel')}
        </span>
      </Link>
    );
  }
  
  // Estilos para categorías - diseño horizontal
  return (
    <Link
      to={localizedPath(buildCatalogUrl(category.slug, minMembership, levels))}
      className={`
        relative flex flex-row items-center gap-2 group
        rounded-xl border-2
        ${isActive 
          ? 'border-primario bg-primario/10 shadow-md' 
          : 'border-gray-200 bg-gray-50 hover:border-primario hover:bg-primario/5'
        }
      `}
      style={{
        padding: '8px 12px',
        height: '48px',
        transition: 'all 300ms ease'
      }}
      onMouseEnter={onMouseEnter}
      onMouseLeave={onMouseLeave}
    >
      {/* Badge de membresía en esquina superior derecha */}
      {minMembership > 0 && (
        <div 
          className="absolute z-20"
          style={{ 
            top: '-8px', 
            right: '-8px'
          }}
        >
          <MembershipBadge level={minMembership} size="xs" />
        </div>
      )}
      
      {/* Contenedor de imagen/icono - con fondo en desktop, sin fondo en mobile */}
      <div 
        className={`
          flex-shrink-0 overflow-hidden flex items-center justify-center
          md:rounded-lg
          ${isActive ? 'md:bg-primario/10' : 'md:bg-gray-100 md:group-hover:bg-primario/5'}
        `}
        style={{
          width: '24px',
          height: '24px',
          transition: 'all 300ms ease'
        }}
      >
        {imageUrl ? (
          <img 
            src={imageUrl} 
            alt=""
            role="presentation"
            className="w-full h-full object-contain"
            loading="lazy"
          />
        ) : (
          <PackageIcon 
            className={`${isActive ? 'text-primario' : 'text-gray-400 group-hover:text-primario'}`}
            style={{ width: '20px', height: '20px' }}
          />
        )}
      </div>
      
      {/* Nombre de categoría */}
      <span 
        className={`
          font-medium whitespace-nowrap
          ${isActive ? 'text-primario' : 'text-gray-700 group-hover:text-primario'}
        `}
        style={{
          fontSize: fluidSizing.text.xs,
          transition: 'color 300ms ease'
        }}
      >
        {formatTitleCase(category.name)}
      </span>
      
      {/* Indicador de subcategorías */}
      {hasSubcategories && (
        <ChevronDownIcon 
          className={`flex-shrink-0 ${isActive ? 'text-primario' : 'text-gray-400 group-hover:text-primario'}`}
          style={{ width: '14px', height: '14px', transition: 'color 300ms ease' }}
        />
      )}
    </Link>
  );
});

/**
 * Icono de flecha hacia la derecha para indicar sub-subcategorías con flyout
 */
const ChevronRightIcon: FC<{ className?: string; style?: React.CSSProperties }> = memo(({ className, style }) => (
  <svg 
    xmlns="http://www.w3.org/2000/svg" 
    viewBox="0 0 24 24" 
    fill="none" 
    stroke="currentColor" 
    strokeWidth="2" 
    strokeLinecap="round" 
    strokeLinejoin="round"
    className={className}
    style={style}
  >
    <polyline points="9 18 15 12 9 6" />
  </svg>
));

/**
 * Item individual del dropdown con soporte para flyout de hijos
 */
interface DropdownItemProps {
  category: Category;
  children: Category[];
  allCategories: Category[];
  categoryLevels: Map<number, number>;
  activeParentSlug: string;
  onClose: () => void;
}

const DropdownItem: FC<DropdownItemProps> = memo(({ 
  category, 
  children: childCategories, 
  allCategories, 
  categoryLevels, 
  activeParentSlug, 
  onClose 
}) => {
  const { localizedPath } = useLanguage();
  const { levels } = useMembershipLevels();
  const [isHovered, setIsHovered] = useState(false);
  const hoverTimeout = useRef<NodeJS.Timeout | null>(null);
  const itemRef = useRef<HTMLDivElement>(null);
  const [flyoutPos, setFlyoutPos] = useState<{ top: number; left: number } | null>(null);

  const hasChildren = childCategories.length > 0;
  const isActive = activeParentSlug === category.slug;

  const handleEnter = () => {
    if (hoverTimeout.current) clearTimeout(hoverTimeout.current);
    setIsHovered(true);
  };

  const handleLeave = () => {
    hoverTimeout.current = setTimeout(() => setIsHovered(false), 120);
  };

  // Calcular posición del flyout cuando se muestra
  useEffect(() => {
    if (isHovered && hasChildren && itemRef.current) {
      const rect = itemRef.current.getBoundingClientRect();
      const flyoutWidth = 240;
      // Si no cabe a la derecha, abrir a la izquierda
      const spaceRight = window.innerWidth - rect.right;
      const left = spaceRight >= flyoutWidth ? rect.right + 4 : rect.left - flyoutWidth - 4;
      setFlyoutPos({ top: rect.top, left });
    }
  }, [isHovered, hasChildren]);

  // Obtener hijos de una categoría
  const getChildCategories = (parentId: number): Category[] => {
    return allCategories.filter(cat => cat.parent === parentId);
  };

  return (
    <div 
      ref={itemRef}
      className="relative"
      onMouseEnter={handleEnter}
      onMouseLeave={handleLeave}
    >
      <Link
        to={localizedPath(buildCatalogUrl(category.slug, categoryLevels.get(category.id) || 0, levels))}
        className={`
          flex items-center gap-2 px-3 py-2 rounded-lg transition-all duration-200
          ${isActive 
            ? 'bg-primario/10 text-primario' 
            : 'hover:bg-gray-100 text-gray-700 hover:text-primario'
          }
        `}
        style={{ fontSize: fluidSizing.text.xs }}
        onClick={onClose}
      >
        {category.image?.src ? (
          <img 
            src={category.image.src} 
            alt=""
            role="presentation"
            className="w-5 h-5 object-contain"
            loading="lazy"
          />
        ) : (
          <PackageIcon 
            className="text-gray-400 flex-shrink-0"
            style={{ width: '16px', height: '16px' }}
          />
        )}
        <span className="flex-1">{formatTitleCase(category.name)}</span>
        {hasChildren && (
          <ChevronRightIcon 
            className="flex-shrink-0 text-gray-400"
            style={{ width: '14px', height: '14px' }}
          />
        )}
      </Link>

      {/* Flyout panel via portal — escapa cualquier overflow del contenedor */}
      {hasChildren && isHovered && flyoutPos && createPortal(
        <div 
          className="category-dropdown fixed bg-white rounded-xl shadow-lg border border-gray-200 z-[9999] min-w-[190px] max-w-[260px] py-2 max-h-[350px] overflow-y-auto"
          style={{ top: flyoutPos.top, left: flyoutPos.left }}
          onMouseEnter={handleEnter}
          onMouseLeave={handleLeave}
        >
          {childCategories.map(child => {
            const grandChildren = getChildCategories(child.id);
            return (
              <DropdownItem
                key={child.id}
                category={child}
                children={grandChildren}
                allCategories={allCategories}
                categoryLevels={categoryLevels}
                activeParentSlug={activeParentSlug}
                onClose={onClose}
              />
            );
          })}
        </div>,
        document.body
      )}
    </div>
  );
});

/**
 * Componente para el dropdown de subcategorías
 */
interface SubcategoryDropdownProps {
  subcategories: Category[];
  allCategories: Category[];
  categoryLevels: Map<number, number>;
  activeParentSlug: string;
  onClose: () => void;
}

const SubcategoryDropdown: FC<SubcategoryDropdownProps> = memo(({ 
  subcategories, 
  allCategories,
  categoryLevels, 
  activeParentSlug,
  onClose 
}) => {
  // Función para obtener hijos de una categoría
  const getChildCategories = (parentId: number): Category[] => {
    return allCategories.filter(cat => cat.parent === parentId);
  };

  // Prevenir scroll del body cuando se hace scroll en el dropdown
  const handleWheel = (e: React.WheelEvent<HTMLDivElement>) => {
    const element = e.currentTarget;
    const { scrollTop, scrollHeight, clientHeight } = element;
    const isAtTop = scrollTop === 0 && e.deltaY < 0;
    const isAtBottom = scrollTop + clientHeight >= scrollHeight && e.deltaY > 0;
    
    if (!isAtTop && !isAtBottom) {
      e.stopPropagation();
    }
  };

  return (
    <div 
      className="category-dropdown absolute top-full left-0 mt-2 bg-white rounded-xl shadow-lg border border-gray-200 z-[9999] min-w-[200px] max-w-[280px] py-2 max-h-[400px] overflow-y-auto"
      onMouseEnter={(e) => e.stopPropagation()}
      onMouseLeave={onClose}
      onWheel={handleWheel}
    >
      {subcategories.map(sub => {
        const children = getChildCategories(sub.id);
        return (
          <DropdownItem
            key={sub.id}
            category={sub}
            children={children}
            allCategories={allCategories}
            categoryLevels={categoryLevels}
            activeParentSlug={activeParentSlug}
            onClose={onClose}
          />
        );
      })}
    </div>
  );
});

/**
 * Skeleton loader para el carousel
 */
const CategoryCarouselSkeleton: FC = memo(() => {
  return (
    <div className="flex items-center justify-center gap-2 py-2">
      {[...Array(10)].map((_, i) => (
        <div key={i} className="flex flex-col items-center gap-1.5 animate-pulse">
          <div className="w-[80px] h-[56px] bg-gray-200 rounded-lg" />
          <div className="w-14 h-3 bg-gray-200 rounded" />
        </div>
      ))}
    </div>
  );
});

/**
 * Props del componente
 */
interface CategoryCarouselProps {
  onMenuToggle?: () => void;
}

/**
 * CategoryCarousel Component
 * 
 * Carousel infinito horizontal con las categorías accesibles por membresía
 * Obtiene categorías directamente desde la API (igual que ShopPage)
 */
const CategoryCarousel: FC<CategoryCarouselProps> = ({ onMenuToggle }) => {
  const location = useLocation();
  const { categories, loading } = useCategoriesContext();
  const { filterAccessibleCategories, getCategoryMinLevel } = useCategoryAccess();
  const { t } = useTranslation('layoutCategoryCarousel');
  
  // Estado para el dropdown de subcategorías (desktop)
  // Usamos el índice del slide en vez de category.id para evitar que items duplicados
  // (loop infinito) desplieguen su dropdown espejo simultáneamente
  const [hoveredSlideIndex, setHoveredSlideIndex] = useState<number | null>(null);
  const hoverTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  
  // Referencia al Swiper para control manual de autoplay
  const swiperRef = useRef<SwiperType | null>(null);

  // Filtrar categorías accesibles, separar padres e hijos, y pre-calcular niveles
  const { parentCategories, duplicatedParentCategories, allAccessibleCategories, categoryLevels, subcategoriesMap } = useMemo(() => {
    if (!categories || categories.length === 0) {
      return { 
        parentCategories: [], 
        duplicatedParentCategories: [],
        allAccessibleCategories: [],
        categoryLevels: new Map<number, number>(),
        subcategoriesMap: new Map<number, Category[]>()
      };
    }
    
    const filtered = filterAccessibleCategories(categories);
    const levels = new Map<number, number>();
    const subsMap = new Map<number, Category[]>();
    
    // Calcular niveles para todas las categorías
    filtered.forEach(cat => {
      levels.set(cat.id, getCategoryMinLevel(cat));
    });
    
    // Separar categorías padre (parent === 0) e hijas
    const parents = filtered.filter(cat => cat.parent === 0);
    
    // Crear mapa de subcategorías directas para cada padre
    parents.forEach(parent => {
      const directChildren = filtered.filter(cat => cat.parent === parent.id);
      if (directChildren.length > 0) {
        subsMap.set(parent.id, directChildren);
      }
    });
    
    // Ordenar padres: primero las que tienen hijos, luego por nivel de membresía
    parents.sort((a, b) => {
      const aHasChildren = subsMap.has(a.id) ? 1 : 0;
      const bHasChildren = subsMap.has(b.id) ? 1 : 0;
      
      // Primero ordenar por si tiene hijos (las que tienen van primero)
      if (bHasChildren !== aHasChildren) {
        return bHasChildren - aHasChildren;
      }
      
      // Luego ordenar por nivel de membresía (menor a mayor)
      return (levels.get(a.id) || 0) - (levels.get(b.id) || 0);
    });
    
    // Duplicar categorías para simular loop infinito (3 repeticiones)
    // Esto es necesario porque Swiper loop no funciona con slidesPerView: "auto"
    const duplicatedParents: Category[] = [];
    for (let i = 0; i < 3; i++) {
      duplicatedParents.push(...parents);
    }
    
    return { 
      parentCategories: parents,
      duplicatedParentCategories: duplicatedParents,
      allAccessibleCategories: filtered,
      categoryLevels: levels,
      subcategoriesMap: subsMap
    };
  }, [categories, filterAccessibleCategories, getCategoryMinLevel]);

  // Handlers para hover con delay - pausan/reanudan el autoplay del Swiper
  const handleMouseEnter = useCallback((slideIndex: number) => {
    if (hoverTimeoutRef.current) {
      clearTimeout(hoverTimeoutRef.current);
    }
    setHoveredSlideIndex(slideIndex);
    
    // Pausar autoplay cuando se muestra el dropdown
    if (swiperRef.current?.autoplay) {
      swiperRef.current.autoplay.stop();
    }
  }, []);

  const handleMouseLeave = useCallback(() => {
    hoverTimeoutRef.current = setTimeout(() => {
      setHoveredSlideIndex(null);
      
      // Reanudar autoplay cuando se cierra el dropdown
      if (swiperRef.current?.autoplay) {
        swiperRef.current.autoplay.start();
      }
    }, 150); // Pequeño delay para permitir mover el mouse al dropdown
  }, []);

  // Determinar categoría padre activa basada en la URL
  // Si estamos en una subcategoría, marcar el padre como activo
  const activeParentSlug = useMemo(() => {
    // Normalizar pathname eliminando el prefijo /en para comparaciones consistentes
    const rawPath = location.pathname;
    const path = rawPath.startsWith('/en/') ? rawPath.slice(3) : (rawPath === '/en' ? '/' : rawPath);
    // Solo marcar 'all' si estamos exactamente en /catalogo
    if (path === '/catalogo') return 'all';
    // Para categorías/subcategorías, extraer el slug de la URL
    if (path.startsWith('/catalogo/')) {
      const segments = path.split('/');
      const currentSlug = segments[2] || '';
      
      // Buscar la categoría actual
      const currentCategory = allAccessibleCategories.find(cat => cat.slug === currentSlug);
      
      if (currentCategory) {
        // Si es una categoría padre (parent === 0), devolver su slug
        if (currentCategory.parent === 0) {
          return currentCategory.slug;
        }
        
        // Si es una subcategoría, buscar el padre raíz
        let parentId = currentCategory.parent;
        let parentCategory = allAccessibleCategories.find(cat => cat.id === parentId);
        
        // Subir en la jerarquía hasta encontrar el padre raíz
        while (parentCategory && parentCategory.parent !== 0) {
          parentId = parentCategory.parent;
          parentCategory = allAccessibleCategories.find(cat => cat.id === parentId);
        }
        
        // Devolver el slug del padre raíz
        if (parentCategory) {
          return parentCategory.slug;
        }
      }
      
      return currentSlug;
    }
    // En cualquier otra página, ninguna categoría está activa
    return '';
  }, [location.pathname, allAccessibleCategories]);

  if (loading) {
    return <CategoryCarouselSkeleton />;
  }

  if (parentCategories.length === 0) {
    return null;
  }

  return (
    <div className="w-full flex items-center" style={{ gap: fluidSizing.space.sm, overflow: 'visible' }}>
      {/* Botón hamburguesa para abrir menú - solo desktop */}
      {onMenuToggle && (
        <button
          onClick={onMenuToggle}
          className="hidden md:flex flex-shrink-0 flex-row items-center gap-2 rounded-xl border-2 border-gray-200 bg-gray-50 hover:border-primario hover:bg-primario/5 transition-all duration-300 group"
          style={{ padding: '8px 12px', height: '48px' }}
          aria-label={t('aria.openMenu')}
        >
          <div 
            className="flex-shrink-0 rounded-lg flex items-center justify-center bg-gray-100 group-hover:bg-primario/10"
            style={{ width: '28px', height: '28px' }}
          >
            <svg 
              xmlns="http://www.w3.org/2000/svg" 
              className="text-gray-600 group-hover:text-primario transition-colors" 
              style={{ width: '20px', height: '20px' }}
              fill="none" 
              viewBox="0 0 24 24" 
              stroke="currentColor"
              strokeWidth={2}
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </div>
          <span 
            className="font-semibold text-gray-700 group-hover:text-primario whitespace-nowrap transition-colors"
            style={{ fontSize: fluidSizing.text.xs }}
          >
            {t('menuButton')}
          </span>
        </button>
      )}
      
      {/* Item "Catálogo" fijo - solo visible en desktop */}
      <div className="flex-shrink-0 hidden md:block">
        <CategoryItem 
          category={{ id: 0, name: t('allLabel'), slug: 'all', image: null }} 
          isActive={activeParentSlug === 'all'}
          isAll={true}
        />
      </div>
      
      {/* Separador entre "Todos" y carrusel - solo desktop */}
      <div className="hidden md:block w-4 flex-shrink-0" />
      
      {/* Icono navegación izquierda - solo visible en desktop */}
      <button 
        className="category-carousel-prev flex-shrink-0 items-center justify-center
          text-gray-400 text-3xl cursor-pointer
          transition-colors duration-300
          hover:text-primario
          focus:outline-none
          hidden md:flex"
        style={{ border: 'none', background: 'none', padding: '8px', lineHeight: 1, minWidth: '48px', minHeight: '48px' }}
        aria-label={t('aria.previous')}
        type="button"
      >
        ‹
      </button>
      
      {/* Scroll horizontal para mobile - sin scrollbar visible */}
      <div 
        className="mobile-scroll-container flex-1 min-w-0 md:hidden overflow-x-auto pt-3"
        style={{ 
          WebkitOverflowScrolling: 'touch',
          overflowY: 'visible'
        }}
      >
        <div className="flex gap-3 pb-1 items-center" style={{ width: 'max-content', overflow: 'visible' }}>
          {/* Mobile: carousel simple de categorías padre (sin subcategorías) */}
          {parentCategories.map((category: Category) => (
            <CategoryItem 
              key={category.id}
              category={category} 
              isActive={activeParentSlug === category.slug}
              minMembership={categoryLevels.get(category.id) || 0}
            />
          ))}
        </div>
      </div>
      
      {/* Flecha indicadora de scroll - solo mobile */}
      <div className="flex-shrink-0 md:hidden flex items-center justify-center text-gray-400 text-2xl pl-1">
        ›
      </div>
      
      {/* Swiper para desktop */}
      <div className="flex-1 min-w-0 py-3 hidden md:block pr-4" style={{ overflowX: 'clip', overflowY: 'visible' }}>
        <Swiper
          modules={[Autoplay, FreeMode, Navigation]}
          spaceBetween={12}
          slidesPerView="auto"
          slidesPerGroup={2}
          freeMode={{
            enabled: true,
            sticky: false,
            momentumRatio: 0.5,
            momentumVelocityRatio: 0.5
          }}
          navigation={{
            prevEl: '.category-carousel-prev',
            nextEl: '.category-carousel-next',
          }}
          loop={false}
          onSwiper={(swiper: SwiperType) => { swiperRef.current = swiper; }}
          autoplay={{
            delay: 4000,
            disableOnInteraction: false,
            pauseOnMouseEnter: false,
            reverseDirection: false
          }}
          centeredSlides={false}
          grabCursor={true}
          className="category-carousel"
          initialSlide={parentCategories.length}
        >
          {/* Desktop: categorías duplicadas para loop infinito visual */}
          {duplicatedParentCategories.map((category: Category, index: number) => (
            <SwiperSlide key={`${category.id}-${index}`} style={{ width: 'auto', overflow: 'visible' }}>
              <div 
                className="relative"
                onMouseEnter={() => handleMouseEnter(index)}
                onMouseLeave={handleMouseLeave}
              >
                <CategoryItem 
                  category={category} 
                  isActive={activeParentSlug === category.slug}
                  minMembership={categoryLevels.get(category.id) || 0}
                  hasSubcategories={subcategoriesMap.has(category.id)}
                />
                {/* Dropdown de subcategorías */}
                {hoveredSlideIndex === index && subcategoriesMap.has(category.id) && (
                  <SubcategoryDropdown
                    subcategories={subcategoriesMap.get(category.id) || []}
                    allCategories={allAccessibleCategories}
                    categoryLevels={categoryLevels}
                    activeParentSlug={activeParentSlug}
                    onClose={() => setHoveredSlideIndex(null)}
                  />
                )}
              </div>
            </SwiperSlide>
          ))}
          {/* Slide espaciador para dar espacio al badge del último item */}
          <SwiperSlide key="spacer" style={{ width: '16px', flexShrink: 0 }}>
            <div style={{ width: '16px' }} />
          </SwiperSlide>
        </Swiper>
      </div>
      
      {/* Icono navegación derecha - solo visible en desktop */}
      <button 
        className="category-carousel-next flex-shrink-0 items-center justify-center
          text-gray-400 text-3xl cursor-pointer
          transition-colors duration-300
          hover:text-primario
          focus:outline-none
          hidden md:flex"
        style={{ border: 'none', background: 'none', padding: '8px', lineHeight: 1, minWidth: '48px', minHeight: '48px' }}
        aria-label={t('aria.next')}
        type="button"
      >
        ›
      </button>
    </div>
  );
};

export default CategoryCarousel;
