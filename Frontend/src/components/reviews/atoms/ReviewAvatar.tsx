/**
 * ReviewAvatar - Componente átomo para mostrar avatar del autor de una reseña
 * Avatar custom → fallback imagen genérica de miembro
 */

import { useState } from 'react';

const FALLBACK_AVATAR = '/assets/images/Reviews/Flores-Inc-Fallback-Member.webp';

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
  const [imgSrc, setImgSrc] = useState(avatarUrl || FALLBACK_AVATAR);

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
      <img
        src={imgSrc}
        alt={name}
        className="w-full h-full object-cover"
        loading="lazy"
        onError={() => setImgSrc(FALLBACK_AVATAR)}
      />
    </div>
  );
};

export default ReviewAvatar;
