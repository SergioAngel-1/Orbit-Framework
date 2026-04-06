/**
 * MembershipsPage - Página de membresías
 * Muestra información completa sobre el sistema de membresías
 * Diseño alineado con ReferidosPage, CartPage y CheckoutPage
 */

import { useState, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import logger from '../utils/logger';
import { useTranslation } from 'react-i18next';
import { 
  FiAward, FiHelpCircle, FiTrendingUp, FiCheck, FiGift, FiLock
} from 'react-icons/fi';
import { useLanguage } from '../contexts/LanguageContext';
import { useAuth } from '../contexts/AuthContext';
import { useMembership } from '../contexts/MembershipContext';
import { useActiveBenefits } from '../hooks/useActiveBenefits';
import CategoryDiscountCard from '../components/memberships/CategoryDiscountCard';
import BenefitCard from '../components/memberships/BenefitCard';
import { MembershipLevel } from '../services/membership/membershipTypes';
import useMembershipLevels from '../hooks/useMembershipLevels';
import {
  MembershipLevelsGrid,
  MembershipFAQ,
  MembershipCTA
} from '../components/memberships';
import FallbackBanner from '../components/common/FallbackBanner';
import CollapsibleSection from '../components/common/CollapsibleSection';
import MembershipPurchaseModal from '../components/memberships/MembershipPurchaseModal';
import alertService from '../services/alertService';
import { fluidSizing } from '../utils/fluidSizing';
import { getBenefitConfig, getUpcomingBenefits } from '../config/benefitsConfig';
import { useSEO } from '../hooks/useSEO';
import { transformClubText } from '../utils/clubNarrative';
import { OG_IMAGES, getBaseUrl } from '../utils/seo';

const MembershipsPage = () => {
  const { t } = useTranslation('membershipsPage');
  const { t: tBenefits } = useTranslation('benefitsConfig');

  // SEO: Meta tags optimizados para indexación en Google - Membresías
  useSEO({
    title: t('seo.title'),
    description: t('seo.description'),
    keywords: t('seo.keywords'),
    url: `${getBaseUrl()}/membresias`,
    type: 'website',
    image: OG_IMAGES.membresias,
    schema: {
      '@context': 'https://schema.org',
      '@type': 'WebPage',
      'name': t('seo.schemaName'),
      'description': t('seo.schemaDescription'),
      'url': `${getBaseUrl()}/membresias`,
      'isPartOf': {
        '@type': 'WebSite',
        'name': 'My Store',
        'url': getBaseUrl()
      },
      'breadcrumb': {
        '@type': 'BreadcrumbList',
        'itemListElement': [
          {
            '@type': 'ListItem',
            'position': 1,
            'name': t('seo.breadcrumbHome'),
            'item': getBaseUrl()
          },
          {
            '@type': 'ListItem',
            'position': 2,
            'name': t('seo.breadcrumbMemberships'),
            'item': `${getBaseUrl()}/membresias`
          }
        ]
      },
      'mainEntity': {
        '@type': 'ItemList',
        'name': t('seo.schemaListName'),
        'itemListElement': [
          { '@type': 'ListItem', 'position': 1, 'name': t('seo.schemaLevel1') },
          { '@type': 'ListItem', 'position': 2, 'name': t('seo.schemaLevel2') },
          { '@type': 'ListItem', 'position': 3, 'name': t('seo.schemaLevel3') },
          { '@type': 'ListItem', 'position': 4, 'name': t('seo.schemaLevel4') },
          { '@type': 'ListItem', 'position': 5, 'name': t('seo.schemaLevel5') },
          { '@type': 'ListItem', 'position': 6, 'name': t('seo.schemaLevel6') }
        ]
      }
    }
  });
  
  const { isAuthenticated } = useAuth();
  const { membership, membershipName, membershipColor, currentLevel, isActive, refreshMembership } = useMembership();
  const { getLevelById } = useMembershipLevels();
  
  const { benefits: activeBenefits, loading: benefitsLoading } = useActiveBenefits(isAuthenticated);
  const navigate = useNavigate();
  const location = useLocation();
  const { localizedPath } = useLanguage();
  
  // Estado para controlar el colapso de la sección de niveles (abierto por defecto)
  const [isLevelsCollapsed, setIsLevelsCollapsed] = useState(false);
  
  // Estado para controlar el colapso del FAQ
  const [isFAQExpanded, setIsFAQExpanded] = useState(!isAuthenticated);
  
  // Estado para el modal de compra de membresía
  const [selectedMembership, setSelectedMembership] = useState<MembershipLevel | null>(null);
  const [isPurchaseModalOpen, setIsPurchaseModalOpen] = useState(false);
  
  // Manejar hash de URL para scroll automático
  useEffect(() => {
    if (location.hash === '#niveles-section') {
      setTimeout(() => {
        setIsLevelsCollapsed(false);
        scrollToSection('niveles-section');
      }, 100);
    }
  }, [location.hash]);

  // Scroll suave contemplando el header sticky
  const scrollToSection = (sectionId: string) => {
    const element = document.getElementById(sectionId);
    if (element) {
      const headerOffset = 140; // Altura del header sticky + margen
      const elementPosition = element.getBoundingClientRect().top;
      const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
      
      window.scrollTo({
        top: offsetPosition,
        behavior: 'smooth'
      });
    }
  };

  const handleLevelSelect = (level: MembershipLevel) => {
    if (!isAuthenticated) {
      alertService.info(t('alerts.loginRequired'));
      navigate(localizedPath('/iniciar-sesion'));
      return;
    }
    
    if (level.is_free) {
      alertService.info(t('alerts.alreadyBasic'));
      return;
    }

    // Abrir modal de compra
    setSelectedMembership(level);
    setIsPurchaseModalOpen(true);
  };
  
  const handlePurchaseSuccess = async (_purchasedMembership: MembershipLevel) => {
    setIsPurchaseModalOpen(false);
    setSelectedMembership(null);
    
    // Refrescar el contexto de membresía para actualizar el estado sin recargar la página
    // Esto proporciona una mejor experiencia de usuario y mantiene el estado de la aplicación
    try {
      await refreshMembership();
      alertService.success(t('alerts.purchaseSuccess'));
    } catch (error) {
      // Si falla el refresh, el usuario puede recargar manualmente
      logger.error('MembershipsPage', 'Error al refrescar membresía:', error);
    }
  };

  return (
    <div className="container mx-auto" style={{ padding: fluidSizing.space.lg }}>
      {/* Header */}
      <div 
        className="flex flex-col sm:flex-row sm:items-center sm:justify-between" 
        style={{ gap: fluidSizing.space.md, marginBottom: fluidSizing.space.lg }}
      >
        <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
          <div 
            className="flex items-center justify-center rounded-full bg-primario/10"
            style={{ width: fluidSizing.size.floatingButton, height: fluidSizing.size.floatingButton }}
          >
            <FiAward className="text-primario" style={{ width: fluidSizing.size.iconMd, height: fluidSizing.size.iconMd }} />
          </div>
          <div>
            <h1 className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text['2xl'] }}>
              {t('pageTitle')}
            </h1>
            <p className="text-texto" style={{ fontSize: fluidSizing.text.sm }}>
              {t('pageSubtitle')}
            </p>
          </div>
        </div>
        <button
          onClick={() => {
            if (!isFAQExpanded) {
              setIsFAQExpanded(true);
            }
            setTimeout(() => scrollToSection('faq-section'), 100);
          }}
          className="flex items-center justify-center bg-primario hover:bg-hover hover:text-white text-white rounded-md transition-colors w-full sm:w-auto"
          style={{
            paddingLeft: fluidSizing.space.lg,
            paddingRight: fluidSizing.space.lg,
            paddingTop: fluidSizing.space.sm,
            paddingBottom: fluidSizing.space.sm,
            fontSize: fluidSizing.text.sm,
            gap: fluidSizing.space.xs
          }}
        >
          <FiHelpCircle style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
          {t('faqButton')}
        </button>
      </div>

      {/* SECCIÓN 1: Mi Membresía Actual (solo autenticados) o Banner introductorio */}
      {isAuthenticated ? (
        <button
          onClick={() => {
            window.dispatchEvent(new CustomEvent('openProfileModal', { detail: { section: 'membership' } }));
          }}
          className="w-full flex items-center justify-between bg-white rounded-lg border border-gray-100 shadow-sm mb-6 group hover:border-primario/30 transition-colors"
          style={{ padding: fluidSizing.space.sm }}
        >
          <div className="flex items-center" style={{ gap: fluidSizing.space.sm }}>
            <div 
              className="rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden border-2 border-secundario"
              style={{ 
                backgroundColor: `${membershipColor}15`, 
                width: fluidSizing.size.buttonLg, 
                height: fluidSizing.size.buttonLg 
              }}
            >
              {getLevelById(currentLevel)?.icon_url ? (
                <img 
                  src={getLevelById(currentLevel)?.icon_url} 
                  alt={membershipName}
                  className="w-full h-full object-cover"
                />
              ) : (
                <FiAward className="text-primario" style={{ width: '60%', height: '60%' }} />
              )}
            </div>
            <div className="text-left">
              <p className="font-bold text-oscuro" style={{ fontSize: fluidSizing.text.sm }}>
                {t('myMembership')}
              </p>
              <p className="text-texto" style={{ fontSize: fluidSizing.text.xs }}>
                {membershipName} {isActive && `• ${t('active')}`}
              </p>
            </div>
          </div>
          <div 
            className="flex items-center justify-center bg-primario group-hover:bg-hover text-white rounded-lg transition-colors flex-shrink-0"
            style={{ 
              padding: '6px 10px',
              gap: '4px'
            }}
          >
            <FiAward style={{ width: '14px', height: '14px' }} />
            <span style={{ fontSize: fluidSizing.text.xs }}>{t('viewDetails')}</span>
          </div>
        </button>
      ) : (
        /* Banner introductorio para usuarios no autenticados */
        <FallbackBanner
          title={t('unauthenticated.bannerTitle')}
          description={t('unauthenticated.bannerDescription')}
          tags={[
            { icon: <FiAward className="w-full h-full" />, text: t('unauthenticated.tagCoins') },
            { icon: <FiCheck className="w-full h-full" />, text: t('unauthenticated.tagDiscounts') },
            { icon: <FiTrendingUp className="w-full h-full" />, text: t('unauthenticated.tagVIP') }
          ]}
          primaryButtonPath="/registrarse"
          secondaryButtonPath="/iniciar-sesion"
        />
      )}

      {/* SECCIÓN 2: Mis Beneficios (solo usuarios autenticados con nivel > 0) */}
      {isAuthenticated && currentLevel > 0 && (
        <CollapsibleSection
          id="beneficios-section"
          title={t('benefits.title')}
          subtitle={t('benefits.subtitle', { name: membershipName })}
          icon={FiGift}
          collapsible={true}
          defaultExpanded={true}
          className="mb-6"
        >
          {benefitsLoading ? (
            <div className="text-center text-texto" style={{ padding: fluidSizing.space.lg }}>
              {t('benefits.loading')}
            </div>
          ) : activeBenefits.length > 0 ? (
            <>
              {/* Separar category_discount de los demás beneficios */}
              {(() => {
                const categoryDiscount = activeBenefits.find(b => b.key === 'category_discount');
                const otherBenefits = activeBenefits.filter(b => b.key !== 'category_discount');
                
                // Detectar si es mobile (< 768px)
                const isMobile = typeof window !== 'undefined' && window.innerWidth < 768;
                // En desktop: primeras 3 abiertas, en mobile: solo la primera
                const maxOpen = isMobile ? 1 : 3;
                
                return (
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 items-start" style={{ gap: fluidSizing.space.md }}>
                    {/* Tarjeta especial de descuento en categorías (índice 0) */}
                    {categoryDiscount && (
                      <CategoryDiscountCard 
                        benefit={categoryDiscount} 
                        defaultExpanded={0 < maxOpen}
                      />
                    )}
                    
                    {/* Otros beneficios */}
                    {otherBenefits.map((benefit, index) => {
                      const configBenefit = getBenefitConfig(benefit.key, tBenefits);
                      const IconComponent = configBenefit?.icon || FiGift;
                      // Índice real considerando si existe categoryDiscount
                      const realIndex = (categoryDiscount ? 1 : 0) + index;
                      const isHighlighted = realIndex < maxOpen;
                      
                      return (
                        <BenefitCard 
                          key={benefit.key} 
                          benefit={benefit} 
                          icon={IconComponent}
                          defaultExpanded={isHighlighted}
                          variant={isHighlighted ? 'default' : 'soft'}
                        />
                      );
                    })}
                  </div>
                );
              })()}
            </>
          ) : (
            // Fallback a config estática si no hay beneficios de la API
            <div className="text-center text-texto" style={{ padding: fluidSizing.space.lg }}>
              {t('benefits.empty')}
            </div>
          )}

          {/* Beneficios próximos (si hay niveles superiores y puede mejorar) */}
          {membership?.can_upgrade && (
            <div className="border-t border-secundario/30" style={{ marginTop: fluidSizing.space.lg, paddingTop: fluidSizing.space.lg }}>
              <div className="flex items-center text-texto" style={{ gap: fluidSizing.space.xs, marginBottom: fluidSizing.space.md }}>
                <FiLock style={{ width: fluidSizing.size.iconSm, height: fluidSizing.size.iconSm }} />
                <span style={{ fontSize: fluidSizing.text.sm }}>{t('benefits.upcomingLabel')}</span>
              </div>
              <div className="flex flex-wrap" style={{ gap: fluidSizing.space.sm }}>
                {getUpcomingBenefits(currentLevel, 4, tBenefits).map((benefit) => {
                  const IconComponent = benefit.icon;
                  return (
                    <span
                      key={benefit.key}
                      className="inline-flex items-center bg-secundario/30 text-texto rounded-full"
                      style={{ 
                        fontSize: fluidSizing.text.xs, 
                        paddingLeft: fluidSizing.space.md, 
                        paddingRight: fluidSizing.space.md, 
                        paddingTop: fluidSizing.space.xs, 
                        paddingBottom: fluidSizing.space.xs, 
                        gap: fluidSizing.space.xs 
                      }}
                    >
                      <IconComponent style={{ width: fluidSizing.size.iconXs, height: fluidSizing.size.iconXs }} />
                      <span>{transformClubText(benefit.title)}</span>
                    </span>
                  );
                })}
              </div>
            </div>
          )}
        </CollapsibleSection>
      )}

      {/* SECCIÓN 3: Niveles de Membresía (colapsable si está autenticado) */}
      <CollapsibleSection
        id="niveles-section"
        title={t('levels.title')}
        subtitle={t('levels.subtitle')}
        icon={FiAward}
        expanded={!isLevelsCollapsed}
        onExpandedChange={(expanded) => setIsLevelsCollapsed(!expanded)}
        collapsible={isAuthenticated}
        className="mb-6"
      >
        <MembershipLevelsGrid 
          onLevelSelect={handleLevelSelect} 
        />
      </CollapsibleSection>
      
      {/* FAQ */}
      <MembershipFAQ 
        expanded={isFAQExpanded}
        onExpandedChange={setIsFAQExpanded}
      />
      
      {/* CTA para no autenticados */}
      <MembershipCTA />
      
      {/* Modal de compra de membresía */}
      <MembershipPurchaseModal
        isOpen={isPurchaseModalOpen}
        onClose={() => {
          setIsPurchaseModalOpen(false);
          setSelectedMembership(null);
        }}
        membership={selectedMembership}
        onPurchaseSuccess={handlePurchaseSuccess}
      />
    </div>
  );
};

export default MembershipsPage;
