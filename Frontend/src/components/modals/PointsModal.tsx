import { FC, useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { FiUsers, FiArrowRight, FiHelpCircle, FiTrendingUp, FiGift } from 'react-icons/fi';
import { HiLightBulb } from 'react-icons/hi';
import { pointsService } from '../../services/api';
import AnimatedModal from '../ui/AnimatedModal';
import logger from '../../utils/logger';
import Loader from '../ui/Loader';
import VirtualCoinPrice from '../common/VirtualCoinPrice';
import { useAuth } from '../../contexts/AuthContext';
import AccessDeniedMessage from '../membership/AccessDeniedMessage';
import { useLanguage } from '../../contexts/LanguageContext';
import { fluidSizing } from '../../utils/fluidSizing';
import VirtualCoin from '../layout/headerComponents/VirtualCoin';

interface VirtualCoinsModalProps {
  isOpen: boolean;
  onClose: () => void;
  onOpenHelpModal?: () => void;
}

interface UserVirtualCoins {
  balance: number;
  total_earned: number;
  used: number;
  monetary_value: number;
  conversion_rate: number;
}

const VirtualCoinsModal: FC<VirtualCoinsModalProps> = ({ isOpen, onClose, onOpenHelpModal }) => {
  const { isAuthenticated } = useAuth();
  const { t } = useTranslation('pointsModal');
  const { localizedPath } = useLanguage();
  const [VirtualCoins, setVirtualCoins] = useState<UserVirtualCoins | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const handleOpenHelpModal = () => {
    onClose(); // Cerrar el modal actual
    if (onOpenHelpModal) {
      onOpenHelpModal(); // Abrir el modal de ayuda desde el componente padre
    }
  };

  useEffect(() => {
    const fetchVirtualCoins = async () => {
      if (!isOpen || !isAuthenticated) return;
      
      try {
        setLoading(true);
        const response = await pointsService.getUserPoints();
        logger.info('PointsModal', 'Datos recibidos:', response.data);
        setVirtualCoins(response.data);
        setError('');
      } catch (err) {
        logger.error('VirtualCoinsModal', 'Error al cargar datos de Virtual Coins:', err);
        setError(t('error'));
      } finally {
        setLoading(false);
      }
    };

    fetchVirtualCoins();
  }, [isOpen, isAuthenticated]);

  return (
    <AnimatedModal 
      isOpen={isOpen} 
      onClose={onClose} 
      className="max-w-md"
      title={
        <div className="flex items-center text-primario">
          <span style={{ marginRight: fluidSizing.space.xs }}>
            <VirtualCoin onClick={() => {}} size="sm" />
          </span>
          <span>{t('title')}</span>
        </div>
      }
    >
      <div>
        {!isAuthenticated ? (
          <AccessDeniedMessage
            compact
            title={t('accessDenied.title')}
            reason={t('accessDenied.reason')}
            description={t('accessDenied.description')}
            showCatalogButton={false}
            showMembershipButton={true}
            membershipButtonText={t('accessDenied.buttonText')}
            membershipButtonPath={localizedPath('/iniciar-sesion')}
            onButtonClick={onClose}
          />
        ) : loading ? (
          <div className="flex justify-center items-center" style={{ padding: fluidSizing.space.xl }}>
            <Loader text={t('loading')} size="large" />
          </div>
        ) : error ? (
          <div 
            className="bg-red-50 rounded-lg text-center"
            style={{ padding: fluidSizing.space.md }}
          >
            <p className="text-red-600" style={{ fontSize: fluidSizing.text.sm }}>{error}</p>
            <button 
              onClick={() => window.location.reload()} 
              className="bg-primario text-white rounded-lg hover:bg-hover transition-colors"
              style={{ marginTop: fluidSizing.space.sm, padding: `${fluidSizing.space.xs} ${fluidSizing.space.md}`, fontSize: fluidSizing.text.sm }}
            >
              {t('retry')}
            </button>
          </div>
        ) : VirtualCoins ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
            {/* Balance principal destacado */}
            <div 
              className="bg-gradient-to-br from-primario/10 to-primario/5 border border-primario/20 rounded-xl"
              style={{ padding: fluidSizing.space.md }}
            >
              <div className="flex items-center justify-center" style={{ gap: fluidSizing.space.xs, marginBottom: fluidSizing.space.sm }}>
                <FiGift className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <p className="text-gray-600" style={{ fontSize: fluidSizing.text.xs }}>{t('balance.label')}</p>
              </div>
              <div className="flex justify-center">
                <VirtualCoinPrice amount={VirtualCoins.balance} size="lg" showLabel={false} />
              </div>
              <p className="text-gray-500 text-center" style={{ fontSize: fluidSizing.text.xs, marginTop: fluidSizing.space.sm }}>
                {t('balance.usage')}
              </p>
            </div>

            {/* Stats en grid */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: fluidSizing.space.sm }}>
              <div 
                className="bg-blue-50 rounded-lg border border-blue-100 flex flex-col items-center"
                style={{ padding: fluidSizing.space.sm }}
              >
                <FiTrendingUp className="text-blue-500" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm, marginBottom: fluidSizing.space.xs }} />
                <p className="text-gray-500" style={{ fontSize: fluidSizing.text['2xs'] }}>{t('stats.earned')}</p>
                <VirtualCoinPrice amount={VirtualCoins.total_earned} size="xs" showLabel={false} />
              </div>
              
              <div 
                className="bg-purple-50 rounded-lg border border-purple-100 flex flex-col items-center"
                style={{ padding: fluidSizing.space.sm }}
              >
                <FiUsers className="text-purple-500" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm, marginBottom: fluidSizing.space.xs }} />
                <p className="text-gray-500" style={{ fontSize: fluidSizing.text['2xs'] }}>{t('stats.used')}</p>
                <VirtualCoinPrice amount={VirtualCoins.used} size="xs" showLabel={false} />
              </div>
              
              <div 
                className="bg-yellow-50 rounded-lg border border-yellow-100 flex flex-col items-center"
                style={{ padding: fluidSizing.space.sm }}
              >
                <span style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}>💰</span>
                <p className="text-gray-500" style={{ fontSize: fluidSizing.text['2xs'] }}>{t('stats.value')}</p>
                <p className="font-bold text-yellow-600" style={{ fontSize: fluidSizing.text.sm }}>
                  ${VirtualCoins.conversion_rate?.toFixed(2) || '0'}
                </p>
              </div>
            </div>

            {/* Tip informativo */}
            <div 
              className="bg-primario/10 border border-primario/20 rounded-lg"
              style={{ padding: fluidSizing.space.sm }}
            >
              <p 
                className="text-primario flex items-start"
                style={{ fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
              >
                <HiLightBulb 
                  className="flex-shrink-0" 
                  style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm, marginTop: '2px' }} 
                />
                <span>{t('tip')}</span>
              </p>
            </div>
            
            {/* Botones de acción */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm, marginBottom: fluidSizing.space.sm }}>
              <Link 
                to={localizedPath('/invitados')} 
                className="flex items-center justify-center w-full bg-primario text-white rounded-lg hover:bg-oscuro hover:text-claro transition-colors font-medium"
                style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
                onClick={onClose}
              >
                {t('buttons.viewReferrals')}
                <FiArrowRight style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
              </Link>
              
              <button
                onClick={handleOpenHelpModal}
                className="flex items-center justify-center w-full bg-white border border-primario text-primario rounded-lg hover:bg-primario/5 transition-colors"
                style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
              >
                <FiHelpCircle style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                {t('buttons.howItWorks')}
              </button>
            </div>
          </div>
        ) : (
          <div className="text-center" style={{ padding: fluidSizing.space.xl }}>
            <p style={{ fontSize: fluidSizing.text.sm }}>{t('noData')}</p>
          </div>
        )}
      </div>
    </AnimatedModal>
  );
};

export default VirtualCoinsModal;
