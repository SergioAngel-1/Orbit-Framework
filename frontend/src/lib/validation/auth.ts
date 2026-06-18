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
  email: z.string().email("Email no válido").max(200),
  password: z.string().min(8, "Mínimo 8 caracteres").max(200),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof registerSchema>;
