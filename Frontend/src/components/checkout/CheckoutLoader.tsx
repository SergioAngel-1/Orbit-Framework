import React from 'react';
import Loader from '../ui/Loader';

const CheckoutLoader: React.FC = () => {
  return (
    <div className="container mx-auto px-4 py-16 flex justify-center items-center min-h-[60vh]">
      <Loader size="large" />
    </div>
  );
};

export default CheckoutLoader;
