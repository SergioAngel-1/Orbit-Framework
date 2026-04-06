import { Component, ErrorInfo, ReactNode } from 'react';
import i18n from '../../config/i18n';
import logger from '../../utils/logger';
import { getLangFromPath, buildLocalizedPath } from '../../contexts/LanguageContext';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
  onError?: (error: Error, errorInfo: ErrorInfo) => void;
}

interface State {
  hasError: boolean;
  error: Error | null;
  errorInfo: ErrorInfo | null;
}

/**
 * Error Boundary Component
 * Captura errores en el árbol de componentes hijos y muestra un UI de fallback
 */
class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = {
      hasError: false,
      error: null,
      errorInfo: null
    };
  }

  static getDerivedStateFromError(error: Error): Partial<State> {
    // Actualizar el estado para que el siguiente render muestre el UI de fallback
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    // Loguear el error
    logger.error('ErrorBoundary', 'Error capturado:', {
      error: error.message,
      stack: error.stack,
      componentStack: errorInfo.componentStack
    });

    // Actualizar el estado con la información del error
    this.setState({
      error,
      errorInfo
    });

    // Llamar al callback personalizado si existe
    if (this.props.onError) {
      this.props.onError(error, errorInfo);
    }

    // En producción, podrías enviar el error a un servicio de tracking como Sentry
    if (import.meta.env.PROD) {
      // Ejemplo: Sentry.captureException(error, { extra: errorInfo });
      logger.error('ErrorBoundary', 'Error en producción:', { error, errorInfo });
    }
  }

  handleReset = (): void => {
    this.setState({
      hasError: false,
      error: null,
      errorInfo: null
    });
  };

  render(): ReactNode {
    if (this.state.hasError) {
      // Si se proporciona un fallback personalizado, usarlo
      if (this.props.fallback) {
        return this.props.fallback;
      }

      // UI de fallback por defecto
      return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
          <div className="max-w-md w-full bg-white shadow-lg rounded-lg p-6">
            <div className="flex items-center justify-center w-12 h-12 mx-auto bg-red-100 rounded-full mb-4">
              <svg
                className="w-6 h-6 text-red-600"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                />
              </svg>
            </div>

            <h2 className="text-2xl font-bold text-gray-900 text-center mb-2">
              {i18n.t('errorBoundary:title')}
            </h2>

            <p className="text-gray-600 text-center mb-6">
              {i18n.t('errorBoundary:description')}
            </p>

            {/* Mostrar detalles del error solo en desarrollo */}
            {!import.meta.env.PROD && this.state.error && (
              <details className="mb-4 p-4 bg-gray-100 rounded text-sm">
                <summary className="cursor-pointer font-semibold text-gray-700 mb-2">
                  {i18n.t('errorBoundary:errorDetails')}
                </summary>
                <div className="mt-2 space-y-2">
                  <div>
                    <strong className="text-red-600">Error:</strong>
                    <p className="text-gray-800 font-mono text-xs mt-1">
                      {this.state.error.message}
                    </p>
                  </div>
                  {this.state.error.stack && (
                    <div>
                      <strong className="text-red-600">Stack:</strong>
                      <pre className="text-gray-800 font-mono text-xs mt-1 overflow-x-auto">
                        {this.state.error.stack}
                      </pre>
                    </div>
                  )}
                  {this.state.errorInfo?.componentStack && (
                    <div>
                      <strong className="text-red-600">Component Stack:</strong>
                      <pre className="text-gray-800 font-mono text-xs mt-1 overflow-x-auto">
                        {this.state.errorInfo.componentStack}
                      </pre>
                    </div>
                  )}
                </div>
              </details>
            )}

            <div className="flex gap-3">
              <button
                onClick={this.handleReset}
                className="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded transition-colors"
              >
                {i18n.t('errorBoundary:tryAgain')}
              </button>
              <button
                onClick={() => { const { lang } = getLangFromPath(window.location.pathname); window.location.href = buildLocalizedPath('/', lang); }}
                className="flex-1 bg-primary hover:bg-primary-dark text-white font-semibold py-2 px-4 rounded transition-colors"
              >
                {i18n.t('errorBoundary:goHome')}
              </button>
            </div>

            <p className="text-xs text-gray-500 text-center mt-4">
              {i18n.t('errorBoundary:persistMessage')}
            </p>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
