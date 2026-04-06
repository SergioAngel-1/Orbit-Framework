<?php
/**
 * Funciones para gestionar categorías destacadas desde WordPress
 * Sistema de slots/posiciones con categorías por nivel de membresía
 */

// Número máximo de posiciones/slots
define('FEATURED_CATEGORIES_MAX_SLOTS', 12);

/**
 * Añadir página de opciones para categorías destacadas
 */
function register_featured_categories_page() {
    add_menu_page(
        'Categorías Destacadas',
        'Categorías Destacadas',
        'manage_options',
        'featured-categories',
        'featured_categories_page_callback',
        'dashicons-star-filled',
        21
    );
}
add_action('admin_menu', 'register_featured_categories_page');

/**
 * Callback para la página de opciones - Vista por Nivel de Membresía
 * Muestra tarjetas por cada nivel con las categorías que tienen ese nivel mínimo asignado
 * Permite seleccionar manualmente las 12 categorías destacadas por nivel
 */
function featured_categories_page_callback() {
    // Obtener niveles de membresía
    $membership_levels = [];
    if (class_exists('Starter_Memberships')) {
        $membership_levels = Starter_Memberships::get_all_membership_levels();
    }
    
    if (empty($membership_levels)) {
        echo '<div class="wrap"><h1>Categorías Destacadas</h1>';
        echo '<div class="notice notice-error"><p>El plugin de membresías no está activo.</p></div></div>';
        return;
    }
    
    // Guardar cambios si se envió el formulario
    if (isset($_POST['save_featured_categories']) && check_admin_referer('save_featured_categories_nonce')) {
        $selected_by_level = isset($_POST['featured_by_level']) ? $_POST['featured_by_level'] : array();
        $clean_selection = array();
        
        foreach ($selected_by_level as $level => $categories) {
            $level = intval($level);
            $clean_selection[$level] = array_map('intval', array_slice($categories, 0, FEATURED_CATEGORIES_MAX_SLOTS));
        }
        
        update_option('starter_featured_categories_by_level', $clean_selection);
        
        // Guardar exclusiones por nivel
        $excluded_by_level = isset($_POST['excluded_for_level']) ? $_POST['excluded_for_level'] : array();
        // Primero limpiar todas las exclusiones existentes
        for ($lvl = 0; $lvl <= 5; $lvl++) {
            delete_option('starter_excluded_categories_level_' . $lvl);
        }
        // Guardar las nuevas exclusiones
        foreach ($excluded_by_level as $level => $excluded_cats) {
            $level = intval($level);
            $clean_excluded = array_map('intval', $excluded_cats);
            update_option('starter_excluded_categories_level_' . $level, $clean_excluded);
        }
        
        echo '<div class="notice notice-success is-dismissible"><p>Categorías destacadas guardadas correctamente.</p></div>';
    }
    
    // Obtener selección guardada
    $saved_selection = get_option('starter_featured_categories_by_level', array());
    
    // Obtener todas las categorías de WooCommerce con su membresía mínima
    $args = array(
        'taxonomy'   => 'product_cat',
        'orderby'    => 'name',
        'order'      => 'ASC',
        'hide_empty' => false,
    );
    $product_categories = get_terms($args);
    
    // Organizar categorías por nivel de membresía
    $categories_by_level = array();
    foreach ($membership_levels as $level_id => $level) {
        $categories_by_level[$level_id] = array();
    }
    
    if (!empty($product_categories) && !is_wp_error($product_categories)) {
        foreach ($product_categories as $cat) {
            $min_level = get_term_meta($cat->term_id, '_min_membership_level', true);
            $min_level = $min_level !== '' ? intval($min_level) : 0;
            
            // Obtener imagen
            $thumbnail_id = get_term_meta($cat->term_id, 'thumbnail_id', true);
            $image = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : '';
            
            $cat_data = array(
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'count' => $cat->count,
                'image' => $image,
                'min_level' => $min_level
            );
            
            if (isset($categories_by_level[$min_level])) {
                $categories_by_level[$min_level][] = $cat_data;
            }
        }
    }
    
    ?>
    <div class="wrap">
        <h1>Categorías Destacadas por Membresía</h1>
        <p>Selecciona hasta <?php echo FEATURED_CATEGORIES_MAX_SLOTS; ?> categorías destacadas para cada nivel de membresía.</p>
        <p><strong>Jerarquía (mayor a menor):</strong> � Antigüedad → �💎 Diamante → 🥇 Dorada → 🥈 Plateada → 🥉 Bronce → 🥕 Zanahoria (público/base). Las categorías de niveles inferiores se heredan automáticamente.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('save_featured_categories_nonce'); ?>
            
            <div class="membership-cards-container">
                <?php 
                // Pre-calcular categorías acumuladas para cada nivel (necesario para herencia)
                $all_accumulated = array();
                $temp_accumulated = array();
                foreach ($membership_levels as $lvl_id => $lvl) {
                    $own = $categories_by_level[$lvl_id];
                    $all_accumulated[$lvl_id] = $temp_accumulated;
                    $temp_accumulated = array_merge($temp_accumulated, $own);
                }
                
                // Orden de visualización: Diamante primero, Zanahoria último
                $display_order = array(5, 4, 3, 2, 1, 0);
                
                foreach ($display_order as $level_id) :
                    if (!isset($membership_levels[$level_id])) continue;
                    $level = $membership_levels[$level_id]; 
                    $own_categories = $categories_by_level[$level_id];
                    $own_count = count($own_categories);
                    
                    // Categorías seleccionadas para este nivel
                    $selected_for_level = isset($saved_selection[$level_id]) ? $saved_selection[$level_id] : array();
                    $selected_count = count($selected_for_level);
                    
                    // Usar categorías acumuladas pre-calculadas
                    $accumulated_categories = $all_accumulated[$level_id];
                    
                    // Contar heredadas seleccionadas
                    $inherited_selected_count = 0;
                    foreach ($accumulated_categories as $acc_cat) {
                        $acc_level = $acc_cat['min_level'];
                        if (isset($saved_selection[$acc_level]) && in_array($acc_cat['id'], $saved_selection[$acc_level])) {
                            $inherited_selected_count++;
                        }
                    }
                    
                    $total_selected = $selected_count + $inherited_selected_count;
                    
                    // Determinar estado
                    $status_class = '';
                    $status_text = '';
                    if ($total_selected === 0) {
                        $status_class = 'status-empty';
                        $status_text = 'Sin selección';
                    } elseif ($total_selected > FEATURED_CATEGORIES_MAX_SLOTS) {
                        $status_class = 'status-overflow';
                        $status_text = $total_selected . ' seleccionadas (excede ' . FEATURED_CATEGORIES_MAX_SLOTS . ')';
                    } else {
                        $status_class = 'status-ok';
                        $status_text = $total_selected . '/' . FEATURED_CATEGORIES_MAX_SLOTS . ' seleccionadas';
                    }
                ?>
                <div class="membership-card" style="border-left-color: <?php echo esc_attr($level['color']); ?>" data-level="<?php echo $level_id; ?>">
                    <div class="membership-header" style="background: <?php echo esc_attr($level['color']); ?>15;">
                        <div class="membership-title">
                            <span class="membership-icon"><?php echo esc_html($level['icon']); ?></span>
                            <span class="membership-name"><?php echo esc_html($level['name']); ?></span>
                            <?php if ($level_id === 0) : ?>
                                <span class="membership-badge" style="background: #FF6B35;">Base/Público</span>
                            <?php elseif ($level_id === 5) : ?>
                                <span class="membership-badge" style="background: #9B59B6;">Nivel más alto</span>
                            <?php endif; ?>
                        </div>
                        <div class="membership-status <?php echo $status_class; ?>" id="status-level-<?php echo $level_id; ?>">
                            <?php echo $status_text; ?>
                        </div>
                    </div>
                    
                    <div class="membership-body">
                        <?php if ($level_id > 0 && !empty($accumulated_categories)) : 
                            // Recopilar exclusiones en cascada (de niveles anteriores)
                            $cascade_excluded = array();
                            for ($prev_lvl = 1; $prev_lvl < $level_id; $prev_lvl++) {
                                $exc = get_option('starter_excluded_categories_level_' . $prev_lvl, array());
                                $cascade_excluded = array_merge($cascade_excluded, $exc);
                            }
                            $cascade_excluded = array_unique($cascade_excluded);
                            
                            // Exclusiones propias de este nivel
                            $excluded_for_level = get_option('starter_excluded_categories_level_' . $level_id, array());
                            
                            // Filtrar solo las seleccionadas de niveles anteriores (que no estén excluidas en cascada)
                            $inherited_selected = array();
                            foreach ($accumulated_categories as $cat) {
                                if (isset($saved_selection[$cat['min_level']]) && in_array($cat['id'], $saved_selection[$cat['min_level']])) {
                                    // No mostrar si ya fue excluida en un nivel anterior (cascada)
                                    if (!in_array($cat['id'], $cascade_excluded)) {
                                        $inherited_selected[] = $cat;
                                    }
                                }
                            }
                            
                            $inherited_active_count = 0;
                            foreach ($inherited_selected as $cat) {
                                if (!in_array($cat['id'], $excluded_for_level)) {
                                    $inherited_active_count++;
                                }
                            }
                        ?>
                        <div class="inherited-section">
                            <div class="section-label">
                                <span class="dashicons dashicons-arrow-up-alt"></span>
                                Heredadas de niveles anteriores: <strong class="inherited-count"><?php echo $inherited_active_count; ?></strong>
                                <span class="inherited-hint">(click para excluir - se aplica a niveles superiores)</span>
                            </div>
                            <div class="inherited-categories">
                                <?php foreach ($inherited_selected as $cat) : 
                                    $cat_level = $membership_levels[$cat['min_level']];
                                    $is_excluded = in_array($cat['id'], $excluded_for_level);
                                ?>
                                <label class="category-chip inherited <?php echo $is_excluded ? 'excluded' : ''; ?>" 
                                       title="<?php echo $is_excluded ? 'Excluida - click para incluir' : 'Click para excluir (afecta niveles superiores)'; ?>">
                                    <input type="checkbox" 
                                           name="excluded_for_level[<?php echo $level_id; ?>][]" 
                                           value="<?php echo $cat['id']; ?>"
                                           class="inherited-exclude-checkbox"
                                           data-level="<?php echo $level_id; ?>"
                                           data-cat-name="<?php echo esc_attr($cat['name']); ?>"
                                           <?php checked($is_excluded); ?>
                                           style="display: none;">
                                    <span class="chip-icon" style="color: <?php echo esc_attr($cat_level['color']); ?>"><?php echo esc_html($cat_level['icon']); ?></span>
                                    <?php echo esc_html($cat['name']); ?>
                                    <span class="chip-exclude-icon"></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="own-section">
                            <div class="section-label">
                                <span class="dashicons dashicons-star-filled" style="color: <?php echo esc_attr($level['color']); ?>"></span>
                                Categorías de este nivel: <strong><?php echo $own_count; ?></strong> disponibles
                                <span class="selected-own-count">(<?php echo $selected_count; ?> seleccionadas)</span>
                            </div>
                            <?php if ($own_count > 0) : ?>
                            <div class="own-categories">
                                <?php foreach ($own_categories as $cat) : 
                                    $is_selected = in_array($cat['id'], $selected_for_level);
                                ?>
                                <label class="category-item <?php echo $is_selected ? 'selected' : ''; ?>">
                                    <input type="checkbox" 
                                           name="featured_by_level[<?php echo $level_id; ?>][]" 
                                           value="<?php echo $cat['id']; ?>"
                                           class="category-checkbox"
                                           data-level="<?php echo $level_id; ?>"
                                           <?php checked($is_selected); ?>>
                                    <?php if ($cat['image']) : ?>
                                    <img src="<?php echo esc_url($cat['image']); ?>" class="category-thumb" alt="">
                                    <?php else : ?>
                                    <div class="category-thumb no-image"><span class="dashicons dashicons-format-image"></span></div>
                                    <?php endif; ?>
                                    <div class="category-info">
                                        <span class="category-name"><?php echo esc_html($cat['name']); ?></span>
                                        <span class="category-count"><?php echo $cat['count']; ?> productos</span>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php else : ?>
                            <p class="no-categories">No hay categorías asignadas a este nivel.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php 
                endforeach; 
                ?>
            </div>
            
            <p class="submit" style="position: sticky; bottom: 0; background: #f0f0f1; padding: 15px; margin: 20px -20px -10px; border-top: 1px solid #ccc;">
                <input type="submit" name="save_featured_categories" class="button button-primary button-large" value="Guardar Categorías Destacadas">
            </p>
        </form>
    </div>
    
    <style>
    .membership-cards-container {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-top: 20px;
    }
    
    .membership-card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-left-width: 4px;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .membership-header {
        padding: 12px 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #eee;
    }
    
    .membership-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 15px;
        font-weight: 600;
    }
    
    .membership-icon {
        font-size: 20px;
    }
    
    .membership-badge {
        background: #2271b1;
        color: #fff;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: normal;
    }
    
    .membership-status {
        font-size: 13px;
        padding: 4px 10px;
        border-radius: 4px;
    }
    
    .membership-status.status-ok {
        background: #d4edda;
        color: #155724;
    }
    
    .membership-status.status-overflow {
        background: #fff3cd;
        color: #856404;
    }
    
    .membership-status.status-empty {
        background: #f0f0f1;
        color: #666;
    }
    
    .membership-body {
        padding: 15px;
    }
    
    .section-label {
        font-size: 13px;
        color: #666;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .section-label .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
    }
    
    .inherited-section {
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px dashed #ddd;
    }
    
    .inherited-categories {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .category-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #f0f0f1;
        padding: 4px 10px;
        border-radius: 15px;
        font-size: 12px;
        color: #555;
    }
    
    .category-chip.inherited {
        background: #f8f9fa;
        border: 1px dashed #ccc;
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
    }
    
    .category-chip.inherited:hover {
        background: #fee;
        border-color: #d63638;
    }
    
    .category-chip.inherited.excluded {
        background: #f0f0f1;
        color: #999;
        text-decoration: line-through;
        opacity: 0.6;
    }
    
    .category-chip.inherited.excluded:hover {
        background: #e7f5e7;
        border-color: #00a32a;
        opacity: 1;
    }
    
    .chip-exclude-icon {
        display: none;
        margin-left: 4px;
        color: #d63638;
        font-weight: bold;
    }
    
    .category-chip.inherited:hover .chip-exclude-icon {
        display: inline;
    }
    
    .category-chip.inherited.excluded .chip-exclude-icon {
        display: inline;
        color: #00a32a;
    }
    
    .category-chip.inherited.excluded .chip-exclude-icon::before {
        content: '+';
    }
    
    .category-chip.inherited:not(.excluded) .chip-exclude-icon::before {
        content: '✕';
    }
    
    .inherited-hint {
        font-size: 11px;
        color: #999;
        font-weight: normal;
        margin-left: 5px;
    }
    
    .chip-icon {
        font-size: 12px;
    }
    
    .chip-count {
        color: #999;
        font-size: 11px;
    }
    
    .own-categories {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .category-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px;
        background: #f9f9f9;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
        border: 2px solid transparent;
    }
    
    .category-item:hover {
        background: #f0f0f1;
    }
    
    .category-item.selected {
        background: #e7f5e7;
        border-color: #00a32a;
    }
    
    .category-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin: 0;
        cursor: pointer;
    }
    
    .category-thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 4px;
        background: #eee;
    }
    
    .category-thumb.no-image {
        display: flex;
        align-items: center;
        justify-content: center;
        color: #999;
    }
    
    .category-info {
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    
    .category-name {
        font-weight: 500;
        font-size: 13px;
    }
    
    .category-count {
        font-size: 11px;
        color: #666;
    }
    
    .selected-own-count {
        color: #00a32a;
        font-weight: 500;
    }
    
    .no-categories {
        color: #999;
        font-style: italic;
        margin: 0;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        var MAX_CATEGORIES = <?php echo FEATURED_CATEGORIES_MAX_SLOTS; ?>;
        
        // Actualizar visual al cambiar checkbox de categoría propia
        $('.category-checkbox').on('change', function() {
            var $item = $(this).closest('.category-item');
            if ($(this).is(':checked')) {
                $item.addClass('selected');
            } else {
                $item.removeClass('selected');
            }
            
            // Actualizar conteo del nivel
            var level = $(this).data('level');
            updateLevelStatus(level);
        });
        
        // Manejar click en categorías heredadas para excluir/incluir
        $('.category-chip.inherited').on('click', function(e) {
            e.preventDefault();
            var $chip = $(this);
            var $checkbox = $chip.find('.inherited-exclude-checkbox');
            var level = $checkbox.data('level');
            
            if ($chip.hasClass('excluded')) {
                // Re-incluir
                $chip.removeClass('excluded');
                $checkbox.prop('checked', false);
            } else {
                // Excluir
                $chip.addClass('excluded');
                $checkbox.prop('checked', true);
            }
            
            updateLevelStatus(level);
        });
        
        // Calcular y actualizar el estado de un nivel
        function updateLevelStatus(level) {
            var $card = $('.membership-card[data-level="' + level + '"]');
            
            // Contar propias seleccionadas
            var ownSelected = $card.find('.category-checkbox:checked').length;
            $card.find('.selected-own-count').text('(' + ownSelected + ' seleccionadas)');
            
            // Contar heredadas NO excluidas
            var inheritedActive = $card.find('.category-chip.inherited:not(.excluded)').length;
            $card.find('.inherited-count').text(inheritedActive);
            
            // Total
            var total = ownSelected + inheritedActive;
            
            // Actualizar badge de estado
            var $status = $('#status-level-' + level);
            $status.removeClass('status-ok status-overflow status-empty');
            
            if (total === 0) {
                $status.addClass('status-empty').text('Sin selección');
            } else if (total > MAX_CATEGORIES) {
                $status.addClass('status-overflow').text(total + ' seleccionadas (excede ' + MAX_CATEGORIES + ')');
            } else {
                $status.addClass('status-ok').text(total + '/' + MAX_CATEGORIES + ' seleccionadas');
            }
        }
        
        // Inicializar estados
        $('.membership-card').each(function() {
            var level = $(this).data('level');
            updateLevelStatus(level);
        });
    });
    </script>
    <?php
}

/**
 * Crear endpoint de API REST para obtener categorías destacadas
 */
function register_featured_categories_rest_route() {
    register_rest_route('starter/v1', '/featured-categories', array(
        'methods' => 'GET',
        'callback' => 'get_featured_categories_callback',
        'permission_callback' => '__return_true'
    ));
    
}
add_action('rest_api_init', 'register_featured_categories_rest_route', 10);

/**
 * Variable global para almacenar las imágenes ya utilizadas y evitar duplicados
 */
$used_product_images = array();

/**
 * Obtiene una imagen aleatoria de un producto perteneciente a una categoría
 * evitando imágenes que ya han sido utilizadas en otras categorías
 *
 * @param int $category_id ID de la categoría
 * @return string URL de la imagen o cadena vacía si no se encuentra
 */
function get_random_product_image_from_category($category_id) {
    global $used_product_images;
    
    // Consultar productos de la categoría
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => 10,  // Aumentamos a 10 productos para tener más opciones
        'orderby'        => 'rand', // Orden aleatorio
        'tax_query'      => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category_id,
            ),
        ),
    );
    
    $products = new WP_Query($args);
    $potential_images = array();
    
    if ($products->have_posts()) {
        while ($products->have_posts()) {
            $products->the_post();
            $product_id = get_the_ID();
            $product = wc_get_product($product_id);
            
            if ($product) {
                // Intentar obtener la imagen principal
                $image_id = $product->get_image_id();
                if ($image_id) {
                    $image_url = wp_get_attachment_url($image_id);
                    if ($image_url && !in_array($image_url, $used_product_images)) {
                        $potential_images[] = $image_url;
                    }
                }
                
                // También revisar imágenes de la galería
                $gallery_ids = $product->get_gallery_image_ids();
                if (!empty($gallery_ids)) {
                    foreach ($gallery_ids as $gallery_id) {
                        $image_url = wp_get_attachment_url($gallery_id);
                        if ($image_url && !in_array($image_url, $used_product_images)) {
                            $potential_images[] = $image_url;
                        }
                    }
                }
            }
        }
        wp_reset_postdata();
    }
    
    // Si encontramos imágenes no utilizadas, usar la primera
    if (!empty($potential_images)) {
        $selected_image = $potential_images[0];
        $used_product_images[] = $selected_image; // Marcar como utilizada
        return $selected_image;
    }
    
    // Si todas las imágenes ya están utilizadas o no hay productos,
    // intentar con cualquier imagen (incluso si ya se usó)
    if ($products->have_posts()) {
        $products->rewind_posts();
        while ($products->have_posts()) {
            $products->the_post();
            $product_id = get_the_ID();
            $product = wc_get_product($product_id);
            
            if ($product) {
                $image_id = $product->get_image_id();
                if ($image_id) {
                    $image_url = wp_get_attachment_url($image_id);
                    if ($image_url) {
                        wp_reset_postdata();
                        return $image_url;
                    }
                }
            }
        }
        wp_reset_postdata();
    }
    
    // Si no se encuentra ninguna imagen, devolver cadena vacía
    return '';
}

/**
 * Verifica si hay un token JWT válido en la petición REST
 * Si no hay token Bearer, retorna 0 (usuario anónimo) independientemente de las cookies de sesión
 * 
 * IMPORTANTE: Esta función existe porque WordPress puede mantener cookies de sesión
 * que persisten después de que el frontend elimina el token JWT. Esto causaba que
 * usuarios "deslogueados" en el frontend siguieran viendo contenido de membresía.
 * 
 * @return int ID del usuario si hay token JWT válido, 0 si no hay token o es inválido
 */
function starter_get_jwt_authenticated_user_id() {
    // Verificar si hay header de autorización Bearer
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    
    // También verificar el header alternativo (algunos servidores lo usan)
    if (empty($auth_header) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    
    // Si no hay header Bearer, el usuario es anónimo (nivel 0)
    if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
        return 0;
    }
    
    // Si hay token Bearer, confiar en get_current_user_id() que ya fue procesado por el plugin JWT
    return get_current_user_id();
}

/**
 * Callback para el endpoint de categorías destacadas
 * Retorna las categorías seleccionadas manualmente para el nivel de membresía del usuario
 * Respeta las exclusiones configuradas por nivel
 * 
 * IMPORTANTE: Este endpoint es sensible a membresía - retorna datos diferentes
 * según el nivel del usuario. Se envían headers para prevenir caché del navegador.
 */
function get_featured_categories_callback($request) {
    global $used_product_images;
    $used_product_images = array(); // Reiniciar el array de imágenes utilizadas
    
    // Detect language for translations
    $lang = function_exists('starter_get_request_lang')
        ? starter_get_request_lang($request->get_param('lang'))
        : 'es';
    
    // CRÍTICO: Usar función que verifica JWT, no cookies de sesión
    // Esto asegura que usuarios sin token Bearer sean tratados como anónimos
    $user_membership_level = 0;
    $user_id = starter_get_jwt_authenticated_user_id();
    if ($user_id && function_exists('starter_get_user_membership_level')) {
        $user_membership_level = starter_get_user_membership_level($user_id);
    }
    
    // Helper para agregar headers de no-cache a la respuesta
    $add_no_cache_headers = function($response) use ($user_membership_level) {
        if ($response instanceof WP_REST_Response) {
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            $response->header('X-Membership-Level', $user_membership_level);
        }
        return $response;
    };
    
    // Obtener selección manual guardada
    $saved_selection = get_option('starter_featured_categories_by_level', array());
    
    // Recopilar todas las exclusiones desde nivel 1 hasta el nivel del usuario (cascada)
    // Si algo se excluye en Bronce, también está excluido en Plateada, Dorada, etc.
    $all_excluded = array();
    for ($lvl = 1; $lvl <= $user_membership_level; $lvl++) {
        $excluded_at_level = get_option('starter_excluded_categories_level_' . $lvl, array());
        $all_excluded = array_merge($all_excluded, $excluded_at_level);
    }
    $all_excluded = array_unique($all_excluded);
    
    // Recopilar IDs de categorías seleccionadas para el nivel del usuario y niveles inferiores
    $selected_category_ids = array();
    for ($level = 0; $level <= $user_membership_level; $level++) {
        if (isset($saved_selection[$level]) && is_array($saved_selection[$level])) {
            foreach ($saved_selection[$level] as $cat_id) {
                // No incluir si está excluida en cualquier nivel hasta el del usuario
                if (!in_array($cat_id, $all_excluded)) {
                    $selected_category_ids[] = $cat_id;
                }
            }
        }
    }
    
    // Eliminar duplicados y limitar a 12
    $selected_category_ids = array_unique($selected_category_ids);
    $selected_category_ids = array_slice($selected_category_ids, 0, FEATURED_CATEGORIES_MAX_SLOTS);
    
    if (empty($selected_category_ids)) {
        $response = new WP_REST_Response(array(), 200);
        return $add_no_cache_headers($response);
    }
    
    // Obtener niveles de membresía para la info
    $membership_levels = [];
    if (class_exists('Starter_Memberships')) {
        $membership_levels = Starter_Memberships::get_all_membership_levels();
    }
    
    $featured_categories = array();
    
    foreach ($selected_category_ids as $category_id) {
        $term = get_term($category_id, 'product_cat');
        
        if (is_wp_error($term) || !$term) {
            continue;
        }
        
        // Obtener membresía mínima de la categoría
        $min_membership = get_term_meta($term->term_id, '_min_membership_level', true);
        $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
        
        // Verificar que el usuario tenga acceso
        if ($user_membership_level < $min_membership) {
            continue;
        }
        
        // Primero intentar obtener imagen aleatoria de un producto de la categoría
        $image = get_random_product_image_from_category($term->term_id);
        
        // Si no hay imagen de producto, usar la imagen de categoría como fallback
        if (!$image) {
            $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
            $image = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : '';
        }
        
        // Preparar información de membresía
        $membership_info = null;
        if ($min_membership > 0 && isset($membership_levels[$min_membership])) {
            $level_info = $membership_levels[$min_membership];
            $membership_info = array(
                'level' => $min_membership,
                'name' => $level_info['name'],
                'icon' => $level_info['icon'],
                'color' => $level_info['color']
            );
        }
        
        $category = array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'count' => $term->count,
            'image' => $image ? $image : '',
            'description' => $term->description,
            'link' => get_term_link($term->term_id, 'product_cat'),
            'min_membership' => $min_membership,
            'membership_info' => $membership_info
        );
        
        // Translate category name and description if needed
        if ($lang !== 'es' && function_exists('starter_translate_category')) {
            $category = starter_translate_category($category, $lang);
        }
        
        $featured_categories[] = $category;
    }
    
    $response = new WP_REST_Response($featured_categories, 200);
    return $add_no_cache_headers($response);
}
