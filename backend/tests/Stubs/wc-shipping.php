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
