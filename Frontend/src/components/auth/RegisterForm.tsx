import React, { useEffect, useRef, useState } from "react";
import { useTranslation } from 'react-i18next';
import { fluidSizing } from "../../utils/fluidSizing";
import Loader from "../ui/Loader";

// Importar componentes individuales
import logger from "../../utils/logger";
import secureStorage from "../../utils/secureStorage";
import BirthDateInput from "./form-inputs/BirthDateInput";
import CedulaInput from "./form-inputs/CedulaInput";
import DataVeracityCheckbox from "./form-inputs/DataVeracityCheckbox";
import EmailInput from "./form-inputs/EmailInput";
import PasswordInput from "./form-inputs/PasswordInput";
import PhoneInputComponent from "./form-inputs/PhoneInput";
import ReferralCodeInput from "./form-inputs/ReferralCodeInput";
import TermsCheckbox from "./form-inputs/TermsCheckbox";
import UsernameInput from "./form-inputs/UsernameInput";
import WelcomeDisclaimer from "./form-inputs/WelcomeDisclaimer";
import useFormValidation from "./form-inputs/useFormValidation";
import { validateEmailFormat } from "./form-inputs/validationUtils";

interface RegisterFormProps {
  onSubmit: (e: React.FormEvent) => void;
  identifier: string;
  setIdentifier: (value: string) => void;
  email: string;
  setEmail: (value: string) => void;
  password: string;
  setPassword: (value: string) => void;
  phone: string;
  setPhone: (value: string) => void;
  cedula: string;
  setCedula: (value: string) => void;
  referralCode: string;
  setReferralCode: (value: string) => void;
  // El nombre del referente que ya no usamos directamente, pero mantenemos en la interfaz
  // para compatibilidad con componentes padres
  referrerName: string;
  birthDate: string;
  setBirthDate: (value: string) => void;
  acceptedDataVeracity: boolean;
  setAcceptedDataVeracity: (value: boolean) => void;
  acceptedTerms: boolean;
  setAcceptedTerms: (value: boolean) => void;
  loading: boolean;
  validatingReferralCode?: boolean;
  onReferralCodeChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onValidateReferralCode?: () => void;
}

const RegisterForm: React.FC<RegisterFormProps> = ({
  onSubmit,
  identifier,
  setIdentifier,
  email,
  setEmail,
  password,
  setPassword,
  phone,
  setPhone,
  cedula,
  setCedula,
  referralCode,
  setReferralCode,
  birthDate,
  setBirthDate,
  acceptedDataVeracity,
  setAcceptedDataVeracity,
  acceptedTerms,
  setAcceptedTerms,
  loading,
  validatingReferralCode = false,
  onReferralCodeChange,
  onValidateReferralCode,
}) => {
  const { t } = useTranslation('registerForm');

  // Estados para la validación de los campos
  const [emailFormatIsValid, setEmailFormatIsValid] = useState<boolean | null>(
    null
  );
  const [isAdult, setIsAdult] = useState<boolean | null>(null);
  const [passwordStrength, setPasswordStrength] = useState({
    strength: 0,
    message: "",
  });
  const [referralCodeFormatIsValid, setReferralCodeFormatIsValid] = useState<
    boolean | null
  >(null);
  const [referralCodeIsValid, setReferralCodeIsValid] = useState<
    boolean | null
  >(null);
  const [isValidatingCode, setIsValidatingCode] = useState(false);

  // Estado para validación del teléfono
  const [phoneIsValid, setPhoneIsValid] = useState<boolean | null>(null);
  
  // Estado para el disclaimer de referido
  const [referralDisclaimerAccepted, setReferralDisclaimerAccepted] = useState<boolean>(false);
  
  // Referencia para mantener el elemento form y garantizar cleanup correcto
  const formElementRef = useRef<HTMLFormElement | null>(null);

  // Validar teléfono cuando cambia el valor
  useEffect(() => {
    if (!phone) {
      setPhoneIsValid(false);
      return;
    }

    // Comprobar que el teléfono incluye el indicativo (formato +XX XXXXXXXXXX o +XX-XXXXXXXXXX)
    const hasCountryCode = phone.includes("+");
    const hasSeparator = phone.includes(" ") || phone.includes("-");

    if (hasCountryCode && hasSeparator) {
      // Extraer la parte del número después del indicativo
      let numberPart = "";
      let countryCode = "";

      if (phone.includes(" ")) {
        // Formato con espacio: +XX XXXXXXXXXX
        const parts = phone.split(" ");
        countryCode = parts[0];
        numberPart = parts[1] || "";
      } else if (phone.includes("-")) {
        // Formato con guión: +XX-XXXXXXXXXX
        const parts = phone.split("-");
        countryCode = parts[0];
        numberPart = parts[1] || "";
      }

      // Eliminar caracteres no numéricos del número
      numberPart = numberPart.replace(/[^0-9]/g, "");

      // Validar según el país
      if (countryCode === "+57" && numberPart.length === 10) {
        // Colombia
        setPhoneIsValid(true);
      } else if (countryCode === "+52" && numberPart.length === 10) {
        // México
        setPhoneIsValid(true);
      } else if (countryCode === "+34" && numberPart.length === 9) {
        // España
        setPhoneIsValid(true);
      } else if (countryCode === "+1" && numberPart.length === 10) {
        // Estados Unidos
        setPhoneIsValid(true);
      } else if (numberPart.length >= 6) {
        // Otros países
        setPhoneIsValid(true);
      } else {
        setPhoneIsValid(false);
      }
    } else {
      setPhoneIsValid(false);
    }
  }, [phone]);

  // Suscribirse al evento personalizado referralCodeValidated
  useEffect(() => {
    // Manejador para actualizar el estado cuando se recibe el evento de validación
    const handleReferralCodeValidated = (event: CustomEvent) => {
      const { isValid, referrerName } = event.detail;
      logger.info(
        `RegisterForm recibe evento referralCodeValidated:`,
        `isValid=${isValid}, referrer=${referrerName}`
      );

      if (isValid) {
        // La API ha validado exitosamente el código
        setReferralCodeIsValid(true);
        setReferralCodeFormatIsValid(true);
        logger.info(
          "RegisterForm",
          "Código de referido validado exitosamente, actualizando estado del formulario"
        );
      } else {
        // La API ha rechazado el código
        setReferralCodeIsValid(false);
        logger.info(
          "RegisterForm",
          "Código de referido rechazado, actualizando estado del formulario"
        );
      }
    };

    // Buscar el formulario de registro usando ID específico
    const form = document.getElementById('register-form') || document.querySelector('form');
    
    if (form) {
      // Guardar referencia del form para cleanup
      formElementRef.current = form as HTMLFormElement;
      
      form.addEventListener(
        "referralCodeValidated",
        handleReferralCodeValidated as EventListener
      );
      
      logger.debug('RegisterForm', 'Event listener agregado al formulario de registro');
    } else {
      logger.warn('RegisterForm', 'No se encontró formulario de registro para agregar event listener');
    }

    // Verificar secureStorage para códigos previamente validados (al recargar la página)
    // Solo verificamos si hay un código de referido y todavía no ha sido validado
    if (referralCode && referralCodeIsValid !== true) {
      const storedReferrerName = secureStorage.getItem("referrerName");

      if (storedReferrerName) {
        logger.info(
          "RegisterForm",
          `Encontrado nombre de referido en secureStorage: ${storedReferrerName}, marcando código como válido`
        );
        setReferralCodeIsValid(true);
        setReferralCodeFormatIsValid(true);
      }
    }

    // Limpiar el event listener cuando el componente se desmonte
    return () => {
      // Usar la referencia guardada para garantizar que eliminamos del mismo elemento
      if (formElementRef.current) {
        formElementRef.current.removeEventListener(
          "referralCodeValidated",
          handleReferralCodeValidated as EventListener
        );
        logger.debug('RegisterForm', 'Event listener eliminado correctamente');
        formElementRef.current = null;
      }
    };
  }, [referralCode, referralCodeIsValid]);

  // Usar el hook de validación del formulario
  const { formIsValid, invalidFormMessage } = useFormValidation({
    identifier,
    email,
    password,
    birthDate,
    phone,
    referralCode,
    emailFormatIsValid,
    isAdult,
    passwordStrength,
    referralCodeFormatIsValid,
    referralCodeIsValid,
    phoneIsValid,
    referralDisclaimerAccepted,
  });

  // Estado para controlar validación del código de referido
  const handleValidateReferralCode = () => {
    if (!referralCode) return;

    // Iniciar la validación local
    setIsValidatingCode(true);

    // Si hay una función externa para validar, usarla
    if (onValidateReferralCode) {
      // Llamar a la función externa para validar
      onValidateReferralCode();

      // Solo configuramos un timeout de seguridad para evitar que se quede cargando indefinidamente
      // pero NO asumimos que el código es válido automáticamente
      // El componente padre (LandingPage o donde se use) debe llamar a setReferralCodeIsValid con el resultado real
      setTimeout(() => {
        if (isValidatingCode) {
          setIsValidatingCode(false);
        }
      }, 5000);

      return;
    }

    // Simulación de validación para desarrollo (fallback si no hay función externa)
    setTimeout(() => {
      // Códigos de prueba que siempre son válidos
      const validCodes = ["admin1234", "test1234", "user1234"];

      if (validCodes.includes(referralCode)) {
        // Código válido
        setReferralCodeIsValid(true);
        setReferralCodeFormatIsValid(true);
      } else {
        // Código inválido
        setReferralCodeIsValid(false);
        setReferralCodeFormatIsValid(false);
      }
      setIsValidatingCode(false);
    }, 1000); // Simular un retraso de red de 1 segundo
  };

  return (
    <form 
      id="register-form" 
      onSubmit={onSubmit} 
      style={{ display: 'flex', flexDirection: 'column', gap: fluidSizing.space.md }}
    >
      {/* Nombre de usuario */}
      <UsernameInput
        identifier={identifier}
        setIdentifier={setIdentifier}
        disabled={loading}
      />

      {/* Correo electrónico */}
      <EmailInput
        email={email}
        setEmail={(value) => {
          setEmail(value);
          // EmailInput ya maneja la validación internamente usando validateEmailFormat
          // Solo necesitamos actualizar el estado local para useFormValidation
          if (value) {
            const isValidFormat = validateEmailFormat(value);
            setEmailFormatIsValid(isValidFormat);
          } else {
            setEmailFormatIsValid(null);
          }
        }}
        disabled={loading}
      />

      {/* Cédula */}
      <CedulaInput
        cedula={cedula}
        setCedula={setCedula}
        disabled={loading}
      />

      {/* Fecha de nacimiento */}
      <BirthDateInput
        birthDate={birthDate}
        setBirthDate={(value) => {
          setBirthDate(value);
          // Ya no necesitamos resetear isAdult aquí, el componente lo hará
        }}
        setIsAdult={setIsAdult}
        disabled={loading}
      />

      {/* Teléfono */}
      <PhoneInputComponent
        phone={phone}
        setPhone={setPhone}
        disabled={loading}
      />

      {/* Contraseña */}
      <PasswordInput
        password={password}
        setPassword={(value) => {
          setPassword(value);
          // La validación ahora se comunica directamente con setPasswordStrength
        }}
        setPasswordStrength={setPasswordStrength}
        disabled={loading}
      />

      {/* Código de referido */}
      <ReferralCodeInput
        referralCode={referralCode}
        setReferralCode={(value) => {
          setReferralCode(value);

          // Si el usuario borra el código, resetear el estado de validación
          if (!value) {
            setReferralCodeIsValid(null);
            setReferralCodeFormatIsValid(null);
            setReferralDisclaimerAccepted(false);
          } else if (referralCodeIsValid) {
            // Resetear validación al cambiar el código
            setReferralCodeIsValid(null);
            setReferralDisclaimerAccepted(false);
          }

          // Si hay una función externa para manejar cambios, llamarla
          if (onReferralCodeChange) {
            const syntheticEvent = {
              target: { value },
            } as React.ChangeEvent<HTMLInputElement>;
            onReferralCodeChange(syntheticEvent);
          }
        }}
        disabled={loading}
        onValidateReferralCode={handleValidateReferralCode}
        isValidatingCode={validatingReferralCode || isValidatingCode}
        // Pasamos explícitamente el estado de validación para sincronizar con el componente hijo
        isValid={referralCodeIsValid}
        onReferralDisclaimerChange={setReferralDisclaimerAccepted}
      />

      {/* 1. Declaración de veracidad de datos */}
      <DataVeracityCheckbox
        accepted={acceptedDataVeracity}
        setAccepted={setAcceptedDataVeracity}
        disabled={loading}
      />

      {/* 2. Términos y condiciones */}
      <TermsCheckbox
        accepted={acceptedTerms}
        setAccepted={setAcceptedTerms}
        disabled={loading}
      />

      {/* Indicador de campos obligatorios */}
      <div className="flex justify-end items-center">
        <span className="text-gray-500 italic" style={{ fontSize: fluidSizing.text.xs }}>
          <span className="text-red-500" style={{ marginRight: fluidSizing.space.xs }}>*</span>{t('requiredFields')}
        </span>
      </div>

      {/* 3. Bienvenido al club */}
      <WelcomeDisclaimer />

      {/* Botón de envío con tooltip mejorado */}
      <div className="relative group">
        <button
          type="submit"
          disabled={!formIsValid || loading}
          className={`w-full flex justify-center items-center rounded-lg font-semibold text-white bg-primario hover:bg-primario/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario transition-all ${
            !formIsValid || loading ? "opacity-50 cursor-not-allowed" : ""
          }`}
          style={{
            height: fluidSizing.size.buttonMd,
            fontSize: fluidSizing.text.base,
            marginTop: fluidSizing.space.sm
          }}
          title={!formIsValid ? invalidFormMessage : ""}
        >
          {loading ? (
            <>
              <Loader text="" size="small" />
              <span style={{ marginLeft: fluidSizing.space.sm }}>{t('processing')}</span>
            </>
          ) : (
            t('submitButton')
          )}
        </button>
        {!formIsValid && invalidFormMessage && (
          <div 
            className="invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-opacity duration-300 absolute bottom-full left-1/2 transform -translate-x-1/2 bg-gray-800 text-white rounded-lg z-10"
            style={{ 
              marginBottom: fluidSizing.space.sm,
              padding: `${fluidSizing.space.sm} ${fluidSizing.space.md}`,
              fontSize: fluidSizing.text.xs,
              whiteSpace: 'nowrap'
            }}
          >
            {invalidFormMessage}
            <div className="absolute -bottom-1 left-1/2 transform -translate-x-1/2 w-2 h-2 bg-gray-800 rotate-45"></div>
          </div>
        )}
      </div>
    </form>
  );
};

export default RegisterForm;
