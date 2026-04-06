/**
 * FAQPage - Página pública de Preguntas Frecuentes
 * SEO: Schema FAQPage para rich snippets en Google
 * Usa CollapsibleSection para el acordeón de preguntas
 */

import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { FiHelpCircle, FiMessageCircle } from 'react-icons/fi';
import { useLanguage } from '../contexts/LanguageContext';
import CollapsibleSection from '../components/common/CollapsibleSection';
import { useSEO } from '../hooks/useSEO';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';
import { fluidSizing } from '../utils/fluidSizing';

const FAQPage = () => {
  const { t } = useTranslation('faqPage');
  const { localizedPath } = useLanguage();

  // Cargar FAQs desde traducciones
  const faqs = useMemo(() => {
    const items = t('faqs', { returnObjects: true }) as Array<{
      question: string;
      answer: string;
      category?: string;
    }>;
    return Array.isArray(items) ? items : [];
  }, [t]);

  // Schema FAQPage para rich snippets en Google
  const faqSchema = useMemo(() => {
    if (faqs.length === 0) return [];
    return faqs.map(faq => ({
      '@type': 'Question',
      'name': faq.question,
      'acceptedAnswer': {
        '@type': 'Answer',
        'text': faq.answer,
      },
    }));
  }, [faqs]);

  // SEO: Meta tags + Schema @graph (WebPage + FAQPage)
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    keywords: t('seo.keywords'),
    url: `${getBaseUrl()}/faq`,
    type: 'website',
    image: OG_IMAGES.home,
    schema: {
      '@context': 'https://schema.org',
      '@graph': [
        {
          '@type': 'WebPage',
          'name': t('seo.title'),
          'description': t('seo.description'),
          'url': `${getBaseUrl()}/faq`,
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
                'name': t('schema.breadcrumbFaq'),
                'item': `${getBaseUrl()}/faq`
              }
            ]
          }
        },
        ...(faqSchema.length > 0 ? [{
          '@type': 'FAQPage',
          'mainEntity': faqSchema,
        }] : [])
      ]
    }
  });

  return (
    <div className="container mx-auto" style={{ padding: fluidSizing.space.lg }}>
      {/* Header */}
      <div
        className="flex flex-col sm:flex-row sm:items-center sm:justify-between"
        style={{ gap: fluidSizing.space.md, marginBottom: fluidSizing.space.lg }}
      >
        <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
          <div
            className="flex items-center justify-center rounded-full bg-primario/10"
            style={{ width: fluidSizing.size.floatingButton, height: fluidSizing.size.floatingButton }}
          >
            <FiHelpCircle className="text-primario" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
          </div>
          <div>
            <h1 className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text['2xl'] }}>
              {t('pageTitle')}
            </h1>
            <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
              {t('pageSubtitle')}
            </p>
          </div>
        </div>
      </div>

      {/* FAQ Acordeón */}
      <div className="space-y-3" style={{ marginBottom: fluidSizing.space['2xl'] }}>
        {faqs.map((faq, index) => (
          <CollapsibleSection
            key={index}
            title={faq.question}
            subtitle={faq.category || undefined}
            variant="soft"
            defaultExpanded={index === 0}
            collapsible={true}
            className="hover:border-primario/30 hover:shadow-md transition-all duration-300"
          >
            <p className="text-texto leading-relaxed" style={{ fontSize: fluidSizing.text.sm }}>
              {faq.answer}
            </p>
          </CollapsibleSection>
        ))}
      </div>

      {/* CTA a Contacto */}
      <div
        className="bg-gradient-to-r from-primario to-hover rounded-lg text-white text-center"
        style={{ padding: fluidSizing.space['2xl'] }}
      >
        <FiMessageCircle
          className="mx-auto mb-3 opacity-90"
          style={{ width: fluidSizing.size.iconLg, height: fluidSizing.size.iconLg }}
        />
        <h2 className="font-bold" style={{ fontSize: fluidSizing.text.xl, marginBottom: fluidSizing.space.sm }}>
          {t('cta.title')}
        </h2>
        <p className="opacity-90" style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.lg }}>
          {t('cta.description')}
        </p>
        <Link
          to={localizedPath('/contacto')}
          className="inline-flex items-center gap-2 bg-white text-primario font-semibold rounded-md hover:bg-gray-100 transition-colors"
          style={{
            paddingLeft: fluidSizing.space.xl,
            paddingRight: fluidSizing.space.xl,
            paddingTop: fluidSizing.space.sm,
            paddingBottom: fluidSizing.space.sm,
            fontSize: fluidSizing.text.sm,
          }}
        >
          <FiMessageCircle style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          {t('cta.button')}
        </Link>
      </div>
    </div>
  );
};

export default FAQPage;
