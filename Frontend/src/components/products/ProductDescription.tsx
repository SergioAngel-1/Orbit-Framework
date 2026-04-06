import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import DOMPurify from 'dompurify';
import './ProductDescription.css'; // Importamos un archivo CSS externo

interface ProductDescriptionProps {
  description: string;
}

interface ImageModalProps {
  src: string;
  alt: string;
  isOpen: boolean;
  onClose: () => void;
}

// Componente Modal para mostrar imágenes
const ImageModal: React.FC<ImageModalProps> = ({ src, alt, isOpen, onClose }) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-75" onClick={onClose}>
      <div className="relative max-w-4xl max-h-[90vh] p-2">
        <button 
          className="absolute top-2 right-2 bg-white rounded-full p-1 shadow-md"
          onClick={(e) => {
            e.stopPropagation();
            onClose();
          }}
        >
          <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <img 
          src={src} 
          alt={alt} 
          className="max-w-full max-h-[85vh] object-contain" 
        />
      </div>
    </div>
  );
};

const ProductDescription: React.FC<ProductDescriptionProps> = ({ description }) => {
  const { t } = useTranslation('productComponents');
  const [modalImage, setModalImage] = useState<{src: string; alt: string; isOpen: boolean}>({ 
    src: '', 
    alt: '', 
    isOpen: false 
  });

  // Procesar la descripción HTML para mejorar su formato y legibilidad
  const formattedDescription = useMemo(() => {
    if (!description) return '';
    
    // Limpiar y normalizar el HTML
    let cleanedHtml = description
      // Normalizar saltos de línea
      .replace(/\r\n/g, '\n')
      // Eliminar múltiples saltos de línea
      .replace(/\n{2,}/g, '\n')
      .trim();
    
    // Eliminar párrafos vacíos o con solo espacios en blanco
    cleanedHtml = cleanedHtml
      .replace(/<p>\s*(&nbsp;)*\s*<\/p>/gi, '')
      .replace(/<p>\s*<br\s*\/?\s*>\s*<\/p>/gi, '');
    
    // Reemplazar imágenes por enlaces "Ver imagen"
    let imageCount = 0;
    cleanedHtml = cleanedHtml.replace(/<img[^>]*src=["']([^"']+)["'][^>]*alt=["']([^"']*)["'][^>]*\/?>/gi, (_, src, alt) => {
      imageCount++;
      const imageId = `desc-image-${imageCount}`;
      const imageAlt = alt || t('productDescription.imageAlt', { count: imageCount });
      return `<button class="product-image-btn" data-image-src="${src}" data-image-alt="${imageAlt}" data-image-id="${imageId}">${t('productDescription.viewImage')}</button>`;
    });
    
    // Reemplazar imágenes sin atributo alt
    cleanedHtml = cleanedHtml.replace(/<img[^>]*src=["']([^"']+)["'][^>]*\/?>/gi, (_, src) => {
      imageCount++;
      const imageId = `desc-image-${imageCount}`;
      return `<button class="product-image-btn" data-image-src="${src}" data-image-alt="${t('productDescription.imageAlt', { count: imageCount })}" data-image-id="${imageId}">${t('productDescription.viewImage')}</button>`;
    });
    
    // Si el HTML ya tiene formato de párrafos, procesamos el HTML
    if (cleanedHtml.includes('<p')) {
      cleanedHtml = cleanedHtml
        // Asegurar que los párrafos tengan la clase correcta
        .replace(/<p>/g, '<p class="product-paragraph">')
        // Eliminar <br> seguidos de <p> o precedidos por </p>
        .replace(/<br\s*\/?><p/gi, '<p')
        .replace(/<\/p><br\s*\/?>/gi, '</p>')
        // Eliminar &nbsp; solitarios
        .replace(/(&nbsp;)+/g, ' ');
    } else {
      // Si no tiene formato HTML, procesamos el texto plano
      // Dividir por saltos de línea y filtrar líneas vacías
      const lines = cleanedHtml.split('\n')
        .map(line => line.trim())
        .filter(line => line.length > 0 && line !== '&nbsp;');
      
      // Crear párrafos solo con líneas que tengan contenido
      if (lines.length === 0) return '';
      
      cleanedHtml = lines
        .map(line => `<p class="product-paragraph">${line}</p>`)
        .join('');
    }
    
    // Aplicar mejoras de formato a encabezados, listas y otros elementos
    return cleanedHtml
      // Mejorar los encabezados con tamaño controlado
      .replace(/<h1>(.*?)<\/h1>/g, '<h2 class="product-heading-lg">$1</h2>')
      .replace(/<h2>(.*?)<\/h2>/g, '<h3 class="product-heading-md">$1</h3>')
      .replace(/<h3>(.*?)<\/h3>/g, '<h4 class="product-heading-sm">$1</h4>')
      .replace(/<h([4-6])>(.*?)<\/h([4-6])>/g, '<h$1 class="product-heading-xs">$2</h$3>')
      
      // Formatear listas con tamaño controlado
      .replace(/<ul>/g, '<ul class="product-list product-list-disc">')
      .replace(/<ol>/g, '<ol class="product-list product-list-decimal">')
      .replace(/<li>/g, '<li class="product-list-item">')
      
      // Mejorar enlaces
      .replace(/<a(.*?)>/g, '<a$1 class="product-link">')
      
      // Formatear texto enfatizado
      .replace(/<strong>/g, '<strong class="product-strong">')
      .replace(/<em>/g, '<em class="product-italic">');
  }, [description, t]);

  // Manejador para abrir el modal de imagen
  const handleImageClick = (e: React.MouseEvent<HTMLDivElement>) => {
    const target = e.target as HTMLElement;
    if (target.classList.contains('product-image-btn')) {
      const src = target.getAttribute('data-image-src') || '';
      const alt = target.getAttribute('data-image-alt') || '';
      
      if (src) {
        setModalImage({
          src,
          alt,
          isOpen: true
        });
      }
    }
  };

  // Cerrar el modal
  const closeModal = () => {
    setModalImage(prev => ({ ...prev, isOpen: false }));
  };

  // Sanitizar HTML antes de renderizar para prevenir XSS
  const sanitizedDescription = useMemo(() => {
    return DOMPurify.sanitize(formattedDescription, {
      ALLOWED_TAGS: ['p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'strong', 'em', 'button', 'br'],
      ALLOWED_ATTR: ['class', 'href', 'target', 'rel', 'data-image-src', 'data-image-alt', 'data-image-id'],
      ALLOW_DATA_ATTR: true
    });
  }, [formattedDescription]);

  return (
    <div className="flex-grow h-full text-texto product-animate overflow-y-auto pr-2 custom-scrollbar">
      <div 
        className="product-description"
        dangerouslySetInnerHTML={{ __html: sanitizedDescription }}
        onClick={handleImageClick}
      />
      
      {/* Modal para mostrar imágenes */}
      <ImageModal 
        src={modalImage.src}
        alt={modalImage.alt}
        isOpen={modalImage.isOpen}
        onClose={closeModal}
      />
    </div>
  );
};

export default ProductDescription;
