import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

// --- ES: Layout ---
import esTopbar from '../locales/es/layout/topbar.json';
import esHeader from '../locales/es/layout/header.json';
import esHeaderIcons from '../locales/es/layout/headerIcons.json';
import esSearchBar from '../locales/es/layout/searchBar.json';
import esAddressBar from '../locales/es/layout/addressBar.json';
import esMainMenu from '../locales/es/layout/mainMenu.json';
import esVirtualCoin from '../locales/es/layout/virtualCoin.json';
import esBannerCarousel from '../locales/es/home/bannerCarousel.json';
import esFeaturedCategories from '../locales/es/home/featuredCategories.json';
import esVirtualCoinsBanner from '../locales/es/home/virtualCoinsBanner.json';
import esHomeBenefits from '../locales/es/home/benefits.json';
import esCategoryCarousel from '../locales/es/layout/categoryCarousel.json';
import esHomeMembershipLevels from '../locales/es/home/membershipLevels.json';
import esHomeSocialNetworks from '../locales/es/home/socialNetworks.json';
import esFooter from '../locales/es/layout/footer.json';
import esLegalFramework from '../locales/es/layout/legalFramework.json';

// --- ES: Profile ---
import esProfileModal from '../locales/es/profile/profileModal.json';
import esProfileSection from '../locales/es/profile/profileSection.json';
import esAddressesSection from '../locales/es/profile/addressesSection.json';
import esOrdersSection from '../locales/es/profile/ordersSection.json';
import esReferralsSection from '../locales/es/profile/referralsSection.json';
import esMembershipSection from '../locales/es/profile/membershipSection.json';
import esDigitalCardSection from '../locales/es/profile/digitalCardSection.json';
import esAddressCard from '../locales/es/profile/addressCard.json';

// --- ES: Auth ---
import esPhoneInput from '../locales/es/auth/phoneInput.json';

// --- ES: Checkout ---
import esCheckoutSuccess from '../locales/es/checkout/checkoutSuccess.json';
import esFreeSamplesProgress from '../locales/es/checkout/freeSamplesProgress.json';
import esCheckoutPage from '../locales/es/checkout/checkoutPage.json';

// --- ES: Wallet ---
import esWalletComponents from '../locales/es/wallet/walletComponents.json';

// --- ES: Referrals ---
import esReferralComponents from '../locales/es/referrals/referralComponents.json';

// --- ES: Membership ---
import esMembershipComponents from '../locales/es/membership/membershipComponents.json';

// --- ES: UI ---
import esLoader from '../locales/es/ui/loader.json';

// --- ES: Modals ---
import esPointsModal from '../locales/es/modals/pointsModal.json';

// --- ES: Layout (cont.) ---
import esMobileMenu from '../locales/es/layout/mobileMenu.json';

// --- ES: Cart ---
import esCartModal from '../locales/es/cart/cartModal.json';

// --- ES: Help ---
import esHelpModal from '../locales/es/help/helpModal.json';

// --- ES: Products ---
import esProductCard from '../locales/es/products/productCard.json';

// --- ES: Home (cont.) ---
import esProductGrid from '../locales/es/home/productGrid.json';

// --- ES: UI (cont.) ---
import esVerMasButton from '../locales/es/ui/verMasButton.json';

// --- ES: Shop ---
import esShopPage from '../locales/es/shop/shopPage.json';

// --- ES: Pages ---
import esContactPage from '../locales/es/pages/contactPage.json';
import esWalletPage from '../locales/es/pages/walletPage.json';
import esCartPage from '../locales/es/pages/cartPage.json';
import esReferidosPage from '../locales/es/pages/referidosPage.json';
import esMembershipsPage from '../locales/es/pages/membershipsPage.json';
import esProductDetailPage from '../locales/es/pages/productDetailPage.json';
import esTouresPage from '../locales/es/pages/touresPage.json';
import esFaqPage from '../locales/es/pages/faqPage.json';
import esNotFoundPage from '../locales/es/pages/notFoundPage.json';
import esClubNarrative from '../locales/es/utils/clubNarrative.json';
import esQuantityCounter from '../locales/es/components/quantityCounter.json';
import esToures from '../locales/es/components/toures.json';

// --- ES: Popups ---
import esPopups from '../locales/es/popups/popups.json';

// --- ES: Pages (cont.) ---
import esHomePage from '../locales/es/pages/homePage.json';
import esSearchPage from '../locales/es/pages/searchPage.json';
import esResetPasswordPage from '../locales/es/pages/resetPasswordPage.json';
import esVerifyMemberPage from '../locales/es/pages/verifyMemberPage.json';

// --- ES: Legal ---
import esLegalPages from '../locales/es/legal/legalPages.json';

// --- ES: Account ---
import esPointsRewards from '../locales/es/account/pointsRewards.json';

// --- ES: Modals (cont.) ---
import esTransactionSuccessModal from '../locales/es/modals/transactionSuccessModal.json';

// --- ES: Common (cont.) ---
import esLoginButton from '../locales/es/common/loginButton.json';
import esCommonComponents from '../locales/es/common/commonComponents.json';
import esErrorBoundary from '../locales/es/common/errorBoundary.json';
import esAlerts from '../locales/es/common/alerts.json';
import esErrors from '../locales/es/common/errors.json';
import esSeo from '../locales/es/common/seo.json';

// --- ES: Auth Modals ---
import esAuthModals from '../locales/es/auth/authModals.json';

// --- ES: Checkout (cont.) ---
import esCheckoutComponents from '../locales/es/checkout/checkoutComponents.json';

// --- ES: Membership (cont.) ---
import esMembershipUpgrade from '../locales/es/membership/membershipUpgrade.json';

// --- ES: Products (cont.) ---
import esProductComponents from '../locales/es/products/productComponents.json';

// --- ES: Shop (cont.) ---
import esShopComponents from '../locales/es/shop/shopComponents.json';

// --- ES: Toures (cont.) ---
import esTourSections from '../locales/es/toures/tourSections.json';

// --- ES: Config ---
import esBenefitsConfig from '../locales/es/config/benefitsConfig.json';

// --- ES: UI (cont.) ---
import esUiComponents from '../locales/es/ui/uiComponents.json';

// --- ES: Reviews ---
import esReviews from '../locales/es/reviews/reviews.json';

// --- ES: Auth ---
import esLandingPage from '../locales/es/auth/landingPage.json';
import esLoginForm from '../locales/es/auth/loginForm.json';
import esRegisterForm from '../locales/es/auth/registerForm.json';
import esPasswordReset from '../locales/es/auth/passwordReset.json';

// --- EN: Layout ---
import enTopbar from '../locales/en/layout/topbar.json';
import enHeader from '../locales/en/layout/header.json';
import enHeaderIcons from '../locales/en/layout/headerIcons.json';
import enSearchBar from '../locales/en/layout/searchBar.json';
import enAddressBar from '../locales/en/layout/addressBar.json';
import enMainMenu from '../locales/en/layout/mainMenu.json';
import enVirtualCoin from '../locales/en/layout/virtualCoin.json';
import enBannerCarousel from '../locales/en/home/bannerCarousel.json';
import enFeaturedCategories from '../locales/en/home/featuredCategories.json';
import enVirtualCoinsBanner from '../locales/en/home/virtualCoinsBanner.json';
import enHomeBenefits from '../locales/en/home/benefits.json';
import enCategoryCarousel from '../locales/en/layout/categoryCarousel.json';
import enHomeMembershipLevels from '../locales/en/home/membershipLevels.json';
import enHomeSocialNetworks from '../locales/en/home/socialNetworks.json';
import enFooter from '../locales/en/layout/footer.json';
import enLegalFramework from '../locales/en/layout/legalFramework.json';

// --- EN: Profile ---
import enProfileModal from '../locales/en/profile/profileModal.json';
import enProfileSection from '../locales/en/profile/profileSection.json';
import enAddressesSection from '../locales/en/profile/addressesSection.json';
import enOrdersSection from '../locales/en/profile/ordersSection.json';
import enReferralsSection from '../locales/en/profile/referralsSection.json';
import enMembershipSection from '../locales/en/profile/membershipSection.json';
import enDigitalCardSection from '../locales/en/profile/digitalCardSection.json';
import enAddressCard from '../locales/en/profile/addressCard.json';

// --- EN: Auth ---
import enPhoneInput from '../locales/en/auth/phoneInput.json';

// --- EN: Checkout ---
import enCheckoutSuccess from '../locales/en/checkout/checkoutSuccess.json';
import enFreeSamplesProgress from '../locales/en/checkout/freeSamplesProgress.json';
import enCheckoutPage from '../locales/en/checkout/checkoutPage.json';

// --- EN: Wallet ---
import enWalletComponents from '../locales/en/wallet/walletComponents.json';

// --- EN: Referrals ---
import enReferralComponents from '../locales/en/referrals/referralComponents.json';

// --- EN: Membership ---
import enMembershipComponents from '../locales/en/membership/membershipComponents.json';

// --- EN: UI ---
import enLoader from '../locales/en/ui/loader.json';

// --- EN: Modals ---
import enPointsModal from '../locales/en/modals/pointsModal.json';

// --- EN: Layout (cont.) ---
import enMobileMenu from '../locales/en/layout/mobileMenu.json';

// --- EN: Cart ---
import enCartModal from '../locales/en/cart/cartModal.json';

// --- EN: Help ---
import enHelpModal from '../locales/en/help/helpModal.json';

// --- EN: Products ---
import enProductCard from '../locales/en/products/productCard.json';

// --- EN: Home (cont.) ---
import enProductGrid from '../locales/en/home/productGrid.json';

// --- EN: UI (cont.) ---
import enVerMasButton from '../locales/en/ui/verMasButton.json';

// --- EN: Shop ---
import enShopPage from '../locales/en/shop/shopPage.json';

// --- EN: Pages ---
import enContactPage from '../locales/en/pages/contactPage.json';
import enWalletPage from '../locales/en/pages/walletPage.json';
import enCartPage from '../locales/en/pages/cartPage.json';
import enReferidosPage from '../locales/en/pages/referidosPage.json';
import enMembershipsPage from '../locales/en/pages/membershipsPage.json';
import enProductDetailPage from '../locales/en/pages/productDetailPage.json';
import enTouresPage from '../locales/en/pages/touresPage.json';
import enFaqPage from '../locales/en/pages/faqPage.json';
import enNotFoundPage from '../locales/en/pages/notFoundPage.json';
import enClubNarrative from '../locales/en/utils/clubNarrative.json';
import enQuantityCounter from '../locales/en/components/quantityCounter.json';
import enToures from '../locales/en/components/toures.json';

// --- EN: Popups ---
import enPopups from '../locales/en/popups/popups.json';

// --- EN: Pages (cont.) ---
import enHomePage from '../locales/en/pages/homePage.json';
import enSearchPage from '../locales/en/pages/searchPage.json';
import enResetPasswordPage from '../locales/en/pages/resetPasswordPage.json';
import enVerifyMemberPage from '../locales/en/pages/verifyMemberPage.json';

// --- EN: Legal ---
import enLegalPages from '../locales/en/legal/legalPages.json';

// --- EN: Account ---
import enPointsRewards from '../locales/en/account/pointsRewards.json';

// --- EN: Modals (cont.) ---
import enTransactionSuccessModal from '../locales/en/modals/transactionSuccessModal.json';

// --- EN: Common (cont.) ---
import enLoginButton from '../locales/en/common/loginButton.json';
import enCommonComponents from '../locales/en/common/commonComponents.json';
import enErrorBoundary from '../locales/en/common/errorBoundary.json';
import enAlerts from '../locales/en/common/alerts.json';
import enErrors from '../locales/en/common/errors.json';
import enSeo from '../locales/en/common/seo.json';

// --- EN: Auth Modals ---
import enAuthModals from '../locales/en/auth/authModals.json';

// --- EN: Checkout (cont.) ---
import enCheckoutComponents from '../locales/en/checkout/checkoutComponents.json';

// --- EN: Membership (cont.) ---
import enMembershipUpgrade from '../locales/en/membership/membershipUpgrade.json';

// --- EN: Products (cont.) ---
import enProductComponents from '../locales/en/products/productComponents.json';

// --- EN: Shop (cont.) ---
import enShopComponents from '../locales/en/shop/shopComponents.json';

// --- EN: Toures (cont.) ---
import enTourSections from '../locales/en/toures/tourSections.json';

// --- EN: Config ---
import enBenefitsConfig from '../locales/en/config/benefitsConfig.json';

// --- EN: UI (cont.) ---
import enUiComponents from '../locales/en/ui/uiComponents.json';

// --- EN: Reviews ---
import enReviews from '../locales/en/reviews/reviews.json';

// --- EN: Auth ---
import enLandingPage from '../locales/en/auth/landingPage.json';
import enLoginForm from '../locales/en/auth/loginForm.json';
import enRegisterForm from '../locales/en/auth/registerForm.json';
import enPasswordReset from '../locales/en/auth/passwordReset.json';

// Helper para leer siteName desde caché localStorage de SiteConfig
function getCachedSiteName(): string {
  try {
    const raw = localStorage.getItem('site_config_cache');
    if (!raw) return 'My Store';
    const parsed = JSON.parse(raw);
    const data = parsed?.data ?? parsed;
    return data?.identity?.site_name || 'My Store';
  } catch { return 'My Store'; }
}

/**
 * Configuración de i18next
 * 
 * Patrón modular: cada sección/componente tiene su propio namespace.
 * Estructura de archivos: src/locales/{lang}/{section}/{component}.json
 * 
 * Para agregar traducciones de un nuevo componente:
 * 1. Crear archivos JSON en src/locales/es/{section}/ y src/locales/en/{section}/
 * 2. Importarlos aquí
 * 3. Agregarlos al objeto resources bajo el namespace correspondiente
 * 4. Usar en el componente: const { t } = useTranslation('nombreNamespace');
 */
i18n
  .use(initReactI18next)
  .init({
    resources: {
      es: {
        topbar: esTopbar,
        header: esHeader,
        headerIcons: esHeaderIcons,
        searchBar: esSearchBar,
        addressBar: esAddressBar,
        mainMenu: esMainMenu,
        virtualCoin: esVirtualCoin,
        homeBannerCarousel: esBannerCarousel,
        homeFeaturedCategories: esFeaturedCategories,
        homeVirtualCoinsBanner: esVirtualCoinsBanner,
        homeBenefits: esHomeBenefits,
        layoutCategoryCarousel: esCategoryCarousel,
        homeMembershipLevels: esHomeMembershipLevels,
        homeSocialNetworks: esHomeSocialNetworks,
        layoutFooter: esFooter,
        layoutLegalFramework: esLegalFramework,
        profileModal: esProfileModal,
        profileSection: esProfileSection,
        addressesSection: esAddressesSection,
        ordersSection: esOrdersSection,
        referralsSection: esReferralsSection,
        membershipSection: esMembershipSection,
        digitalCardSection: esDigitalCardSection,
        addressCard: esAddressCard,
        phoneInput: esPhoneInput,
        checkoutSuccess: esCheckoutSuccess,
        freeSamplesProgress: esFreeSamplesProgress,
        checkoutPage: esCheckoutPage,
        walletComponents: esWalletComponents,
        referralComponents: esReferralComponents,
        membershipComponents: esMembershipComponents,
        loader: esLoader,
        pointsModal: esPointsModal,
        mobileMenu: esMobileMenu,
        cartModal: esCartModal,
        helpModal: esHelpModal,
        productCard: esProductCard,
        homeProductGrid: esProductGrid,
        verMasButton: esVerMasButton,
        landingPage: esLandingPage,
        loginForm: esLoginForm,
        registerForm: esRegisterForm,
        passwordReset: esPasswordReset,
        shopPage: esShopPage,
        contactPage: esContactPage,
        walletPage: esWalletPage,
        cartPage: esCartPage,
        referidosPage: esReferidosPage,
        membershipsPage: esMembershipsPage,
        productDetailPage: esProductDetailPage,
        touresPage: esTouresPage,
        faqPage: esFaqPage,
        notFoundPage: esNotFoundPage,
        clubNarrative: esClubNarrative,
        quantityCounter: esQuantityCounter,
        toures: esToures,
        popups: esPopups,
        homePage: esHomePage,
        searchPage: esSearchPage,
        resetPasswordPage: esResetPasswordPage,
        verifyMemberPage: esVerifyMemberPage,
        legalPages: esLegalPages,
        pointsRewards: esPointsRewards,
        transactionSuccessModal: esTransactionSuccessModal,
        loginButton: esLoginButton,
        authModals: esAuthModals,
        checkoutComponents: esCheckoutComponents,
        commonComponents: esCommonComponents,
        errorBoundary: esErrorBoundary,
        alerts: esAlerts,
        membershipUpgrade: esMembershipUpgrade,
        productComponents: esProductComponents,
        shopComponents: esShopComponents,
        tourSections: esTourSections,
        uiComponents: esUiComponents,
        benefitsConfig: esBenefitsConfig,
        errors: esErrors,
        seo: esSeo,
        reviews: esReviews,
      },
      en: {
        topbar: enTopbar,
        header: enHeader,
        headerIcons: enHeaderIcons,
        searchBar: enSearchBar,
        addressBar: enAddressBar,
        mainMenu: enMainMenu,
        virtualCoin: enVirtualCoin,
        homeBannerCarousel: enBannerCarousel,
        homeFeaturedCategories: enFeaturedCategories,
        homeVirtualCoinsBanner: enVirtualCoinsBanner,
        homeBenefits: enHomeBenefits,
        layoutCategoryCarousel: enCategoryCarousel,
        homeMembershipLevels: enHomeMembershipLevels,
        homeSocialNetworks: enHomeSocialNetworks,
        layoutFooter: enFooter,
        layoutLegalFramework: enLegalFramework,
        profileModal: enProfileModal,
        profileSection: enProfileSection,
        addressesSection: enAddressesSection,
        ordersSection: enOrdersSection,
        referralsSection: enReferralsSection,
        membershipSection: enMembershipSection,
        digitalCardSection: enDigitalCardSection,
        addressCard: enAddressCard,
        phoneInput: enPhoneInput,
        checkoutSuccess: enCheckoutSuccess,
        freeSamplesProgress: enFreeSamplesProgress,
        checkoutPage: enCheckoutPage,
        walletComponents: enWalletComponents,
        referralComponents: enReferralComponents,
        membershipComponents: enMembershipComponents,
        loader: enLoader,
        pointsModal: enPointsModal,
        mobileMenu: enMobileMenu,
        cartModal: enCartModal,
        helpModal: enHelpModal,
        productCard: enProductCard,
        homeProductGrid: enProductGrid,
        verMasButton: enVerMasButton,
        landingPage: enLandingPage,
        loginForm: enLoginForm,
        registerForm: enRegisterForm,
        passwordReset: enPasswordReset,
        shopPage: enShopPage,
        contactPage: enContactPage,
        walletPage: enWalletPage,
        cartPage: enCartPage,
        referidosPage: enReferidosPage,
        membershipsPage: enMembershipsPage,
        productDetailPage: enProductDetailPage,
        touresPage: enTouresPage,
        faqPage: enFaqPage,
        notFoundPage: enNotFoundPage,
        clubNarrative: enClubNarrative,
        quantityCounter: enQuantityCounter,
        toures: enToures,
        popups: enPopups,
        homePage: enHomePage,
        searchPage: enSearchPage,
        resetPasswordPage: enResetPasswordPage,
        verifyMemberPage: enVerifyMemberPage,
        legalPages: enLegalPages,
        pointsRewards: enPointsRewards,
        transactionSuccessModal: enTransactionSuccessModal,
        loginButton: enLoginButton,
        authModals: enAuthModals,
        checkoutComponents: enCheckoutComponents,
        commonComponents: enCommonComponents,
        errorBoundary: enErrorBoundary,
        alerts: enAlerts,
        membershipUpgrade: enMembershipUpgrade,
        productComponents: enProductComponents,
        shopComponents: enShopComponents,
        tourSections: enTourSections,
        uiComponents: enUiComponents,
        benefitsConfig: enBenefitsConfig,
        errors: enErrors,
        seo: enSeo,
        reviews: enReviews,
      },
    },
    lng: 'es',
    fallbackLng: 'es',
    defaultNS: 'header',
    ns: ['topbar', 'header', 'headerIcons', 'searchBar', 'addressBar', 'mainMenu', 'virtualCoin', 'homeBannerCarousel', 'homeFeaturedCategories', 'homeVirtualCoinsBanner', 'homeBenefits', 'homeMembershipLevels', 'homeSocialNetworks', 'layoutCategoryCarousel', 'layoutFooter', 'layoutLegalFramework', 'profileModal', 'profileSection', 'addressesSection', 'ordersSection', 'referralsSection', 'membershipSection', 'digitalCardSection', 'addressCard', 'phoneInput', 'checkoutSuccess', 'freeSamplesProgress', 'checkoutPage', 'walletComponents', 'referralComponents', 'membershipComponents', 'loader', 'pointsModal', 'mobileMenu', 'cartModal', 'helpModal', 'productCard', 'homeProductGrid', 'verMasButton', 'landingPage', 'loginForm', 'registerForm', 'passwordReset', 'shopPage', 'contactPage', 'walletPage', 'cartPage', 'referidosPage', 'membershipsPage', 'productDetailPage', 'touresPage', 'faqPage', 'notFoundPage', 'clubNarrative', 'quantityCounter', 'toures', 'popups', 'homePage', 'searchPage', 'resetPasswordPage', 'verifyMemberPage', 'legalPages', 'pointsRewards', 'transactionSuccessModal', 'loginButton', 'authModals', 'checkoutComponents', 'commonComponents', 'errorBoundary', 'alerts', 'membershipUpgrade', 'productComponents', 'shopComponents', 'tourSections', 'uiComponents', 'benefitsConfig', 'errors', 'seo', 'reviews'],
    interpolation: {
      escapeValue: false, // React ya escapa por defecto
      defaultVariables: {
        siteName: getCachedSiteName(),
      },
    },
  });

export default i18n;
