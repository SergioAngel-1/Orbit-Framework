"use client";

import { useCallback, useEffect, useRef, useState, type PointerEvent } from "react";
import { Link } from "@/i18n/navigation";
import { Skeleton } from "@/components/ui";
import { cn } from "@/lib/utils";

export interface HeroSlide {
  id: string | number;
  image: string;
  title: string;
  subtitle?: string;
  cta?: string;
  ctaHref?: string;
  badge?: string;
}

export interface HeroCarouselProps {
  slides: HeroSlide[];
  autoPlay?: boolean;
  /** Milisegundos entre transiciones automáticas. */
  interval?: number;
  loading?: boolean;
  className?: string;
}

interface CarouselArrowProps {
  direction: "left" | "right";
  onClick: () => void;
  disabled?: boolean;
}

function CarouselArrow({ direction, onClick, disabled }: CarouselArrowProps) {
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation();
        onClick();
      }}
      disabled={disabled}
      className={cn(
        "group absolute top-1/2 z-30 flex h-12 w-12 -translate-y-1/2 items-center justify-center",
        "rounded-full border border-white/40 bg-white/30 shadow-lg backdrop-blur-md",
        "transition-all duration-300 hover:scale-110 hover:bg-white/50 hover:shadow-xl active:scale-95",
        "disabled:cursor-not-allowed disabled:opacity-40",
        direction === "left" ? "left-4" : "right-4",
      )}
      aria-label={direction === "left" ? "Anterior" : "Siguiente"}
    >
      <svg
        className={cn("h-5 w-5 text-white", direction === "right" && "rotate-180")}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M15 18l-6-6 6-6" />
      </svg>
    </button>
  );
}

export function HeroCarousel({
  slides,
  autoPlay = true,
  interval = 5000,
  loading = false,
  className,
}: HeroCarouselProps) {
  const [current, setCurrent] = useState(0);
  const [isHovered, setIsHovered] = useState(false);
  const [isDragging, setIsDragging] = useState(false);
  const [startX, setStartX] = useState(0);
  const [dragOffset, setDragOffset] = useState(0);
  const [progress, setProgress] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);

  const count = slides.length;

  const next = useCallback(() => {
    setCurrent((prev) => (prev + 1) % count);
    setProgress(0);
  }, [count]);

  const prev = useCallback(() => {
    setCurrent((prev) => (prev - 1 + count) % count);
    setProgress(0);
  }, [count]);

  const goToSlide = useCallback((index: number) => {
    setCurrent(index);
    setProgress(0);
  }, []);

  useEffect(() => {
    if (!autoPlay || isHovered || loading || isDragging || count <= 1) {
      setProgress(0);
      return;
    }
    const timer = setInterval(next, interval);
    const progressTimer = setInterval(() => {
      setProgress((prev) => (prev >= 100 ? 0 : prev + 100 / (interval / 100)));
    }, 100);
    return () => {
      clearInterval(timer);
      clearInterval(progressTimer);
    };
  }, [autoPlay, interval, isHovered, next, loading, isDragging, count]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "ArrowLeft") prev();
      else if (e.key === "ArrowRight") next();
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [next, prev]);

  const handlePointerDown = (e: PointerEvent<HTMLDivElement>) => {
    if ((e.target as HTMLElement).closest("button")) return;
    setIsDragging(true);
    setStartX(e.clientX);
    setDragOffset(0);
    setProgress(0);
    e.currentTarget.setPointerCapture(e.pointerId);
  };

  const handlePointerMove = (e: PointerEvent<HTMLDivElement>) => {
    if (!isDragging) return;
    const diff = e.clientX - startX;
    const maxDrag = (containerRef.current?.offsetWidth ?? 500) * 0.4;
    setDragOffset(Math.max(-maxDrag, Math.min(maxDrag, diff)));
  };

  const handlePointerUp = () => {
    if (!isDragging) return;
    setIsDragging(false);
    const threshold = 50;
    if (dragOffset < -threshold) next();
    else if (dragOffset > threshold) prev();
    setDragOffset(0);
  };

  if (loading) {
    return (
      <div className={cn("relative", className)}>
        <Skeleton variant="block" className="h-[480px] w-full rounded-2xl" />
        <div className="absolute bottom-6 left-1/2 flex -translate-x-1/2 gap-2">
          <Skeleton variant="circle" className="h-2.5 w-2.5" />
          <Skeleton variant="circle" className="h-2.5 w-2.5" />
          <Skeleton variant="circle" className="h-2.5 w-2.5" />
        </div>
      </div>
    );
  }

  const baseTranslate = -(current * 100);

  return (
    <div
      ref={containerRef}
      className={cn(
        "relative cursor-grab overflow-hidden rounded-2xl shadow-xl active:cursor-grabbing",
        className,
      )}
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
      onPointerDown={handlePointerDown}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
      onPointerCancel={handlePointerUp}
      role="region"
      aria-label="Carrusel de banners"
      tabIndex={0}
    >
      <div
        className="flex"
        style={{
          transform: `translateX(calc(${baseTranslate}% + ${isDragging ? dragOffset : 0}px))`,
          transition: isDragging ? "none" : "transform 500ms ease-out",
        }}
      >
        {slides.map((slide, index) => (
          <div
            key={slide.id}
            className="relative h-[480px] min-w-full"
            aria-hidden={index !== current}
          >
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={slide.image}
              alt={slide.title}
              className="pointer-events-none h-full w-full select-none object-cover"
              draggable={false}
            />
            <div className="absolute inset-0 bg-gradient-to-r from-gray-900/75 via-gray-900/40 to-transparent" />
            <div className="absolute inset-0 flex items-center px-8 md:px-24">
              <div className="max-w-lg">
                {slide.badge && (
                  <span className="mb-4 inline-block rounded-full bg-accent px-3 py-1 text-[10px] tracking-wide text-[--foreground] shadow-md">
                    {slide.badge}
                  </span>
                )}
                <h2 className="mb-3 font-heading text-[36px] leading-tight text-white">
                  {slide.title}
                </h2>
                {slide.subtitle && (
                  <p className="mb-5 font-sans text-[14px] leading-relaxed text-white/90">
                    {slide.subtitle}
                  </p>
                )}
                {slide.cta && slide.ctaHref && (
                  <Link
                    href={slide.ctaHref}
                    className="inline-block transform rounded-lg bg-accent px-6 py-2.5 font-heading text-sm text-[--foreground] shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:bg-accent/90 hover:shadow-lg"
                  >
                    {slide.cta}
                  </Link>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>

      {count > 1 && (
        <>
          <CarouselArrow direction="left" onClick={prev} disabled={isDragging} />
          <CarouselArrow direction="right" onClick={next} disabled={isDragging} />

          <div className="absolute bottom-5 left-1/2 z-10 flex -translate-x-1/2 gap-2.5">
            {slides.map((_, idx) => (
              <button
                key={idx}
                type="button"
                onClick={() => goToSlide(idx)}
                disabled={isDragging}
                className={cn(
                  "relative h-2.5 rounded-full transition-all duration-300",
                  "disabled:cursor-not-allowed disabled:opacity-50",
                  current === idx ? "w-7 bg-accent shadow-md" : "w-2.5 bg-white/40 hover:bg-white/70",
                )}
                aria-label={`Ir a slide ${idx + 1}`}
                aria-current={current === idx}
              >
                {current === idx && autoPlay && !isHovered && !isDragging && (
                  <div
                    className="absolute inset-0 rounded-full bg-white/30"
                    style={{ width: `${progress}%`, transition: "width 100ms linear" }}
                  />
                )}
              </button>
            ))}
          </div>

          <div className="absolute right-4 top-4 z-10 rounded-full bg-white/20 px-3 py-1.5 text-xs text-white backdrop-blur-md">
            <span className="font-heading">{current + 1}</span>
            <span className="mx-1 text-white/60">/</span>
            <span className="text-white/60">{count}</span>
          </div>
        </>
      )}
    </div>
  );
}
