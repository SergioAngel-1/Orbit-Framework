export interface SavedAddress {
  /**
   * Identificador estable dentro de la libreta. Lo asigna el BFF: al crear una dirección o, en las
   * guardadas antes de existir este campo, al leerla (`parseSavedAddresses`).
   */
  id?: string;
  first_name: string;
  last_name: string;
  company: string;
  address_1: string;
  address_2: string;
  city: string;
  state: string;
  postcode: string;
  country: string;
  phone: string;
  is_default: boolean;
  label: string;
  /**
   * Pin del cliente (punto de entrega del envío por distancia). Lo marca el cliente en el mapa
   * y se reutiliza sin peticiones; se borra si cambia la calle o la ciudad (`mergeAddressLocation`).
   */
  location?: { lat: number; lng: number };
}
