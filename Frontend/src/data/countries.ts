/**
 * Lista centralizada de países soportados para el selector de teléfono
 * 
 * Cada país incluye:
 * - code: Código ISO de 2 letras (minúsculas) para identificación interna
 * - name: Nombre del país en español
 * - dialCode: Código de marcación internacional (sin el +)
 * - flag: Emoji de la bandera del país
 */

export interface Country {
  code: string;
  name: string;
  dialCode: string;
  flag: string;
}

/**
 * Países de Latinoamérica y otros países frecuentes
 * Ordenados por relevancia para el negocio
 */
export const COUNTRIES: Country[] = [
  // Países principales (más usados)
  { code: 'co', name: 'Colombia', dialCode: '57', flag: '🇨🇴' },
  { code: 'mx', name: 'México', dialCode: '52', flag: '🇲🇽' },
  { code: 'us', name: 'Estados Unidos', dialCode: '1', flag: '🇺🇸' },
  { code: 'es', name: 'España', dialCode: '34', flag: '🇪🇸' },
  
  // Latinoamérica (alfabético)
  { code: 'ar', name: 'Argentina', dialCode: '54', flag: '🇦🇷' },
  { code: 'bo', name: 'Bolivia', dialCode: '591', flag: '🇧🇴' },
  { code: 'br', name: 'Brasil', dialCode: '55', flag: '🇧🇷' },
  { code: 'cl', name: 'Chile', dialCode: '56', flag: '🇨🇱' },
  { code: 'cr', name: 'Costa Rica', dialCode: '506', flag: '🇨🇷' },
  { code: 'cu', name: 'Cuba', dialCode: '53', flag: '🇨🇺' },
  { code: 'do', name: 'República Dominicana', dialCode: '1', flag: '🇩🇴' },
  { code: 'ec', name: 'Ecuador', dialCode: '593', flag: '🇪🇨' },
  { code: 'sv', name: 'El Salvador', dialCode: '503', flag: '🇸🇻' },
  { code: 'gt', name: 'Guatemala', dialCode: '502', flag: '🇬🇹' },
  { code: 'hn', name: 'Honduras', dialCode: '504', flag: '🇭🇳' },
  { code: 'ni', name: 'Nicaragua', dialCode: '505', flag: '🇳🇮' },
  { code: 'pa', name: 'Panamá', dialCode: '507', flag: '🇵🇦' },
  { code: 'py', name: 'Paraguay', dialCode: '595', flag: '🇵🇾' },
  { code: 'pe', name: 'Perú', dialCode: '51', flag: '🇵🇪' },
  { code: 'pr', name: 'Puerto Rico', dialCode: '1', flag: '🇵🇷' },
  { code: 'uy', name: 'Uruguay', dialCode: '598', flag: '🇺🇾' },
  { code: 've', name: 'Venezuela', dialCode: '58', flag: '🇻🇪' },
  
  // Europa (alfabético)
  { code: 'de', name: 'Alemania', dialCode: '49', flag: '🇩🇪' },
  { code: 'at', name: 'Austria', dialCode: '43', flag: '🇦🇹' },
  { code: 'be', name: 'Bélgica', dialCode: '32', flag: '🇧🇪' },
  { code: 'dk', name: 'Dinamarca', dialCode: '45', flag: '🇩🇰' },
  { code: 'fr', name: 'Francia', dialCode: '33', flag: '🇫🇷' },
  { code: 'gr', name: 'Grecia', dialCode: '30', flag: '🇬🇷' },
  { code: 'ie', name: 'Irlanda', dialCode: '353', flag: '🇮🇪' },
  { code: 'it', name: 'Italia', dialCode: '39', flag: '🇮🇹' },
  { code: 'no', name: 'Noruega', dialCode: '47', flag: '🇳🇴' },
  { code: 'nl', name: 'Países Bajos', dialCode: '31', flag: '🇳🇱' },
  { code: 'pl', name: 'Polonia', dialCode: '48', flag: '🇵🇱' },
  { code: 'pt', name: 'Portugal', dialCode: '351', flag: '🇵🇹' },
  { code: 'gb', name: 'Reino Unido', dialCode: '44', flag: '🇬🇧' },
  { code: 'cz', name: 'República Checa', dialCode: '420', flag: '🇨🇿' },
  { code: 'ro', name: 'Rumania', dialCode: '40', flag: '🇷🇴' },
  { code: 'ru', name: 'Rusia', dialCode: '7', flag: '🇷🇺' },
  { code: 'se', name: 'Suecia', dialCode: '46', flag: '🇸🇪' },
  { code: 'ch', name: 'Suiza', dialCode: '41', flag: '🇨🇭' },
  
  // Norteamérica
  { code: 'ca', name: 'Canadá', dialCode: '1', flag: '🇨🇦' },
  
  // Asia (países más relevantes)
  { code: 'cn', name: 'China', dialCode: '86', flag: '🇨🇳' },
  { code: 'kr', name: 'Corea del Sur', dialCode: '82', flag: '🇰🇷' },
  { code: 'ae', name: 'Emiratos Árabes Unidos', dialCode: '971', flag: '🇦🇪' },
  { code: 'ph', name: 'Filipinas', dialCode: '63', flag: '🇵🇭' },
  { code: 'in', name: 'India', dialCode: '91', flag: '🇮🇳' },
  { code: 'id', name: 'Indonesia', dialCode: '62', flag: '🇮🇩' },
  { code: 'il', name: 'Israel', dialCode: '972', flag: '🇮🇱' },
  { code: 'jp', name: 'Japón', dialCode: '81', flag: '🇯🇵' },
  { code: 'my', name: 'Malasia', dialCode: '60', flag: '🇲🇾' },
  { code: 'sg', name: 'Singapur', dialCode: '65', flag: '🇸🇬' },
  { code: 'th', name: 'Tailandia', dialCode: '66', flag: '🇹🇭' },
  { code: 'tr', name: 'Turquía', dialCode: '90', flag: '🇹🇷' },
  { code: 'vn', name: 'Vietnam', dialCode: '84', flag: '🇻🇳' },
  
  // Oceanía
  { code: 'au', name: 'Australia', dialCode: '61', flag: '🇦🇺' },
  { code: 'nz', name: 'Nueva Zelanda', dialCode: '64', flag: '🇳🇿' },
  
  // África (países más relevantes)
  { code: 'za', name: 'Sudáfrica', dialCode: '27', flag: '🇿🇦' },
  { code: 'eg', name: 'Egipto', dialCode: '20', flag: '🇪🇬' },
  { code: 'ma', name: 'Marruecos', dialCode: '212', flag: '🇲🇦' },
  { code: 'ng', name: 'Nigeria', dialCode: '234', flag: '🇳🇬' },
  { code: 'ke', name: 'Kenia', dialCode: '254', flag: '🇰🇪' },
];

/**
 * Países preferidos (mostrados primero en el selector)
 */
export const PREFERRED_COUNTRIES = ['co', 'mx', 'us', 'es'];

/**
 * Obtener un país por su código
 */
export const getCountryByCode = (code: string): Country | undefined => {
  return COUNTRIES.find(country => country.code === code);
};

/**
 * Obtener un país por su código de marcación
 */
export const getCountryByDialCode = (dialCode: string): Country | undefined => {
  return COUNTRIES.find(country => country.dialCode === dialCode);
};

/**
 * Buscar países por nombre o código de marcación
 */
export const searchCountries = (query: string): Country[] => {
  if (!query.trim()) return COUNTRIES;
  
  const lowerQuery = query.toLowerCase();
  return COUNTRIES.filter(country => 
    country.name.toLowerCase().includes(lowerQuery) ||
    country.dialCode.includes(lowerQuery)
  );
};
