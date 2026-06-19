"use client";

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <html>
      <body className="flex min-h-screen items-center justify-center bg-white p-8 dark:bg-gray-950">
        <div className="max-w-md text-center">
          <h1 className="mb-4 text-6xl font-extrabold text-gray-200 dark:text-gray-800">500</h1>
          <p className="mb-6 text-lg text-gray-600 dark:text-gray-400">
            Error interno del servidor. Inténtalo de nuevo más tarde.
          </p>
          <p className="mb-8 text-sm text-gray-400">{error.message}</p>
          <button
            onClick={reset}
            className="rounded-lg bg-brand px-6 py-2 font-semibold text-white transition-colors hover:bg-brand-dark"
          >
            Reintentar
          </button>
        </div>
      </body>
    </html>
  );
}
