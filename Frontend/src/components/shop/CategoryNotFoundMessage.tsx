import React from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useLanguage } from '../../contexts/LanguageContext';

/**
 * Componente para mostrar un mensaje cuando no se encuentra la categoría
 */
const CategoryNotFoundMessage: React.FC = () => {
  const { t } = useTranslation('shopPage');
  const { localizedPath } = useLanguage();

  return (
    <div className="py-8 text-center">
      <h2 className="text-2xl font-bold mb-4 text-gray-800">{t('categoryNotFound.title')}</h2>
      <p className="mb-6 text-gray-600">
        {t('categoryNotFound.description')}
      </p>
      <Link 
        to={localizedPath('/catalogo')}
        className="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primario hover:bg-hover focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario"
      >
        {t('categoryNotFound.backLink')}
      </Link>
    </div>
  );
};

export default CategoryNotFoundMessage;
