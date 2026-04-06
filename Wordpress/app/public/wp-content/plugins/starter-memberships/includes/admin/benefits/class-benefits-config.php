<?php
/**
 * Configuración de tipos de beneficios de membresía
 * 
 * Define todos los tipos de beneficios disponibles y sus valores predeterminados.
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Benefits
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Benefits_Config {
    
    /**
     * Obtener todos los tipos de beneficios disponibles
     * 
     * @return array
     */
    public static function get_benefit_types() {
        return [
            // =====================================================
            // BENEFICIOS VARIABLES (configurables por nivel)
            // =====================================================
            
            // 2.11 - Virtual Coins por periodo (valor viene del producto, solo lectura)
            'monthly_points' => [
                'name' => 'Virtual Coins por Periodo',
                'description' => 'Cantidad de Virtual Coins otorgados según la periodicidad de la membresía',
                'type' => 'readonly',
                'icon' => '🌸',
                'category' => 'economia',
                'product_meta' => '_membership_monthly_points',
                'format' => 'fc'
            ],
            
            // 2.1 - Periodicidad (valor viene del producto, solo lectura)
            'extended_period' => [
                'name' => 'Periodicidad de Membresía',
                'description' => 'Duración configurada en el producto de membresía',
                'type' => 'readonly',
                'icon' => '⏱️',
                'category' => 'tiempo',
                'product_meta' => '_membership_duration_days',
                'format' => 'days'
            ],
            
            // 2.2 - Descuentos en categorías
            'category_discount' => [
                'name' => 'Descuento en Categorías',
                'description' => 'Porcentaje de descuento en productos de categorías específicas',
                'type' => 'variable',
                'icon' => '🏷️',
                'category' => 'economia',
                'fields' => [
                    'percentage' => ['type' => 'number', 'label' => 'Porcentaje de descuento', 'min' => 0, 'max' => 100, 'suffix' => '%'],
                    'categories' => ['type' => 'categories', 'label' => 'Categorías aplicables']
                ]
            ],
            
            // 2.3 - Beneficio referidos (configurado desde plugin de referidos)
            'referral_bonus' => [
                'name' => 'Bonus en Referidos',
                'description' => 'Porcentaje adicional en comisiones del sistema de referidos. Configurar en: Virtual Coins → Configuración → Comisiones por Membresía',
                'type' => 'readonly',
                'icon' => '👥',
                'category' => 'economia',
                'external_config' => true,
                'external_config_url' => 'admin.php?page=starter-rp-settings',
                'fields' => []
            ],
            
            // 2.3.1 - Bonus de membresía para referidos (1 mes Plata gratis)
            'referral_membership_bonus' => [
                'name' => 'Membresía Gratis para Referidos',
                'description' => 'Los usuarios que se registren con el código de referido de este nivel reciben membresía Plata gratis por 1 mes',
                'type' => 'variable',
                'icon' => '🎁',
                'category' => 'economia',
                'fields' => [
                    'membership_level' => [
                        'type' => 'select', 
                        'label' => 'Nivel de membresía a otorgar',
                        'options' => [
                            '1' => 'Bronce (Nivel 1)',
                            '2' => 'Plata (Nivel 2)',
                            '3' => 'Oro (Nivel 3)',
                            '4' => 'Diamante (Nivel 4)'
                        ]
                    ],
                    'duration_days' => [
                        'type' => 'number', 
                        'label' => 'Duración en días', 
                        'min' => 1, 
                        'max' => 365, 
                        'suffix' => ' días'
                    ]
                ]
            ],
            
            // 2.4 - Descuento marca amiga (Licorera de Kush)
            'partner_discount_licorera' => [
                'name' => 'Descuento Licorera de Kush',
                'description' => 'Descuento en marca aliada: Licorera de Kush',
                'type' => 'variable',
                'icon' => '🍺',
                'category' => 'aliados',
                'fields' => [
                    'percentage' => ['type' => 'number', 'label' => 'Porcentaje de descuento', 'min' => 0, 'max' => 100, 'suffix' => '%']
                ]
            ],
            
            // 2.5 - Entregas: Solo domicilio | Recoger en sede
            'delivery_options' => [
                'name' => 'Opciones de Entrega',
                'description' => 'Métodos de entrega disponibles para el usuario',
                'type' => 'variable',
                'icon' => '🚚',
                'category' => 'entregas',
                'fields' => [
                    'home_delivery' => ['type' => 'checkbox', 'label' => 'Domicilio'],
                    'pickup' => ['type' => 'checkbox', 'label' => 'Recoger en sede (Próximamente)', 'disabled' => true]
                ]
            ],
            
            // 2.6 - Entregas gratis
            'free_deliveries' => [
                'name' => 'Entregas Gratis',
                'description' => 'Cantidad de entregas gratis durante el período de membresía',
                'type' => 'variable',
                'icon' => '🎁',
                'category' => 'entregas',
                'fields' => [
                    'quantity' => ['type' => 'number', 'label' => 'Cantidad de entregas gratis', 'min' => 0]
                ]
            ],
            
            // 2.7 - Beneficio Club Casa Kush
            'partner_club_casa_kush' => [
                'name' => 'Club Casa Kush',
                'description' => 'Precio de entrada a Club Casa Kush',
                'type' => 'variable',
                'icon' => '🏠',
                'category' => 'aliados',
                'fields' => [
                    'price' => ['type' => 'number', 'label' => 'Precio de entrada', 'min' => 0, 'suffix' => ' COP']
                ]
            ],
            
            // 2.8 - Muestras gratis
            'free_samples' => [
                'name' => 'Muestras Gratis',
                'description' => 'Gramos totales repartidos en porciones cada X pedidos',
                'type' => 'variable',
                'icon' => '🌿',
                'category' => 'productos',
                'fields' => [
                    'total_grams' => ['type' => 'number', 'label' => 'Gramos totales (período)', 'min' => 0, 'step' => 0.5, 'suffix' => 'g'],
                    'grams_per_delivery' => ['type' => 'number', 'label' => 'Gramos por entrega', 'min' => 0, 'step' => 0.5, 'suffix' => 'g'],
                    'every_orders' => ['type' => 'number', 'label' => 'Cada cuántos pedidos', 'min' => 1, 'suffix' => ' pedidos']
                ]
            ],
            
            // 2.9 - Seguridad: Espacio seguro | Asesoría jurídica
            'security_benefits' => [
                'name' => 'Beneficios de Seguridad',
                'description' => 'Acceso a servicios de seguridad y asesoría',
                'type' => 'variable',
                'icon' => '🛡️',
                'category' => 'seguridad',
                'fields' => [
                    'safe_space' => ['type' => 'checkbox', 'label' => 'Espacio seguro'],
                    'legal_advice' => ['type' => 'checkbox', 'label' => 'Asesoría jurídica (videollamada)'],
                    'legal_advice_whatsapp' => ['type' => 'text', 'label' => 'WhatsApp Emergencia Legal', 'placeholder' => 'Ej: 573225303310']
                ]
            ],
            
            // 2.10 - Descuento en eventos
            'events_discount' => [
                'name' => 'Descuento en Eventos',
                'description' => 'Porcentaje de descuento en productos de categorías de eventos',
                'type' => 'variable',
                'icon' => '🎉',
                'category' => 'eventos',
                'fields' => [
                    'percentage' => ['type' => 'number', 'label' => 'Porcentaje de descuento', 'min' => 0, 'max' => 100, 'suffix' => '%'],
                    'categories' => ['type' => 'categories', 'label' => 'Categorías de eventos']
                ]
            ],
            
            // =====================================================
            // BENEFICIOS FIJOS (on/off por nivel)
            // =====================================================
            
            // 2.12 - Productos y contenido exclusivo
            'exclusive_products' => [
                'name' => 'Productos Exclusivos',
                'description' => 'Acceso a productos y contenido exclusivo por membresía',
                'type' => 'fixed',
                'icon' => '⭐',
                'category' => 'productos'
            ],
            
            'exclusive_content' => [
                'name' => 'Contenido Exclusivo',
                'description' => 'Acceso a contenido educativo y promocional exclusivo',
                'type' => 'fixed',
                'icon' => '📚',
                'category' => 'productos'
            ],
            
            'early_access' => [
                'name' => 'Acceso Anticipado',
                'description' => 'Acceso anticipado a nuevos productos y ofertas',
                'type' => 'fixed',
                'icon' => '🚀',
                'category' => 'productos'
            ],
            
            'priority_support' => [
                'name' => 'Soporte Prioritario',
                'description' => 'Atención al cliente prioritaria',
                'type' => 'fixed',
                'icon' => '💬',
                'category' => 'servicio'
            ]
        ];
    }
    
    /**
     * Obtener categorías de beneficios para agrupar en UI
     * 
     * @return array
     */
    public static function get_benefit_categories() {
        return [
            'economia' => [
                'name' => 'Economía',
                'icon' => '💰',
                'description' => 'Beneficios económicos y descuentos'
            ],
            'tiempo' => [
                'name' => 'Tiempo',
                'icon' => '⏱️',
                'description' => 'Beneficios relacionados con duración'
            ],
            'entregas' => [
                'name' => 'Entregas',
                'icon' => '🚚',
                'description' => 'Beneficios de envío y entrega'
            ],
            'aliados' => [
                'name' => 'Marcas Aliadas',
                'icon' => '🤝',
                'description' => 'Descuentos en marcas aliadas'
            ],
            'productos' => [
                'name' => 'Productos',
                'icon' => '🛍️',
                'description' => 'Acceso a productos y muestras'
            ],
            'seguridad' => [
                'name' => 'Seguridad',
                'icon' => '🛡️',
                'description' => 'Servicios de seguridad y asesoría'
            ],
            'eventos' => [
                'name' => 'Eventos',
                'icon' => '🎉',
                'description' => 'Beneficios en eventos de la marca'
            ],
            'servicio' => [
                'name' => 'Servicio',
                'icon' => '💬',
                'description' => 'Beneficios de atención al cliente'
            ]
        ];
    }
    
    /**
     * Obtener beneficios predeterminados por nivel
     * 
     * @return array
     */
    public static function get_default_level_benefits() {
        return [
            // Nivel 0 - Zanahoria (Gratis) - Sin membresía comprada
            0 => [
                'monthly_points' => ['enabled' => false],
                'extended_period' => ['enabled' => false],
                'category_discount' => ['enabled' => false],
                'referral_bonus' => ['enabled' => false],
                'referral_membership_bonus' => ['enabled' => false], // Nivel 0 no puede dar membresía a referidos
                'partner_discount_licorera' => ['enabled' => false],
                'delivery_options' => ['enabled' => true, 'home_delivery' => true, 'pickup' => false],
                'free_deliveries' => ['enabled' => false],
                'partner_club_casa_kush' => ['enabled' => false],
                'free_samples' => ['enabled' => false],
                'security_benefits' => ['enabled' => false],
                'events_discount' => ['enabled' => false],
                'exclusive_products' => ['enabled' => false],
                'exclusive_content' => ['enabled' => false],
                'early_access' => ['enabled' => false],
                'priority_support' => ['enabled' => false]
            ],
            
            // Nivel 1 - Zanahoria Bronce
            1 => [
                'monthly_points' => ['enabled' => true, 'amount' => 52000],
                'extended_period' => ['enabled' => false],
                'category_discount' => ['enabled' => true, 'percentage' => 5, 'categories' => []],
                'referral_bonus' => ['enabled' => true, 'percentage' => 2, 'percentage_level2' => 1],
                'referral_membership_bonus' => ['enabled' => true, 'membership_level' => 2, 'duration_days' => 30], // Referidos reciben Plata 1 mes
                'partner_discount_licorera' => ['enabled' => true, 'percentage' => 5],
                'delivery_options' => ['enabled' => true, 'home_delivery' => true, 'pickup' => false],
                'free_deliveries' => ['enabled' => false],
                'partner_club_casa_kush' => ['enabled' => false],
                'free_samples' => ['enabled' => false],
                'security_benefits' => ['enabled' => false],
                'events_discount' => ['enabled' => true, 'percentage' => 5],
                'exclusive_products' => ['enabled' => true],
                'exclusive_content' => ['enabled' => false],
                'early_access' => ['enabled' => false],
                'priority_support' => ['enabled' => false]
            ],
            
            // Nivel 2 - Zanahoria Plateada
            2 => [
                'monthly_points' => ['enabled' => true, 'amount' => 102000],
                'extended_period' => ['enabled' => false],
                'category_discount' => ['enabled' => true, 'percentage' => 10, 'categories' => []],
                'referral_bonus' => ['enabled' => true, 'percentage' => 5, 'percentage_level2' => 2],
                'referral_membership_bonus' => ['enabled' => true, 'membership_level' => 2, 'duration_days' => 30], // Referidos reciben Plata 1 mes
                'partner_discount_licorera' => ['enabled' => true, 'percentage' => 10],
                'delivery_options' => ['enabled' => true, 'home_delivery' => true, 'pickup' => true],
                'free_deliveries' => ['enabled' => true, 'quantity' => 1],
                'partner_club_casa_kush' => ['enabled' => true, 'price' => 15000],
                'free_samples' => ['enabled' => true, 'total_grams' => 1, 'grams_per_delivery' => 0.5, 'every_orders' => 2],
                'security_benefits' => ['enabled' => true, 'safe_space' => true, 'legal_advice' => false],
                'events_discount' => ['enabled' => true, 'percentage' => 10],
                'exclusive_products' => ['enabled' => true],
                'exclusive_content' => ['enabled' => true],
                'early_access' => ['enabled' => true],
                'priority_support' => ['enabled' => false]
            ],
            
            // Nivel 3 - Zanahoria Dorada
            3 => [
                'monthly_points' => ['enabled' => true, 'amount' => 202000],
                'extended_period' => ['enabled' => false],
                'category_discount' => ['enabled' => true, 'percentage' => 15, 'categories' => []],
                'referral_bonus' => ['enabled' => true, 'percentage' => 8, 'percentage_level2' => 3],
                'referral_membership_bonus' => ['enabled' => true, 'membership_level' => 2, 'duration_days' => 30], // Referidos reciben Plata 1 mes
                'partner_discount_licorera' => ['enabled' => true, 'percentage' => 15],
                'delivery_options' => ['enabled' => true, 'home_delivery' => true, 'pickup' => true],
                'free_deliveries' => ['enabled' => true, 'quantity' => 2],
                'partner_club_casa_kush' => ['enabled' => true, 'price' => 10000],
                'free_samples' => ['enabled' => true, 'total_grams' => 2, 'grams_per_delivery' => 0.5, 'every_orders' => 2],
                'security_benefits' => ['enabled' => true, 'safe_space' => true, 'legal_advice' => true],
                'events_discount' => ['enabled' => true, 'percentage' => 15],
                'exclusive_products' => ['enabled' => true],
                'exclusive_content' => ['enabled' => true],
                'early_access' => ['enabled' => true],
                'priority_support' => ['enabled' => true]
            ],
            
            // Nivel 4 - Zanahoria Diamante
            4 => [
                'monthly_points' => ['enabled' => true, 'amount' => 502000],
                'extended_period' => ['enabled' => false],
                'category_discount' => ['enabled' => true, 'percentage' => 20, 'categories' => []],
                'referral_bonus' => ['enabled' => true, 'percentage' => 12, 'percentage_level2' => 5],
                'referral_membership_bonus' => ['enabled' => true, 'membership_level' => 2, 'duration_days' => 30], // Referidos reciben Plata 1 mes
                'partner_discount_licorera' => ['enabled' => true, 'percentage' => 20],
                'delivery_options' => ['enabled' => true, 'home_delivery' => true, 'pickup' => true],
                'free_deliveries' => ['enabled' => true, 'quantity' => 4],
                'partner_club_casa_kush' => ['enabled' => true, 'price' => 0],
                'free_samples' => ['enabled' => true, 'total_grams' => 5, 'grams_per_delivery' => 1, 'every_orders' => 2],
                'security_benefits' => ['enabled' => true, 'safe_space' => true, 'legal_advice' => true],
                'events_discount' => ['enabled' => true, 'percentage' => 25],
                'exclusive_products' => ['enabled' => true],
                'exclusive_content' => ['enabled' => true],
                'early_access' => ['enabled' => true],
                'priority_support' => ['enabled' => true]
            ],
            
            // Nivel 5 - Membresía por Antigüedad
            5 => [
                'monthly_points' => ['enabled' => true, 'amount' => 25000],
                'extended_period' => ['enabled' => false],
                'category_discount' => ['enabled' => true, 'percentage' => 10, 'categories' => []],
                'referral_bonus' => ['enabled' => true, 'percentage' => 5, 'percentage_level2' => 2],
                'referral_membership_bonus' => ['enabled' => true, 'membership_level' => 2, 'duration_days' => 30], // Referidos reciben Plata 1 mes
                'partner_discount_licorera' => ['enabled' => true, 'percentage' => 10],
                'delivery_options' => ['enabled' => true, 'home_delivery' => true, 'pickup' => true],
                'free_deliveries' => ['enabled' => true, 'quantity' => 1],
                'partner_club_casa_kush' => ['enabled' => true, 'price' => 15000],
                'free_samples' => ['enabled' => true, 'total_grams' => 1, 'grams_per_delivery' => 0.5, 'every_orders' => 2],
                'security_benefits' => ['enabled' => true, 'safe_space' => true, 'legal_advice' => false],
                'events_discount' => ['enabled' => true, 'percentage' => 10],
                'exclusive_products' => ['enabled' => true],
                'exclusive_content' => ['enabled' => true],
                'early_access' => ['enabled' => true],
                'priority_support' => ['enabled' => false]
            ]
        ];
    }
}
