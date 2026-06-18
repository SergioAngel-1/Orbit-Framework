import type { Metadata } from "next";
import { Inter } from "next/font/google";
import Link from "next/link";
import "./globals.css";
import { CartProvider } from "@/components/cart/cart-context";
import { CartIndicator } from "@/components/cart/cart-indicator";

// Fuente optimizada por Next.js, expuesta como variable CSS (--font-inter)
// y consumida por Tailwind (ver globals.css -> @theme --font-sans).
const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
});

export const metadata: Metadata = {
  title: {
    default: "Headless Web Ecosystem",
    template: "%s · Headless Web Ecosystem",
  },
  description:
    "Frontend Next.js (App Router) conectado a WordPress vía WPGraphQL.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es" className={inter.variable}>
      <body className="min-h-screen font-sans antialiased">
        <CartProvider>
          <header className="border-b border-gray-200 bg-white/80 backdrop-blur dark:border-gray-800 dark:bg-black/50">
            <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
              <Link href="/" className="text-lg font-bold tracking-tight">
                Headless<span className="text-brand">WP</span>
              </Link>
              <nav className="flex items-center gap-5 text-sm font-medium text-gray-600 dark:text-gray-300">
                <Link href="/products" className="transition-colors hover:text-brand">
                  Tienda
                </Link>
                <Link href="/account" className="transition-colors hover:text-brand">
                  Cuenta
                </Link>
                <CartIndicator />
              </nav>
            </div>
          </header>

          <main className="mx-auto max-w-5xl px-6 py-12">{children}</main>

          <footer className="border-t border-gray-200 py-8 text-center text-sm text-gray-500 dark:border-gray-800">
            <div className="mx-auto max-w-5xl px-6">
              Next.js + WordPress (WPGraphQL) · Headless Web Ecosystem
            </div>
          </footer>
        </CartProvider>
      </body>
    </html>
  );
}
