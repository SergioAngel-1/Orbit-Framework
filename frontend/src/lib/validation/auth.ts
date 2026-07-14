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
});

export const forgotPasswordSchema = z.object({
  email: z.email("Email no válido").max(200),
});

export const resetPasswordSchema = z.object({
  key: z.string().min(1, "Token requerido"),
  login: z.string().min(1, "Usuario requerido"),
  password: z.string().min(8, "Mínimo 8 caracteres").max(200),
});

export const twoFactorCodeSchema = z.object({
  code: z
    .string()
    .length(6, "Código debe tener 6 dígitos")
    .regex(/^\d{6}$/, "Solo dígitos"),
});

export const twoFactorSetupSchema = z.object({
  secret: z.string().min(1, "Secreto requerido"),
  code: z
    .string()
    .length(6, "Código debe tener 6 dígitos")
    .regex(/^\d{6}$/, "Solo dígitos"),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof registerSchema>;
export type ForgotPasswordInput = z.infer<typeof forgotPasswordSchema>;
export type ResetPasswordInput = z.infer<typeof resetPasswordSchema>;
export type TwoFactorCodeInput = z.infer<typeof twoFactorCodeSchema>;
export type TwoFactorSetupInput = z.infer<typeof twoFactorSetupSchema>;
