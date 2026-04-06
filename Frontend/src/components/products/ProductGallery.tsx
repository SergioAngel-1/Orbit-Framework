import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface ProductImage {
  id: number;
  src: string;
  alt?: string;
}

interface ProductGalleryProps {
  images: ProductImage[];
  productName: string;
}

const ProductGallery: React.FC<ProductGalleryProps> = ({ images = [], productName }) => {
  const { t } = useTranslation('productDetailPage');
  const [activeImage, setActiveImage] = useState(0);

  return (
    <div className="w-full h-full">
      <div className="flex flex-col-reverse md:flex-row gap-4 md:gap-6">
        {/* Miniaturas - horizontal en móvil, vertical en desktop */}
        {images && images.length > 1 && (
          <div className="md:self-start flex flex-row md:flex-col gap-2 md:gap-3 pb-3 md:pb-3 overflow-x-auto md:overflow-y-auto scrollbar-none md:h-auto md:max-h-[500px] md:min-w-[100px] md:w-auto pl-1 pt-1">
            {images.map((image, index) => (
              <button
                key={image.id}
                className={`flex-shrink-0 rounded overflow-hidden 
                  ${index === activeImage 
                    ? 'ring-2 ring-primario shadow-md' 
                    : 'shadow hover:shadow-md'}
                  w-14 h-14 sm:w-16 sm:h-16 md:w-[80px] md:h-[80px] lg:w-[90px] lg:h-[90px] transition-all duration-200 hover:opacity-90 p-0`}
                onClick={() => setActiveImage(index)}
              >
                <img
                  src={image.src}
                  alt={t('gallery.imageAlt', { name: productName, index: index + 1 })}
                  className="w-full h-full object-cover"
                  loading="lazy"
                />
              </button>
            ))}
          </div>
        )}

        {/* Imagen principal */}
        <div className="flex-grow flex items-center justify-center">
          <img
            src={
              images && images.length > 0
                ? images[activeImage].src
                : '/wp-content/themes/Starter/assets/img/no-image.svg'
            }
            alt={productName}
            className="w-full h-auto max-h-[350px] sm:max-h-[450px] md:max-h-[500px] lg:max-h-[600px] rounded-2xl shadow-md hover:shadow-lg transition-shadow duration-300"
          />
        </div>
      </div>
    </div>
  );
};

export default ProductGallery;
