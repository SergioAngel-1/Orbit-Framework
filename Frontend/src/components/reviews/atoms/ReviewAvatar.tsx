/**
 * ReviewAvatar - Componente átomo para mostrar avatar del autor de una reseña
 * Avatar custom → fallback icono genérico de usuario
 */

import { useState } from 'react';
import { FiUser } from 'react-icons/fi';

interface ReviewAvatarProps {
  /** URL del avatar */
  avatarUrl: string;
  /** Nombre del autor (para alt) */
  name: string;
  /** Tamaño en CSS */
  size?: string;
  /** Si es admin (borde destacado) */
  isAdmin?: boolean;
}

const ReviewAvatar = ({ avatarUrl, name, size = '2.25rem', isAdmin = false }: ReviewAvatarProps) => {
  const [imgError, setImgError] = useState(false);
  const showIcon = !avatarUrl || imgError;

  return (
    <div
      className={`relative flex-shrink-0 rounded-full overflow-hidden flex items-center justify-center ${
        isAdmin ? 'border-2 border-primario' : 'border border-gray-200'
      }`}
      style={{
        width: size,
        height: size,
        background: '#f3f4f6',
      }}
    >
      {showIcon ? (
        <FiUser className="w-1/2 h-1/2 text-gray-400" />
      ) : (
        <img
          src={avatarUrl}
          alt={name}
          className="w-full h-full object-cover"
          loading="lazy"
          onError={() => setImgError(true)}
        />
      )}
    </div>
  );
};

export default ReviewAvatar;
