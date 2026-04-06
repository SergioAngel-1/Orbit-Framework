/**
 * useLanguagePrefix - Wrapper de conveniencia sobre LanguageContext
 * 
 * Re-exporta las utilidades de LanguageContext para mantener compatibilidad
 * y también re-exporta los helpers puros (getLangFromPath, buildLocalizedPath).
 * 
 * Uso preferido: importar directamente desde LanguageContext
 *   import { useLanguage } from '../contexts/LanguageContext';
 */
export { useLanguage as useLanguagePrefix, useLanguage as default } from '../contexts/LanguageContext';
export { getLangFromPath, buildLocalizedPath } from '../contexts/LanguageContext';

