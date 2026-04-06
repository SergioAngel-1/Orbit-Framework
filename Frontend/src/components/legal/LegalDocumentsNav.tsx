import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage, getLangFromPath } from '../../contexts/LanguageContext';

const LegalDocumentsNav = () => {
  const { t } = useTranslation('legalPages');
  const location = useLocation();
  const { localizedPath } = useLanguage();

  const legalDocuments = [
    { path: '/terminos', label: t('nav.terms') },
    { path: '/privacidad', label: t('nav.privacy') },
    { path: '/politica-invitados', label: t('nav.referral') },
    { path: '/guia-requisa', label: t('nav.police') },
    { path: '/marco-legal', label: t('nav.protective') },
  ];

  const { pathWithoutLang } = getLangFromPath(location.pathname);
  const otherDocuments = legalDocuments.filter(
    (doc) => doc.path !== pathWithoutLang
  );

  if (otherDocuments.length === 0) return null;

  return (
    <div className="mt-10 pt-6">
      <hr className="mb-6 border-gray-200" />
      <h3 className="text-lg font-semibold text-primario mb-4 flex items-center gap-2">
        <svg className="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        {t('nav.title')}
      </h3>
      <div className="flex flex-col sm:flex-row sm:flex-wrap gap-3">
        {otherDocuments.map((doc) => (
          <Link
            key={doc.path}
            to={localizedPath(doc.path)}
            className="inline-flex items-center px-4 py-2 bg-gray-50 hover:bg-primario/10 border border-gray-200 hover:border-primario/30 text-gray-700 hover:text-primario rounded-lg transition-colors duration-200 text-sm"
            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
          >
            {doc.label}
          </Link>
        ))}
      </div>
    </div>
  );
};

export default LegalDocumentsNav;
