<div class="wrap starter-rp-network">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="starter-rp-tabs">
        <a href="<?php echo admin_url('admin.php?page=starter-rp-dashboard'); ?>" class="tab">
            <?php _e('Dashboard', 'starter-rp'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=starter-rp-transactions'); ?>" class="tab">
            <?php _e('Transacciones', 'starter-rp'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=starter-rp-network'); ?>" class="tab active">
            <?php _e('Red de Referidos', 'starter-rp'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=starter-rp-settings'); ?>" class="tab">
            <?php _e('Configuración', 'starter-rp'); ?>
        </a>
    </div>
    
    <!-- Formulario de búsqueda -->
    <form id="referrals-filter" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
        <?php
        // Si hay un referrer_id, mantenerlo en la búsqueda
        if (isset($_REQUEST['referrer']) && !empty($_REQUEST['referrer'])) {
            echo '<input type="hidden" name="referrer" value="' . esc_attr($_REQUEST['referrer']) . '" />';
        }
        ?>
        
        <div class="search-box">
            <input type="search" id="referrals-search-input" name="s" 
                   value="<?php echo isset($_REQUEST['s']) ? esc_attr($_REQUEST['s']) : ''; ?>" 
                   placeholder="<?php esc_attr_e('Buscar por nombre, email o código...', 'starter-rp'); ?>" />
            <?php submit_button(__('Buscar', 'starter-rp'), 'button', false, false, array('id' => 'search-submit')); ?>
        </div>
    </form>
    
    <!-- Estadísticas de referidos -->
    <div class="starter-rp-stats-cards">
        <div class="starter-rp-card">
            <div class="card-icon">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="card-content">
                <h3><?php _e('Total Usuarios', 'starter-rp'); ?></h3>
                <div class="card-value"><?php echo esc_html($total_users); ?></div>
            </div>
        </div>
        
        <div class="starter-rp-card">
            <div class="card-icon">
                <span class="dashicons dashicons-networking"></span>
            </div>
            <div class="card-content">
                <h3><?php _e('Con Referidor', 'starter-rp'); ?></h3>
                <div class="card-value"><?php echo esc_html($users_with_referrer); ?></div>
            </div>
        </div>
        
        <div class="starter-rp-card">
            <div class="card-icon">
                <span class="dashicons dashicons-chart-bar"></span>
            </div>
            <div class="card-content">
                <h3><?php _e('Tasa de Referidos', 'starter-rp'); ?></h3>
                <div class="card-value"><?php echo esc_html($referral_rate); ?>%</div>
            </div>
        </div>
    </div>
    
    <!-- Tabla de usuarios con referido -->
    <div class="starter-rp-table-container">
        <?php
        // Mostrar la tabla
        $referrals_table = new Starter_RP_Referrals_Table();
        $referrals_table->prepare_items();
        $referrals_table->display();
        ?>
    </div>
</div>

<!-- Los estilos ahora se cargan desde el archivo starter-styles.css -->
