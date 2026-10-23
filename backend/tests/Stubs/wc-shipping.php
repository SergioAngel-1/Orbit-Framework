<?php
/**
 * Dobles mínimos de las clases de envío de WooCommerce que toca `hwe-shipping`: una tarifa, un
 * producto, un cupón y una zona con sus métodos. Solo lo que el código propio usa.
 */

declare(strict_types=1);

if (!class_exists('WC_Shipping_Rate')) {
    class WC_Shipping_Rate
    {
        public array $meta = [];
        public array $taxes = [1];
        public string $id = '';
        public string $label = '';
        public int $instance_id = 0;

        public function __construct(public string $method_id = 'flat_rate', public float $cost = 0.0)
        {
        }

        public function set_cost($cost): void
        {
            $this->cost = (float) $cost;
        }

        public function set_taxes(array $taxes): void
        {
            $this->taxes = $taxes;
        }

        public function add_meta_data(string $key, $value): void
        {
            $this->meta[$key] = $value;
        }
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product
    {
        public function __construct(private int $id = 1, private bool $shipping = true)
        {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function needs_shipping(): bool
        {
            return $this->shipping;
        }
    }
}

if (!class_exists('WC_Coupon')) {
    class WC_Coupon
    {
        /** @var array<string,bool> código => envío gratis */
        public static array $free = [];
        private string $code;

        public function __construct($code = '')
        {
            $this->code = (string) $code;
        }

        public function get_id(): int
        {
            return array_key_exists($this->code, self::$free) ? 1 : 0;
        }

        public function get_free_shipping(): bool
        {
            return self::$free[$this->code] ?? false;
        }
    }
}

if (!class_exists('WC_Shipping_Method')) {
    /**
     * Lo mínimo de `WC_Shipping_Method` que usa el método propio: ser instanciable por
     * WooCommerce con un id de instancia y acumular tarifas con `add_rate()`.
     */
    abstract class WC_Shipping_Method
    {
        public string $id = '';
        public int $instance_id = 0;
        public string $method_title = '';
        public string $method_description = '';
        public string $enabled = 'yes';
        public string $title = '';
        /** @var list<string> */
        public array $supports = [];
        /** @var array<string,WC_Shipping_Rate> */
        public array $rates = [];

        public function __construct($instance_id = 0)
        {
            $this->instance_id = (int) $instance_id;
        }

        public function calculate_shipping($package = []): void
        {
        }

        public function add_rate($args = []): void
        {
            $id = (string) ($args['id'] ?? '');
            $label = (string) ($args['label'] ?? '');
            if ('' === $id || '' === $label) {
                return;
            }

            $rate = new WC_Shipping_Rate((string) ($args['method_id'] ?? $this->id), (float) ($args['cost'] ?? 0));
            $rate->id = $id;
            $rate->label = $label;
            $rate->instance_id = (int) ($args['instance_id'] ?? $this->instance_id);
            $rate->taxes = (array) ($args['taxes'] ?? []);
            foreach ((array) ($args['meta_data'] ?? []) as $key => $value) {
                $rate->add_meta_data((string) $key, $value);
            }
            $this->rates[$id] = $rate;
        }

        public function get_rate_id($suffix = ''): string
        {
            $parts = [$this->id];
            if ($this->instance_id) {
                $parts[] = (string) $this->instance_id;
            }
            if ('' !== $suffix) {
                $parts[] = (string) $suffix;
            }

            return implode(':', $parts);
        }
    }
}

if (!class_exists('WC_Shipping_Method_Fake')) {
    class WC_Shipping_Method_Fake
    {
        public function __construct(public string $id, public int $instance_id)
        {
        }
    }
}

if (!class_exists('WC_Shipping_Zone')) {
    class WC_Shipping_Zone
    {
        /** @var array<int,array{name:string,locations:array,methods:list<WC_Shipping_Method_Fake>}> */
        public static array $store = [];
        public static int $nextId = 1;
        public static int $nextInstance = 100;
        public static int $saves = 0;

        private int $id = 0;
        private string $name = '';
        private array $locations = [];
        /** @var list<WC_Shipping_Method_Fake> */
        private array $methods = [];

        public function __construct($id = 0)
        {
            $id = (int) $id;
            if ($id > 0 && isset(self::$store[$id])) {
                $this->id = $id;
                $this->name = self::$store[$id]['name'];
                $this->locations = self::$store[$id]['locations'];
                $this->methods = self::$store[$id]['methods'];
            }
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function set_zone_name(string $name): void
        {
            $this->name = $name;
        }

        public function clear_locations(): void
        {
            $this->locations = [];
        }

        public function add_location(string $code, string $type): void
        {
            $this->locations[] = [$code, $type];
        }

        public function add_shipping_method(string $type): int
        {
            $instance = self::$nextInstance++;
            $this->methods[] = new WC_Shipping_Method_Fake($type, $instance);
            return $instance;
        }

        public function delete_shipping_method($instance_id): bool
        {
            $before = count($this->methods);
            $this->methods = array_values(array_filter(
                $this->methods,
                static fn (WC_Shipping_Method_Fake $method): bool => $method->instance_id !== (int) $instance_id
            ));

            return count($this->methods) < $before;
        }

        /** @return list<WC_Shipping_Method_Fake> */
        public function get_shipping_methods(): array
        {
            return $this->methods;
        }

        public function save(): int
        {
            self::$saves++;
            if ($this->id === 0) {
                $this->id = self::$nextId++;
            }
            self::$store[$this->id] = ['name' => $this->name, 'locations' => $this->locations, 'methods' => $this->methods];
            return $this->id;
        }
    }
}
