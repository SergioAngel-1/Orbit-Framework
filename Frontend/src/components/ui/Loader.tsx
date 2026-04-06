import { useTranslation } from 'react-i18next';

interface LoaderProps {
  text?: string;
  size?: 'xsmall' | 'small' | 'medium' | 'large';
}

const Loader: React.FC<LoaderProps> = ({ text, size = 'medium' }) => {
  const { t } = useTranslation('loader');
  const displayText = text ?? t('defaultText');
  const sizeClasses = {
    xsmall: 'h-5 w-5',
    small: 'h-8 w-8',
    medium: 'h-12 w-12',
    large: 'h-16 w-16'
  };

  return (
    <div className="flex justify-center items-center">
      <img 
        src="/assets/images/flores_loader.svg" 
        alt={t('alt')} 
        className={`animate-spin ${sizeClasses[size]}`}
      />
      {displayText && <span className="ml-3 text-gray-700 font-medium">{displayText}</span>}
    </div>
  );
};

export default Loader;
