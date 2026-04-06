import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { pointsService } from '../../../services/api';
import logger from '../../../utils/logger';
import Loader from '../../ui/Loader';
import CollapsibleSection from '../../common/CollapsibleSection';
import { FiArrowRight, FiGift, FiCopy, FiCheck, FiUsers } from 'react-icons/fi';
import { FaCoins, FaUserFriends } from 'react-icons/fa';
import { useLanguage } from '../../../contexts/LanguageContext';
import { fluidSizing } from '../../../utils/fluidSizing';

interface ReferralStats {
  total_referrals: number;
  indirect_referrals: number;
  total_points_generated: number;
  referral_code: string;
}

interface MyReferrerInfo {
  id: number;
  name: string;
  status: string;
}

interface ReferralsSectionProps {
  onClose?: () => void;
}

const ReferralsSection = ({ onClose }: ReferralsSectionProps) => {
  const { t } = useTranslation('referralsSection');
  const { localizedPath } = useLanguage();
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState<ReferralStats | null>(null);
  const [copied, setCopied] = useState(false);
  const [myReferrer, setMyReferrer] = useState<MyReferrerInfo | null>(null);
  
  useEffect(() => {
    const loadReferralStats = async () => {
      try {
        setLoading(true);
        const statsResponse = await pointsService.getReferralStats();
        const codeResponse = await pointsService.getReferralCode();
        
        if (statsResponse?.data && codeResponse?.data?.code) {
          setStats({
            total_referrals: statsResponse.data.total_referrals || 0,
            indirect_referrals: statsResponse.data.indirect_referrals || 0,
            total_points_generated: statsResponse.data.total_points_generated || 0,
            referral_code: codeResponse.data.code
          });
        }
        
        const referrerResponse = await pointsService.getMyReferrer();
        if (referrerResponse?.has_referrer && referrerResponse?.referrer) {
          setMyReferrer(referrerResponse.referrer);
          logger.info('ReferralsSection', 'Referidor encontrado:', referrerResponse.referrer.name);
        }
      } catch (error) {
        logger.error('ReferralsSection', 'Error al cargar datos de invitados:', error);
      } finally {
        setLoading(false);
      }
    };
    
    loadReferralStats();
  }, []);
  
  const copyReferralLink = () => {
    if (stats?.referral_code) {
      const referralUrl = `${window.location.origin}${localizedPath('/registrarse')}?ref=${stats.referral_code}`;
      navigator.clipboard.writeText(referralUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };
  
  return (
    <CollapsibleSection
      title={t('title')}
      icon={FiGift}
      collapsible={false}
      showCollapseButton={false}
    >
      {loading ? (
        <div className="flex justify-center items-center" style={{ padding: fluidSizing.space.xl }}>
          <Loader size="medium" />
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
        <p 
          className="text-texto"
          style={{ fontSize: fluidSizing.text.sm }}
        >
          {t('description')}
        </p>
        
        {/* Código de invitación */}
        <div 
          className="bg-primario/10 border border-primario/20 rounded-lg"
          style={{ padding: fluidSizing.space.sm }}
        >
          <p 
            className="text-primario/70"
            style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}
          >
            {t('code.label')}
          </p>
          <button 
            onClick={copyReferralLink}
            className={`w-full flex items-center justify-center rounded-lg font-medium transition-all duration-300 ${
              copied 
                ? 'bg-green-50 text-green-600 border border-green-200' 
                : 'bg-white text-primario border border-primario/30 hover:shadow-md hover:border-primario'
            }`}
            style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
          >
            {copied ? (
              <>
                <FiCheck style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span>{t('code.copied')}</span>
              </>
            ) : (
              <>
                <FiCopy style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span>{stats?.referral_code || 'REF123'}</span>
              </>
            )}
          </button>
        </div>
        
        {/* Estadísticas */}
        <div 
          className="grid grid-cols-2"
          style={{ gap: fluidSizing.space.sm }}
        >
          <div 
            className="bg-secundario/30 rounded-lg text-center"
            style={{ padding: fluidSizing.space.sm }}
          >
            <div 
              className="flex items-center justify-center text-texto"
              style={{ gap: fluidSizing.space.xs, marginBottom: fluidSizing.space.xs }}
            >
              <FiUsers style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
              <span style={{ fontSize: fluidSizing.text.xs }}>{t('stats.referrals')}</span>
            </div>
            <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl }}>
              {stats?.total_referrals || 0}
            </p>
          </div>
          
          <div 
            className="bg-secundario/30 rounded-lg text-center"
            style={{ padding: fluidSizing.space.sm }}
          >
            <div 
              className="flex items-center justify-center text-texto"
              style={{ gap: fluidSizing.space.xs, marginBottom: fluidSizing.space.xs }}
            >
              <FaCoins style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
              <span style={{ fontSize: fluidSizing.text.xs }}>{t('stats.fcEarned')}</span>
            </div>
            <p className="font-bold text-primario" style={{ fontSize: fluidSizing.text.xl }}>
              {stats?.total_points_generated || 0}
            </p>
          </div>
        </div>
        
        {/* Quien me invitó */}
        {myReferrer && (
          <div 
            className="bg-gray-50 border border-gray-200 rounded-lg flex items-center"
            style={{ padding: fluidSizing.space.sm, gap: fluidSizing.space.sm }}
          >
            <div 
              className="bg-primario/20 rounded-full flex items-center justify-center flex-shrink-0"
              style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
            >
              <FaUserFriends 
                className="text-primario" 
                style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} 
              />
            </div>
            <div>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                {t('referrer.label')}
              </p>
              <p className="font-semibold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
                {myReferrer.name}
              </p>
            </div>
          </div>
        )}
        
        {/* Enlace a página completa */}
        <Link 
          to={localizedPath('/invitados')} 
          className="flex items-center justify-center bg-primario text-white hover:bg-hover hover:!text-white rounded-lg transition-all duration-300 font-medium group"
          style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
          onClick={onClose}
        >
          <span>{t('viewProgram')}</span>
          <FiArrowRight 
            className="group-hover:translate-x-1 transition-transform" 
            style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }}
          />
        </Link>
      </div>
      )}
    </CollapsibleSection>
  );
};

export default ReferralsSection;
