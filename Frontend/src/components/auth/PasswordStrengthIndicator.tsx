import React from 'react';

interface PasswordStrengthIndicatorProps {
  strength: number;
  message: string;
  password: string;
}

/**
 * Componente para mostrar un indicador visual de la fortaleza de la contraseña
 */
const PasswordStrengthIndicator: React.FC<PasswordStrengthIndicatorProps> = ({ 
  strength, 
  message, 
  password 
}) => {
  return (
    <div className="mt-1">
      <div className="flex h-2 w-full bg-gray-200 rounded-full overflow-hidden">
        <div 
          className={`h-full ${
            strength === 0 ? 'w-0' :
            strength === 1 ? 'w-1/4 bg-red-500' :
            strength === 2 ? 'w-2/4 bg-yellow-500' :
            strength === 3 ? 'w-3/4 bg-blue-500' :
            'w-full bg-green-500'
          }`}
        />
      </div>
      {password && (
        <p className={`text-xs mt-1 ${
          strength < 3 ? 'text-red-500' : 'text-green-500'
        }`}>
          {message}
        </p>
      )}
    </div>
  );
};

export default PasswordStrengthIndicator;
