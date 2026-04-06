<?php
/**
 * Handler: Acceso Exclusivo
 * 
 * Gestiona beneficios de acceso exclusivo:
 * - Productos exclusivos
 * - Contenido exclusivo
 * - Acceso anticipado a nuevos productos
 * 
 * Estos son beneficios de tipo "fixed" (on/off).
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handler para Productos Exclusivos
 */
class Starter_Exclusive_Products_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'exclusive_products';
        $this->name = 'Productos Exclusivos';
        $this->description = 'Acceso a productos y contenido exclusivo por membresía';
        $this->requires_wc = true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return __($this->name, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __($this->description, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // Este beneficio se aplica a través del sistema de categorías con _min_membership_level
        // No requiere hooks adicionales aquí, pero podemos agregar filtros de verificación
        add_filter('starter_can_view_exclusive_product', [$this, 'check_product_access'], 10, 3);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        return [
            'has_access' => $this->is_enabled_for_user($user_id)
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_value(int $user_id): string {
        if (!$this->is_enabled_for_user($user_id)) {
            return '';
        }
        
        return __('Acceso a productos exclusivos', 'starter-memberships');
    }
    
    /**
     * Verificar acceso a producto exclusivo
     * 
     * @param bool $can_view
     * @param int $product_id
     * @param int $user_id
     * @return bool
     */
    public function check_product_access(bool $can_view, int $product_id, int $user_id): bool {
        if ($can_view) {
            return true;
        }
        
        return $this->is_enabled_for_user($user_id);
    }
}

/**
 * Handler para Contenido Exclusivo
 */
class Starter_Exclusive_Content_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'exclusive_content';
        $this->name = 'Contenido Exclusivo';
        $this->description = 'Acceso a contenido educativo y promocional exclusivo';
        $this->requires_wc = false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return __($this->name, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __($this->description, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // Hook para verificar acceso a contenido exclusivo
        add_filter('starter_can_view_exclusive_content', [$this, 'check_content_access'], 10, 3);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        return [
            'has_access' => $this->is_enabled_for_user($user_id)
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_value(int $user_id): string {
        if (!$this->is_enabled_for_user($user_id)) {
            return '';
        }
        
        return __('Acceso a contenido exclusivo', 'starter-memberships');
    }
    
    /**
     * Verificar acceso a contenido exclusivo
     * 
     * @param bool $can_view
     * @param int $content_id
     * @param int $user_id
     * @return bool
     */
    public function check_content_access(bool $can_view, int $content_id, int $user_id): bool {
        if ($can_view) {
            return true;
        }
        
        return $this->is_enabled_for_user($user_id);
    }
}

/**
 * Handler para Acceso Anticipado
 */
class Starter_Early_Access_Handler extends Starter_Benefit_Handler_Base {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->key = 'early_access';
        $this->name = 'Acceso Anticipado';
        $this->description = 'Acceso anticipado a nuevos productos y ofertas';
        $this->requires_wc = true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return __($this->name, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __($this->description, 'starter-memberships');
    }
    
    /**
     * {@inheritdoc}
     */
    public function register_hooks(): void {
        // Hook para verificar acceso anticipado a productos
        add_filter('starter_can_view_early_access_product', [$this, 'check_early_access'], 10, 3);
        
        // Hook para modificar query de productos (mostrar productos en pre-lanzamiento)
        add_filter('woocommerce_product_query_meta_query', [$this, 'modify_product_query'], 10, 2);
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply(int $user_id, array $context = []) {
        return [
            'has_access' => $this->is_enabled_for_user($user_id)
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_value(int $user_id): string {
        if (!$this->is_enabled_for_user($user_id)) {
            return '';
        }
        
        return __('Acceso anticipado a nuevos productos', 'starter-memberships');
    }
    
    /**
     * Verificar acceso anticipado
     * 
     * @param bool $can_view
     * @param int $product_id
     * @param int $user_id
     * @return bool
     */
    public function check_early_access(bool $can_view, int $product_id, int $user_id): bool {
        if ($can_view) {
            return true;
        }
        
        return $this->is_enabled_for_user($user_id);
    }
    
    /**
     * Modificar query de productos para incluir productos en pre-lanzamiento
     * 
     * @param array $meta_query
     * @param WC_Query $query
     * @return array
     */
    public function modify_product_query(array $meta_query, $query): array {
        $user_id = get_current_user_id();
        
        if (!$user_id || !$this->is_enabled_for_user($user_id)) {
            return $meta_query;
        }
        
        // Si el usuario tiene acceso anticipado, incluir productos marcados como early_access
        // Esto requiere que los productos tengan un meta '_early_access' = 'yes'
        // La implementación específica depende de cómo se marquen los productos
        
        return $meta_query;
    }
}

// Registrar los handlers inmediatamente
// (las clases core se cargan antes que los handlers en benefits/index.php)
starter_benefit_registry()->register(new Starter_Exclusive_Products_Handler());
starter_benefit_registry()->register(new Starter_Exclusive_Content_Handler());
starter_benefit_registry()->register(new Starter_Early_Access_Handler());
