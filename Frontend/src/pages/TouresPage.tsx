/**
 * TouresPage - Landing page de Toures Recreativos por Colombia
 * Construida por fases: cada sección es un componente independiente
 */

import { useRef, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { TourHeroSection, TourExperienceSection, TourPackagesSection, TourSecuritySection, TourTestimonialsSection, TourFAQSection } from '../components/toures';
import { useSEO } from '../hooks/useSEO';
import { getBaseUrl } from '../utils/seo';

const TouresPage = () => {
  const { t } = useTranslation('touresPage');
  const { t: tSections } = useTranslation('tourSections');

  // Construir FAQs desde traducciones (misma fuente que TourFAQSection)
  const faqSchema = useMemo(() => {
    const faqs = tSections('faq.defaultFaqs', { returnObjects: true }) as Array<{ question: string; answer: string }>;
    if (!Array.isArray(faqs)) return [];
    return faqs.map(faq => ({
      '@type': 'Question',
      'name': faq.question,
      'acceptedAnswer': {
        '@type': 'Answer',
        'text': faq.answer,
      },
    }));
  }, [tSections]);

  // SEO: Meta tags optimizados para Toures Cannábicos
  // Schema @graph combina WebPage + FAQPage para rich snippets en Google
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    keywords: t('seo.keywords'),
    url: `${getBaseUrl()}/toures`,
    type: 'website',
    schema: {
      '@context': 'https://schema.org',
      '@graph': [
        {
          '@type': 'WebPage',
          'name': t('seo.schemaName'),
          'description': t('seo.schemaDescription'),
          'url': `${getBaseUrl()}/toures`,
          'isPartOf': {
            '@type': 'WebSite',
            'name': 'My Store',
            'url': getBaseUrl()
          },
          'breadcrumb': {
            '@type': 'BreadcrumbList',
            'itemListElement': [
              {
                '@type': 'ListItem',
                'position': 1,
                'name': t('schema.breadcrumbHome'),
                'item': getBaseUrl()
              },
              {
                '@type': 'ListItem',
                'position': 2,
                'name': t('schema.breadcrumbTours'),
                'item': `${getBaseUrl()}/toures`
              }
            ]
          },
          'mainEntity': {
            '@type': 'TouristTrip',
            'name': t('schema.tourName'),
            'description': t('schema.tourDescription'),
            'provider': {
              '@type': 'Organization',
              'name': 'My Store'
            }
          }
        },
        ...(faqSchema.length > 0 ? [{
          '@type': 'FAQPage',
          'mainEntity': faqSchema,
        }] : [])
      ]
    }
  });

  // Ref para scroll a la sección de paquetes (se usará en fases posteriores)
  const packagesRef = useRef<HTMLDivElement>(null);
  const experienceRef = useRef<HTMLDivElement>(null);

  const scrollToPackages = () => {
    packagesRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const scrollToExperience = () => {
    experienceRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  return (
    <div className="min-h-screen -mt-4 bg-gradient-to-b from-claro via-white to-claro">
      {/* Fase 1: Hero Section — BannerCarousel autónomo con fallback estático */}
      <TourHeroSection
        imageUrl="https://placehold.co/1920x600/16a34a/ffffff?text=Tours"
        onPrimaryClick={scrollToPackages}
        onSecondaryClick={scrollToExperience}
      />

      {/* Fase 2: Sección de Valor - La Experiencia 360° */}
      <div ref={experienceRef}>
        <TourExperienceSection />
      </div>

      {/* Fase 3: Paquetes y Tours */}
      <div ref={packagesRef} id="paquetes">
        <TourPackagesSection />
      </div>

      {/* Fase 4: Testimonios */}
      <TourTestimonialsSection />

      {/* Fase 5: Seguridad y Legalidad */}
      <TourSecuritySection />

      {/* Fase 6: FAQ Acordeón */}
      <TourFAQSection />
    </div>
  );
};

export default TouresPage;
