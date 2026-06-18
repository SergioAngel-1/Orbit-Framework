// ============================================================================
//  Operaciones GraphQL de autenticación (WPGraphQL + WPGraphQL JWT Auth).
//  Son cadenas de texto: pueden importarse desde el middleware (edge) sin coste.
// ============================================================================

/** Inicia sesión y devuelve los tokens + datos básicos del usuario. */
export const LOGIN_MUTATION = /* GraphQL */ `
  mutation Login($username: String!, $password: String!) {
    login(input: { username: $username, password: $password }) {
      authToken
      refreshToken
      user {
        id
        databaseId
        name
        email
      }
    }
  }
`;

/** Intercambia un refresh token por un nuevo JWT de acceso. */
export const REFRESH_TOKEN_MUTATION = /* GraphQL */ `
  mutation RefreshToken($refreshToken: String!) {
    refreshJwtAuthToken(input: { jwtRefreshToken: $refreshToken }) {
      authToken
    }
  }
`;

/** Registra un nuevo usuario (requiere registro habilitado en WordPress). */
export const REGISTER_USER_MUTATION = /* GraphQL */ `
  mutation RegisterUser($username: String!, $email: String!, $password: String!) {
    registerUser(input: { username: $username, email: $email, password: $password }) {
      user {
        id
        databaseId
        name
        email
      }
    }
  }
`;

/** Devuelve el usuario autenticado según el JWT enviado. */
export const VIEWER_QUERY = /* GraphQL */ `
  query Viewer {
    viewer {
      id
      databaseId
      name
      email
    }
  }
`;
