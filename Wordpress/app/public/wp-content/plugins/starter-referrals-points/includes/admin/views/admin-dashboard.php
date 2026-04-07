<div class="starter-rp-dashboard">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="starter-rp-stats-cards">
        <div class="starter-rp-card">
            <div class="card-icon">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="card-content">
                <h3><?php _e('Usuarios con Puntos', 'starter-rp'); ?></h3>
                <div class="card-value"><?php echo esc_html($users_with_points); ?></div>
            </div>
        </div>
        
        <div class="starter-rp-card">
            <div class="card-icon">
                <span class="dashicons dashicons-star-filled"></span>
            </div>
            <div class="card-content">
                <h3><?php _e('Puntos Activos', 'starter-rp'); ?></h3>
                <div class="card-value"><?php echo esc_html(number_format($total_active_points)); ?></div>
            </div>
        </div>
        
        <div class="starter-rp-card">
            <div class="card-icon">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="card-content">
                <h3><?php _e('Transacciones', 'starter-rp'); ?></h3>
                <div class="card-value"><?php echo esc_html(number_format($total_transactions)); ?></div>
            </div>
        </div>
        
        <div class="starter-rp-card">
            <div class="card-icon">
                <span class="dashicons dashicons-networking"></span>
            </div>
            <div class="card-content">
                <h3><?php _e('Relaciones de Referidos', 'starter-rp'); ?></h3>
                <div class="card-value"><?php echo esc_html(number_format($total_referrals)); ?></div>
            </div>
        </div>
    </div>
    
    <div class="starter-rp-dashboard-widgets">
        <!-- Transacciones recientes -->
        <div class="starter-rp-widget">
            <h2><?php _e('Transacciones Recientes', 'starter-rp'); ?></h2>
            
            <?php if (empty($recent_transactions)) : ?>
                <p><?php _e('No hay transacciones recientes.', 'starter-rp'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Usuario', 'starter-rp'); ?></th>
                            <th><?php _e('Fecha', 'starter-rp'); ?></th>
                            <th><?php _e('Tipo', 'starter-rp'); ?></th>
                            <th><?php _e('Puntos', 'starter-rp'); ?></th>
                            <th><?php _e('Descripción', 'starter-rp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_transactions as $transaction) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_user_link($transaction->user_id); ?>">
                                        <?php echo esc_html($transaction->display_name); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at)); ?>
                                </td>
                                <td>
                                    <?php 
                                    switch ($transaction->type) {
                                        case 'earned':
                                            _e('Ganado', 'starter-rp');
                                            break;
                                        case 'used':
                                            _e('Usado', 'starter-rp');
                                            break;
                                        case 'expired':
                                            _e('Expirado', 'starter-rp');
                                            break;
                                        case 'admin_add':
                                            _e('Añadido por admin', 'starter-rp');
                                            break;
                                        case 'admin_deduct':
                                            _e('Deducido por admin', 'starter-rp');
                                            break;
                                        case 'referral':
                                            _e('Comisión de referido', 'starter-rp');
                                            break;
                                        default:
                                            echo ucfirst($transaction->type);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span style="color: <?php echo $transaction->points >= 0 ? 'green' : 'red'; ?>">
                                        <?php echo $transaction->points; ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($transaction->description); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="starter-rp-view-all">
                    <a href="<?php echo admin_url('admin.php?page=starter-rp-transactions'); ?>" class="button button-secondary">
                        <?php _e('Ver Todas las Transacciones', 'starter-rp'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        
        <!-- Usuarios con más puntos -->
        <div class="starter-rp-widget">
            <h2><?php _e('Usuarios con Más Puntos', 'starter-rp'); ?></h2>
            
            <?php if (empty($top_users)) : ?>
                <p><?php _e('No hay usuarios con puntos.', 'starter-rp'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Usuario', 'starter-rp'); ?></th>
                            <th><?php _e('Email', 'starter-rp'); ?></th>
                            <th><?php _e('Saldo de Puntos', 'starter-rp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_users as $user) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_user_link($user->ID); ?>">
                                        <?php echo esc_html($user->display_name); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($user->user_email); ?></td>
                                <td>
                                    <strong><?php echo number_format($user->points); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Mejores referidores -->
        <div class="starter-rp-widget">
            <h2><?php _e('Mejores Referidores', 'starter-rp'); ?></h2>
            
            <?php if (empty($top_referrers)) : ?>
                <p><?php _e('No hay referidores activos.', 'starter-rp'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Usuario', 'starter-rp'); ?></th>
                            <th><?php _e('Referidos', 'starter-rp'); ?></th>
                            <th><?php _e('Acciones', 'starter-rp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_referrers as $referrer) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo get_edit_user_link($referrer->ID); ?>">
                                        <?php echo esc_html($referrer->display_name); ?>
                                    </a>
                                </td>
                                <td>
                                    <strong><?php echo number_format($referrer->total_referrals); ?></strong>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=starter-rp-network&referrer=' . $referrer->ID); ?>" class="button button-small">
                                        <?php _e('Ver Referidos', 'starter-rp'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="starter-rp-view-all">
                    <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="button button-secondary">
                        <?php _e('Ver Red de Referidos', 'starter-rp'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        
        <!-- Formulario para asignar puntos a usuarios -->
        <div class="starter-rp-widget">
            <h2><?php _e('Asignar Puntos a Usuarios', 'starter-rp'); ?></h2>
            
            <?php
            // Mostrar errores/mensajes
            settings_errors('starter_rp_admin_points');
            ?>
            
            <form method="post" action="" class="starter-rp-admin-points-form">
                <?php wp_nonce_field('starter_rp_admin_points_action', 'starter_rp_admin_points_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="user_id"><?php _e('Usuario', 'starter-rp'); ?></label>
                        </th>
                        <td>
                            <select name="user_id" id="user_id" class="regular-text" required>
                                <option value=""><?php _e('Seleccionar usuario...', 'starter-rp'); ?></option>
                                <?php
                                // Obtener usuarios
                                $users = get_users([
                                    'orderby' => 'display_name',
                                    'order' => 'ASC',
                                    'fields' => ['ID', 'display_name', 'user_email']
                                ]);
                                
                                foreach ($users as $user) {
                                    printf(
                                        '<option value="%d">%s (%s)</option>',
                                        $user->ID,
                                        $user->display_name,
                                        $user->user_email
                                    );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="action_type"><?php _e('Acción', 'starter-rp'); ?></label>
                        </th>
                        <td>
                            <select name="action_type" id="action_type" required>
                                <option value="add"><?php _e('Añadir puntos', 'starter-rp'); ?></option>
                                <option value="deduct"><?php _e('Deducir puntos', 'starter-rp'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="points"><?php _e('Cantidad de puntos', 'starter-rp'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="points" id="points" class="regular-text" min="1" step="1" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="description"><?php _e('Descripción', 'starter-rp'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="description" id="description" class="regular-text" required>
                            <p class="description"><?php _e('Motivo por el que se añaden/deducen los puntos', 'starter-rp'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Asignar Puntos', 'starter-rp'); ?>">
                </p>
            </form>
        </div>
    </div>
</div>

<!-- Los estilos ahora se cargan desde el archivo starter-styles.css -->
