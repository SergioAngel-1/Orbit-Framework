import i18n from '../../../config/i18n';
import logger from "../../../utils/logger";

/**
 * Estado de validación para los inputs
 */
export type ValidationStatus = "none" | "valid" | "invalid" | "validating";

/**
 * Configuración de colores por estado de validación
 */
export interface ValidationStylesConfig {
  text: Record<ValidationStatus, string>;
  border: Record<ValidationStatus, string>;
  icon: Record<ValidationStatus, string>;
  background: Record<ValidationStatus, string>;
}

/**
 * Configuración de colores estandarizados para los inputs
 */
export const validationStyles: ValidationStylesConfig = {
  text: {
    none: "text-gray-400",
    valid: "text-[#34C759]",
    invalid: "text-[#FF3B30]",
    validating: "text-yellow-600",
  },
  border: {
    none: "border-gray-300",
    valid: "border-[#34C759]",
    invalid: "border-[#FF3B30]",
    validating: "border-yellow-500",
  },
  icon: {
    none: "text-gray-400",
    valid: "text-[#34C759]",
    invalid: "text-[#FF3B30]",
    validating: "text-yellow-500",
  },
  background: {
    none: "bg-white",
    valid: "bg-green-50",
    invalid: "bg-red-50",
    validating: "bg-yellow-50",
  },
};

/**
 * Obtiene el estado de validación basado en varias condiciones
 * @param params Parámetros para determinar el estado de validación
 * @returns Estado de validación
 */
export const getValidationStatus = (params: {
  isValidating?: boolean;
  isValid?: boolean | null;
  hasValue?: boolean;
}): ValidationStatus => {
  const { isValidating, isValid, hasValue } = params;

  if (isValidating) return "validating";
  if (isValid === true) return "valid";
  if (isValid === false) return "invalid";
  // Si el campo tiene valor y se quiere mostrar validación
  if (hasValue && isValid !== null) return "none";
  return "none";
};

/**
 * Obtiene los estilos CSS para un input según su estado de validación
 * @param status Estado de validación del input
 * @returns Clases CSS para aplicar al input
 */
export const getInputStyles = (status: ValidationStatus): string => {
  return validationStyles.border[status];
};

/**
 * Obtiene el color del icono según el estado de validación
 * @param status Estado de validación
 * @returns Clase CSS para el color del icono
 */
export const getIconStyles = (status: ValidationStatus): string => {
  return validationStyles.icon[status];
};

/**
 * Obtiene el estilo del texto de validación según el estado
 * @param status Estado de validación
 * @returns Clase CSS para el texto de validación
 */
export const getMessageStyles = (status: ValidationStatus): string => {
  return validationStyles.text[status];
};

/**
 * Genera todas las clases necesarias para un input validado
 * @param status Estado de validación
 * @param baseClasses Clases base del input (opcional)
 * @returns Todas las clases CSS combinadas
 */
export const getValidatedInputClasses = (
  status: ValidationStatus,
  baseClasses: string = "appearance-none block w-full pl-10 pr-3 py-3 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-primario focus:border-primario transition-colors duration-200"
): string => {
  const borderClass = validationStyles.border[status];

  return `${baseClasses} ${borderClass}`;
};

/**
 * Validación de email usando expresión regular
 * @param email Dirección de correo electrónico a validar
 * @returns true si el formato es válido, false en caso contrario
 */
export const validateEmailFormat = (email: string): boolean => {
  const emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
  return emailRegex.test(email);
};

/**
 * Valida la fortaleza de una contraseña
 * @param password Contraseña a validar
 * @returns Objeto con la fortaleza (0-4) y mensaje descriptivo
 */
export const validatePasswordStrength = (
  password: string
): { strength: number; message: string } => {
  let strength = 0;
  let message = "";

  if (!password) return { strength, message };

  // Longitud mínima
  if (password.length < 8) {
    message = i18n.t('password.strength.minLength', { ns: 'registerForm' });
    return { strength, message };
  }

  strength++;

  // Verificar si contiene números
  if (/\d/.test(password)) {
    strength++;
  } else {
    message = i18n.t('password.strength.needNumber', { ns: 'registerForm' });
    return { strength, message };
  }

  // Verificar si contiene letras mayúsculas y minúsculas
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) {
    strength++;
  } else {
    message = i18n.t('password.strength.needCase', { ns: 'registerForm' });
    return { strength, message };
  }

  // Verificar si contiene caracteres especiales
  if (/[^a-zA-Z0-9]/.test(password)) {
    strength++;
  } else {
    message = i18n.t('password.strength.needSpecial', { ns: 'registerForm' });
    return { strength, message };
  }

  message = i18n.t('password.strength.strong', { ns: 'registerForm' });
  return { strength, message };
};

/**
 * Valida el formato de un código de referido
 * @param code Código de referido a validar
 * @returns true si el formato es válido, false en caso contrario
 */
/**
 * Valida el formato del código de referido
 * Debe ser un nombre de usuario (cualquier carácter) seguido de exactamente 4 dígitos
 * Ejemplos válidos: admin1234, user5678, john9876, hola-user2345, test_name4567
 */
export const validateReferralCodeFormat = (code: string): boolean => {
  if (!code) return false;

  // Permitimos cualquier carácter (incluyendo caracteres especiales como guiones, guiones bajos, etc.)
  // seguido de exactamente 4 dígitos al final
  // La expresión .+ garantiza al menos un carácter antes de los 4 dígitos
  const codeRegex = /^.+\d{4}$/;

  // Validación adicional: debe tener al menos 5 caracteres en total (1 carácter + 4 dígitos)
  if (code.length < 5) return false;

  // Validamos y registramos el resultado para depuración
  const isValid = codeRegex.test(code);
  logger.info(
    "validationUtils",
    `Validando formato de código: ${code}, resultado: ${isValid}`
  );
  return isValid;
};
