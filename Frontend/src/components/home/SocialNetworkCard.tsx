import React from 'react';
import { 
  FaFacebook, 
  FaInstagram,
  FaWhatsapp,
  FaTelegram,
  FaTwitter,
  FaLinkedin,
  FaPinterest,
  FaYoutube,
  FaGlobe
} from 'react-icons/fa';
import MembershipBadge from '../common/MembershipBadge';
import { fluidSizing } from '../../utils/fluidSizing';
import logger from '../../utils/logger';

interface SocialNetworkCardProps {
  id: string;
  title: string;
  subtitle?: string;
  cta: string;
  link: string;
  icon: string;
  color: string;
  minMembershipLevel?: number;
}

// Función para renderizar el ícono de red social correspondiente (fuera del componente para evitar recreación)
const renderSocialIcon = (socialIcon: string, size: number = 28) => {
  // Primero intentamos interpretar el valor como una clase de Font Awesome
  const iconClass = socialIcon.toLowerCase();
  
  // Manejar clases comunes de Font Awesome
  if (iconClass.includes('fa-facebook')) {
    return <FaFacebook size={size} />;
  } else if (iconClass.includes('fa-instagram')) {
    return <FaInstagram size={size} />;
  } else if (iconClass.includes('fa-whatsapp')) {
    return <FaWhatsapp size={size} />;
  } else if (iconClass.includes('fa-telegram')) {
    return <FaTelegram size={size} />;
  } else if (iconClass.includes('fa-twitter')) {
    return <FaTwitter size={size} />;
  } else if (iconClass.includes('fa-linkedin')) {
    return <FaLinkedin size={size} />;
  } else if (iconClass.includes('fa-pinterest')) {
    return <FaPinterest size={size} />;
  } else if (iconClass.includes('fa-youtube')) {
    return <FaYoutube size={size} />;
  }
  
  // Si no es una clase de Font Awesome, intentamos con nombres simples
  switch (iconClass) {
    case 'facebook':
      return <FaFacebook size={size} />;
    case 'instagram':
      return <FaInstagram size={size} />;
    case 'whatsapp':
      return <FaWhatsapp size={size} />;
    case 'telegram':
      return <FaTelegram size={size} />;
    case 'twitter':
      return <FaTwitter size={size} />;
    case 'linkedin':
      return <FaLinkedin size={size} />;
    case 'pinterest':
      return <FaPinterest size={size} />;
    case 'youtube':
      return <FaYoutube size={size} />;
    default:
      logger.warn('SocialNetworkCard', `Icono no reconocido: ${iconClass}, usando icono genérico`);
      return <FaGlobe size={size} />;
  }
};

// Determinar el color predeterminado según la red social (fuera del componente para evitar recreación)
const getDefaultColor = (socialIcon: string): string => {
  const iconClass = socialIcon.toLowerCase();
  
  // Manejar clases de Font Awesome
  if (iconClass.includes('fa-facebook')) {
    return '#3b5998';
  } else if (iconClass.includes('fa-instagram')) {
    return '#e1306c';
  } else if (iconClass.includes('fa-whatsapp')) {
    return '#25D366';
  } else if (iconClass.includes('fa-telegram')) {
    return '#0088cc';
  } else if (iconClass.includes('fa-twitter')) {
    return '#1DA1F2';
  } else if (iconClass.includes('fa-linkedin')) {
    return '#0077B5';
  } else if (iconClass.includes('fa-pinterest')) {
    return '#E60023';
  } else if (iconClass.includes('fa-youtube')) {
    return '#FF0000';
  }
  
  // Manejar nombres simples
  switch (iconClass) {
    case 'facebook':
      return '#3b5998';
    case 'instagram':
      return '#e1306c';
    case 'whatsapp':
      return '#25D366';
    case 'telegram':
      return '#0088cc';
    case 'twitter':
      return '#1DA1F2';
    case 'linkedin':
      return '#0077B5';
    case 'pinterest':
      return '#E60023';
    case 'youtube':
      return '#FF0000';
    default:
      return '#3b5998';
  }
};

const SocialNetworkCard: React.FC<SocialNetworkCardProps> = React.memo(({
  title,
  subtitle,
  cta,
  link,
  icon,
  color,
  minMembershipLevel
}) => {
  // Usar el color proporcionado o el color predeterminado según la red social
  const socialColor = color || getDefaultColor(icon);

  return (
    <div className="w-full h-full transition-all duration-300 hover:-translate-y-1 group">
      <div 
        className="flex flex-col items-center h-full bg-white bg-opacity-50 backdrop-blur-sm rounded-xl hover:bg-opacity-70 transition-all duration-300 hover:shadow-md relative"
        style={{ padding: `${fluidSizing.space.md} ${fluidSizing.space.sm}` }}
      >
        {/* Badge de membresía */}
        {minMembershipLevel !== undefined && minMembershipLevel > 0 && (
          <div className="absolute" style={{ top: fluidSizing.space.xs, left: fluidSizing.space.xs }}>
            <MembershipBadge level={minMembershipLevel} size="xs" />
          </div>
        )}
        
        {/* Icono circular con borde y efecto de brillo */}
        <div 
          className="rounded-full flex items-center justify-center text-white shadow-lg transform transition-transform duration-300 group-hover:scale-110 relative"
          style={{ 
            backgroundColor: socialColor,
            width: fluidSizing.size.floatingButton,
            height: fluidSizing.size.floatingButton,
            marginBottom: fluidSizing.space.sm
          }}
        >
          <div className="absolute inset-0 rounded-full bg-white opacity-20 blur-sm"></div>
          <div className="relative z-10">
            {renderSocialIcon(icon, 24)}
          </div>
        </div>
        
        <div className="flex flex-col items-center justify-center">
          {/* Nombre de la red social */}
          <h3 
            className="font-bold text-center text-gray-800"
            style={{ fontSize: fluidSizing.text.sm, marginBottom: fluidSizing.space.xs }}
          >
            {title}
          </h3>
          
          {/* @ o identificador de la red social */}
          {subtitle && (
            <p 
              className="text-primario font-medium text-center"
              style={{ fontSize: fluidSizing.text.xs, marginBottom: fluidSizing.space.xs }}
            >
              @{subtitle.replace(/^@/, '')}
            </p>
          )}
        </div>
        
        <div className="text-center mt-auto" style={{ paddingTop: fluidSizing.space.xs }}>
          <a 
            href={link} 
            target="_blank" 
            rel="noopener noreferrer"
            className="inline-block border border-primario text-primario rounded-full font-medium hover:bg-primario hover:text-white transition-all duration-300 group-hover:shadow-md"
            style={{ 
              padding: `${fluidSizing.space.xs} ${fluidSizing.space.md}`,
              fontSize: fluidSizing.text.xs
            }}
          >
            {cta}
          </a>
        </div>
      </div>
    </div>
  );
});

SocialNetworkCard.displayName = 'SocialNetworkCard';

export default SocialNetworkCard;
