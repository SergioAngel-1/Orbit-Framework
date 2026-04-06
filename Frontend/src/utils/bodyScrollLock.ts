let openLockCount = 0;

interface BodyLockSnapshot {
  overflow: string;
  paddingRight: string;
  position: string;
  top: string;
  width: string;
  left: string;
  right: string;
  touchAction: string;
  htmlOverflow: string;
  scrollY: number;
}

let snapshot: BodyLockSnapshot | null = null;

// Detectar iOS
const isIOS = () => {
  if (typeof window === 'undefined') return false;
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || 
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
};

const getScrollbarWidth = () => {
  if (typeof window === 'undefined') return 0;
  return window.innerWidth - document.documentElement.clientWidth;
};

// Almacenar posición inicial del touch para detectar dirección
let initialTouchY = 0;

// Handler para touchstart - capturar posición inicial
const handleTouchStart = (e: TouchEvent) => {
  if (e.touches.length === 1) {
    initialTouchY = e.touches[0].clientY;
  }
};

// Encontrar el elemento scrollable más cercano
const findScrollableParent = (element: HTMLElement | null): HTMLElement | null => {
  if (!element) return null;
  
  // Selectores para elementos que deben permitir scroll interno
  const scrollableSelectors = [
    '.modal-scrollable',
    '.overflow-y-auto',
    '.overflow-auto',
    '.overflow-y-scroll',
    '.overflow-scroll',
    '[data-allow-scroll]',
    '.animated-modal-body',
    '.animated-modal-content'
  ];
  
  let current: HTMLElement | null = element;
  
  while (current && current !== document.body) {
    // Verificar si coincide con algún selector
    for (const selector of scrollableSelectors) {
      if (current.matches(selector)) {
        return current;
      }
    }
    
    // Verificar estilos computados para overflow
    const style = window.getComputedStyle(current);
    const overflowY = style.overflowY;
    
    if (overflowY === 'auto' || overflowY === 'scroll') {
      // Verificar que realmente tenga contenido scrollable
      if (current.scrollHeight > current.clientHeight) {
        return current;
      }
    }
    
    current = current.parentElement;
  }
  
  return null;
};

// Handler para prevenir touchmove en iOS - solo bloquea scroll del body
const preventTouchMove = (e: TouchEvent) => {
  const target = e.target as HTMLElement;
  const scrollableParent = findScrollableParent(target);
  
  // Si hay un elemento scrollable, permitir su scroll interno
  if (scrollableParent) {
    const { scrollTop, scrollHeight, clientHeight } = scrollableParent;
    const currentTouchY = e.touches[0].clientY;
    const touchDeltaY = currentTouchY - initialTouchY;
    
    // Detectar dirección: positivo = scroll hacia arriba (dedo hacia abajo)
    const isScrollingUp = touchDeltaY > 0;
    const isScrollingDown = touchDeltaY < 0;
    
    const isAtTop = scrollTop <= 0;
    const isAtBottom = scrollTop + clientHeight >= scrollHeight - 1; // -1 para tolerancia
    
    // Solo prevenir si estamos en un límite Y tratando de ir más allá (bounce de iOS)
    if ((isAtTop && isScrollingUp) || (isAtBottom && isScrollingDown)) {
      e.preventDefault();
      return;
    }
    
    // Permitir scroll normal dentro del elemento
    return;
  }
  
  // No hay elemento scrollable - prevenir scroll del body
  e.preventDefault();
};

export const lockBodyScroll = () => {
  if (typeof document === 'undefined') return;
  if (openLockCount === 0) {
    const scrollY = window.scrollY || document.documentElement.scrollTop || 0;

    snapshot = {
      overflow: document.body.style.overflow,
      paddingRight: document.body.style.paddingRight,
      position: document.body.style.position,
      top: document.body.style.top,
      width: document.body.style.width,
      left: document.body.style.left,
      right: document.body.style.right,
      touchAction: document.body.style.touchAction,
      htmlOverflow: document.documentElement.style.overflow,
      scrollY
    };

    const scrollbarWidth = getScrollbarWidth();
    if (scrollbarWidth > 0) {
      document.body.style.paddingRight = `${scrollbarWidth}px`;
    }

    // Bloquear scroll en html y body
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
    // No usar touchAction: none ya que bloquea scrolls internos también
    
    // En iOS, agregar listeners para manejar scroll
    if (isIOS()) {
      document.addEventListener('touchstart', handleTouchStart, { passive: true });
      document.addEventListener('touchmove', preventTouchMove, { passive: false });
    }
  }
  openLockCount += 1;
};

export const unlockBodyScroll = () => {
  if (typeof document === 'undefined') return;
  openLockCount = Math.max(0, openLockCount - 1);
  if (openLockCount === 0 && snapshot) {
    // Restaurar estilos del html
    document.documentElement.style.overflow = snapshot.htmlOverflow || '';
    
    // Restaurar estilos del body
    document.body.style.overflow = snapshot.overflow || '';
    document.body.style.paddingRight = snapshot.paddingRight || '';
    
    // En iOS, remover listeners de touch
    if (isIOS()) {
      document.removeEventListener('touchstart', handleTouchStart);
      document.removeEventListener('touchmove', preventTouchMove);
    }

    snapshot = null;
  }
};
