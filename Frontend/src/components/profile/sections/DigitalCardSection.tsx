import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../contexts/AuthContext';
import { useMembership } from '../../../contexts/MembershipContext';
import Loader from '../../ui/Loader';
import CollapsibleSection from '../../common/CollapsibleSection';
import { fluidSizing } from '../../../utils/fluidSizing';
import { FiUser, FiShield, FiCreditCard, FiCamera } from 'react-icons/fi';
import { useLanguage } from '../../../contexts/LanguageContext';
import MembershipQRCode from '../MembershipQRCode';

interface DigitalCardSectionProps {
  onNavigateToProfile?: () => void;
}

const DigitalCardSection = ({ onNavigateToProfile }: DigitalCardSectionProps) => {
  const { t } = useTranslation('digitalCardSection');
  const { t: tLegal } = useTranslation('legalPages');
  const { user } = useAuth();
  const {
    membership,
    isActive,
    loading: membershipLoading,
  } = useMembership();
  const { localizedPath, currentLang } = useLanguage();

  // Número de socio formateado
  const memberNumber = user?.id ? `FI-${String(user.id).padStart(5, '0')}` : '—';

  // Fecha de adhesión formateada
  const startDate = membership?.start_date
    ? new Date(membership.start_date).toLocaleDateString(currentLang === 'en' ? 'en-US' : 'es-CO', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      })
    : '—';

  // Nombre del socio
  const displayName = user?.lastName || user?.name || '—';


  return (
    <CollapsibleSection
      title={t('title')}
      icon={FiCreditCard}
      collapsible={false}
      showCollapseButton={false}
    >
      {membershipLoading ? (
        <div className="flex justify-center items-center" style={{ padding: fluidSizing.space.xl }}>
          <Loader size="medium" />
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>

          {/* === TARJETA DE CARNÉ DIGITAL === */}
          <div
            className="overflow-hidden shadow-lg"
            style={{
              borderRadius: fluidSizing.modal.borderRadius,
              border: '2px solid transparent',
              backgroundClip: 'padding-box',
              position: 'relative',
            }}
          >
            {/* Borde degradado simulado */}
            <div
              className="absolute inset-0 pointer-events-none"
              style={{
                borderRadius: fluidSizing.modal.borderRadius,
                padding: '2px',
                background: 'linear-gradient(135deg, #B91E59, #8A1443, #6A0F49)',
                WebkitMask: 'linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0)',
                WebkitMaskComposite: 'xor',
                maskComposite: 'exclude',
              }}
            />

            {/* Header blanco con logo */}
            <div
              className="flex items-center justify-between bg-white"
              style={{ padding: `${fluidSizing.space.sm} ${fluidSizing.space.lg}` }}
            >
              <img
                src="/assets/images/logo-flores.png"
                alt="Logo"
                style={{ height: fluidSizing.size.iconLg }}
              />
              <p
                className="text-primario/60 uppercase tracking-widest font-medium"
                style={{ fontSize: fluidSizing.text['2xs'] }}
              >
                {t('card.clubLabel')}
              </p>
            </div>

            {/* Cuerpo fucsia */}
            <div
              className="relative overflow-hidden"
              style={{
                background: 'linear-gradient(135deg, #B91E59 0%, #8A1443 50%, #6A0F49 100%)',
                padding: `${fluidSizing.space.md} ${fluidSizing.space.lg}`,
              }}
            >
              {/* Flor decorativa de fondo */}
              <svg
                className="absolute pointer-events-none"
                style={{
                  top: '50%',
                  left: '50%',
                  transform: 'translate(-50%, -50%)',
                  width: '65%',
                  height: '65%',
                  opacity: 0.07,
                }}
                viewBox="0 0 200 200"
                fill="none"
                xmlns="http://www.w3.org/2000/svg"
              >
                <defs>
                  <mask id="flowerMask">
                    <rect width="200" height="200" fill="white" />
                    <circle cx="100" cy="100" r="28" fill="black" />
                  </mask>
                </defs>
                <g mask="url(#flowerMask)" fill="white" fillOpacity="0.9">
                  <ellipse cx="100" cy="58" rx="28" ry="42" />
                  <ellipse cx="100" cy="142" rx="28" ry="42" />
                  <ellipse cx="58" cy="100" rx="42" ry="28" />
                  <ellipse cx="142" cy="100" rx="42" ry="28" />
                  <ellipse cx="72" cy="72" rx="28" ry="42" transform="rotate(-45 72 72)" />
                  <ellipse cx="128" cy="128" rx="28" ry="42" transform="rotate(-45 128 128)" />
                  <ellipse cx="128" cy="72" rx="28" ry="42" transform="rotate(45 128 72)" />
                  <ellipse cx="72" cy="128" rx="28" ry="42" transform="rotate(45 72 128)" />
                </g>
                <circle cx="100" cy="100" r="28" fill="white" fillOpacity="0.5" />
                <circle cx="100" cy="100" r="12" fill="white" fillOpacity="0.9" />
              </svg>
              {/* Avatar + Info del socio + Badge */}
              <div className="flex items-center" style={{ gap: fluidSizing.space.sm, marginBottom: fluidSizing.space.sm }}>
                {/* Avatar */}
                <div className="flex flex-col items-center flex-shrink-0">
                  <div
                    className="relative flex-shrink-0 rounded-full overflow-hidden border-2 border-white/40 flex items-center justify-center"
                    style={{
                      width: 'clamp(2.5rem, 2.5rem + 1 * ((100vw - 20rem) / 100), 4rem)',
                      height: 'clamp(2.5rem, 2.5rem + 1 * ((100vw - 20rem) / 100), 4rem)',
                      background: 'rgba(255,255,255,0.15)',
                    }}
                  >
                    {user?.avatar ? (
                      <img
                        src={user.avatar}
                        alt={displayName}
                        className="w-full h-full object-cover"
                      />
                    ) : (
                      <FiUser className="text-white/80" style={{ width: '50%', height: '50%' }} />
                    )}
                    {!user?.customAvatar && onNavigateToProfile && (
                      <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); onNavigateToProfile(); }}
                        className="absolute inset-0 flex items-center justify-center bg-black/30 opacity-0 hover:opacity-100 transition-opacity rounded-full cursor-pointer"
                      >
                        <FiCamera className="text-white" style={{ width: '40%', height: '40%' }} />
                      </button>
                    )}
                  </div>
                </div>

                {/* Nombre y tipo de socio */}
                <div className="flex-1 min-w-0">
                  {!user?.customAvatar && onNavigateToProfile && (
                    <span
                      onClick={onNavigateToProfile}
                      className="text-white/40 hover:text-white/70 transition-colors cursor-pointer"
                      style={{ fontSize: '9px' }}
                    >
                      {t('card.addPhoto')}
                    </span>
                  )}
                  <p
                    className="font-bold text-white truncate"
                    style={{ fontSize: fluidSizing.text.base, lineHeight: 1.3 }}
                  >
                    {displayName}
                  </p>
                  <p
                    className="text-white/80 font-medium"
                    style={{ fontSize: fluidSizing.text['2xs'], lineHeight: 1.3 }}
                  >
                    {t('card.memberType')}
                  </p>
                </div>

                {/* Badge de estado */}
                {isActive && (
                  <div
                    className="flex items-center rounded-full flex-shrink-0"
                    style={{
                      background: 'rgba(255,255,255,0.2)',
                      backdropFilter: 'blur(4px)',
                      padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
                      gap: fluidSizing.space.xs,
                    }}
                  >
                    <FiShield className="text-white" style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
                    <span
                      className="text-white font-semibold uppercase tracking-wider"
                      style={{ fontSize: fluidSizing.text['2xs'] }}
                    >
                      {t('card.active')}
                    </span>
                  </div>
                )}
              </div>

              {/* Datos del carné + QR integrado */}
              <div
                className="flex"
                style={{
                  gap: fluidSizing.space.md,
                  borderTop: '1px solid rgba(255,255,255,0.2)',
                  paddingTop: fluidSizing.space.sm,
                }}
              >
                {/* Columna izquierda: datos en grid 2×2 */}
                <div
                  className="grid grid-cols-2 flex-1 min-w-0"
                  style={{ gap: fluidSizing.space.sm }}
                >
                  {/* Nº de socio */}
                  <div>
                    <p
                      className="text-white/60 uppercase tracking-wider"
                      style={{ fontSize: fluidSizing.text['2xs'], marginBottom: '2px' }}
                    >
                      {t('card.memberId')}
                    </p>
                    <p
                      className="text-white font-bold tracking-wide"
                      style={{ fontSize: fluidSizing.text.xs }}
                    >
                      {memberNumber}
                    </p>
                  </div>

                  {/* Fecha de adhesión */}
                  <div>
                    <p
                      className="text-white/60 uppercase tracking-wider"
                      style={{ fontSize: fluidSizing.text['2xs'], marginBottom: '2px' }}
                    >
                      {t('card.since')}
                    </p>
                    <p
                      className="text-white font-bold"
                      style={{ fontSize: fluidSizing.text.xs }}
                    >
                      {startDate}
                    </p>
                  </div>

                  {/* Vigencia */}
                  <div className="col-span-2">
                    <p
                      className="text-white/60 uppercase tracking-wider"
                      style={{ fontSize: fluidSizing.text['2xs'], marginBottom: '2px' }}
                    >
                      {t('card.validity')}
                    </p>
                    <p
                      className="text-white font-bold"
                      style={{ fontSize: fluidSizing.text.xs }}
                    >
                      {isActive ? t('card.validityActive') : t('card.validityInactive')}
                    </p>
                  </div>
                </div>

                {/* Columna derecha: QR integrado en la tarjeta */}
                <MembershipQRCode />
              </div>
            </div>
          </div>

          {/* Nota informativa + legal disclaimer compacto */}
          <p
            className="text-texto/50 text-center"
            style={{ fontSize: fluidSizing.text['2xs'], lineHeight: 1.4 }}
          >
            {t('info.note')}{' '}
            {t('info.legalDisclaimer')}{' '}
            <Link to={localizedPath('/terminos')} className="underline text-primario/60 hover:text-primario">{tLegal('nav.terms')}</Link>
            {' · '}
            <Link to={localizedPath('/privacidad')} className="underline text-primario/60 hover:text-primario">{tLegal('nav.privacy')}</Link>
          </p>
        </div>
      )}
    </CollapsibleSection>
  );
};

export default DigitalCardSection;
