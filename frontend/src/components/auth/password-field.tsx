"use client";
import { useId, useState } from "react";
import { useTranslations } from "next-intl";

interface PasswordFieldProps {
  /** Nombres de los campos en el `FormData` del formulario. */
  passwordName?: string;
  confirmName?: string;
  /** Rótulos (por defecto, «Contraseña» y «Confirmar contraseña»). */
  passwordLabel?: string;
  confirmLabel?: string;
}

const INPUT =
  "w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900";

/**
 * Contraseña nueva + confirmación, compartido por registro, restablecer y
 * cambiar contraseña. No controla el envío: el formulario lee los dos campos
 * de su `FormData` y debe comprobar que coinciden antes de enviar. Aquí solo se
 * avisa en vivo (mínimo de longitud y que no coinciden).
 */
export function PasswordField({
  passwordName = "password",
  confirmName = "confirm",
  passwordLabel,
  confirmLabel,
}: PasswordFieldProps) {
  const tForm = useTranslations("form");
  const id = useId();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const mismatch = confirm.length > 0 && confirm !== password;

  return (
    <div className="space-y-4">
      <div>
        <label htmlFor={`${id}-password`} className="mb-1 block text-sm font-medium">
          {passwordLabel ?? tForm("password")}
        </label>
        <input
          id={`${id}-password`}
          name={passwordName}
          type="password"
          required
          minLength={8}
          maxLength={200}
          autoComplete="new-password"
          aria-describedby={`${id}-help`}
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className={INPUT}
        />
        <p
          id={`${id}-help`}
          className={`mt-1 text-xs ${password.length >= 8 ? "text-green-700 dark:text-green-400" : "text-gray-500"}`}
        >
          {tForm("passwordMin")}
        </p>
      </div>
      <div>
        <label htmlFor={`${id}-confirm`} className="mb-1 block text-sm font-medium">
          {confirmLabel ?? tForm("confirmPassword")}
        </label>
        <input
          id={`${id}-confirm`}
          name={confirmName}
          type="password"
          required
          minLength={8}
          maxLength={200}
          autoComplete="new-password"
          aria-invalid={mismatch || undefined}
          aria-describedby={mismatch ? `${id}-mismatch` : undefined}
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          className={INPUT}
        />
        <p
          id={`${id}-mismatch`}
          aria-live="polite"
          className="mt-1 text-xs text-red-700 dark:text-red-400"
        >
          {mismatch ? tForm("passwordsDontMatch") : ""}
        </p>
      </div>
    </div>
  );
}
