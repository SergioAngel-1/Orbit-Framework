export interface ShippingMethod {
  id: number;
  name: string;
  /** Importe adicional en unidades mayores de la moneda de la tienda (p. ej. 5). 0 = sin costo extra. */
  addedValue: number;
}
