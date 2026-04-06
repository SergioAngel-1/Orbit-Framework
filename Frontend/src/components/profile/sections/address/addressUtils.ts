import { AddressDetails } from './types';
import logger from '../../../../utils/logger';

// Tipos de vía en Colombia
export const tiposVia = [
  'Calle', 'Carrera', 'Avenida', 'Diagonal', 'Transversal', 'Circular', 'Autopista', 'Variante', 'Vía'
];

// Mapa de indicativos telefónicos por país
export const countryPhoneCodes = {
  'Colombia': '+57',
  'Ecuador': '+593',
  'Perú': '+51',
  'Venezuela': '+58'
};

// Lista de departamentos de Colombia
export const colombianDepartments = [
  'Amazonas', 'Antioquia', 'Arauca', 'Atlántico', 'Bolívar', 'Boyacá', 'Caldas', 'Caquetá', 
  'Casanare', 'Cauca', 'Cesar', 'Chocó', 'Córdoba', 'Cundinamarca', 'Guainía', 'Guaviare', 
  'Huila', 'La Guajira', 'Magdalena', 'Meta', 'Nariño', 'Norte de Santander', 'Putumayo', 
  'Quindío', 'Risaralda', 'San Andrés y Providencia', 'Santander', 'Sucre', 'Tolima', 
  'Valle del Cauca', 'Vaupés', 'Vichada', 'Bogotá D.C.'
];

// Lista de principales ciudades de Colombia
export const colombianCities = [
  'Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Cartagena', 'Cúcuta', 'Bucaramanga', 'Pereira',
  'Santa Marta', 'Ibagué', 'Pasto', 'Manizales', 'Neiva', 'Villavicencio', 'Armenia', 'Valledupar',
  'Montería', 'Popayán', 'Tunja', 'Riohacha', 'Quibdó', 'Yopal', 'Florencia', 'Sincelejo',
  'Mocoa', 'San José del Guaviare', 'Inírida', 'Mitú', 'Puerto Carreño', 'Arauca', 'Leticia'
];

/**
 * Construye una dirección completa a partir de sus componentes
 * @param direccionDetalle - Detalles de la dirección
 * @returns Dirección completa en formato de texto
 */
export const construirDireccionCompleta = (direccionDetalle: AddressDetails): string => {
  const {
    tipoVia, numeroVia, letraVia, bis, cardinal1,
    numero1, letra1, numero2, letra2, cardinal2, complemento
  } = direccionDetalle;
  
  let direccion = `${tipoVia} ${numeroVia}`;
  
  if (letraVia) direccion += ` ${letraVia}`;
  if (bis) direccion += ' BIS';
  if (cardinal1) direccion += ` ${cardinal1}`;
  if (numero1) direccion += ` # ${numero1}`;
  if (letra1) direccion += ` ${letra1}`;
  if (numero2) direccion += ` - ${numero2}`;
  if (letra2) direccion += ` ${letra2}`;
  if (cardinal2) direccion += ` ${cardinal2}`;
  if (complemento) direccion += `, ${complemento}`;
  
  return direccion;
};

/**
 * Parsea una dirección completa en sus componentes
 * @param direccionCompleta - Dirección en formato de texto
 * @returns Objeto con los componentes de la dirección
 */
export const parsearDireccion = (direccionCompleta: string): AddressDetails => {
  try {
    // Valores por defecto
    const direccionParseada: AddressDetails = {
      tipoVia: 'Calle',
      numeroVia: '',
      letraVia: '',
      bis: false,
      cardinal1: '',
      numero1: '',
      letra1: '',
      numero2: '',
      letra2: '',
      cardinal2: '',
      complemento: ''
    };
    
    // Detectar tipo de vía
    for (const tipo of tiposVia) {
      if (direccionCompleta.startsWith(tipo)) {
        direccionParseada.tipoVia = tipo;
        direccionCompleta = direccionCompleta.substring(tipo.length).trim();
        break;
      }
    }
    
    // Extraer número de vía
    const numeroViaMatch = direccionCompleta.match(/^\s*(\d+)/);
    if (numeroViaMatch) {
      direccionParseada.numeroVia = numeroViaMatch[1];
      direccionCompleta = direccionCompleta.substring(numeroViaMatch[0].length).trim();
    }
    
    // Extraer letra de vía
    const letraViaMatch = direccionCompleta.match(/^\s*([A-Za-z])/);
    if (letraViaMatch) {
      direccionParseada.letraVia = letraViaMatch[1].toUpperCase();
      direccionCompleta = direccionCompleta.substring(letraViaMatch[0].length).trim();
    }
    
    // Detectar BIS
    if (direccionCompleta.toUpperCase().includes('BIS')) {
      direccionParseada.bis = true;
      direccionCompleta = direccionCompleta.replace(/BIS/i, '').trim();
    }
    
    // Detectar cardinal 1
    const cardinales = ['Norte', 'Sur', 'Este', 'Oeste'];
    for (const cardinal of cardinales) {
      if (direccionCompleta.includes(cardinal)) {
        direccionParseada.cardinal1 = cardinal;
        direccionCompleta = direccionCompleta.replace(cardinal, '').trim();
        break;
      }
    }
    
    // Extraer número 1 (después de #)
    const numero1Match = direccionCompleta.match(/#\s*(\d+)/);
    if (numero1Match) {
      direccionParseada.numero1 = numero1Match[1];
      direccionCompleta = direccionCompleta.replace(numero1Match[0], '').trim();
    }
    
    // Extraer letra 1
    const letra1Match = direccionCompleta.match(/^\s*([A-Za-z])/);
    if (letra1Match) {
      direccionParseada.letra1 = letra1Match[1].toUpperCase();
      direccionCompleta = direccionCompleta.substring(letra1Match[0].length).trim();
    }
    
    // Extraer número 2 (después de -)
    const numero2Match = direccionCompleta.match(/-\s*(\d+)/);
    if (numero2Match) {
      direccionParseada.numero2 = numero2Match[1];
      direccionCompleta = direccionCompleta.replace(numero2Match[0], '').trim();
    }
    
    // Extraer letra 2
    const letra2Match = direccionCompleta.match(/^\s*([A-Za-z])/);
    if (letra2Match) {
      direccionParseada.letra2 = letra2Match[1].toUpperCase();
      direccionCompleta = direccionCompleta.substring(letra2Match[0].length).trim();
    }
    
    // Detectar cardinal 2
    for (const cardinal of cardinales) {
      if (direccionCompleta.includes(cardinal)) {
        direccionParseada.cardinal2 = cardinal;
        direccionCompleta = direccionCompleta.replace(cardinal, '').trim();
        break;
      }
    }
    
    // El resto es complemento
    if (direccionCompleta.startsWith(',')) {
      direccionCompleta = direccionCompleta.substring(1).trim();
    }
    if (direccionCompleta) {
      direccionParseada.complemento = direccionCompleta;
    }
    
    return direccionParseada;
  } catch (error) {
    logger.error('addressUtils', 'Error al parsear la dirección', error);
    return {
      tipoVia: 'Calle',
      numeroVia: '',
      letraVia: '',
      bis: false,
      cardinal1: '',
      numero1: '',
      letra1: '',
      numero2: '',
      letra2: '',
      cardinal2: '',
      complemento: ''
    };
  }
};
