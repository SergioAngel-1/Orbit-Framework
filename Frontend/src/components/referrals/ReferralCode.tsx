import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { FiCopy, FiArrowRight } from 'react-icons/fi';
import { FaFacebook, FaInstagram, FaWhatsapp, FaTelegram, FaTwitter, FaYoutube, FaTiktok, FaPinterest, FaLinkedin, FaGlobe, FaUserFriends } from 'react-icons/fa';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../contexts/LanguageContext';
import alertService from '../../services/alertService';
import logger from '../../utils/logger';
import { useSocialNetworks } from '../../hooks/useSocialNetworks';
import { pointsService } from '../../services/api';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';

interface ReferralInfo {
  code: string;
  url: string;
}

interface MyReferrerInfo {
  id: number;
  name: string;
  status: string;
}

interface ReferralCodeProps {
  referralInfo: ReferralInfo | null;
  canUseReferrals: boolean;
  referralsEnabled: boolean;
}

/**
 * Componente para mostrar el código de referido y opciones para compartir
 */
const ReferralCode: React.FC<ReferralCodeProps> = ({ 
  referralInfo, 
  canUseReferrals,
  referralsEnabled 
}) => {
  const { localizedPath } = useLanguage();
  const { t } = useTranslation('referralComponents');
  // Estado para el referidor del usuario actual
  const [myReferrer, setMyReferrer] = useState<MyReferrerInfo | null>(null);
  const [loadingReferrer, setLoadingReferrer] = useState(true);

  // Obtener redes sociales desde el hook
  const { socialNetworks, loading: loadingSocial, error: errorSocial } = useSocialNetworks();
  
  // Obtener información del referidor al montar el componente
  useEffect(() => {
    if (canUseReferrals) {
      pointsService.getMyReferrer()
        .then(response => {
          if (response.has_referrer && response.referrer) {
            setMyReferrer(response.referrer);
            logger.info('ReferralCode', 'Referidor encontrado:', response.referrer.name);
          } else {
            setMyReferrer(null);
            logger.info('ReferralCode', 'El usuario no tiene referidor');
          }
        })
        .catch(error => {
          logger.error('ReferralCode', 'Error al obtener referidor:', error);
          setMyReferrer(null);
        })
        .finally(() => {
          setLoadingReferrer(false);
        });
    } else {
      setLoadingReferrer(false);
    }
  }, [canUseReferrals]);
  
  // Debug logs
  logger.info('ReferralCode', `Redes sociales: ${socialNetworks.length} encontradas, Loading: ${loadingSocial}, Error: ${errorSocial}`);
  // Función para copiar al portapapeles
  const copyToClipboard = (text: string, message: string) => {
    navigator.clipboard.writeText(text)
      .then(() => {
        alertService.success(message);
      })
      .catch(err => {
        logger.error('ReferralCode', 'Error al copiar:', err);
        alertService.error(t('code.copyError'));
      });
  };

  // Función para compartir en redes sociales
  const shareViaNetwork = (network: string) => {
    if (!referralInfo) return;

    const message = t('code.shareMessage');
    const url = referralInfo.url;

    let shareUrl = '';

    switch (network.toLowerCase()) {
      case 'whatsapp':
        shareUrl = `https://wa.me/?text=${encodeURIComponent(message + ' ' + url)}`;
        break;
      case 'facebook':
        shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}&quote=${encodeURIComponent(message)}`;
        break;
      case 'twitter':
        shareUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(message)}&url=${encodeURIComponent(url)}`;
        break;
      case 'telegram':
        shareUrl = `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(message)}`;
        break;
      case 'pinterest':
        shareUrl = `https://pinterest.com/pin/create/button/?url=${encodeURIComponent(url)}&description=${encodeURIComponent(message)}`;
        break;
      default:
        shareUrl = url;
    }

    window.open(shareUrl, '_blank');
  };

  // Obtener componente de icono de red social (igual que en Footer)
  const getSocialIconComponent = (network: { name: string; icon?: string }) => {
    // Intentar primero con el campo icon si existe
    if (network.icon) {
      const iconKey = network.icon.toLowerCase().trim();
      if (iconKey === 'facebook' || iconKey === 'fb') return FaFacebook;
      if (iconKey === 'instagram' || iconKey === 'ig') return FaInstagram;
      if (iconKey === 'whatsapp' || iconKey === 'wa') return FaWhatsapp;
      if (iconKey === 'telegram') return FaTelegram;
      if (iconKey === 'twitter') return FaTwitter;
      if (iconKey === 'youtube' || iconKey === 'yt') return FaYoutube;
      if (iconKey === 'tiktok') return FaTiktok;
      if (iconKey === 'pinterest') return FaPinterest;
    }
    
    // Intentar con el nombre de la red social
    const nameKey = network.name.toLowerCase().trim();
    
    // Buscar coincidencias parciales
    if (nameKey.includes('facebook') || nameKey.includes('fb')) return FaFacebook;
    if (nameKey.includes('instagram') || nameKey.includes('ig')) return FaInstagram;
    if (nameKey.includes('whatsapp') || nameKey.includes('wa')) return FaWhatsapp;
    if (nameKey.includes('telegram')) return FaTelegram;
    if (nameKey.includes('tiktok')) return FaTiktok;
    if (nameKey.includes('twitter') || nameKey.includes('x.com')) return FaTwitter;
    if (nameKey.includes('youtube') || nameKey.includes('yt')) return FaYoutube;
    if (nameKey.includes('linkedin')) return FaLinkedin;
    if (nameKey.includes('pinterest')) return FaPinterest;
    
    // Fallback
    return FaGlobe;
  };

  // Obtener color de la red social
  const getSocialColor = (socialIcon: string): string => {
    switch (socialIcon.toLowerCase()) {
      case 'facebook':
        return 'bg-[#3b5998] hover:bg-[#2d4373]';
      case 'instagram':
        return 'bg-[#e1306c] hover:bg-[#c13584]';
      case 'whatsapp':
        return 'bg-[#25D366] hover:bg-[#128C7E]';
      case 'telegram':
        return 'bg-[#0088cc] hover:bg-[#0077b5]';
      case 'twitter':
        return 'bg-[#1DA1F2] hover:bg-[#0c85d0]';
      case 'youtube':
        return 'bg-[#FF0000] hover:bg-[#cc0000]';
      case 'tiktok':
        return 'bg-[#000000] hover:bg-[#333333]';
      case 'pinterest':
        return 'bg-[#E60023] hover:bg-[#bd001f]';
      case 'linkedin':
        return 'bg-[#0077B5] hover:bg-[#005885]';
      default:
        return 'bg-[#3b5998] hover:bg-[#2d4373]';
    }
  };

  // Filtrar solo las redes que tienen nombre (todas son compartibles)
  const shareableNetworks = socialNetworks.filter(network => 
    network.name && network.name.trim() !== ''
  );
  
  logger.info('ReferralCode', `Redes compartibles: ${shareableNetworks.length}`);

  if (!canUseReferrals) {
    return (
      <CollapsibleSection
        title={t('code.title')}
        variant="soft"
        collapsible={false}
        showCollapseButton={false}
        className="mb-4"
      >
        <p className="text-texto text-center" style={{ paddingTop: fluidSizing.space.lg, paddingBottom: fluidSizing.space.lg }}>
          {t('code.unavailable')}
        </p>
      </CollapsibleSection>
    );
  }

  return (
    <>
      <CollapsibleSection
        title={t('code.title')}
        variant="soft"
        collapsible={false}
        showCollapseButton={false}
        className="mb-4"
      >
        {referralInfo && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
            {/* Código único */}
            <div 
              className="bg-secundario/20 rounded-lg border border-secundario/30"
              style={{ padding: fluidSizing.space.md }}
            >
              <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('code.yourCode')}</p>
              <div className="flex items-center justify-between">
                <p className="font-mono font-bold text-primario tracking-wider" style={{ fontSize: fluidSizing.text.xl }}>{referralInfo.code}</p>
                <button
                  onClick={() => copyToClipboard(referralInfo.code, t('code.codeCopied'))}
                  className="bg-primario text-white rounded-md hover:bg-hover transition-colors"
                  style={{ padding: fluidSizing.space.sm }}
                  aria-label={t('code.copyCode')}
                >
                  <FiCopy style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                </button>
              </div>
            </div>

            {/* Enlace de referido */}
            <div>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}>{t('code.yourLink')}</p>
              <div className="flex items-stretch">
                <input
                  type="text"
                  value={referralInfo.url}
                  readOnly
                  className="flex-1 border border-secundario/50 border-r-0 rounded-l-md rounded-r-none focus:outline-none focus:ring-1 focus:ring-primario text-texto"
                  style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.xs }}
                />
                <button
                  onClick={() => copyToClipboard(referralInfo.url, t('code.linkCopied'))}
                  className="bg-primario text-white rounded-l-none rounded-r-md hover:bg-hover transition-colors flex-shrink-0"
                  style={{ paddingLeft: fluidSizing.space.sm, paddingRight: fluidSizing.space.sm }}
                  aria-label={t('code.copyLink')}
                >
                  <FiCopy style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                </button>
              </div>
            </div>

            {/* Compartir en redes */}
            <div>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.sm }}>{t('code.shareWith')}</p>
              {loadingSocial ? (
                <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>{t('code.loadingSocial')}</p>
              ) : errorSocial ? (
                <p className="text-red-500" style={{ fontSize: fluidSizing.text.xs }}>{t('code.errorLoadingSocial', { error: errorSocial })}</p>
              ) : shareableNetworks.length > 0 ? (
                <div className="flex flex-wrap" style={{ gap: fluidSizing.space.sm }}>
                  {shareableNetworks.map((network, index) => {
                    const networkName = (network.name || '').toLowerCase().trim();
                    const IconComponent = getSocialIconComponent(network);
                    return (
                      <button
                        key={`${network.name}-${index}`}
                        onClick={() => {
                          if (referralInfo) {
                            shareViaNetwork(networkName);
                            logger.info('ReferralCode', `Compartido en ${network.name}`);
                          }
                        }}
                        className={`text-white rounded-md transition-colors flex items-center justify-center ${getSocialColor(networkName)}`}
                        style={{ padding: fluidSizing.space.sm }}
                        aria-label={t('code.shareOnNetwork', { network: network.name })}
                        title={t('code.shareOnNetwork', { network: network.name })}
                      >
                        <IconComponent style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
                      </button>
                    );
                  })}
                </div>
              ) : (
                <p className="text-texto/70" style={{ fontSize: fluidSizing.text.xs }}>{t('code.noSocialNetworks')}</p>
              )}
            </div>
            
            {/* Mi referidor (solo si tiene) */}
            {!loadingReferrer && myReferrer && (
              <div 
                className="bg-acento/20 rounded-lg border border-acento/30"
                style={{ padding: fluidSizing.space.md }}
              >
                <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                  <FaUserFriends className="text-primario" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
                  <div>
                    <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>{t('code.invitedBy')}</p>
                    <p className="font-semibold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>{myReferrer.name}</p>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}
      </CollapsibleSection>

      {/* Enlace a la política de referidos */}
      {referralsEnabled && (
        <div 
          className="bg-white rounded-lg border border-secundario text-center"
          style={{ padding: fluidSizing.space.md, marginTop: fluidSizing.space.md }}
        >
          <Link 
            to={localizedPath('/politica-invitados')} 
            className="text-primario hover:text-hover transition-colors inline-flex items-center font-medium"
            style={{ fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
          >
            {t('code.viewPolicy')}
            <FiArrowRight style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          </Link>
        </div>
      )}
    </>
  );
};

export default ReferralCode;
