import React from 'react';
import { Link, LinkProps } from 'react-router-dom';

// Componente personalizado que extiende el Link de react-router-dom
// para hacer scroll al inicio de la página al navegar
const ScrollToTopLink: React.FC<LinkProps> = ({ children, onClick, ...props }) => {
  const handleClick = (e: React.MouseEvent<HTMLAnchorElement, MouseEvent>) => {
    // Si hay un onClick personalizado, lo ejecutamos primero
    if (onClick) {
      onClick(e);
    }
    
    // Hacemos scroll al inicio de la página
    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  };

  return (
    <Link {...props} onClick={handleClick}>
      {children}
    </Link>
  );
};

export default ScrollToTopLink;
