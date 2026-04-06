<?php
/**
 * Estilos CSS para la página de administración de grillas
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderizar estilos de la página de admin
 */
function fipg_render_admin_styles() {
    ?>
    <style>
    .fipg-admin-wrap {
        max-width: 1400px;
    }
    
    .membership-cards-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-top: 20px;
    }
    
    @media (max-width: 1200px) {
        .membership-cards-grid {
            grid-template-columns: 1fr;
        }
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
        background: linear-gradient(to right, rgba(0,0,0,0.02), transparent);
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
    
    .membership-stats {
        display: flex;
        gap: 10px;
        font-size: 12px;
    }
    
    .membership-stats .stat-own {
        background: #d4edda;
        color: #155724;
        padding: 2px 8px;
        border-radius: 10px;
    }
    
    .membership-stats .stat-inherited {
        background: #e7f3ff;
        color: #0073aa;
        padding: 2px 8px;
        border-radius: 10px;
    }
    
    .membership-body {
        padding: 15px;
    }
    
    .inherited-section {
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px dashed #ddd;
    }
    
    .section-label {
        font-size: 13px;
        color: #666;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
    }
    
    .section-label .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
    }
    
    .inherited-hint {
        font-size: 11px;
        color: #999;
        font-weight: normal;
    }
    
    .inherited-items {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .grid-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #f0f0f1;
        padding: 6px 12px;
        border-radius: 15px;
        font-size: 12px;
        color: #555;
        cursor: pointer;
        transition: all 0.2s;
        border: 1px dashed #ccc;
    }
    
    .grid-chip.inherited:hover {
        background: #fee;
        border-color: #d63638;
    }
    
    .grid-chip.inherited.excluded {
        background: #f0f0f1;
        color: #999;
        text-decoration: line-through;
        opacity: 0.6;
    }
    
    .grid-chip.inherited.excluded:hover {
        background: #e7f5e7;
        border-color: #00a32a;
        opacity: 1;
    }
    
    .chip-icon {
        font-size: 14px;
    }
    
    .chip-type {
        font-size: 10px;
        background: rgba(0,0,0,0.1);
        padding: 1px 5px;
        border-radius: 8px;
    }
    
    .chip-exclude-icon {
        display: none;
        margin-left: 4px;
        font-weight: bold;
    }
    
    .grid-chip.inherited:hover .chip-exclude-icon {
        display: inline;
        color: #d63638;
    }
    
    .grid-chip.inherited.excluded .chip-exclude-icon {
        display: inline;
        color: #00a32a;
    }
    
    .own-section .section-label {
        justify-content: space-between;
    }
    
    .own-grids-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .grid-item {
        background: #f9f9f9;
        border: 1px solid #ddd;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .grid-item.disabled {
        opacity: 0.6;
    }
    
    .grid-item-header {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        background: #fff;
        border-bottom: 1px solid #eee;
        cursor: pointer;
    }
    
    .grid-item-header:hover {
        background: #f5f5f5;
    }
    
    .grid-title-display {
        flex: 1;
        font-weight: 500;
    }
    
    .grid-type-badge {
        font-size: 10px;
        background: #e7f3ff;
        color: #0073aa;
        padding: 2px 6px;
        border-radius: 8px;
    }
    
    .grid-type-badge.category {
        background: #fff3cd;
        color: #856404;
    }
    
    .grid-products-count {
        font-size: 11px;
        color: #666;
    }
    
    .grid-item-details {
        padding: 15px;
        background: #fafafa;
    }
    
    .grid-field-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }
    
    .grid-field-row label {
        min-width: 80px;
        font-weight: 500;
        font-size: 12px;
    }
    
    .grid-field-row select,
    .grid-field-row input[type="text"] {
        flex: 1;
        max-width: 300px;
    }
    
    .products-selector {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .products-selector select {
        min-width: 200px;
    }
    
    .grid-checkboxes {
        display: flex;
        gap: 15px;
    }
    
    .grid-checkboxes label {
        min-width: auto;
        display: flex;
        align-items: center;
        gap: 4px;
        cursor: pointer;
    }
    
    .no-grids {
        color: #999;
        font-style: italic;
        padding: 10px;
        text-align: center;
        background: #f9f9f9;
        border-radius: 4px;
    }
    
    .submit-section {
        position: sticky;
        bottom: 0;
        background: #f0f0f1;
        padding: 15px 20px;
        margin: 20px -20px -10px;
        border-top: 1px solid #ccc;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    
    .tools-panel {
        background: #f0f6fc;
        border: 1px solid #c3d9ed;
        border-left: 4px solid #2271b1;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    
    .tools-panel h3 {
        margin: 0 0 5px 0;
        color: #2271b1;
    }
    
    .tools-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 10px;
    }
    
    .migration-panel {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-left: 4px solid #ffc107;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    </style>
    <?php
}
