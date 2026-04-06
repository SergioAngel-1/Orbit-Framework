import React from 'react';
import { useTranslation } from 'react-i18next';
import { FiUser } from 'react-icons/fi';
import PhoneInput from '../auth/form-inputs/PhoneInput';
import CedulaInput from '../auth/form-inputs/CedulaInput';
import CollapsibleSection from '../common/CollapsibleSection';
import { fluidSizing } from '../../utils/fluidSizing';
import { useLanguage } from '../../contexts/LanguageContext';

// Define User interface
interface User {
  id: number;
  name: string;
  email: string;
  firstName?: string;
  lastName?: string;
  phone?: string;
  documentId?: string;
}

interface ContactFormData {
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
  documentId: string;
  recipientFirstName: string;
  recipientLastName: string;
  recipientPhone: string;
}

interface InitialEmptyFields {
  firstName: boolean;
  lastName: boolean;
  phone: boolean;
  documentId: boolean;
}

interface ContactInformationProps {
  isAuthenticated: boolean;
  user: User | null;
  formData: ContactFormData;
  isGift: boolean;
  onInputChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onGiftToggle: (e: React.ChangeEvent<HTMLInputElement>) => void;
  initialEmptyFields?: InitialEmptyFields;
  onPhoneChange: (value: string) => void;
  onDocumentIdChange: (value: string) => void;
  onDocumentIdValidChange: (isValid: boolean | null, isUnique: boolean | null) => void;
  onRecipientPhoneChange: (value: string) => void;
  onOpenProfileModal: () => void;
}

const ContactInformation: React.FC<ContactInformationProps> = ({
  isAuthenticated,
  user,
  formData,
  isGift,
  onInputChange,
  onGiftToggle: _onGiftToggle,
  initialEmptyFields,
  onPhoneChange,
  onDocumentIdChange,
  onDocumentIdValidChange,
  onRecipientPhoneChange,
  onOpenProfileModal
}) => {
  const { t } = useTranslation('checkoutPage');
  const { localizedPath } = useLanguage();

  return (
    <CollapsibleSection
      title={t('contact.title')}
      icon={FiUser}
      collapsible={false}
      showCollapseButton={false}
      headerExtra={
        isAuthenticated ? (
          <button
            type="button"
            onClick={onOpenProfileModal}
            className="text-white/90 hover:text-white bg-white/20 hover:bg-white/30 rounded-md transition-colors font-medium"
            style={{ 
              padding: `${fluidSizing.space.xs} ${fluidSizing.space.sm}`,
              fontSize: fluidSizing.text.xs
            }}
          >
            {t('contact.myProfile')}
          </button>
        ) : undefined
      }
    >
      {isAuthenticated ? (
        <div>
          {/* Mensaje informativo si hay campos vacíos */}
          {initialEmptyFields && (initialEmptyFields.lastName || initialEmptyFields.phone) && !isGift && (
            <div className="bg-blue-50 border-l-4 border-blue-400 p-3 mb-4">
              <p className="text-sm text-blue-700">
                {t('contact.fieldsAutoSave')}
              </p>
            </div>
          )}

          {!isGift ? (
            <>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="firstName" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('contact.username')}
                  </label>
                  <input
                    type="text"
                    id="firstName"
                    name="firstName"
                    value={formData.firstName}
                    onChange={onInputChange}
                    className={`w-full px-3 py-2 border border-gray-300 rounded-md ${!formData.firstName ? 'bg-white focus:ring-2 focus:ring-primario' : 'bg-gray-50'} focus:outline-none`}
                    disabled={isAuthenticated && initialEmptyFields ? !initialEmptyFields.firstName : false}
                  />
                </div>

                <div>
                  <label htmlFor="lastName" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('contact.name')}
                  </label>
                  <input
                    type="text"
                    id="lastName"
                    name="lastName"
                    value={formData.lastName}
                    onChange={onInputChange}
                    className={`w-full px-3 py-2 border border-gray-300 rounded-md ${!formData.lastName ? 'bg-white focus:ring-2 focus:ring-primario' : 'bg-gray-50'} focus:outline-none`}
                    disabled={isAuthenticated && initialEmptyFields ? !initialEmptyFields.lastName : false}
                  />
                </div>

                <div>
                  <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('contact.email')}
                  </label>
                  <input
                    type="email"
                    id="email"
                    name="email"
                    value={formData.email}
                    onChange={onInputChange}
                    className={`w-full px-3 py-2 border border-gray-300 rounded-md ${!user?.email ? 'bg-white focus:ring-2 focus:ring-primario' : 'bg-gray-50'} focus:outline-none`}
                    disabled={!!user?.email}
                  />
                </div>

                <div>
                  <label htmlFor="phone" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('contact.phone')}
                  </label>
                  <PhoneInput
                    phone={formData.phone}
                    setPhone={onPhoneChange}
                    disabled={isAuthenticated && initialEmptyFields ? !initialEmptyFields.phone : false}
                    showLabel={false}
                    skipUniqueValidation={true}
                  />
                </div>
              </div>

              {/* Solicitud de cédula — solo si el usuario no tiene una guardada */}
              {!user?.documentId && (
                <div className="mt-4">
                  <CollapsibleSection
                    title={t('contact.documentRequired')}
                    variant="soft"
                    collapsible={false}
                    showCollapseButton={false}
                  >
                    <p className="text-sm text-texto mb-3">
                      {t('contact.documentExplanation')}{' '}
                      <a
                        href={localizedPath('/terminos')}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primario hover:text-hover font-medium underline"
                      >
                        {t('contact.terms')}
                      </a>
                      {' '}{t('contact.and')}{' '}
                      <a
                        href={localizedPath('/privacidad')}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primario hover:text-hover font-medium underline"
                      >
                        {t('contact.privacyPolicies')}
                      </a>
                      {t('contact.documentReason')}
                    </p>
                    <CedulaInput
                      cedula={formData.documentId}
                      setCedula={onDocumentIdChange}
                      disabled={false}
                      showLabel={true}
                      onValidationChange={onDocumentIdValidChange}
                    />
                  </CollapsibleSection>
                </div>
              )}
            </>
          ) : (
            <div className="bg-gray-50 p-3 md:p-4 rounded-md">
              <h3 className="text-sm md:text-md font-medium text-gray-700 mb-2 md:mb-3">{t('contact.recipientInfo')}</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                <div>
                  <label htmlFor="recipientFirstName" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('contact.recipientName')} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="recipientFirstName"
                    name="recipientFirstName"
                    value={formData.recipientFirstName}
                    onChange={onInputChange}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primario"
                    required={isGift}
                  />
                </div>

                <div>
                  <label htmlFor="recipientLastName" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('contact.recipientLastName')} <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="recipientLastName"
                    name="recipientLastName"
                    value={formData.recipientLastName}
                    onChange={onInputChange}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primario"
                    required={isGift}
                  />
                </div>

                <div>
                  <label htmlFor="recipientPhone" className="block text-sm font-medium text-gray-700 mb-1">
                    {t('contact.recipientPhone')} <span className="text-red-500">*</span>
                  </label>
                  <PhoneInput
                    phone={formData.recipientPhone}
                    setPhone={onRecipientPhoneChange}
                    disabled={false}
                    showLabel={false}
                    skipUniqueValidation={true}
                  />
                </div>
              </div>
            </div>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6">
          <div>
            <label htmlFor="firstName" className="block text-sm font-medium text-gray-700 mb-2">
              {t('contact.recipientName')} <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              id="firstName"
              name="firstName"
              value={formData.firstName}
              onChange={onInputChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primario"
              required
            />
          </div>

          <div>
            <label htmlFor="lastName" className="block text-sm font-medium text-gray-700 mb-2">
              {t('contact.lastName')} <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              id="lastName"
              name="lastName"
              value={formData.lastName}
              onChange={onInputChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primario"
              required
            />
          </div>

          <div>
            <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-2">
              {t('contact.email')} <span className="text-red-500">*</span>
            </label>
            <input
              type="email"
              id="email"
              name="email"
              value={formData.email}
              onChange={onInputChange}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primario"
              required
            />
          </div>

          <div>
            <label htmlFor="phone" className="block text-sm font-medium text-gray-700 mb-2">
              {t('contact.phone')} <span className="text-red-500">*</span>
            </label>
            <PhoneInput
              phone={formData.phone}
              setPhone={onPhoneChange}
              disabled={false}
              showLabel={false}
              skipUniqueValidation={true}
            />
          </div>
        </div>
      )}
    </CollapsibleSection>
  );
};

export default ContactInformation;
