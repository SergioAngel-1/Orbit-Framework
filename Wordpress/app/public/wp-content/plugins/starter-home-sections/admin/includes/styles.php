<?php
/**
 * Estilos CSS para la página de administración
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza los estilos CSS
 */
function fihs_render_admin_styles() {
    ?>
    <style>
    .fihs-admin-wrap {
        max-width: 1400px;
    }

    .legacy-sections-panel {
        background: #fff8e5;
        border: 1px solid #dba617;
        border-left-width: 4px;
        padding: 15px 20px;
        margin-bottom: 25px;
        border-radius: 4px;
    }

    .legacy-sections-panel h2 {
        margin: 0 0 10px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        color: #826200;
    }

    .legacy-sections-panel .description {
        color: #826200;
    }

    .legacy-actions {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #dba617;
    }

    .membership-cards-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 30px;
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
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }

    .membership-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        background: #f8f9fa;
        border-bottom: 1px solid #eee;
    }

    .membership-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 16px;
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

    .stat-own {
        color: #2271b1;
        font-weight: 500;
    }

    .stat-inherited {
        color: #666;
    }

    .membership-body {
        padding: 15px;
    }

    .inherited-section {
        background: #f8f9fa;
        border: 1px dashed #ccc;
        border-radius: 4px;
        padding: 12px;
        margin-bottom: 15px;
    }

    .section-label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        font-size: 13px;
        color: #555;
    }

    .inherited-hint {
        font-size: 11px;
        color: #999;
        font-weight: normal;
    }

    .inherited-items {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .section-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fff;
        border: 1px solid #ddd;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .section-chip.inherited:hover {
        background: #fee;
        border-color: #d63638;
    }

    .section-chip.inherited.excluded {
        background: #f0f0f1;
        color: #999;
        text-decoration: line-through;
        opacity: 0.6;
    }

    .section-chip.inherited.excluded:hover {
        background: #e7f5e7;
        border-color: #00a32a;
        opacity: 1;
    }

    .chip-icon {
        font-size: 14px;
    }

    .chip-text {
        font-weight: 500;
    }

    .chip-layout {
        font-size: 10px;
        color: #888;
        background: #f0f0f1;
        padding: 2px 6px;
        border-radius: 8px;
    }

    .chip-exclude-icon {
        display: none;
        margin-left: 4px;
        font-weight: bold;
    }

    .section-chip.inherited:hover .chip-exclude-icon {
        display: inline;
        color: #d63638;
    }

    .section-chip.inherited:hover .chip-exclude-icon::before {
        content: '✕';
    }

    .section-chip.inherited.excluded .chip-exclude-icon {
        display: inline;
        color: #00a32a;
    }

    .section-chip.inherited.excluded .chip-exclude-icon::before {
        content: '+';
    }

    .own-section .section-label {
        justify-content: space-between;
    }

    .add-section-btn {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .add-section-btn .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
    }

    .own-sections-list {
        min-height: 50px;
    }

    .no-sections {
        color: #999;
        font-style: italic;
        margin: 0;
        padding: 10px;
        text-align: center;
    }

    .section-item {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        margin-bottom: 8px;
    }

    .section-item-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        background: #fafafa;
        border-bottom: 1px solid #eee;
    }

    .section-drag-handle {
        cursor: move;
        color: #999;
    }

    .section-title-display {
        flex: 1;
    }

    .section-layout-badge,
    .section-zone-badge {
        font-size: 10px;
        padding: 2px 8px;
        border-radius: 10px;
        background: #e7e7e7;
        color: #555;
    }

    .section-zone-badge {
        background: #e0f0ff;
        color: #0073aa;
    }

    .toggle-section-details,
    .delete-section-btn {
        padding: 0;
        background: none;
        border: none;
        cursor: pointer;
    }

    .toggle-section-details .dashicons {
        transition: transform 0.2s;
    }

    .section-item.expanded .toggle-section-details .dashicons {
        transform: rotate(180deg);
    }

    .section-item-details {
        padding: 15px;
        background: #fff;
    }

    .section-field-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }

    .section-field-row label {
        min-width: 80px;
        font-weight: 500;
        font-size: 12px;
    }

    .section-field-row select,
    .section-field-row input[type="text"],
    .section-field-row input[type="number"] {
        flex: 1;
        max-width: 300px;
    }

    .fihs-translation-field {
        background: #f0f6fc;
        border-left: 3px solid #2271b1;
        padding: 4px 8px;
        border-radius: 0 4px 4px 0;
    }

    .fihs-translation-field label {
        color: #2271b1;
        min-width: 110px;
    }

    .section-checkboxes {
        gap: 20px;
    }

    .section-checkboxes label {
        min-width: auto;
        font-weight: normal;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .submit-section {
        background: #fff;
        padding: 20px;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        text-align: center;
    }

    .submit-section .button-large {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 30px;
        font-size: 14px;
    }
    </style>
    <?php
}
