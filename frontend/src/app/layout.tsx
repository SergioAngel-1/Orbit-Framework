import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

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
        <header className="border-b border-gray-200 bg-white/80 backdrop-blur dark:border-gray-800 dark:bg-black/50">
          <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
            <span className="text-lg font-bold tracking-tight">
              Headless<span className="text-brand">WP</span>
            </span>
            <nav className="text-sm font-medium text-gray-600 dark:text-gray-300">
              <a
                href="http://localhost:8080/wp-admin"
                className="transition-colors hover:text-brand"
                target="_blank"
                rel="noopener noreferrer"
              >
                Panel CMS →
              </a>
            </nav>
          </div>
        </header>

        <main className="mx-auto max-w-5xl px-6 py-12">{children}</main>

        <footer className="border-t border-gray-200 py-8 text-center text-sm text-gray-500 dark:border-gray-800">
          <div className="mx-auto max-w-5xl px-6">
            Next.js + WordPress (WPGraphQL) · Headless Web Ecosystem
          </div>
        </footer>
      </body>
    </html>
  );
}
