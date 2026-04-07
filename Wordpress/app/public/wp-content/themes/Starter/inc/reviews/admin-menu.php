<?php
/**
 * Reviews - Menú de administración
 * 
 * Registra el menú "Reseñas" en la sidebar de WordPress admin
 * con una página de listado y filtrado por estrellas.
 * 
 * @package Starter
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar menú principal de Reseñas en WP Admin
 */
function starter_add_reviews_admin_menu() {
    add_menu_page(
        'Reseñas',
        'Reseñas',
        'manage_woocommerce',
        'starter-reviews',
        'starter_reviews_admin_page',
        'dashicons-star-filled',
        56 // Después de WooCommerce (55)
    );
}
add_action('admin_menu', 'starter_add_reviews_admin_menu');

/**
 * Página admin: Listado de reseñas con filtros
 */
function starter_reviews_admin_page() {
    global $wpdb;

    // Parámetros de filtro
    $rating_filter = isset($_GET['rating']) ? absint($_GET['rating']) : 0;
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $per_page = 20;
    $offset = ($paged - 1) * $per_page;
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $review_type = isset($_GET['review_type']) ? sanitize_text_field($_GET['review_type']) : ''; // 'order' | 'product' | ''
    $not_received_ok = isset($_GET['not_received_ok']) ? absint($_GET['not_received_ok']) : 0; // 1 = solo "No recibió a conformidad"

    // ── JOINs adicionales (dinámicos según filtros) ──
    $extra_joins = '';

    // ── WHERE dinámico ──
    $where_clauses = array(
        "c.comment_type = 'review'",
        "c.comment_approved = '1'",
        "c.comment_parent = 0",
        "cm.meta_key = 'rating'",
        "cm.meta_value > 0",
    );
    $where_values = array();

    if ($rating_filter >= 1 && $rating_filter <= 5) {
        $where_clauses[] = "CAST(cm.meta_value AS UNSIGNED) = %d";
        $where_values[] = $rating_filter;
    }

    if (!empty($search)) {
        $where_clauses[] = "(c.comment_content LIKE %s OR c.comment_author LIKE %s)";
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where_values[] = $like;
        $where_values[] = $like;
    }

    // Filtro por tipo de reseña (producto vs pedido)
    if ($review_type === 'order') {
        $extra_joins .= " INNER JOIN {$wpdb->commentmeta} cm_type ON c.comment_ID = cm_type.comment_ID AND cm_type.meta_key = '_starter_from_order_rating'";
    } elseif ($review_type === 'product') {
        $extra_joins .= " LEFT JOIN {$wpdb->commentmeta} cm_type ON c.comment_ID = cm_type.comment_ID AND cm_type.meta_key = '_starter_from_order_rating'";
        $where_clauses[] = "cm_type.meta_value IS NULL";
    }

    // Filtro "No recibió a conformidad": reseñas de pedido donde el order tiene _starter_order_received_ok = 0
    if ($not_received_ok === 1) {
        // Forzar que sea reseña de pedido si no se filtró ya
        if ($review_type !== 'order') {
            $extra_joins .= " INNER JOIN {$wpdb->commentmeta} cm_type2 ON c.comment_ID = cm_type2.comment_ID AND cm_type2.meta_key = '_starter_from_order_rating'";
            // Usar cm_type2 para el JOIN con orders
            $extra_joins .= " INNER JOIN {$wpdb->postmeta} pm_rok ON pm_rok.post_id = CAST(cm_type2.meta_value AS UNSIGNED) AND pm_rok.meta_key = '_starter_order_received_ok' AND pm_rok.meta_value = '0'";
        } else {
            // Ya tenemos cm_type con el order_id
            $extra_joins .= " INNER JOIN {$wpdb->postmeta} pm_rok ON pm_rok.post_id = CAST(cm_type.meta_value AS UNSIGNED) AND pm_rok.meta_key = '_starter_order_received_ok' AND pm_rok.meta_value = '0'";
        }
    }

    $where_sql = implode(' AND ', $where_clauses);

    // ── Total ──
    $count_query = "
        SELECT COUNT(*)
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        {$extra_joins}
        WHERE {$where_sql}
    ";
    if (!empty($where_values)) {
        $count_query = $wpdb->prepare($count_query, ...$where_values);
    }
    $total = (int) $wpdb->get_var($count_query);
    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;

    // ── Reseñas ──
    $select_query = "
        SELECT c.comment_ID, c.comment_post_ID, c.comment_author, c.comment_content,
               c.comment_date, c.user_id, CAST(cm.meta_value AS UNSIGNED) AS rating
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        {$extra_joins}
        WHERE {$where_sql}
        ORDER BY c.comment_date DESC
        LIMIT %d OFFSET %d
    ";
    $select_values = array_merge($where_values, array($per_page, $offset));
    $reviews = $wpdb->get_results($wpdb->prepare($select_query, ...$select_values));

    // ── Estadísticas globales ──
    $stats_rows = $wpdb->get_results("
        SELECT CAST(cm.meta_value AS UNSIGNED) AS rating, COUNT(*) AS cnt
        FROM {$wpdb->comments} c
        INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
        WHERE c.comment_type = 'review'
          AND c.comment_approved = '1'
          AND c.comment_parent = 0
          AND cm.meta_key = 'rating'
          AND cm.meta_value > 0
        GROUP BY rating
        ORDER BY rating DESC
    ");
    $distribution = array(5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0);
    $stats_total = 0;
    $stats_sum = 0;
    foreach ($stats_rows as $row) {
        $r = intval($row->rating);
        $c = intval($row->cnt);
        if ($r >= 1 && $r <= 5) {
            $distribution[$r] = $c;
            $stats_total += $c;
            $stats_sum += $r * $c;
        }
    }
    $stats_average = $stats_total > 0 ? round($stats_sum / $stats_total, 1) : 0;

    $base_url = admin_url('admin.php?page=starter-reviews');
    if (!empty($search)) {
        $base_url = add_query_arg('s', urlencode($search), $base_url);
    }
    if (!empty($review_type)) {
        $base_url = add_query_arg('review_type', $review_type, $base_url);
    }
    if ($not_received_ok === 1) {
        $base_url = add_query_arg('not_received_ok', 1, $base_url);
    }

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Reseñas</h1>
        <hr class="wp-header-end">

        <!-- Estadísticas rápidas -->
        <div style="display: flex; gap: 12px; margin: 16px 0; flex-wrap: wrap;">
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 20px; min-width: 120px; text-align: center;">
                <div style="font-size: 28px; font-weight: 600; color: #1d2327;"><?php echo esc_html($stats_total); ?></div>
                <div style="color: #646970; font-size: 13px;">Total reseñas</div>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 20px; min-width: 120px; text-align: center;">
                <div style="font-size: 28px; font-weight: 600; color: #dba617;">★ <?php echo esc_html($stats_average); ?></div>
                <div style="color: #646970; font-size: 13px;">Promedio</div>
            </div>
            <?php foreach ($distribution as $stars => $count) : ?>
                <a href="<?php echo esc_url(add_query_arg('rating', $stars, $base_url)); ?>"
                   style="background: <?php echo $rating_filter === $stars ? '#f0f0f1' : '#fff'; ?>; border: 1px solid <?php echo $rating_filter === $stars ? '#2271b1' : '#c3c4c7'; ?>; border-radius: 4px; padding: 12px 16px; min-width: 80px; text-align: center; text-decoration: none; color: inherit; transition: border-color 0.2s;">
                    <div style="font-size: 18px; font-weight: 600; color: #1d2327;"><?php echo esc_html($count); ?></div>
                    <div style="color: #dba617; font-size: 13px;"><?php echo str_repeat('★', $stars) . str_repeat('☆', 5 - $stars); ?></div>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Barra de filtros -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <?php if ($rating_filter > 0) : ?>
                    <span style="background: #2271b1; color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 13px;">
                        Filtro: <?php echo str_repeat('★', $rating_filter); ?>
                        <a href="<?php echo esc_url(remove_query_arg('rating', $base_url)); ?>" style="color: #fff; text-decoration: none; margin-left: 6px;">✕</a>
                    </span>
                <?php endif; ?>
                <?php if (!empty($review_type)) : ?>
                    <span style="background: #8c5e2a; color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 13px;">
                        Tipo: <?php echo $review_type === 'order' ? 'Pedido' : 'Producto'; ?>
                        <a href="<?php echo esc_url(remove_query_arg('review_type', $base_url)); ?>" style="color: #fff; text-decoration: none; margin-left: 6px;">✕</a>
                    </span>
                <?php endif; ?>
                <?php if ($not_received_ok === 1) : ?>
                    <span style="background: #d63638; color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 13px;">
                        No recibió a conformidad
                        <a href="<?php echo esc_url(remove_query_arg('not_received_ok', $base_url)); ?>" style="color: #fff; text-decoration: none; margin-left: 6px;">✕</a>
                    </span>
                <?php endif; ?>
                <span style="color: #646970; font-size: 13px;">
                    <?php echo esc_html($total); ?> reseña<?php echo $total !== 1 ? 's' : ''; ?> encontrada<?php echo $total !== 1 ? 's' : ''; ?>
                </span>
            </div>
            <form method="get" action="" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="page" value="starter-reviews">
                <?php if ($rating_filter > 0) : ?>
                    <input type="hidden" name="rating" value="<?php echo esc_attr($rating_filter); ?>">
                <?php endif; ?>
                <select name="review_type" style="min-width: 140px;">
                    <option value="">Todos los tipos</option>
                    <option value="order" <?php selected($review_type, 'order'); ?>>Reseña de pedido</option>
                    <option value="product" <?php selected($review_type, 'product'); ?>>Reseña de producto</option>
                </select>
                <label style="font-size: 13px; display: flex; align-items: center; gap: 4px; white-space: nowrap;">
                    <input type="checkbox" name="not_received_ok" value="1" <?php checked($not_received_ok, 1); ?>>
                    No recibió a conformidad
                </label>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por autor o contenido..." class="regular-text" style="max-width: 260px;">
                <button type="submit" class="button">Filtrar</button>
            </form>
        </div>

        <!-- Tabla de reseñas -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th style="width: 140px;">Autor</th>
                    <th>Reseña</th>
                    <th style="width: 200px;">Producto</th>
                    <th style="width: 90px;">Tipo</th>
                    <th style="width: 100px;">Rating</th>
                    <th style="width: 140px;">Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reviews)) : ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px; color: #646970;">
                            No se encontraron reseñas<?php echo $rating_filter > 0 ? ' con ' . $rating_filter . ' estrella' . ($rating_filter > 1 ? 's' : '') : ''; ?>.
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($reviews as $review) :
                        $product = wc_get_product($review->comment_post_ID);
                        $product_name = $product ? $product->get_name() : '(Producto eliminado)';
                        $product_edit_link = $product ? get_edit_post_link($review->comment_post_ID) : '#';
                        $stars_html = str_repeat('★', $review->rating) . str_repeat('☆', 5 - $review->rating);
                        $comment_edit_link = admin_url('comment.php?action=editcomment&c=' . $review->comment_ID);
                        $review_excerpt = mb_strlen($review->comment_content) > 120
                            ? mb_substr($review->comment_content, 0, 120) . '…'
                            : $review->comment_content;

                        // Determinar tipo: pedido vs producto
                        $from_order_id = get_comment_meta($review->comment_ID, '_starter_from_order_rating', true);
                        $is_order_review = !empty($from_order_id);

                        // Si es de pedido, verificar conformidad
                        $order_received_ok = null;
                        if ($is_order_review) {
                            $order_obj = wc_get_order(absint($from_order_id));
                            if ($order_obj) {
                                $rok_meta = $order_obj->get_meta('_starter_order_received_ok');
                                if ($rok_meta !== '') {
                                    $order_received_ok = intval($rok_meta) === 1;
                                }
                            }
                        }
                    ?>
                        <tr>
                            <td><a href="<?php echo esc_url($comment_edit_link); ?>">#<?php echo esc_html($review->comment_ID); ?></a></td>
                            <td>
                                <?php if ($review->user_id > 0) : ?>
                                    <strong><a href="<?php echo esc_url(get_edit_user_link($review->user_id)); ?>"><?php echo esc_html($review->comment_author); ?></a></strong>
                                    <br><small style="color: #646970;">ID: <?php echo esc_html($review->user_id); ?></small>
                                <?php else : ?>
                                    <strong><?php echo esc_html($review->comment_author); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html($review_excerpt); ?>
                                <div class="row-actions">
                                    <span><a href="<?php echo esc_url($comment_edit_link); ?>">Editar</a></span>
                                </div>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($product_edit_link); ?>"><?php echo esc_html($product_name); ?></a>
                            </td>
                            <td>
                                <?php if ($is_order_review) : ?>
                                    <span style="background: #dba617; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px; white-space: nowrap;">Pedido #<?php echo esc_html($from_order_id); ?></span>
                                    <?php if ($order_received_ok === false) : ?>
                                        <br><span style="background: #d63638; color: #fff; padding: 1px 6px; border-radius: 3px; font-size: 11px; margin-top: 2px; display: inline-block;">No conformidad</span>
                                    <?php elseif ($order_received_ok === true) : ?>
                                        <br><span style="color: #00a32a; font-size: 11px;">✓ Conforme</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span style="background: #2271b1; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px;">Producto</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: #dba617; font-size: 14px;"><?php echo $stars_html; ?></span>
                            </td>
                            <td><?php echo esc_html(date_i18n('j M Y, g:i a', strtotime($review->comment_date))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Paginación -->
        <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo esc_html($total); ?> elemento<?php echo $total !== 1 ? 's' : ''; ?></span>
                    <?php
                    $pagination_args = array('rating' => $rating_filter);
                    if (!empty($search)) {
                        $pagination_args['s'] = $search;
                    }
                    $paginate_add_args = array();
                    if ($rating_filter > 0) $paginate_add_args['rating'] = $rating_filter;
                    if (!empty($review_type)) $paginate_add_args['review_type'] = $review_type;
                    if ($not_received_ok === 1) $paginate_add_args['not_received_ok'] = 1;
                    echo paginate_links(array(
                        'base'      => add_query_arg('paged', '%#%', $base_url),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'add_args'  => $paginate_add_args,
                    ));
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
