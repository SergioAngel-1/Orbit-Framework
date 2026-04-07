<?php
/**
 * Estilos CSS para la página de Ventas Especiales
 * 
 * Se inyectan inline solo cuando se renderiza la página admin.
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imprimir estilos CSS de la página de Ventas Especiales
 */
function starter_so_print_styles() {
    ?>
    <style type="text/css">
        /* ============================================
         * Tabla de pedidos especiales
         * ============================================ */
        .starter-so-table {
            margin-top: 10px;
        }
        
        .starter-so-table .column-order a {
            text-decoration: none;
            color: #2271b1;
        }
        
        .starter-so-table .column-order a:hover {
            color: #135e96;
        }
        
        .starter-so-table .description {
            color: #666;
            font-size: 12px;
        }
        
        /* ============================================
         * Badges de tipo de orden
         * ============================================ */
        .order-type-tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .membership-tag {
            background-color: #f3e8ff;
            color: #7c3aed;
            border: 1px solid #ddd6fe;
        }
        
        .fc-tag {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        /* ============================================
         * Referencia Wompi
         * ============================================ */
        .wompi-reference {
            font-size: 11px;
            padding: 2px 6px;
            background: #f0f0f1;
            border-radius: 3px;
            word-break: break-all;
        }
        
        /* ============================================
         * Estados de orden (badges)
         * ============================================ */
        .order-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background: transparent;
            line-height: 1.4;
        }
        
        .order-status.status-completed {
            background: #c6e1c6;
            color: #2e4a2e;
        }
        
        .order-status.status-processing {
            background: #c6d9e1;
            color: #2e3f4a;
        }
        
        .order-status.status-on-hold {
            background: #f8dda7;
            color: #6e4a00;
        }
        
        .order-status.status-pending {
            background: #e5e5e5;
            color: #555;
        }
        
        .order-status.status-cancelled,
        .order-status.status-failed {
            background: #eba3a3;
            color: #5a1a1a;
        }
        
        .order-status.status-refunded {
            background: #cdb7e5;
            color: #3b1f5e;
        }
        
        /* ============================================
         * Tarjetas de resumen
         * ============================================ */
        .starter-so-summary {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        
        .starter-so-summary h3 {
            margin-bottom: 15px;
        }
        
        .summary-cards {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .summary-card {
            flex: 1;
            min-width: 280px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }
        
        .summary-card .card-icon {
            font-size: 32px;
            line-height: 1;
        }
        
        .summary-card h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #1d2327;
        }
        
        .card-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .card-stats .stat {
            display: flex;
            flex-direction: column;
        }
        
        .card-stats .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #1d2327;
            line-height: 1.2;
        }
        
        .card-stats .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .membership-card {
            border-left: 4px solid #7c3aed;
        }
        
        .fc-card {
            border-left: 4px solid #d97706;
        }
        
        /* ============================================
         * Badges de motivo (vista Pendientes)
         * ============================================ */
        .reason-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            line-height: 1.4;
            cursor: help;
        }
        
        .reason-waiting {
            background: #e5e5e5;
            color: #555;
        }
        
        .reason-timeout {
            background: #f8dda7;
            color: #6e4a00;
        }
        
        .reason-abandoned {
            background: #f0d4d4;
            color: #8b2020;
        }
        
        .reason-processing {
            background: #c6d9e1;
            color: #2e3f4a;
        }
        
        .reason-error {
            background: #eba3a3;
            color: #5a1a1a;
        }
        
        .reason-unknown {
            background: #e5e5e5;
            color: #555;
        }
        
        /* ============================================
         * Botón Completar (vista Pendientes)
         * ============================================ */
        .starter-so-complete-btn {
            white-space: nowrap;
        }
        
        .starter-so-complete-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* ============================================
         * Búsqueda
         * ============================================ */
        .search-box {
            margin-bottom: 0 !important;
        }
        
        /* ============================================
         * Responsive
         * ============================================ */
        @media screen and (max-width: 782px) {
            .summary-cards {
                flex-direction: column;
            }
            
            .summary-card {
                min-width: auto;
            }
        }
    </style>
    <?php
}
