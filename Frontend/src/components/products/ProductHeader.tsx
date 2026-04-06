import React from 'react';
import MembershipBadge from '../common/MembershipBadge';
import { fluidSizing } from '../../utils/fluidSizing';

interface ProductHeaderProps {
  name: string;
  className?: string;
  minMembershipLevel?: number;
}

const ProductHeader: React.FC<ProductHeaderProps> = ({ 
  name, 
  className = '',
  minMembershipLevel
}) => {
  return (
    <div className={`${className} hidden md:block`} style={{ marginBottom: fluidSizing.space.md }}>
      {/* Título con badge alineado a la primera línea */}
      <div className="flex items-start" style={{ gap: fluidSizing.space.sm }}>
        {minMembershipLevel !== undefined && minMembershipLevel > 0 && (
          <div className="flex-shrink-0" style={{ marginTop: '0.25em' }}>
            <MembershipBadge level={minMembershipLevel} size="sm" />
          </div>
        )}
        <h1 
          className="font-bold text-oscuro product-animate"
          style={{ fontSize: fluidSizing.text['3xl'] }}
        >
          {name}
        </h1>
      </div>
    </div>
  );
};

export default ProductHeader;
