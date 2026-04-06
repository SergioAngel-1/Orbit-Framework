import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { api } from '../services/apiConfig';
import { fluidSizing } from '../utils/fluidSizing';
import { FiShield, FiAlertTriangle, FiClock, FiUser, FiHash, FiFileText, FiCalendar } from 'react-icons/fi';
import Loader from '../components/ui/Loader';
import { useLanguage } from '../contexts/LanguageContext';

interface VerificationData {
  verified: boolean;
  member_number: string;
  name: string;
  document_id: string | null;
  status: string;
  member_since: string | null;
  verified_at: string;
}

const VerifyMemberPage = () => {
  const { token } = useParams<{ token: string }>();
  const { t } = useTranslation('verifyMemberPage');
  const { currentLang } = useLanguage();
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<VerificationData | null>(null);
  const [errorType, setErrorType] = useState<'not_found' | 'expired' | null>(null);

  useEffect(() => {
    window.scrollTo({ top: 0, behavior: 'instant' });

    const verify = async () => {
      try {
        setLoading(true);
        const response = await api.get(`/starter/v1/membership/verify/${token}`);
        if (response.data?.success) {
          setData(response.data.data);
        } else {
          setErrorType('not_found');
        }
      } catch (err: any) {
        const code = err?.response?.data?.code;
        setErrorType(code === 'token_expired' ? 'expired' : 'not_found');
      } finally {
        setLoading(false);
      }
    };

    if (token && /^[a-f0-9]{32}$/.test(token)) {
      verify();
    } else {
      setErrorType('not_found');
      setLoading(false);
    }
  }, [token]);

  const locale = currentLang === 'en' ? 'en-US' : 'es-CO';

  const formatDate = (dateStr: string | null) => {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString(locale, {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });
  };

  return (
    <div className="bg-claro">
      <div className="container mx-auto px-3 md:px-4 py-6 md:py-10">
        <div className="max-w-lg mx-auto">

          {loading ? (
            <div className="flex flex-col items-center justify-center" style={{ minHeight: '40vh' }}>
              <Loader size="large" text={t('loading')} />
            </div>
          ) : errorType ? (
            <>
              {/* Header — Error */}
              <div className="text-center" style={{ marginBottom: fluidSizing.space.lg }}>
                <div
                  className={`inline-flex items-center justify-center rounded-full ${errorType === 'expired' ? 'bg-yellow-100' : 'bg-red-100'}`}
                  style={{ width: '5rem', height: '5rem', marginBottom: fluidSizing.space.md }}
                >
                  {errorType === 'expired' ? (
                    <FiClock className="text-yellow-600" style={{ width: '2.5rem', height: '2.5rem' }} />
                  ) : (
                    <FiAlertTriangle className="text-red-600" style={{ width: '2.5rem', height: '2.5rem' }} />
                  )}
                </div>
                <h1
                  className={`font-bold ${errorType === 'expired' ? 'text-yellow-700' : 'text-red-700'}`}
                  style={{ fontSize: fluidSizing.text['2xl'], marginBottom: fluidSizing.space.xs }}
                >
                  {errorType === 'expired' ? t('expired.title') : t('notFound.title')}
                </h1>
                <p className="text-texto/70" style={{ fontSize: fluidSizing.text.sm }}>
                  {errorType === 'expired' ? t('expired.description') : t('notFound.description')}
                </p>
              </div>

              {/* Footer */}
              <div
                className="text-center bg-gray-50 rounded-lg"
                style={{ padding: fluidSizing.space.md }}
              >
                <p className="text-texto/60" style={{ fontSize: fluidSizing.text.xs }}>
                  {t('footer.brand')}
                </p>
              </div>
            </>
          ) : data ? (
            <>
              {/* Header — Resultado */}
              <div className="text-center" style={{ marginBottom: fluidSizing.space.lg }}>
                <div
                  className={`inline-flex items-center justify-center rounded-full ${data.verified ? 'bg-green-100' : 'bg-red-100'}`}
                  style={{ width: '5rem', height: '5rem', marginBottom: fluidSizing.space.md }}
                >
                  {data.verified ? (
                    <FiShield className="text-green-600" style={{ width: '2.5rem', height: '2.5rem' }} />
                  ) : (
                    <FiAlertTriangle className="text-red-600" style={{ width: '2.5rem', height: '2.5rem' }} />
                  )}
                </div>
                <h1
                  className={`font-bold ${data.verified ? 'text-primario' : 'text-red-700'}`}
                  style={{ fontSize: fluidSizing.text['2xl'], marginBottom: fluidSizing.space.xs }}
                >
                  {data.verified ? t('result.active') : t('result.inactive')}
                </h1>
                <p className="text-texto/60" style={{ fontSize: fluidSizing.text.sm }}>
                  {t('header')}
                </p>
              </div>

              {/* Card de datos del socio */}
              <div
                className="bg-white rounded-lg shadow-md overflow-hidden"
                style={{ marginBottom: fluidSizing.space.md }}
              >
                {/* Barra superior con gradiente de marca */}
                <div
                  style={{
                    height: '4px',
                    background: data.verified
                      ? 'linear-gradient(90deg, #B91E59, #8A1443, #6A0F49)'
                      : 'linear-gradient(90deg, #ef4444, #dc2626)',
                  }}
                />

                <div style={{ padding: fluidSizing.space.lg, display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
                  {/* Filas de datos con iconos */}
                  <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                    <div
                      className="flex-shrink-0 rounded-full bg-primario/10 flex items-center justify-center"
                      style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                    >
                      <FiHash className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-texto/50 uppercase tracking-wider" style={{ fontSize: fluidSizing.text['2xs'] }}>
                        {t('fields.memberNumber')}
                      </p>
                      <p className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
                        {data.member_number}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                    <div
                      className="flex-shrink-0 rounded-full bg-primario/10 flex items-center justify-center"
                      style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                    >
                      <FiUser className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-texto/50 uppercase tracking-wider" style={{ fontSize: fluidSizing.text['2xs'] }}>
                        {t('fields.name')}
                      </p>
                      <p className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
                        {data.name}
                      </p>
                    </div>
                  </div>

                  {data.document_id && (
                    <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                      <div
                        className="flex-shrink-0 rounded-full bg-primario/10 flex items-center justify-center"
                        style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                      >
                        <FiFileText className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-texto/50 uppercase tracking-wider" style={{ fontSize: fluidSizing.text['2xs'] }}>
                          {t('fields.documentId')}
                        </p>
                        <p className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
                          {data.document_id}
                        </p>
                      </div>
                    </div>
                  )}

                  {data.member_since && (
                    <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                      <div
                        className="flex-shrink-0 rounded-full bg-primario/10 flex items-center justify-center"
                        style={{ width: fluidSizing.size.buttonSm, height: fluidSizing.size.buttonSm }}
                      >
                        <FiCalendar className="text-primario" style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-texto/50 uppercase tracking-wider" style={{ fontSize: fluidSizing.text['2xs'] }}>
                          {t('fields.memberSince')}
                        </p>
                        <p className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
                          {formatDate(data.member_since)}
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Separador + timestamp */}
                  <div
                    className="border-t border-gray-100 text-center"
                    style={{ paddingTop: fluidSizing.space.sm }}
                  >
                    <p className="text-texto/40 italic" style={{ fontSize: fluidSizing.text['2xs'] }}>
                      {t('verifiedAt', { date: new Date(data.verified_at).toLocaleString(locale) })}
                    </p>
                  </div>
                </div>
              </div>

              {/* Footer con marca + legal */}
              <div
                className="text-center bg-gray-50 rounded-lg"
                style={{ padding: fluidSizing.space.md, marginBottom: fluidSizing.space.md }}
              >
                <img
                  src="/assets/images/logo-flores.png"
                  alt="Logo"
                  style={{ height: '28px', margin: '0 auto', marginBottom: fluidSizing.space.sm, opacity: 0.7 }}
                />
                <p className="text-texto/50" style={{ fontSize: fluidSizing.text['2xs'] }}>
                  {t('footer.brand')}
                </p>
              </div>
            </>
          ) : null}

        </div>
      </div>
    </div>
  );
};

export default VerifyMemberPage;
