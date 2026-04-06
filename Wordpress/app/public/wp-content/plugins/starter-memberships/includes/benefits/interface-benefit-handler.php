<?php
/**
 * Interfaz para handlers de beneficios de membresía
 * 
 * Cada beneficio debe implementar esta interfaz para garantizar
 * consistencia en la aplicación de beneficios.
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/Handlers
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface Starter_Benefit_Handler_Interface {
    
    /**
     * Obtener la clave única del beneficio
     * Debe coincidir con la key en Starter_Benefits_Config::get_benefit_types()
     * 
     * @return string
     */
    public function get_key(): string;
    
    /**
     * Obtener el nombre legible del beneficio
     * 
     * @return string
     */
    public function get_name(): string;
    
    /**
     * Obtener la descripción del beneficio
     * 
     * @return string
     */
    public function get_description(): string;
    
    /**
     * Verificar si el beneficio está habilitado para un usuario
     * 
     * @param int $user_id ID del usuario
     * @return bool
     */
    public function is_enabled_for_user(int $user_id): bool;
    
    /**
     * Obtener la configuración del beneficio para un usuario
     * 
     * @param int $user_id ID del usuario
     * @return array|null Configuración o null si no está habilitado
     */
    public function get_config_for_user(int $user_id): ?array;
    
    /**
     * Aplicar el beneficio en el contexto dado
     * 
     * @param int $user_id ID del usuario
     * @param array $context Contexto de aplicación (ej: producto, carrito, etc.)
     * @return mixed Resultado de la aplicación
     */
    public function apply(int $user_id, array $context = []);
    
    /**
     * Obtener el valor formateado para mostrar al usuario
     * 
     * @param int $user_id ID del usuario
     * @return string Valor formateado (ej: "10% de descuento")
     */
    public function get_display_value(int $user_id): string;
    
    /**
     * Registrar los hooks de WordPress/WooCommerce necesarios
     * Se llama durante la inicialización del plugin
     * 
     * @return void
     */
    public function register_hooks(): void;
    
    /**
     * Verificar si el beneficio requiere WooCommerce
     * 
     * @return bool
     */
    public function requires_woocommerce(): bool;
}
