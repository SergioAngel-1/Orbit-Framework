/**
 * Marco Legal - Configuración de leyes y sentencias
 * 
 * Este archivo centraliza toda la información legal mostrada en el footer
 * para facilitar su actualización y mantenimiento.
 * 
 * Los textos se cargan desde los archivos de traducción (locales/es|en/layout/legalFramework.json)
 * para soportar internacionalización.
 */

import i18n from './i18n';

export interface LegalItem {
  id: string;
  title: string;
  description: string;
  fullWidth?: boolean;
}

/**
 * Leyes y sentencias que amparan la operación del sitio
 * Los textos se obtienen dinámicamente del sistema de traducción.
 */
export const getLegalFramework = (): LegalItem[] => {
  return i18n.t('layoutLegalFramework:items', { returnObjects: true }) as LegalItem[];
};

/**
 * Texto introductorio de la sección legal
 */
export const getLegalIntro = (): { title: string; subtitle: string } => {
  return {
    title: i18n.t('layoutLegalFramework:intro.title'),
    subtitle: i18n.t('layoutLegalFramework:intro.subtitle'),
  };
};

/**
 * Declaración de Retiro Asociativo - Checkout
 * Textos para el disclaimer obligatorio en el proceso de pago
 */
export interface WithdrawalDeclarationItem {
  id: string;
  title: string;
  description: string;
}

export const getWithdrawalDeclaration = (): { title: string; items: WithdrawalDeclarationItem[] } => {
  return {
    title: i18n.t('layoutLegalFramework:withdrawal.title'),
    items: i18n.t('layoutLegalFramework:withdrawal.items', { returnObjects: true }) as WithdrawalDeclarationItem[],
  };
};

// Constantes estáticas para compatibilidad con imports existentes
// Se evalúan al momento de la importación con el idioma activo
export const LEGAL_FRAMEWORK: LegalItem[] = getLegalFramework();
export const LEGAL_INTRO = getLegalIntro();
export const WITHDRAWAL_DECLARATION = getWithdrawalDeclaration();

export default LEGAL_FRAMEWORK;
