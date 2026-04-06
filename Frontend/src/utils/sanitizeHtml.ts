/**
 * Utilidad centralizada de sanitización HTML con DOMPurify
 * 
 * Proporciona funciones pre-configuradas para sanitizar HTML según el contexto:
 * - sanitizeRichContent: Para contenido rico de WordPress/API (legal, popups, descripciones)
 * - sanitizeInlineHtml: Para strings i18n que contienen tags inline (<strong>, <a>, <em>)
 * 
 * @package Starter
 * @version 1.0.0
 */

import DOMPurify from 'dompurify';

/**
 * Sanitiza contenido HTML rico proveniente de APIs (WordPress, CMS).
 * Permite tags de contenido editorial completo.
 */
export const sanitizeRichContent = (html: string): string => {
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: [
      'p', 'br', 'strong', 'em', 'b', 'i', 'u',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
      'ul', 'ol', 'li',
      'a', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
      'span', 'div', 'hr', 'sub', 'sup', 'pre', 'code',
      'figure', 'figcaption', 'img',
    ],
    ALLOWED_ATTR: [
      'href', 'target', 'rel', 'class', 'id',
      'src', 'alt', 'width', 'height', 'loading',
      'colspan', 'rowspan', 'scope',
    ],
    ALLOW_DATA_ATTR: false,
  });
};

/**
 * Sanitiza strings i18n que contienen tags HTML inline.
 * Solo permite tags de formato básico — ideal para traducciones con <strong>, <a>, <em>.
 */
export const sanitizeInlineHtml = (html: string): string => {
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS: ['strong', 'em', 'b', 'i', 'a', 'br', 'span'],
    ALLOWED_ATTR: ['href', 'target', 'rel', 'class'],
  });
};
