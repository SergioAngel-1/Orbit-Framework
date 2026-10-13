import { Link } from "@/i18n/navigation";

/**
 * Invitación a iniciar sesión (o crear cuenta) en la pantalla de «gracias»
 * cuando la compra se hizo como invitado. Al abrir sesión en este dispositivo
 * con el correo del pedido, `claimGuestOrder` lo pasa a la cuenta y aparece en
 * «Mis pedidos».
 *
 * Es una acción secundaria bajo la confirmación: título, una frase y dos
 * enlaces. El texto lo pone quien la usa (i18n).
 */
export function GuestAccountInvite({
  copy,
}: {
  copy: { title: string; body: string; login: string; register: string };
}) {
  return (
    <aside
      aria-labelledby="guest-account-invite-title"
      className="rounded-xl border border-gray-200 bg-gray-50 p-5 text-left dark:border-gray-800 dark:bg-gray-900"
    >
      <h2 id="guest-account-invite-title" className="text-base font-semibold">
        {copy.title}
      </h2>
      <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{copy.body}</p>
      <p className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm font-medium">
        <Link href="/login" className="text-brand hover:underline">
          {copy.login}
        </Link>
        <Link href="/register" className="text-brand hover:underline">
          {copy.register}
        </Link>
      </p>
    </aside>
  );
}
