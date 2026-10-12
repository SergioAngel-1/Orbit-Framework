import { setRequestLocale } from "next-intl/server";
import { getSiteConfig } from "@/lib/config";

export const revalidate = 300;

/**
 * Vista de EJEMPLO de una instancia (ver `examples/instance-minimal/README.md`).
 *
 * Lee `config.instance.example.greeting` —el campo que el ejemplo declara en el Control
 * Center— con tipos aumentados (`frontend/src/instance/config.d.ts` del ejemplo). Si
 * WordPress no trae el campo (p. ej. el stub de CI, que sirve una config sin `instance`),
 * cae al saludo por defecto: el contenido falla de forma suave.
 */
export default async function ExamplePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const config = await getSiteConfig();
  const greeting =
    config.instance.example?.greeting || "Hola (ejemplo sin configurar)";

  return (
    <div className="max-w-3xl">
      <h1 className="mb-4 text-3xl font-extrabold tracking-tight">
        {greeting}
      </h1>
      <p className="text-gray-600">
        Esta vista la aporta la instancia, no el framework: lee un campo propio
        del Control Center (`instance.example.greeting`).
      </p>
    </div>
  );
}
