<?php
/**
 * Clase para gestionar la configuración del plugin
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class Starter_Home_Sections_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Registrar configuraciones
     */
    public function register_settings() {
        register_setting('starter_home_sections', 'starter_home_sections_options');
        
        add_settings_section(
            'starter_home_sections_main',
            'Configuración de Secciones de Productos',
            array($this, 'section_callback'),
            'starter-home-sections'
        );
        
        $plugin = Starter_Home_Sections::get_instance();
        $sections = $plugin->get_sections();
        
        // Registrar campos para cada sección
        foreach ($sections as $section_id => $section_data) {
            // Extraer el nombre de la sección (puede ser string o array)
            $section_name = is_array($section_data) ? ($section_data['name'] ?? $section_id) : $section_data;
            
            // Añadir separador antes de cada nueva sección (excepto la primera)
            if ($section_id !== 'section_top_1') {
                add_settings_field(
                    $section_id . '_separator',
                    '',
                    array($this, 'separator_callback'),
                    'starter-home-sections',
                    'starter_home_sections_main',
                    array(
                        'section_id' => $section_id,
                    )
                );
            }
            
            add_settings_field(
                $section_id . '_category',
                $section_name,
                array($this, 'category_field_callback'),
                'starter-home-sections',
                'starter_home_sections_main',
                array(
                    'section_id' => $section_id,
                    'label_for' => $section_id . '_category'
                )
            );
            
            add_settings_field(
                $section_id . '_title',
                $section_name . ' - Título',
                array($this, 'title_field_callback'),
                'starter-home-sections',
                'starter_home_sections_main',
                array(
                    'section_id' => $section_id,
                    'label_for' => $section_id . '_title'
                )
            );
            
            add_settings_field(
                $section_id . '_subtitle',
                $section_name . ' - Subtítulo',
                array($this, 'subtitle_field_callback'),
                'starter-home-sections',
                'starter_home_sections_main',
                array(
                    'section_id' => $section_id,
                    'label_for' => $section_id . '_subtitle'
                )
            );
            
            add_settings_field(
                $section_id . '_random',
                $section_name . ' - Productos Aleatorios',
                array($this, 'random_field_callback'),
                'starter-home-sections',
                'starter_home_sections_main',
                array(
                    'section_id' => $section_id,
                    'label_for' => $section_id . '_random'
                )
            );
        }
    }
    
    /**
     * Callback para la sección
     */
    public function section_callback() {
        echo '<p>Configure las categorías y títulos para cada sección de productos en la página de inicio.</p>';
    }
    
    /**
     * Callback para el separador
     */
    public function separator_callback($args) {
        echo '<hr style="border: 0; height: 1px; background: #ccc; margin: 20px 0;">';
    }
    
    /**
     * Callback para los campos de categoría
     */
    public function category_field_callback($args) {
        $options = get_option('starter_home_sections_options');
        $section_id = $args['section_id'];
        $field_name = $section_id . '_category';
        $value = isset($options[$field_name]) ? $options[$field_name] : '';
        
        // Obtener todas las categorías de productos
        $product_categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
        
        echo '<select id="' . esc_attr($args['label_for']) . '" name="starter_home_sections_options[' . esc_attr($field_name) . ']">';
        echo '<option value="">Seleccionar categoría</option>';
        
        foreach ($product_categories as $category) {
            echo '<option value="' . esc_attr($category->term_id) . '" ' . selected($value, $category->term_id, false) . '>' . esc_html($category->name) . '</option>';
        }
        
        echo '</select>';
        echo '<p class="description">Seleccione la categoría de productos para esta sección.</p>';
    }
    
    /**
     * Callback para los campos de título
     */
    public function title_field_callback($args) {
        $options = get_option('starter_home_sections_options');
        $section_id = $args['section_id'];
        $field_name = $section_id . '_title';
        $value = isset($options[$field_name]) ? $options[$field_name] : '';
        
        echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="starter_home_sections_options[' . esc_attr($field_name) . ']" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">Título personalizado para esta sección (opcional).</p>';
    }
    
    /**
     * Callback para los campos de subtítulo
     */
    public function subtitle_field_callback($args) {
        $options = get_option('starter_home_sections_options');
        $section_id = $args['section_id'];
        $field_name = $section_id . '_subtitle';
        $value = isset($options[$field_name]) ? $options[$field_name] : '';
        
        echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="starter_home_sections_options[' . esc_attr($field_name) . ']" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">Subtítulo personalizado para esta sección (opcional).</p>';
    }
    
    /**
     * Callback para los campos de productos aleatorios
     */
    public function random_field_callback($args) {
        $options = get_option('starter_home_sections_options');
        $section_id = $args['section_id'];
        $field_name = $section_id . '_random';
        $value = isset($options[$field_name]) ? $options[$field_name] : '';
        
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="starter_home_sections_options[' . esc_attr($field_name) . ']" value="1" ' . checked('1', $value, false) . '>';
        echo '<label for="' . esc_attr($args['label_for']) . '">Mostrar productos aleatorios de esta categoría</label>';
        echo '<p class="description">Si se marca esta opción, se mostrarán productos aleatorios de la categoría seleccionada.</p>';
    }
}
