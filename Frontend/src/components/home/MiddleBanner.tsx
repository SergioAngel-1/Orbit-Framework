import { memo } from 'react';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';

interface Banner {
  id: number;
  title: string;
  subtitle: string;
  image: string;
  imageMobile: string;
  cta: string;
  link: string;
  order?: number;
  type?: string;
  socialNetworks?: Array<{
    title: string;
    subtitle: string;
    cta: string;
    link: string;
    icon: string;
    color: string;
  }>;
  socialIcon?: string;
  socialColor?: string;
}

interface MiddleBannerProps {
  banners: Banner[];
}

const getLocalizedLink = (link: string | undefined, localizedPath: (path: string) => string): string => {
  if (!link) return '#';
  // Si es path relativo, localizar directamente
  if (link.startsWith('/')) return localizedPath(link);
  // Si es URL absoluta del mismo dominio, extraer pathname y localizar
  try {
    const url = new URL(link);
    const currentHost = window.location.hostname;
    if (url.hostname === currentHost || url.origin === window.location.origin) {
      return localizedPath(url.pathname + url.search + url.hash);
    }
  } catch {
    // No es URL válida, devolver tal cual
  }
  return link;
};

const MiddleBanner: React.FC<MiddleBannerProps> = memo(({ banners }) => {
  const { localizedPath } = useLanguage();
  if (banners.length === 0) {
    return null;
  }

  return (
    <section className="py-0 md:py-6 bg-white">
      <div className="container mx-auto px-2 sm:px-3 md:px-4 max-w-full">
        {banners.map((banner) => (
          <div 
            key={banner.id} 
            className="relative overflow-hidden rounded-lg"
            style={{
              boxShadow: '0 8px 16px rgba(0, 0, 0, 0.3)',
              transform: 'translateZ(0)',
              willChange: 'transform',
              position: 'relative',
              zIndex: 1
            }}
          >
            <Link to={getLocalizedLink(banner.link, localizedPath)} className="block">
              <picture>
                <source media="(max-width: 640px)" srcSet={banner.imageMobile || banner.image || undefined} />
                <img 
                  src={banner.image || undefined} 
                  alt={banner.title} 
                  className="w-full h-auto object-cover"
                  style={{ maxHeight: '200px', objectPosition: 'center' }}
                  loading="lazy"
                />
              </picture>
              <div className="absolute inset-0 bg-gradient-to-t from-oscuro/40 to-transparent flex flex-col justify-center p-6">
              </div>
            </Link>
          </div>
        ))}
      </div>
    </section>
  );
});

MiddleBanner.displayName = 'MiddleBanner';

export default MiddleBanner;
