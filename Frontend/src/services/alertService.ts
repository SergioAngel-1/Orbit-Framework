import alertify from 'alertifyjs';
import 'alertifyjs/build/css/alertify.css';
import 'alertifyjs/build/css/themes/default.css';
import i18n from '../config/i18n';

// Configuración global de alertify
alertify.defaults.transition = 'slide';
alertify.defaults.theme.ok = 'btn btn-primario';
alertify.defaults.theme.cancel = 'btn btn-secundario';
alertify.defaults.theme.input = 'form-control';
alertify.defaults.notifier.position = 'bottom-left';
alertify.defaults.notifier.delay = 4;
alertify.defaults.notifier.closeButton = false;

// Estilizar las notificaciones mediante CSS
const addCustomStyles = () => {
  // Agregar estilos personalizados al head del documento
  const styleEl = document.createElement('style');
  styleEl.textContent = `
    /* Variables para personalización */
    :root {
      --alert-font-family: 'Poppins', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      --alert-font-size: 0.875rem;
      --alert-line-height: 1.5;
      --alert-border-radius: 8px;
      --alert-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      --alert-padding: 14px 20px;
      --alert-margin: 0.5rem;
      --alert-border-width: 4px;
      
      /* Colores para diferentes tipos de alertas */
      --alert-bg: var(--claro, #F5E6E8);
      --alert-text: var(--primario, #B91E59);
      --alert-border-success: var(--primario, #B91E59);
      --alert-border-error: var(--error-color, #dc3545);
      --alert-border-warning: var(--warning-color, #ffc107);
      --alert-border-info: var(--secundario, #EBC7E1);
    }

    /* Estilo base para todas las notificaciones */
    .alertify-notifier .ajs-message {
      color: var(--alert-text) !important;
      background-color: var(--alert-bg) !important;
      border-radius: var(--alert-border-radius) !important;
      box-shadow: var(--alert-shadow) !important;
      padding: var(--alert-padding) !important;
      font-family: var(--alert-font-family) !important;
      font-size: var(--alert-font-size) !important;
      line-height: var(--alert-line-height) !important;
      font-weight: 400 !important;
      min-width: 280px !important;
      max-width: 450px !important;
      margin: var(--alert-margin) !important;
      border: 1px solid rgba(185, 30, 89, 0.2) !important;
      border-left: var(--alert-border-width) solid var(--alert-border-success) !important;
      text-align: left !important;
      text-shadow: none !important;
      -webkit-text-stroke: 0 !important;
      text-rendering: optimizeLegibility !important;
    }

    /* Estilos específicos por tipo - todos con mismo esquema de colores */
    .alertify-notifier .ajs-message.ajs-success {
      background-color: var(--claro, #F5E6E8) !important;
      color: var(--primario, #B91E59) !important;
      border-left-color: var(--primario, #B91E59) !important;
      text-shadow: none !important;
      -webkit-text-stroke: 0 !important;
    }

    .alertify-notifier .ajs-message.ajs-error {
      background-color: var(--claro, #F5E6E8) !important;
      color: var(--primario, #B91E59) !important;
      border-left-color: var(--error-color, #dc3545) !important;
      text-shadow: none !important;
      -webkit-text-stroke: 0 !important;
    }

    .alertify-notifier .ajs-message.ajs-warning {
      background-color: var(--claro, #F5E6E8) !important;
      color: var(--primario, #B91E59) !important;
      border-left-color: var(--warning-color, #ffc107) !important;
      text-shadow: none !important;
      -webkit-text-stroke: 0 !important;
    }

    .alertify-notifier .ajs-message.ajs-message {
      background-color: var(--claro, #F5E6E8) !important;
      color: var(--primario, #B91E59) !important;
      border-left-color: var(--secundario, #EBC7E1) !important;
      text-shadow: none !important;
      -webkit-text-stroke: 0 !important;
    }

    /* Asegurar que todo el texto dentro de las alertas sea consistente */
    .alertify-notifier .ajs-message * {
      font-family: var(--alert-font-family) !important;
      font-size: var(--alert-font-size) !important;
      line-height: var(--alert-line-height) !important;
      font-weight: 400 !important;
      color: var(--primario, #B91E59) !important;
      text-shadow: none !important;
      -webkit-text-stroke: 0 !important;
      text-rendering: optimizeLegibility !important;
    }

    /* Reset de estilos que puedan causar el efecto duplicado */
    .alertify-notifier .ajs-message::before,
    .alertify-notifier .ajs-message::after {
      content: none !important;
      display: none !important;
    }

    /* Asegurar que no haya pseudo-elementos en el texto */
    .alertify-notifier .ajs-message.ajs-success::before,
    .alertify-notifier .ajs-message.ajs-success::after {
      content: none !important;
      display: none !important;
    }

    /* Animación mejorada */
    .alertify-notifier.ajs-bottom.ajs-left .ajs-message {
      transform: translateX(-110%);
      animation: slideInLeft 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .alertify-notifier .ajs-message.ajs-visible {
      animation: slideInLeft 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    }

    @keyframes slideInLeft {
      0% {
        transform: translateX(-110%);
        opacity: 0;
      }
      100% {
        transform: translateX(0);
        opacity: 1;
      }
    }

    /* Contenedor de notificaciones toast - debe estar por encima de modales */
    .alertify-notifier {
      z-index: 10001 !important;
      position: fixed !important;
    }

    /* Contenedor principal de alertify (diálogos) - debe estar por encima de modales */
    .alertify {
      z-index: 10000 !important;
      position: fixed !important;
    }

    /* Fondo con blur - debe estar DEBAJO del diálogo */
    .alertify .ajs-dimmer {
      background-color: rgba(0, 0, 0, 0.6) !important;
      backdrop-filter: blur(5px) !important;
      -webkit-backdrop-filter: blur(5px) !important;
      z-index: 1 !important;
      position: fixed !important;
    }

    /* Estilos para diálogos de confirmación - debe estar ENCIMA del dimmer */
    .alertify .ajs-dialog {
      border-radius: var(--alert-border-radius) !important;
      box-shadow: var(--alert-shadow) !important;
      border: none !important;
      max-width: 400px !important;
      text-align: center !important;
      background-color: white !important;
      font-family: var(--alert-font-family) !important;
      z-index: 2 !important;
      position: relative !important;
      display: block !important;
      visibility: visible !important;
      opacity: 1 !important;
    }

    .alertify .ajs-header {
      color: var(--texto, #3A2A2F) !important;
      border-bottom: 1px solid rgba(0, 0, 0, 0.1) !important;
      padding: 16px !important;
      font-weight: 600 !important;
      font-size: 1.1rem !important;
      text-align: center !important;
      font-family: var(--alert-font-family) !important;
      background: white !important;
    }

    .alertify .ajs-body {
      color: var(--texto, #3A2A2F) !important;
      padding: 20px !important;
      text-align: center !important;
      font-family: var(--alert-font-family) !important;
      font-size: var(--alert-font-size) !important;
      line-height: var(--alert-line-height) !important;
      font-weight: 400 !important;
    }

    .alertify .ajs-body .ajs-content {
      padding: 8px 0 !important;
      text-align: center !important;
      font-weight: 400 !important;
    }

    .alertify .ajs-footer {
      padding: 12px 16px !important;
      border-top: 1px solid rgba(0, 0, 0, 0.1) !important;
      text-align: center !important;
      background: rgba(0, 0, 0, 0.02) !important;
    }

    .alertify .ajs-footer .ajs-buttons {
      text-align: center !important;
      display: flex !important;
      justify-content: center !important;
      gap: 10px !important;
    }

    .alertify .ajs-footer .ajs-buttons .ajs-button {
      border-radius: 6px !important;
      padding: 8px 20px !important;
      font-size: 0.875rem !important;
      font-weight: 500 !important;
      transition: all 0.2s ease !important;
      min-width: 100px !important;
      font-family: var(--alert-font-family) !important;
      cursor: pointer !important;
    }

    /* Estilos de botones */
    .alertify .ajs-button.btn-primario, 
    .alertify .ajs-button.btn.btn-primario {
      background-color: var(--primario) !important;
      color: white !important;
      border: 1px solid var(--primario) !important;
    }
    
    .alertify .ajs-button.btn-primario:hover, 
    .alertify .ajs-button.btn.btn-primario:hover {
      background-color: var(--hover) !important;
      border-color: var(--hover) !important;
      transform: translateY(-1px) !important;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15) !important;
    }
    
    .alertify .ajs-button.btn-secundario, 
    .alertify .ajs-button.btn.btn-secundario {
      border: 1px solid var(--border) !important;
      background-color: white !important;
      color: var(--texto) !important;
    }
    
    .alertify .ajs-button.btn-secundario:hover, 
    .alertify .ajs-button.btn.btn-secundario:hover {
      background-color: var(--claro) !important;
      border-color: var(--primario) !important;
      color: var(--oscuro) !important;
      transform: translateY(-1px) !important;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1) !important;
    }

    /* Estilos para inputs en diálogos */
    .alertify .ajs-input {
      font-family: var(--alert-font-family) !important;
      font-size: var(--alert-font-size) !important;
      padding: 10px 14px !important;
      border: 1px solid var(--border) !important;
      border-radius: 6px !important;
      width: 100% !important;
      margin-top: 12px !important;
      font-weight: 400 !important;
      color: var(--texto) !important;
      background-color: white !important;
      transition: all 0.2s ease !important;
    }

    .alertify .ajs-input:focus {
      border-color: var(--primario) !important;
      outline: none !important;
      box-shadow: 0 0 0 3px rgba(185, 30, 89, 0.1) !important;
    }

    /* Asegurar que el texto no sea bold en las notificaciones */
    .alertify-notifier * {
      font-weight: 400 !important;
    }

    /* Mejorar la legibilidad */
    .alertify .ajs-dialog * {
      -webkit-font-smoothing: antialiased !important;
      -moz-osx-font-smoothing: grayscale !important;
    }

    /* Hover effect para las notificaciones */
    .alertify-notifier .ajs-message:hover {
      transform: translateY(-2px) !important;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2) !important;
      cursor: pointer !important;
    }
  `;
  document.head.appendChild(styleEl);
};

// Ejecutar inmediatamente para aplicar estilos
addCustomStyles();

const alertService = {
  success: (message: string) => {
    alertify.success(message);
  },
  
  error: (message: string) => {
    alertify.error(message);
  },
  
  warning: (message: string) => {
    alertify.warning(message);
  },
  
  info: (message: string) => {
    alertify.message(message);
  },
  
  confirm: (message: string, onOk: () => void, onCancel?: () => void) => {
    alertify.confirm(
      i18n.t('alerts:confirm.title'),
      message,
      () => onOk(),
      () => onCancel && onCancel()
    ).set({
      'labels': {ok: i18n.t('alerts:confirm.ok'), cancel: i18n.t('alerts:confirm.cancel')},
      'defaultFocus': 'ok',
      'movable': false,
      'transition': 'fade',
      'closableByDimmer': true,
      'closable': false,
      'padding': true,
      'overflow': false
    });
  },
  
  prompt: (message: string, defaultValue: string, onOk: (value: string) => void, onCancel?: () => void) => {
    alertify.prompt(
      i18n.t('alerts:prompt.title'),
      message,
      defaultValue,
      (_evt: Event, value: string) => onOk(value),
      () => onCancel && onCancel()
    ).set({
      'labels': {ok: i18n.t('alerts:prompt.ok'), cancel: i18n.t('alerts:prompt.cancel')},
      'defaultFocus': 'input',
      'movable': false,
      'transition': 'fade',
      'closableByDimmer': true,
      'closable': false,
      'padding': true,
      'overflow': false
    });
  }
};

// Función para mostrar una alerta de error del servidor
export const showServerErrorAlert = () => {
  const isProduction = import.meta.env.PROD;
  const message = isProduction
    ? i18n.t('alerts:server.errorProduction')
    : i18n.t('alerts:server.errorDevelopment');
  alertify.error(message, 8);
};

export default alertService;
