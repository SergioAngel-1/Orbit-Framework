import { useRef, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import LoginButton from '../components/common/LoginButton';
import { api } from '../services/apiConfig';
import { useLanguage } from '../contexts/LanguageContext';
import Loader from '../components/ui/Loader';
import logger from '../utils/logger';
import LegalDocumentsNav from '../components/legal/LegalDocumentsNav';
import { fluidSizing } from '../utils/fluidSizing';
import { useSEOPage } from '../hooks/useSEO';
import { sanitizeRichContent } from '../utils/sanitizeHtml';

interface LegalDocument {
  id: number;
  title: string;
  content: string;
  excerpt: string;
  type: string;
  slug: string;
  date: string;
  modified: string;
}

const PoliceProcedurePage = () => {
  const { t } = useTranslation('legalPages');
  useSEOPage('guia-requisa');
  const { currentLang } = useLanguage();
  const contentRef = useRef<HTMLDivElement>(null);
  const [document, setDocument] = useState<LegalDocument | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    fetchLegalDocument();
  }, [currentLang]);

  const fetchLegalDocument = async () => {
    try {
      setLoading(true);
      const response = await api.get('/starter/v1/legal/police_procedure');
      
      if (response.data && response.data.data) {
        setDocument(response.data.data);
        logger.info('PoliceProcedurePage', 'Documento legal cargado:', response.data.data);
      } else {
        setError(t('police.notFound'));
      }
    } catch (err) {
      logger.error('PoliceProcedurePage', 'Error al cargar documento legal:', err);
      setError(t('police.loadError'));
    } finally {
      setLoading(false);
    }
  };

  const formatDate = (dateString: string) => {
    const locale = currentLang === 'en' ? 'en-US' : 'es-ES';
    return new Date(dateString).toLocaleDateString(locale, {
      day: '2-digit',
      month: 'long',
      year: 'numeric'
    });
  };

  return (
    <div className="bg-claro min-h-screen" ref={contentRef}>
      <div className="container mx-auto" style={{ padding: `${fluidSizing.space.lg} ${fluidSizing.space.sm}` }}>
        <div className="bg-white rounded-lg shadow-md max-w-4xl mx-auto" style={{ padding: fluidSizing.space.lg }}>
          <div className="flex justify-between items-center" style={{ marginBottom: fluidSizing.space.lg, gap: fluidSizing.space.sm }}>
            <h1 className="font-bold text-primario" style={{ fontSize: fluidSizing.text['2xl'] }}>{t('police.title')}</h1>
            <LoginButton />
          </div>

          {loading ? (
            <div className="flex justify-center items-center py-12">
              <Loader size="large" text={t('police.loading')} />
            </div>
          ) : error ? (
            <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
              <p className="text-red-600 mb-4">{error}</p>
              <button
                onClick={fetchLegalDocument}
                className="bg-primario text-white px-6 py-2 rounded-md hover:bg-primario-dark transition-colors"
              >
                {t('common.retry')}
              </button>
            </div>
          ) : document ? (
            <>
              <div className="text-gray-500 flex flex-wrap" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.md, gap: fluidSizing.space.md }}>
                <p>{t('common.lastValidation', { date: formatDate(document.modified) })}</p>
              </div>
              
              <hr className="border-gray-200" style={{ marginBottom: fluidSizing.space.lg }} />

              <div 
                className="prose prose-lg max-w-none legal-content"
                dangerouslySetInnerHTML={{ __html: sanitizeRichContent(document.content) }}
              />
            </>
          ) : (
            <div className="text-center py-12 text-gray-600">
              <p>{t('police.notFound')}</p>
            </div>
          )}

          <LegalDocumentsNav />
        </div>
      </div>
    </div>
  );
};

export default PoliceProcedurePage;
