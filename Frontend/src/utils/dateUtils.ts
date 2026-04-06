/**
 * Utilidades para el manejo de fechas
 */

/**
 * Formatea una fecha en formato legible
 * @param date Fecha a formatear
 * @returns Fecha formateada en formato dd/mm/yyyy hh:mm
 */
export const formatDate = (date: Date): string => {
  const day = date.getDate().toString().padStart(2, '0');
  const month = (date.getMonth() + 1).toString().padStart(2, '0');
  const year = date.getFullYear();
  const hours = date.getHours().toString().padStart(2, '0');
  const minutes = date.getMinutes().toString().padStart(2, '0');
  
  return `${day}/${month}/${year} ${hours}:${minutes}`;
};
