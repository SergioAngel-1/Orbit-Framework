import { ReactNode, useEffect } from 'react';
import Header from './Header';
import Footer from './Footer';
import FloatingInstagram from '../common/FloatingInstagram';
import FloatingOrderConfirm from '../common/FloatingOrderConfirm';
import { MembershipLevelsModalSingleton } from '../membership/MembershipLevelsModal';
import { useSocialNetworks } from '../../hooks/useSocialNetworks';

interface LayoutProps {
  children: ReactNode;
}

const Layout = ({ children }: LayoutProps) => {
  const { loading: socialNetworksLoading } = useSocialNetworks();

  // Asegurarse de que la página siempre comience desde arriba al cambiar de ruta
  useEffect(() => {
    window.scrollTo(0, 0);
  }, []);

  return (
    <div className="flex flex-col min-h-screen">
      <Header />
      <main className="flex-grow pt-4">
        {children}
      </main>
      {/* Envolver el Footer en un div para garantizar que siempre se renderice correctamente */}
      <div className="mt-auto">
        <Footer />
      </div>
      {/* Botones flotantes: ambos esperan a que socialNetworks esté listo */}
      <FloatingInstagram ready={!socialNetworksLoading} />
      <FloatingOrderConfirm ready={!socialNetworksLoading} />
      {/* Modal singleton de membresías - una sola instancia para toda la app */}
      <MembershipLevelsModalSingleton />
    </div>
  );
};

export default Layout;
