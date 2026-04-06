/**
 * ReviewReplyForm - Formulario inline para responder a una reseña
 * Solo visible para usuarios autenticados.
 */

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fluidSizing } from '../../utils/fluidSizing';

interface ReviewReplyFormProps {
  onSubmit: (content: string) => Promise<void>;
  onCancel: () => void;
}

const ReviewReplyForm = ({ onSubmit, onCancel }: ReviewReplyFormProps) => {
  const { t } = useTranslation('reviews');
  const [content, setContent] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = content.trim();
    if (trimmed.length < 10) {
      setError(t('replyTooShort', 'La respuesta debe tener al menos 10 caracteres.'));
      return;
    }

    try {
      setSubmitting(true);
      setError(null);
      await onSubmit(trimmed);
      setContent('');
    } catch (err: any) {
      setError(err.response?.data?.message || err.message || t('replyError', 'Error al enviar la respuesta'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="flex flex-col" style={{ gap: fluidSizing.space.xs }}>
      <textarea
        value={content}
        onChange={(e) => setContent(e.target.value)}
        placeholder={t('replyPlaceholder', 'Escribe tu respuesta...')}
        className="w-full border border-gray-200 rounded-lg text-texto focus:outline-none focus:border-primario/50 resize-none transition-colors"
        style={{
          fontSize: fluidSizing.text.sm,
          padding: fluidSizing.space.sm,
          minHeight: '4rem',
        }}
        maxLength={1000}
        disabled={submitting}
      />
      {error && (
        <p className="text-red-500" style={{ fontSize: fluidSizing.text['2xs'] }}>{error}</p>
      )}
      <div className="flex items-center justify-end" style={{ gap: fluidSizing.space.sm }}>
        <button
          type="button"
          onClick={onCancel}
          className="text-texto/60 hover:text-texto transition-colors"
          style={{ fontSize: fluidSizing.text.sm }}
          disabled={submitting}
        >
          {t('cancel', 'Cancelar')}
        </button>
        <button
          type="submit"
          className="bg-primario text-white rounded-lg font-medium hover:bg-hover transition-colors disabled:opacity-50"
          style={{
            fontSize: fluidSizing.text.sm,
            padding: `${fluidSizing.space.xs} ${fluidSizing.space.md}`,
          }}
          disabled={submitting || content.trim().length < 10}
        >
          {submitting ? t('sending', 'Enviando...') : t('sendReply', 'Responder')}
        </button>
      </div>
    </form>
  );
};

export default ReviewReplyForm;
