import React, { useEffect, useState } from "react";
import { useTranslation } from 'react-i18next';
import { FaCalendar } from "react-icons/fa";
import logger from "../../../utils/logger";
import Input, { ValidationStatus } from "./Input";

interface BirthDateInputProps {
  birthDate: string;
  setBirthDate: (value: string) => void;
  setIsAdult?: (value: boolean | null) => void;
  disabled?: boolean;
}

const BirthDateInput: React.FC<BirthDateInputProps> = ({
  birthDate,
  setBirthDate,
  setIsAdult,
  disabled = false,
}) => {
  const { t } = useTranslation('registerForm');

  // Estado para validación de fecha de nacimiento
  const [validationStatus, setValidationStatus] =
    useState<ValidationStatus>("none");
  const [validationMessage, setValidationMessage] = useState<string>("");

  // Verificar si el usuario es mayor de 18 años
  const checkAge = (birthDate: string) => {
    if (!birthDate) {
      setValidationStatus("none");
      setValidationMessage("");
      // Comunicar al componente padre que no hay validación
      if (setIsAdult) setIsAdult(null);
      return;
    }

    const today = new Date();
    const birthDateObj = new Date(birthDate);
    let age = today.getFullYear() - birthDateObj.getFullYear();
    const monthDiff = today.getMonth() - birthDateObj.getMonth();

    if (
      monthDiff < 0 ||
      (monthDiff === 0 && today.getDate() < birthDateObj.getDate())
    ) {
      age--;
    }

    const isUserAdult = age >= 18;

    // Comunicar el resultado de la validación al componente padre
    if (setIsAdult) setIsAdult(isUserAdult);

    if (!isUserAdult) {
      setValidationStatus("invalid");
      setValidationMessage(
        t('birthDate.underage')
      );
    } else {
      setValidationStatus("valid");
      setValidationMessage(t('birthDate.valid'));
    }
  };

  // Manejar cambio en el campo de fecha de nacimiento
  const handleBirthDateChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newBirthDate = e.target.value;
    logger.info("BirthDateInput: Fecha seleccionada:", newBirthDate);
    // Llamar directamente a la función setBirthDate del componente padre
    setBirthDate(newBirthDate);
    // Verificar la edad con el nuevo valor
    checkAge(newBirthDate);
  };

  // Verificar edad cuando cambia la fecha de nacimiento
  useEffect(() => {
    checkAge(birthDate);
    logger.info("BirthDateInput useEffect - Fecha actual:", birthDate);
  }, [birthDate]);

  // Asegurarse de que la fecha se inicialice correctamente
  useEffect(() => {
    if (birthDate) {
      logger.info("BirthDateInput - Inicializando con fecha:", birthDate);
      checkAge(birthDate);
    }
  }, []);

  return (
    <Input
      id="reg-birthdate"
      name="birthDate"
      type="date"
      value={birthDate}
      onChange={handleBirthDateChange}
      required={true}
      disabled={disabled}
      icon={<FaCalendar />}
      label={t('birthDate.label')}
      labelRequired={true}
      validationStatus={validationStatus}
      validationMessage={validationMessage}
    />
  );
};

export default BirthDateInput;
