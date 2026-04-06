<?php
/**
 * Clase principal del plugin Starter Home Sections
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class Starter_Home_Sections {
    
    // Instancia singleton
    private static $instance = null;
    
    // Propiedades públicas (declaradas para evitar deprecation en PHP 8.2+)
    public $admin_menu;
    public $settings;
    public $rest_api;
    
    // Tipos de layout disponibles
    private $layout_types = array(
        'horizontal' => array(
            'name' => 'Horizontal (8 productos en fila)',
            'limit' => 8,
            'grid_type' => 'wide',
            'min_products' => 3,
            'requires_pair' => false,
            'full_width' => true
        ),
        'standard' => array(
            'name' => 'Estándar (6 productos en grilla)',
            'limit' => 6,
            'grid_type' => 'standard',
            'min_products' => 6,
            'requires_pair' => false,
            'full_width' => true
        ),
        'compact_pair' => array(
            'name' => 'Par Compacto (2 grillas de 4 productos lado a lado)',
            'limit' => 4,
            'grid_type' => 'compact_pair',
            'min_products' => 4,
            'requires_pair' => false,
            'full_width' => true,
            'is_pair' => true
        ),
    );
    
    // Zonas disponibles para las secciones
    private $zones = array(
        'top' => 'Superior',
        'middle' => 'Intermedia',
        'bottom' => 'Inferior'
    );
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
        
    }
    
    /**
     * Obtener instancia singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Definir constantes del plugin
     */
    private function define_constants() {
        // Solo definir si no existen (evitar warnings de redefinición)
        if (!defined('FIHS_PLUGIN_DIR')) {
            define('FIHS_PLUGIN_DIR', plugin_dir_path(dirname(__FILE__)));
        }
        if (!defined('FIHS_PLUGIN_URL')) {
            define('FIHS_PLUGIN_URL', plugin_dir_url(dirname(__FILE__)));
        }
        if (!defined('FIHS_PLUGIN_BASENAME')) {
            define('FIHS_PLUGIN_BASENAME', plugin_basename(dirname(__FILE__)));
        }
        if (!defined('FIHS_VERSION')) {
            define('FIHS_VERSION', '1.0.0');
        }
    }
    
    /**
     * Incluir archivos necesarios
     */
    private function includes() {
        // Incluir archivos de administración
        require_once FIHS_PLUGIN_DIR . 'admin/class-admin-menu.php';
        require_once FIHS_PLUGIN_DIR . 'admin/class-settings.php';
        
        // Incluir archivos de API
        require_once FIHS_PLUGIN_DIR . 'api/class-rest-api.php';
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Inicializar componentes
        $this->admin_menu = new Starter_Home_Sections_Admin_Menu();
        
        // Inicializar configuraciones
        $this->settings = new Starter_Home_Sections_Settings();
        
        // Inicializar API REST
        $this->rest_api = new Starter_Home_Sections_REST_API();
    }
    
    /**
     * Obtener secciones configuradas desde la base de datos
     */
    public function get_sections() {
        $sections = get_option('starter_home_sections_list', array());
        return is_array($sections) ? $sections : array();
    }
    
    /**
     * Obtener tipos de layout disponibles
     */
    public function get_layout_types() {
        return $this->layout_types;
    }
    
    /**
     * Obtener zonas disponibles
     */
    public function get_zones() {
        return $this->zones;
    }
    
    /**
     * Crear una nueva sección
     */
    public function create_section($data) {
        $sections = $this->get_sections();
        
        // Generar ID único
        $section_id = 'section_' . uniqid();
        
        // Validar datos
        if (empty($data['layout_type']) || !isset($this->layout_types[$data['layout_type']])) {
            return new WP_Error('invalid_layout', 'Tipo de layout inválido');
        }
        
        if (empty($data['zone']) || !isset($this->zones[$data['zone']])) {
            return new WP_Error('invalid_zone', 'Zona inválida');
        }
        
        $layout_config = $this->layout_types[$data['layout_type']];
        
        // Si es una sección compacta, validar que haya un par o crear el par automáticamente
        if ($layout_config['requires_pair']) {
            // Contar secciones compactas activas en la misma zona con orden similar
            $zone = sanitize_text_field($data['zone']);
            $order = intval($data['order'] ?? count($sections));
            
            $compact_count_in_zone = 0;
            foreach ($sections as $s) {
                if ($s['zone'] === $zone && 
                    $s['layout_type'] === 'compact' && 
                    ($s['enabled'] ?? true) &&
                    abs(($s['order'] ?? 0) - $order) <= 1) {
                    $compact_count_in_zone++;
                }
            }
            
            // Si hay un número impar, está bien (se completará el par)
            // Si hay un número par, advertir que se creará un nuevo par
            if ($compact_count_in_zone % 2 === 0 && $compact_count_in_zone > 0) {
                // Opcional: Advertir que se está iniciando un nuevo par
                error_log("Creando nueva sección compacta. Se necesitará otra para completar el par.");
            }
        }
        
        // Crear sección
        $sections[$section_id] = array(
            'id' => $section_id,
            'layout_type' => sanitize_text_field($data['layout_type']),
            'zone' => sanitize_text_field($data['zone']),
            'category_id' => intval($data['category_id']),
            'title' => sanitize_text_field($data['title'] ?? ''),
            'subtitle' => sanitize_text_field($data['subtitle'] ?? ''),
            'random' => isset($data['random']) ? (bool)$data['random'] : false,
            'order' => intval($data['order'] ?? count($sections)),
            'enabled' => isset($data['enabled']) ? (bool)$data['enabled'] : true,
            'pair_id' => isset($data['pair_id']) ? sanitize_text_field($data['pair_id']) : null,
        );
        
        update_option('starter_home_sections_list', $sections);

        // Invalidar el cache después de crear una sección
        do_action('starter_home_sections_changed');

        return $section_id;
    }
    
    /**
     * Actualizar una sección existente
     */
    public function update_section($section_id, $data) {
        $sections = $this->get_sections();

        if (!isset($sections[$section_id])) {
            return new WP_Error('section_not_found', 'Sección no encontrada');
        }

        // Actualizar campos permitidos
        $allowed_fields = array('layout_type', 'zone', 'category_id', 'title', 'subtitle', 'random', 'order', 'enabled');

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $sections[$section_id][$field] = $data[$field];
            }
        }

        update_option('starter_home_sections_list', $sections);

        // Invalidar el cache después de actualizar una sección
        do_action('starter_home_sections_changed');

        return true;
    }
    
    /**
     * Eliminar una sección
     */
    public function delete_section($section_id) {
        $sections = $this->get_sections();

        if (!isset($sections[$section_id])) {
            return new WP_Error('section_not_found', 'Sección no encontrada');
        }

        unset($sections[$section_id]);
        update_option('starter_home_sections_list', $sections);

        // Invalidar el cache después de eliminar una sección
        do_action('starter_home_sections_changed');

        return true;
    }
}
