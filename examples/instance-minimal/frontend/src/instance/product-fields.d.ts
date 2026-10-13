import "@/lib/instance/types";

// Tipado de los campos de producto declarativos de la instancia de ejemplo: `product.fields`
// ofrece estas claves con su tipo. Lo que no llega es `undefined` (un campo sin valor no viaja).
declare module "@/lib/instance/types" {
  interface ProductFieldValues {
    example_usage?: string;
    example_weight?: string;
  }
}
