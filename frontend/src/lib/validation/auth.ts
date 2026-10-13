import { z } from "zod";

// ============================================================================
//  Esquemas de validación de entrada para los endpoints de autenticación.
//  Nunca se reenvía a WordPress un payload sin validar/acotar.
// ============================================================================

export const loginSchema = z.object({
  // El "username" del plugin acepta nombre de usuario o email.
  username: z.string().min(1, "Usuario requerido").max(200),
  password: z.string().min(1, "Contraseña requerida").max(200),
});

export const registerSchema = z.object({
  username: z
    .string()
    .min(3, "Mínimo 3 caracteres")
    .max(60)
    .regex(/^[a-zA-Z0-9_.\-@]+$/, "Solo letras, números y los símbolos . _ - @"),
  email: z.email("Email no válido").max(200),
  password: z.string().min(8, "Mínimo 8 caracteres").max(200),
  /**
   * Teléfono de la cuenta en E.164 (`+34600112233`). Solo con
   * `auth.phone_login_enabled`; vacío u omitido = sin teléfono.
   */
  phone: z
    .string()
    .regex(/^(\+[1-9]\d{7,14})?$/, "Teléfono no válido")
    .optional(),
  /** Idioma del visitante, para el correo de verificación (ver `lib/auth/email-links`). */
  locale: z.string().max(16).optional(),
});

export const forgotPasswordSchema = z.object({
  email: z.email("Email no válido").max(200),
  /** Idioma del visitante, para el correo de recuperación. */
  locale: z.string().max(16).optional(),
});

export const resetPasswordSchema = z.object({
  key: z.string().min(1, "Token requerido"),
  login: z.string().min(1, "Usuario requerido"),
  password: z.string().min(8, "Mínimo 8 caracteres").max(200),
});

const totpCode = z
  .string()
  .length(6, "Código debe tener 6 dígitos")
  .regex(/^\d{6}$/, "Solo dígitos");

export const twoFactorCodeSchema = z.object({
  code: totpCode,
});

export const twoFactorSetupSchema = z.object({
  secret: z.string().min(1, "Secreto requerido"),
  code: z
    .string()
    .length(6, "Código debe tener 6 dígitos")
    .regex(/^\d{6}$/, "Solo dígitos"),
});

/**
 * POST /api/auth/2fa/disable: la prueba de posesión del segundo factor, que el BFF
 * reenvía a WordPress (que la vuelve a exigir). Va un código TOTP o uno de
 * recuperación; si llegan los dos, el endpoint da prioridad al de recuperación.
 */
export const disable2faBodySchema = z
  .object({
    code: totpCode.optional(),
    recoveryCode: z
      .string()
      .trim()
      .min(1, "Código de recuperación requerido")
      .max(100)
      .optional(),
  })
  .refine((data) => data.code !== undefined || data.recoveryCode !== undefined, {
    message: "Código o código de recuperación requerido",
    path: ["code"],
  });

/** POST /api/auth/verify-email. El token del enlace son 64 hex; 200 deja margen. */
export const verifyEmailBodySchema = z.object({
  // Lo que genera hwe-auth: 32 bytes aleatorios en hexadecimal. Otra cosa no llega a WordPress.
  token: z.string().regex(/^[a-f0-9]{64}$/, "Token no válido"),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof registerSchema>;
export type ForgotPasswordInput = z.infer<typeof forgotPasswordSchema>;
export type ResetPasswordInput = z.infer<typeof resetPasswordSchema>;
export type TwoFactorCodeInput = z.infer<typeof twoFactorCodeSchema>;
export type TwoFactorSetupInput = z.infer<typeof twoFactorSetupSchema>;
export type Disable2faBodyInput = z.infer<typeof disable2faBodySchema>;
export type VerifyEmailBodyInput = z.infer<typeof verifyEmailBodySchema>;
