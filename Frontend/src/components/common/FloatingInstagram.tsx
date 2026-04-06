import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { FaInstagram } from 'react-icons/fa';
import { SOCIAL_MEDIA } from '../../config';
import { fluidSizing } from '../../utils/fluidSizing';
import { useSocialNetworks } from '../../hooks/useSocialNetworks';
import FloatingButton from './FloatingButton';

/**
 * Componente de icono flotante de Instagram
 * Usa FloatingButton como base reutilizable.
 * Consume la URL de Instagram desde el endpoint de banners.
 */
interface FloatingInstagramProps {
  ready?: boolean;
}

const FloatingInstagram = ({ ready = false }: FloatingInstagramProps) => {
  const { t } = useTranslation('header');
  const { getSocialNetwork } = useSocialNetworks();

  const instagramUrl = useMemo(() => {
    const instagramNetwork = getSocialNetwork('Instagram');
    return instagramNetwork?.url || SOCIAL_MEDIA.instagram;
  }, [getSocialNetwork]);

  return (
    <FloatingButton
      href={instagramUrl}
      icon={<FaInstagram style={{ width: '100%', height: '100%' }} />}
      tooltip={
        <>
          {t('instagramTooltip1')}
          <br />
          {t('instagramTooltip2')}
        </>
      }
      ariaLabel={t('instagramAria')}
      visible={ready}
      entryDelay={500}
      position={{
        bottom: `max(${fluidSizing.space.lg}, calc(${fluidSizing.space.lg} + env(safe-area-inset-bottom, 0px)))`,
        right: `max(${fluidSizing.space.lg}, calc(${fluidSizing.space.lg} + env(safe-area-inset-right, 0px)))`,
      }}
    />
  );
};

export default FloatingInstagram;
