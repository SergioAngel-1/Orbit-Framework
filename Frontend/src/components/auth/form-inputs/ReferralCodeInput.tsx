import DOMPurify from 'dompurify';
import React, { useEffect, useRef, useState } from "react";
import { useTranslation } from 'react-i18next';
import { sanitizeInlineHtml } from '../../../utils/sanitizeHtml';
import { FaUserFriends } from "react-icons/fa";
import logger from "../../../utils/logger";
import secureStorage from "../../../utils/secureStorage";
import Loader from "../../ui/Loader";
import Input, { ValidationStatus } from "./Input";
import { validateReferralCodeFormat } from "./validationUtils";

interface ReferralCodeInputProps {
  referralCode: string;
  setReferralCode: (value: string) => void;
  disabled?: boolean;
  onValidateReferralCode?: () => void;
  isValidatingCode?: boolean;
  isValid?: boolean | null;
  /** Callback para notificar si el disclaimer de referido ha sido aceptado */
  onReferralDisclaimerChange?: (accepted: boolean) => void;
}

const ReferralCodeInput: React.FC<ReferralCodeInputProps> = ({
  referralCode,
  setReferralCode,
  disabled = false,
  onValidateReferralCode,
  isValidatingCode = false,
  isValid: externalIsValid,
  onReferralDisclaimerChange,
}) => {
  const { t } = useTranslation('registerForm');

  // Estado local para validación de formato
  const [formatIsValid, setFormatIsValid] = useState<boolean | null>(null);
  const [validationMessage, setValidationMessage] = useState<string>("");
  const [validationStatus, setValidationStatus] =
    useState<ValidationStatus>("none");
  const [isValidating, setIsValidating] = useState(false);
  const [hasInteracted, setHasInteracted] = useState<boolean>(false);

  // Estado adicional exclusivo para controlar si el código ha sido validado exitosamente por API
  const [apiValidated, setApiValidated] = useState<boolean>(false);
  
  // Estado para el disclaimer de membresía por referido
  const [disclaimerAccepted, setDisclaimerAccepted] = useState<boolean>(false);
  
  // Nombre del referidor para mostrar en el disclaimer
  const [referrerDisplayName, setReferrerDisplayName] = useState<string>('');

  // Referencia para saber si es la primera renderización
  const isInitialRender = useRef(true);
  
  // Referencia para mantener el elemento form y garantizar cleanup correcto
  const formElementRef = useRef<HTMLFormElement | null>(null);

  // Efecto para manejar la primera renderización
  useEffect(() => {
    // En la primera renderización, inicializamos todo en estado neutro
    if (isInitialRender.current) {
      setFormatIsValid(null);
      setValidationMessage("");
      setValidationStatus("none");
      setHasInteracted(false);
      isInitialRender.current = false;
    }

    // Manejador para actualizar el estado cuando se recibe el evento de validación
    const handleReferralCodeValidated = (event: CustomEvent) => {
      const { isValid, referrerName, errorMessage, token } = event.detail;
      
      // VALIDAR TOKEN para prevenir eventos falsos
      const expectedToken = sessionStorage.getItem('lastValidationToken');
      if (token && token !== expectedToken) {
        logger.warn('ReferralCodeInput', 'Evento con token inválido, ignorando (posible ataque)');
        return;
      }
      
      logger.info(
        'ReferralCodeInput',
        `Evento recibido referralCodeValidated: isValid=${isValid}, referrer=${referrerName}, errorMessage=${errorMessage}`
      );

      // CRÍTICO: Siempre limpiar el estado de validación primero
      setIsValidating(false);
      logger.debug('ReferralCodeInput', 'Estado de validación limpiado por evento');

      if (isValid) {
        // Esta es la clave: cuando recibimos confirmación de la API, actualizamos todos los estados necesarios

        // Marcar explícitamente que el código ha sido validado por API
        setApiValidated(true);
        logger.info(
          "ReferralCodeInput",
          "Código validado por API: marcando apiValidated = true"
        );

        // Actualizar estados visuales
        setValidationStatus("valid");

        // Garantizar que se ejecute cualquier callback de validación desde el componente padre
        if (onValidateReferralCode) onValidateReferralCode();

        // SANITIZAR nombre del referido antes de guardar y mostrar
        let sanitizedName = '';
        if (referrerName) {
          sanitizedName = DOMPurify.sanitize(referrerName, {
            ALLOWED_TAGS: [],
            ALLOWED_ATTR: []
          }).trim();
          
          // Guardar el nombre sanitizado en secureStorage (encriptado)
          secureStorage.setItem("referrerName", sanitizedName);
          logger.debug('ReferralCodeInput', 'Nombre del referido sanitizado y guardado de forma segura (encriptado)');
          
          // Guardar nombre para mostrar en disclaimer
          setReferrerDisplayName(sanitizedName);
        }

        // Mostrar el mensaje con el nombre del referido sanitizado
        setValidationMessage(
          sanitizedName
            ? t('referralCode.validWithReferrer', { name: sanitizedName })
            : t('referralCode.valid')
        );
        setHasInteracted(true);
        
        // Resetear disclaimer cuando se valida un nuevo código
        setDisclaimerAccepted(false);
        if (onReferralDisclaimerChange) {
          onReferralDisclaimerChange(false);
        }
      } else {
        setValidationStatus("invalid");
        // Usar mensaje específico si está disponible, o mensaje genérico si no
        if (errorMessage) {
          setValidationMessage(errorMessage);
        } else {
          // Verificar si es problema de formato o código no encontrado
          const isFormatProblem = !validateReferralCodeFormat(referralCode);
          if (isFormatProblem) {
            setValidationMessage(
              t('referralCode.formatIncorrect')
            );
          } else {
            setValidationMessage(t('referralCode.notFound'));
          }
        }
        setHasInteracted(true);
      }
    };

    // Buscar el formulario padre usando un selector más específico
    // Intentar primero por ID, luego por el form más cercano al componente
    const form = document.getElementById('register-form') || 
                 document.getElementById('login-form') ||
                 document.querySelector('form');
    
    if (form) {
      // Guardar referencia del form para cleanup
      formElementRef.current = form as HTMLFormElement;
      
      form.addEventListener(
        "referralCodeValidated",
        handleReferralCodeValidated as EventListener
      );
      
      logger.debug('ReferralCodeInput', 'Event listener agregado al formulario');
    } else {
      logger.warn('ReferralCodeInput', 'No se encontró formulario padre para agregar event listener');
    }

    // Limpiar el event listener cuando el componente se desmonte
    return () => {
      // Usar la referencia guardada para garantizar que eliminamos del mismo elemento
      if (formElementRef.current) {
        formElementRef.current.removeEventListener(
          "referralCodeValidated",
          handleReferralCodeValidated as EventListener
        );
        logger.debug('ReferralCodeInput', 'Event listener eliminado correctamente');
        formElementRef.current = null;
      }

      // Limpiar datos del referido en secureStorage al desmontar
      secureStorage.removeItem("referrerName");
      logger.debug('ReferralCodeInput', 'Nombre del referido eliminado de secureStorage');
    };
  }, []);

  // Reiniciar estado cuando el referralCode cambia a vacío (modal recién abierto)
  useEffect(() => {
    if (!referralCode) {
      // Reiniciar todos los estados
      setFormatIsValid(null);
      setValidationMessage("");
      setValidationStatus("none");
      setHasInteracted(false); // Reiniciar el estado de interacción
      setApiValidated(false); // Reiniciar estado de validación API

      // Limpiar datos del referido en secureStorage
      secureStorage.removeItem("referrerName");
      logger.debug('ReferralCodeInput', 'Nombre del referido limpiado de secureStorage (código vacío)');
      return;
    }

    // Validar formato usando la función centralizada
    const isValidFormat = validateReferralCodeFormat(referralCode);
    setFormatIsValid(isValidFormat);

    // Solo mostrar mensajes si hay texto ingresado Y el usuario ha interactuado con el campo
    if (referralCode.length > 0 && hasInteracted) {
      if (isValidFormat) {
        setValidationMessage(t('referralCode.formatCorrect'));
        // Solo indicamos formato válido, no confundir con validación API exitosa
        setValidationStatus("none");
      } else {
        setValidationMessage(
          t('referralCode.formatIncorrect')
        );
        setValidationStatus("invalid");
      }
    } else if (referralCode.length > 0 && !hasInteracted) {
      // Si hay texto pero el usuario no ha interactuado, mantener estado neutro
      setValidationStatus("none");
      setValidationMessage("");
    } else {
      setValidationMessage("");
      setValidationStatus("none");
    }
  }, [referralCode, hasInteracted]);

  // Sincronizar con el estado de validación externo (isValidatingCode)
  useEffect(() => {
    logger.debug('ReferralCodeInput', `isValidatingCode cambió a: ${isValidatingCode}`);
    
    if (isValidatingCode === true) {
      // La validación externa ha comenzado
      setIsValidating(true);
      setValidationMessage(t('referralCode.validating'));
      setValidationStatus("validating");
      logger.debug('ReferralCodeInput', 'Estado de validación iniciado');
    } else if (isValidatingCode === false) {
      // La validación externa ha terminado - CRÍTICO: SIEMPRE limpiar estado de carga
      setIsValidating(false);
      logger.debug('ReferralCodeInput', 'Estado de validación finalizado - limpiando isValidating');
    }
  }, [isValidatingCode]);

  // Efecto para manejar explícitamente el estado de validación externa (API)
  useEffect(() => {
    // Si no hay código o es la primera renderización, no mostrar validación
    if (!referralCode || isInitialRender.current) {
      return;
    }

    logger.info(
      "ReferralCodeInput",
      `Estado de validación externa externalIsValid: ${externalIsValid}`
    );

    // Solo actuamos cuando la validación externa tiene un resultado definido (true o false)
    if (externalIsValid === true) {
      // Código validado correctamente por API
      logger.info("ReferralCodeInput", "Código validado por API correctamente");
      
      // Marcar como validado por API
      setApiValidated(true);

      // Recuperar el nombre del referido desde secureStorage (desencriptado automáticamente)
      const storedReferrerName = secureStorage.getItem("referrerName");
      logger.debug('ReferralCodeInput', `Nombre del referido recuperado de secureStorage: ${storedReferrerName ? 'encontrado' : 'no encontrado'}`);
      if (storedReferrerName) {
        // RE-SANITIZAR por defensa en profundidad
        const safeName = DOMPurify.sanitize(storedReferrerName, {
          ALLOWED_TAGS: [],
          ALLOWED_ATTR: []
        }).trim();
        
        setValidationMessage(
          t('referralCode.validWithReferrer', { name: safeName })
        );
      } else {
        setValidationMessage(t('referralCode.valid'));
      }

      // Actualizar estado visual
      setValidationStatus("valid");
      
      // NO establecemos hasInteracted aquí para evitar que el botón muestre "Reintentar"
    } else if (externalIsValid === false) {
      // Código rechazado por API
      logger.info("ReferralCodeInput", "Código rechazado por API");
      setValidationStatus("invalid");

      // Verificar si es problema de formato o código no encontrado
      const isFormatProblem = !validateReferralCodeFormat(referralCode);
      if (isFormatProblem) {
        setValidationMessage(
          "Formato incorrecto. Debe tener al menos 1 carácter seguido de 4 números."
        );
      } else {
        setValidationMessage("Código no encontrado");
      }
      
      // Establecer hasInteracted solo cuando hay un error, para mostrar "Reintentar"
      setHasInteracted(true);
    }
  }, [externalIsValid, referralCode]);

  // Función para validar el código de referido
  const validateCode = () => {
    if (!formatIsValid) {
      logger.warn(
        "ReferralCodeInput",
        "Intento de validar código con formato inválido"
      );
      return;
    }

    // Reiniciar todos los estados de validación antes de iniciar una nueva validación
    logger.info(
      "ReferralCodeInput",
      "Iniciando nueva validación, limpiando estados anteriores"
    );
    setApiValidated(false);
    setValidationStatus("none");
    secureStorage.removeItem("referrerName");

    // Iniciar el proceso de validación
    if (onValidateReferralCode) {
      setIsValidating(true);
      onValidateReferralCode();
    }
  };

  // Manejar cambios en el input
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value;

    // Si el usuario está editando un código previamente validado, reiniciar el estado
    if (apiValidated || externalIsValid === true) {
      logger.info(
        "ReferralCodeInput",
        "Usuario editando código validado, reiniciando estado"
      );
      setApiValidated(false);
      // No reiniciamos localStorage aquí porque necesitamos conservarlo hasta la validación
    }

    setReferralCode(value);
    setHasInteracted(true);
  };

  // Indicador de carga para validación en proceso
  const loadingIndicator = (
    <Loader text="" size="small" />
  );

  // Elemento a mostrar a la derecha del input
  // Solo mostrar el indicador de carga si estamos validando y no hay un error
  const rightElementToShow = isValidating ? loadingIndicator : undefined;

  return (
    <div className="mb-4">
      <label
        htmlFor="referral-code"
        className="block text-sm font-medium text-gray-700 mb-1"
      >
        {t('referralCode.label')}
      </label>
      <div className="relative">
        <Input
          id="referral-code"
          name="referralCode"
          type="text"
          value={referralCode}
          onChange={handleChange}
          placeholder={t('referralCode.placeholder')}
          autoComplete="off"
          required={false}
          disabled={disabled || apiValidated || isValidating}
          icon={<FaUserFriends />}
          label=""
          validationStatus={validationStatus}
          validationMessage={validationMessage}
          rightElement={rightElementToShow}
          className={
            validationStatus === "invalid" ||
            (referralCode && formatIsValid && !isValidating)
              ? "pr-[100px]"
              : ""
          }
        />
        {/* Botón de validación o reintentar */}
        {/* Mostrar el botón cuando: hay texto con formato válido, no está validando, y NO ha sido validado exitosamente por la API */}
        {referralCode && formatIsValid && !isValidating && !apiValidated && (
          <button
            type="button"
            className="absolute right-0 top-0 h-[46px] px-4 border border-l-0 border-t-0 border-b-0 border-r-0 
                      border-[#d1d5db] text-sm font-medium shadow-sm text-white bg-primario hover:bg-oscuro 
                      focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primario 
                      transition-all duration-200 rounded-r-md rounded-l-none"
            onClick={validateCode}
            disabled={disabled || isValidating}
            aria-label={t('referralCode.validateAria')}
          >
            {validationStatus === "invalid"
              ? t('referralCode.retryButton')
              : t('referralCode.validateButton')}
          </button>
        )}
      </div>
      
      {/* Disclaimer de membresía por referido - solo se muestra cuando el código es válido */}
      {apiValidated && referrerDisplayName && (
        <div className="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg">
          <label 
            className="flex items-start gap-3 cursor-pointer"
            onClick={() => {
              const newValue = !disclaimerAccepted;
              setDisclaimerAccepted(newValue);
              if (onReferralDisclaimerChange) {
                onReferralDisclaimerChange(newValue);
              }
            }}
          >
            {/* Checkbox visual personalizado */}
            <div className="flex items-center pt-0.5">
              <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-all ${
                disclaimerAccepted ? 'border-green-600 bg-green-600' : 'border-gray-300 bg-white'
              }`}>
                {disclaimerAccepted && (
                  <svg className="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                  </svg>
                )}
              </div>
            </div>
            <span className="text-sm text-gray-700 select-none" dangerouslySetInnerHTML={{ __html: sanitizeInlineHtml(t('referralCode.disclaimer', { name: referrerDisplayName })) }} />
          </label>
        </div>
      )}
    </div>
  );
};

export default ReferralCodeInput;
