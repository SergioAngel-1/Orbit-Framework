import { useState, useEffect, useRef } from "react";
import { useTranslation } from 'react-i18next';
import { useAuth } from "../../../contexts/AuthContext";
import alertService from "../../../services/alertService";
import logger from "../../../utils/logger";
import { sanitizeInlineHtml } from "../../../utils/sanitizeHtml";
import { api } from "../../../services/apiConfig";
import PhoneInput from "../../auth/form-inputs/PhoneInput";
import Select from "../../common/Select";
import CedulaInput from "../../auth/form-inputs/CedulaInput";
import CollapsibleSection from "../../common/CollapsibleSection";
import { fluidSizing } from "../../../utils/fluidSizing";
import { FiUser } from "react-icons/fi";
import {
  FaCalendar,
  FaUser,
  FaEnvelope,
  FaPhone,
  FaVenusMars,
  FaNewspaper,
  FaLock,
  FaEdit,
  FaTimes,
  FaCheck,
  FaCheckCircle,
  FaExclamationTriangle,
  FaIdCard,
  FaShieldAlt,
  FaFileContract,
  FaCamera,
} from "react-icons/fa";

// Se eliminó el mapa de indicativos telefónicos ya que ahora se usa el componente PhoneInput

interface ProfileSectionProps {
  onChangePassword?: () => void;
}

const ProfileSection = ({ onChangePassword }: ProfileSectionProps) => {
  const { user, updateProfile, getCurrentUser } = useAuth();
  const { t } = useTranslation('profileSection');
  const [formData, setFormData] = useState({
    firstName: user?.firstName || "",
    lastName: user?.lastName || "",
    email: user?.email || "",
    phone: user?.phone || "",
    birthDate: user?.birthDate || "",
    gender: user?.gender || "",
    newsletter: user?.newsletter || false,
    documentId: user?.documentId || "",
    acceptedDataVeracity: user?.acceptedDataVeracity || false,
    acceptedTerms: user?.acceptedTerms || false,
  });
  const [emailChangePending, setEmailChangePending] = useState(false);
  const [newEmail, setNewEmail] = useState<string | null>(null);
  const [isEditing, setIsEditing] = useState(false);
  const [avatarUrl, setAvatarUrl] = useState(user?.avatar || '');
  const [avatarUploading, setAvatarUploading] = useState(false);
  const avatarInputRef = useRef<HTMLInputElement>(null);

  // Actualizar avatar cuando cambie el usuario
  useEffect(() => {
    if (user?.avatar !== undefined) {
      setAvatarUrl(user.avatar || '');
    }
  }, [user?.avatar]);

  const handleAvatarUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Validar formato
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
      alertService.error(t('fields.avatar.formatError'));
      return;
    }

    // Validar tamaño (2MB)
    if (file.size > 2 * 1024 * 1024) {
      alertService.error(t('fields.avatar.sizeError'));
      return;
    }

    try {
      setAvatarUploading(true);
      const formData = new FormData();
      formData.append('avatar', file);

      const res = await api.post('/starter/v1/user/profile/avatar', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      if (res.data?.success && res.data.data?.avatar) {
        setAvatarUrl(res.data.data.avatar);
        // Refrescar datos del usuario en el contexto
        await getCurrentUser();
        alertService.success(t('messages.profileUpdated'));
      }
    } catch {
      alertService.error(t('fields.avatar.uploadError'));
    } finally {
      setAvatarUploading(false);
      // Limpiar input para permitir re-subir el mismo archivo
      if (avatarInputRef.current) avatarInputRef.current.value = '';
    }
  };

  const handleAvatarRemove = async () => {
    try {
      setAvatarUploading(true);
      const res = await api.delete('/starter/v1/user/profile/avatar');
      if (res.data?.success) {
        setAvatarUrl('');
        await getCurrentUser();
        alertService.success(t('messages.profileUpdated'));
      }
    } catch {
      alertService.error(t('fields.avatar.removeError'));
    } finally {
      setAvatarUploading(false);
    }
  };

  // Actualizar el formulario cuando cambie el usuario
  useEffect(() => {
    if (user) {
      logger.info(
        "ProfileSection",
        "Actualizando formData con datos del usuario:",
        user
      );
      logger.info("ProfileSection", "Email del usuario:", user.email); // Depuración adicional para el email

      // Verificar si hay un cambio de correo pendiente
      if ("emailChangePending" in user && user.emailChangePending) {
        setEmailChangePending(true);
        // Verificar si existe la propiedad newEmail en el usuario
        if ("newEmail" in user) {
          setNewEmail((user.newEmail as string) || null);
        }
      } else {
        setEmailChangePending(false);
        setNewEmail(null);
      }

      setFormData((prev) => ({
        ...prev,
        firstName: user.firstName || prev.firstName,
        lastName: user.lastName || prev.lastName,
        email: user.email || prev.email,
        phone: user.phone || prev.phone,
        birthDate: user.birthDate || prev.birthDate,
        gender: user.gender || prev.gender,
        newsletter: user.newsletter || prev.newsletter,
        documentId: user.documentId || prev.documentId,
        acceptedDataVeracity: user.acceptedDataVeracity || prev.acceptedDataVeracity,
        acceptedTerms: user.acceptedTerms || prev.acceptedTerms,
      }));
    }
  }, [user]);

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>
  ) => {
    const { name, value, type } = e.target as HTMLInputElement;

    setFormData({
      ...formData,
      [name]:
        type === "checkbox" ? (e.target as HTMLInputElement).checked : value,
    });
  };

  // Manejador específico para el campo de teléfono
  const handlePhoneChange = (value: string) => {
    // Asegurarse de que el valor del teléfono esté en el formato correcto
    // y solo actualizar si hay un cambio real
    if (value !== formData.phone) {
      logger.info("ProfileSection", "Actualizando teléfono:", value);
      setFormData({
        ...formData,
        phone: value,
      });
    }
  };

  // Función para obtener el número de teléfono sin el indicador para mostrar en el perfil
  const getPhoneWithoutPrefix = (phone: string) => {
    if (!phone) return "";

    // Si el teléfono ya tiene formato con indicador (+XX XXXXXXXXX)
    if (phone.includes("+") && phone.includes(" ")) {
      // Extraer solo el número sin el indicador
      const parts = phone.split(" ");
      if (parts.length > 1) {
        return parts[1]; // Devolver solo el número sin el indicador
      }
    }

    // Eliminar cualquier caracter que no sea número
    return phone.replace(/[^0-9]/g, "");
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    try {
      // Log para depuración
      logger.info(
        "ProfileSection",
        "Enviando datos de perfil para actualizar:",
        formData
      );

      // Actualizar perfil
      await updateProfile({
        ...formData,
        active: true,
      });

      // Mostrar mensaje de éxito
      alertService.success(t('messages.profileUpdated'));

      // Desactivar modo de edición sin recargar la página
      setIsEditing(false);
    } catch (error) {
      // Manejar errores específicos
      const errorMessage =
        error instanceof Error &&
        error.message &&
        error.message.includes("correo electrónico ya está en uso")
          ? t('messages.emailInUse')
          : error instanceof Error && error.message
          ? error.message
          : t('messages.updateError');
      alertService.error(errorMessage);
    }
  };

  return (
    <CollapsibleSection
      title={t('greeting', { name: formData.firstName })}
      icon={FiUser}
      collapsible={false}
      showCollapseButton={false}
      headerExtra={
        !isEditing ? (
          <button
            type="button"
            onClick={() => setIsEditing(true)}
            className="flex items-center text-white/90 hover:text-white bg-white/20 hover:bg-white/30 rounded-md transition-colors font-medium"
            style={{ padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`, fontSize: fluidSizing.text.xs, gap: fluidSizing.space.xs }}
          >
            <FaEdit style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
            {t('editButton')}
          </button>
        ) : (
          <span
            className="text-white/50 italic"
            style={{ fontSize: fluidSizing.text['2xs'] }}
            dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('alerts.usernameNotEditable', { name: formData.firstName })) }}
          />
        )
      }
    >
      <form onSubmit={handleSubmit}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}>
          
          {/* Alertas importantes */}
          {emailChangePending && newEmail && (
            <div 
              className="flex items-start bg-amber-50 border border-amber-200 rounded-lg"
              style={{ padding: fluidSizing.space.sm, gap: fluidSizing.space.sm }}
            >
              <FaExclamationTriangle className="text-amber-600 flex-shrink-0 mt-0.5" />
              <div>
                <p className="text-amber-900 font-medium" style={{ fontSize: fluidSizing.text.xs }}>
                  {t('alerts.emailChangePending')}
                </p>
                <p className="text-amber-700" style={{ fontSize: fluidSizing.text.xs }}>
                  {t('alerts.emailChangeConfirm')} <strong>{newEmail}</strong>
                </p>
              </div>
            </div>
          )}

          {/* Campos del formulario */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.sm }}>

            {/* Foto de perfil */}
            <div className="flex flex-col items-center pb-4 border-b border-gray-100" style={{ gap: fluidSizing.space.xs }}>
              <div
                className={`relative flex-shrink-0 rounded-full overflow-hidden border-3 border-gray-200 flex items-center justify-center bg-gray-100 shadow-md ${isEditing && !avatarUploading ? 'cursor-pointer' : ''}`}
                style={{ width: '5.5rem', height: '5.5rem' }}
                onClick={() => isEditing && !avatarUploading && avatarInputRef.current?.click()}
              >
                {avatarUrl ? (
                  <img 
                    src={avatarUrl} 
                    alt={formData.firstName} 
                    className="w-full h-full object-cover"
                    onError={(e) => { (e.target as HTMLImageElement).style.display = 'none'; (e.target as HTMLImageElement).nextElementSibling?.classList.remove('hidden'); }}
                  />
                ) : null}
                <FiUser className={`w-1/2 h-1/2 text-gray-400 ${avatarUrl ? 'hidden' : ''}`} />
                {isEditing && !avatarUploading && (
                  <div className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 hover:opacity-100 transition-opacity">
                    <FaCamera className="text-white" style={{ width: '24px', height: '24px' }} />
                  </div>
                )}
              </div>
              {isEditing ? (
                avatarUploading ? (
                  <p className="text-primario" style={{ fontSize: fluidSizing.text.xs }}>
                    {t('fields.avatar.uploading')}
                  </p>
                ) : (
                  <div className="flex flex-col items-center" style={{ gap: '2px' }}>
                    <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
                      <span
                        onClick={() => avatarInputRef.current?.click()}
                        className="text-primario hover:text-hover transition-colors cursor-pointer"
                        style={{ fontSize: fluidSizing.text.xs }}
                      >
                        {t('fields.avatar.change')}
                      </span>
                      {avatarUrl && (
                        <span
                          onClick={handleAvatarRemove}
                          className="text-red-500 hover:text-red-700 transition-colors cursor-pointer"
                          style={{ fontSize: fluidSizing.text.xs }}
                        >
                          {t('fields.avatar.remove')}
                        </span>
                      )}
                    </div>
                    <p className="text-gray-400" style={{ fontSize: fluidSizing.text['2xs'] }}>
                      {t('fields.avatar.maxSize')}
                    </p>
                  </div>
                )
              ) : (
                !user?.customAvatar && (
                  <span
                    onClick={() => setIsEditing(true)}
                    className="text-gray-400 hover:text-primario transition-colors cursor-pointer"
                    style={{ fontSize: fluidSizing.text['2xs'] }}
                  >
                    {t('fields.avatar.addPhoto')}
                  </span>
                )
              )}
              <input
                ref={avatarInputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={handleAvatarUpload}
              />
            </div>

            {/* Nombre Real */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaUser className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.realName.label')}
                </label>
                {isEditing ? (
                  <>
                    <input
                      type="text"
                      name="lastName"
                      value={formData.lastName}
                      onChange={handleChange}
                      className="w-full px-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primario focus:border-transparent transition-all"
                      style={{ 
                        height: fluidSizing.size.inputHeight,
                        fontSize: fluidSizing.text.base 
                      }}
                      placeholder={t('fields.realName.placeholder')}
                    />
                    <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                      {t('fields.realName.hint')}
                    </p>
                  </>
                ) : (
                  <p className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>
                    {formData.lastName || <span className="text-gray-400">{t('fields.realName.notSpecified')}</span>}
                  </p>
                )}
              </div>
            </div>

            {/* Email */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaEnvelope className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.email.label')}
                </label>
                <p 
                  className="text-gray-900 break-all"
                  style={{ fontSize: fluidSizing.text.base }}
                  data-component-name="ProfileSection"
                >
                  {formData.email || <span className="text-gray-400">{t('fields.email.notSpecified')}</span>}
                </p>
                {isEditing && (
                  <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                    {t('fields.email.hint')}
                  </p>
                )}
                <input type="hidden" name="email" value={formData.email} />
              </div>
            </div>

            {/* Teléfono */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaPhone className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.phone.label')}
                </label>
                {isEditing ? (
                  <PhoneInput
                    phone={getPhoneWithoutPrefix(formData.phone)}
                    setPhone={handlePhoneChange}
                    disabled={false}
                    showLabel={false}
                    skipUniqueValidation={true}
                  />
                ) : (
                  <p className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>
                    {formData.phone || <span className="text-gray-400">{t('fields.phone.notSpecified')}</span>}
                  </p>
                )}
              </div>
            </div>

            {/* Fecha de Nacimiento */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaCalendar className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.birthDate.label')}
                </label>
                <p className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>
                  {formData.birthDate || <span className="text-gray-400">{t('fields.birthDate.notSpecified')}</span>}
                </p>
                {isEditing && (
                  <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                    {t('fields.birthDate.hint')}
                  </p>
                )}
                <input type="hidden" name="birthDate" value={formData.birthDate} />
              </div>
            </div>

            {/* Género */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaVenusMars className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.gender.label')}
                </label>
                {isEditing ? (
                  <Select
                    name="gender"
                    value={formData.gender}
                    onChange={(value) => setFormData({ ...formData, gender: value })}
                    placeholder={t('fields.gender.placeholder')}
                    options={[
                      { value: 'male', label: t('fields.gender.options.male') },
                      { value: 'female', label: t('fields.gender.options.female') },
                      { value: 'other', label: t('fields.gender.options.other') },
                      { value: 'prefer_not_to_say', label: t('fields.gender.options.preferNotToSay') },
                    ]}
                  />
                ) : (
                  <p className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>
                    {formData.gender === "male"
                      ? t('fields.gender.options.male')
                      : formData.gender === "female"
                      ? t('fields.gender.options.female')
                      : formData.gender === "other"
                      ? t('fields.gender.options.other')
                      : formData.gender === "prefer_not_to_say"
                      ? t('fields.gender.options.preferNotToSay')
                      : <span className="text-gray-400">{t('fields.gender.notSpecified')}</span>}
                  </p>
                )}
              </div>
            </div>

            {/* Cédula / Documento de identidad */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaIdCard className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.documentId.label')}
                </label>
                {isEditing && !user?.documentId ? (
                  <CedulaInput
                    cedula={formData.documentId}
                    setCedula={(value) => setFormData({ ...formData, documentId: value })}
                    disabled={false}
                    showLabel={false}
                  />
                ) : (
                  <>
                    <p className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>
                      {user?.documentId || <span className="text-gray-400">{t('fields.documentId.notSpecified')}</span>}
                    </p>
                    {isEditing && user?.documentId && (
                      <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('fields.documentId.hint')}
                      </p>
                    )}
                  </>
                )}
              </div>
            </div>

            {/* Newsletter */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaNewspaper className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.newsletter.label')}
                </label>
                {isEditing ? (
                  <label className="flex items-center gap-3 cursor-pointer group">
                    <input
                      type="checkbox"
                      id="newsletter"
                      name="newsletter"
                      checked={formData.newsletter}
                      onChange={handleChange}
                      className="w-5 h-5 text-primario focus:ring-2 focus:ring-primario border-gray-300 rounded transition-all"
                    />
                    <span 
                      className="text-gray-700 group-hover:text-primario transition-colors"
                      style={{ fontSize: fluidSizing.text.base }}
                    >
                      {t('fields.newsletter.subscribe')}
                    </span>
                  </label>
                ) : (
                  <div className="flex items-center gap-2">
                    {formData.newsletter ? (
                      <>
                        <FaCheckCircle className="text-green-600" />
                        <span className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>{t('fields.newsletter.subscribed')}</span>
                      </>
                    ) : (
                      <span className="text-gray-400" style={{ fontSize: fluidSizing.text.base }}>{t('fields.newsletter.notSubscribed')}</span>
                    )}
                  </div>
                )}
              </div>
            </div>

            {/* Veracidad de datos */}
            <div className="flex items-start gap-4 pb-4 border-b border-gray-100">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaShieldAlt className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.dataVeracity.label')}
                </label>
                {isEditing && !user?.acceptedDataVeracity ? (
                  <label className="flex items-center gap-3 cursor-pointer group">
                    <input
                      type="checkbox"
                      name="acceptedDataVeracity"
                      checked={formData.acceptedDataVeracity}
                      onChange={handleChange}
                      className="w-5 h-5 text-primario focus:ring-2 focus:ring-primario border-gray-300 rounded transition-all"
                    />
                    <span 
                      className="text-gray-700 group-hover:text-primario transition-colors"
                      style={{ fontSize: fluidSizing.text.base }}
                    >
                      {t('fields.dataVeracity.declare')}
                    </span>
                  </label>
                ) : (
                  <>
                    <div className="flex items-center gap-2">
                      {user?.acceptedDataVeracity ? (
                        <>
                          <FaCheckCircle className="text-green-600" />
                          <span className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>{t('fields.dataVeracity.accepted')}</span>
                        </>
                      ) : (
                        <span className="text-gray-400" style={{ fontSize: fluidSizing.text.base }}>{t('fields.dataVeracity.notAccepted')}</span>
                      )}
                    </div>
                    {user?.acceptedDataVeracityDate && (
                      <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('fields.dataVeracity.acceptedDate', { date: user.acceptedDataVeracityDate })}
                      </p>
                    )}
                    {isEditing && user?.acceptedDataVeracity && (
                      <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('fields.dataVeracity.notEditable')}
                      </p>
                    )}
                  </>
                )}
              </div>
            </div>

            {/* Términos y condiciones */}
            <div className="flex items-start gap-4">
              <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primario/10 flex-shrink-0">
                <FaFileContract className="text-primario" />
              </div>
              <div className="flex-1 min-w-0">
                <label 
                  className="block font-medium text-gray-700 mb-1"
                  style={{ fontSize: fluidSizing.text.sm }}
                >
                  {t('fields.terms.label')}
                </label>
                {isEditing && !user?.acceptedTerms ? (
                  <label className="flex items-center gap-3 cursor-pointer group">
                    <input
                      type="checkbox"
                      name="acceptedTerms"
                      checked={formData.acceptedTerms}
                      onChange={handleChange}
                      className="w-5 h-5 text-primario focus:ring-2 focus:ring-primario border-gray-300 rounded transition-all"
                    />
                    <span 
                      className="text-gray-700 group-hover:text-primario transition-colors"
                      style={{ fontSize: fluidSizing.text.base }}
                    >
                      {t('fields.terms.accept')}
                    </span>
                  </label>
                ) : (
                  <>
                    <div className="flex items-center gap-2">
                      {user?.acceptedTerms ? (
                        <>
                          <FaCheckCircle className="text-green-600" />
                          <span className="text-gray-900" style={{ fontSize: fluidSizing.text.base }}>{t('fields.terms.accepted')}</span>
                        </>
                      ) : (
                        <span className="text-gray-400" style={{ fontSize: fluidSizing.text.base }}>{t('fields.terms.notAccepted')}</span>
                      )}
                    </div>
                    {user?.acceptedTermsDate && (
                      <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('fields.terms.acceptedDate', { date: user.acceptedTermsDate })}
                      </p>
                    )}
                    {isEditing && user?.acceptedTerms && (
                      <p className="text-gray-500 mt-1" style={{ fontSize: fluidSizing.text.xs }}>
                        {t('fields.terms.notEditable')}
                      </p>
                    )}
                  </>
                )}
              </div>
            </div>
          </div>
          
          {/* Botones de acción en modo edición */}
          {isEditing && (
            <div 
              className="grid grid-cols-2 border-t border-gray-100"
              style={{ gap: fluidSizing.space.sm, paddingTop: fluidSizing.space.md }}
            >
              <button
                type="button"
                onClick={() => setIsEditing(false)}
                className="flex items-center justify-center border border-gray-300 rounded-lg text-texto bg-white hover:bg-gray-50 transition-colors"
                style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
              >
                <FaTimes style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span>{t('buttons.cancel')}</span>
              </button>
              <button
                type="submit"
                className="flex items-center justify-center rounded-lg text-white bg-primario hover:bg-hover transition-colors"
                style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
              >
                <FaCheck style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span>{t('buttons.save')}</span>
              </button>
            </div>
          )}

          {/* Botón cambiar contraseña */}
          {!isEditing && onChangePassword && (
            <button
              type="button"
              onClick={onChangePassword}
              className="w-full flex items-center justify-center border border-primario rounded-lg text-primario hover:bg-primario/5 transition-colors"
              style={{ padding: fluidSizing.space.sm, fontSize: fluidSizing.text.sm, gap: fluidSizing.space.xs }}
            >
              <FaLock style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
              <span>{t('buttons.changePassword')}</span>
            </button>
          )}
        </div>
      </form>
    </CollapsibleSection>
  );
};

export default ProfileSection;
