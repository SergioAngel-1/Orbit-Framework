/** Enlace de menú resuelto desde WordPress (Apariencia → Menús) o desde el fallback local. */
export interface MenuLink {
  label: string;
  /** Ruta relativa del frontend (p. ej. "/products") o URL absoluta externa. */
  href: string;
  children?: MenuLink[];
}
