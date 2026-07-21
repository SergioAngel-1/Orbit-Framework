export interface BannerSlide {
  id: string | number;
  image: string;
  title: string;
  subtitle?: string;
  cta?: string;
  ctaHref?: string;
  badge?: string;
  imageMobile?: string;
  link?: string;
  hideOverlay?: boolean;
  order?: number;
  placement?: string;
}

export interface BannerPlacement {
  slides: BannerSlide[];
  intervalMs: number;
}
