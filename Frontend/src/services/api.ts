/**
 * @file api.ts
 * @description Archivo principal de exportación de servicios de API.
 * Este archivo ha sido refactorizado para mejorar la modularidad y mantenibilidad.
 * Ahora importa y exporta los servicios desde módulos individuales.
 * 
 * IMPORTANTE: Este archivo mantiene la misma API pública que antes de la refactorización
 * para garantizar compatibilidad con el código existente. Internamente, ahora utiliza
 * los servicios modularizados.
 */

// Importar todos los servicios desde el archivo centralizado
export * from './apiServices';
