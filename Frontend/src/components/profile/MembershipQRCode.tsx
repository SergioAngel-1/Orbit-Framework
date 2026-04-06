import { useState, useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { QRCodeSVG } from 'qrcode.react';
import { api } from '../../services/apiConfig';
import { useLanguage } from '../../contexts/LanguageContext';
import { useAuth } from '../../contexts/AuthContext';
import { useMembership } from '../../contexts/MembershipContext';
import AnimatedModal from '../ui/AnimatedModal';
import Loader from '../ui/Loader';
import { FiShield } from 'react-icons/fi';
import { fluidSizing } from '../../utils/fluidSizing';

interface MembershipQRCodeProps {
  /** QR foreground color */
  fgColor?: string;
  /** Show hint text below QR */
  showHint?: boolean;
  /** Show inactive placeholder when membership is not active */
  showInactive?: boolean;
  /** Custom class for the outer wrapper */
  className?: string;
  /** Custom inline style for the outer wrapper */
  style?: React.CSSProperties;
  /** Size CSS for the QR container (default: fluid clamp) */
  size?: string;
}

const MembershipQRCode = ({
  fgColor = '#6A0F49',
  showHint = true,
  showInactive = true,
  className = '',
  style,
  size,
}: MembershipQRCodeProps) => {
  const { t } = useTranslation('digitalCardSection');
  const { user } = useAuth();
  const { isActive } = useMembership();
  const { localizedPath, currentLang } = useLanguage();

  const [verifyToken, setVerifyToken] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const fetchIdRef = useRef(0);

  useEffect(() => {
    if (!user?.id || !isActive) return;
    const id = ++fetchIdRef.current;
    setVerifyToken(null);
    api
      .get('/starter/v1/membership/verify-token')
      .then((res) => {
        if (id === fetchIdRef.current && res.data?.success) {
          setVerifyToken(res.data.data.token);
        }
      })
      .catch(() => {});
  }, [user?.id, isActive]);

  const verifyUrl = verifyToken
    ? `${window.location.origin}${localizedPath(
        currentLang === 'en'
          ? `/verify-member/${verifyToken}`
          : `/verificar-socio/${verifyToken}`
      )}`
    : '';

  const containerSize =
    size || 'clamp(76px, 76px + 44 * ((100vw - 320px) / 1600), 120px)';

  if (isActive) {
    return (
      <>
        <div
          className={`flex-shrink-0 flex flex-col items-center ${className}`}
          style={{ gap: '4px', ...style }}
          onClick={() => verifyUrl && setIsModalOpen(true)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === 'Enter' && verifyUrl) setIsModalOpen(true); }}
        >
          <div
            className="bg-white rounded-md flex items-center justify-center"
            style={{
              padding: '6px',
              width: containerSize,
              height: containerSize,
              cursor: verifyUrl ? 'pointer' : 'default',
            }}
          >
            {verifyUrl ? (
              <QRCodeSVG
                value={verifyUrl}
                size={200}
                level="M"
                bgColor="#FFFFFF"
                fgColor={fgColor}
                style={{ width: '100%', height: '100%' }}
              />
            ) : (
              <Loader size="small" text="" />
            )}
          </div>
          {showHint && (
            <p
              className="text-white/50 text-center"
              style={{ fontSize: '9px', lineHeight: 1.2, maxWidth: '90px' }}
            >
              {t('info.qrHint')}
            </p>
          )}
        </div>

        <AnimatedModal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          title={t('info.qrModalTitle')}
          maxWidth="max-w-xs"
        >
          <div className="flex flex-col items-center py-4 px-2">
            <div
              className="bg-white rounded-lg flex items-center justify-center p-4 mb-4"
              style={{ width: '240px', height: '240px' }}
            >
              {verifyUrl && (
                <QRCodeSVG
                  value={verifyUrl}
                  size={208}
                  level="H"
                  bgColor="#FFFFFF"
                  fgColor={fgColor}
                  style={{ width: '100%', height: '100%' }}
                />
              )}
            </div>
            <p className="text-texto text-center" style={{ fontSize: fluidSizing.text.sm }}>
              {t('info.qrModalDesc')}
            </p>
          </div>
        </AnimatedModal>
      </>
    );
  }

  if (showInactive) {
    return (
      <div
        className={`flex-shrink-0 flex flex-col items-center justify-center rounded-md ${className}`}
        style={{
          background: 'rgba(255,255,255,0.1)',
          width: containerSize,
          padding: fluidSizing.space.sm,
          ...style,
        }}
      >
        <FiShield
          className="text-white/30"
          style={{
            width: fluidSizing.size.iconMd,
            height: fluidSizing.size.iconMd,
            marginBottom: '4px',
          }}
        />
        <p
          className="text-white/40 text-center"
          style={{ fontSize: '8px', lineHeight: 1.2 }}
        >
          {t('info.qrInactive')}
        </p>
      </div>
    );
  }

  return null;
};

export default MembershipQRCode;
