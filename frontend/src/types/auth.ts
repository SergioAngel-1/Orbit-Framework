// ============================================================================
//  Tipos de autenticación (respuestas de WPGraphQL JWT + dominio propio).
// ============================================================================

export interface AuthUser {
  id: string;
  databaseId: number;
  name: string;
  email: string | null;
}

export interface LoginResponse {
  login: {
    authToken: string;
    refreshToken: string;
    user: AuthUser;
  };
}

export interface RefreshResponse {
  refreshJwtAuthToken: {
    authToken: string;
  };
}

export interface RegisterResponse {
  registerUser: {
    user: AuthUser;
  };
}

export interface ViewerResponse {
  viewer: AuthUser | null;
}

/** Sesión resuelta y verificada en el servidor. */
export interface Session {
  userId: string;
  token: string;
}
