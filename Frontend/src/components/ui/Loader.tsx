import { useTranslation } from 'react-i18next';
import { useSiteConfig } from '../../contexts/SiteConfigContext';

interface LoaderProps {
  text?: string;
  size?: 'xsmall' | 'small' | 'medium' | 'large';
}

const sizeClasses = {
  xsmall: 'h-5 w-5',
  small: 'h-8 w-8',
  medium: 'h-12 w-12',
  large: 'h-16 w-16'
};

const Loader: React.FC<LoaderProps> = ({ text, size = 'medium' }) => {
  const { t } = useTranslation('loader');
  const { config } = useSiteConfig();
  const loaderUrl = config.branding.branding_loader;
  const displayText = text ?? t('defaultText');

  return (
    <div className="flex justify-center items-center">
      {loaderUrl ? (
        <img 
          src={loaderUrl}
          alt={t('alt')}
          className={`animate-spin ${sizeClasses[size]}`}
        />
      ) : (
        <div 
          className={`animate-spin ${sizeClasses[size]} rounded-full border-4 border-gray-200`}
          style={{ borderTopColor: 'var(--color-primary)' }}
          role="status"
          aria-label={t('alt')}
        />
      )}
      {displayText && <span className="ml-3 text-gray-700 font-medium">{displayText}</span>}
    </div>
  );
};

export default Loader;
