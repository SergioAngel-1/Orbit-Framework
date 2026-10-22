<?php

namespace HWE\ControlCenter\SchemaGroups;

/**
 * Esquema del núcleo: pasarelas de pago, con las credenciales por pasarela (secretos cifrados).
 *
 * Trait de `HWE\ControlCenter\Schema`: el árbol de configuración se reparte por tema para que
 * cada archivo se lea de una vez. `Schema::core()` los junta en el orden de las pestañas.
 */
trait Payments {

    /**
     * @return array<string,array>
     */
    private static function paymentGroups(): array {
        return [

            /* ------------------------------------------------------------------ */
            /* PASARELAS DE PAGO (secretos cifrados)                               */
            /* ------------------------------------------------------------------ */
            'payments' => [
                'type'     => 'group',
                'label'    => 'Pasarelas de pago',
                'children' => [

                    'gateway_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar pago en línea',
                        'default'     => true,
                        'public'      => true,
                        'description' => 'Ofrece «Pago en línea» en el checkout con la pasarela activa. Apagado no hay pasarela activa (el panel la oculta con sus credenciales): para cobrar solo por WhatsApp, apágalo y activa el link de pago por WhatsApp.',
                    ],

                    'provider' => [
                        'type'        => 'select',
                        'label'       => 'Pasarela activa',
                        'default'     => 'noop',
                        'public'      => false,
                        'options'     => [
                            'noop'    => 'Sandbox (noop) — sin cobro real',
                            'avalpay' => 'Aval Pay (Colombia)',
                            'wompi'   => 'Wompi (Colombia)',
                            'payu'    => 'PayU (LATAM)',
                            'bold'    => 'Bold (Colombia)',
                        ],
                        'description' => 'La pasarela con la que se cobra el «Pago en línea»; abajo solo se ven sus credenciales. Si un campo de credenciales está vacío, el frontend usa su variable de entorno equivalente; la variable PAYMENT_PROVIDER del entorno, si existe, anula esta elección (solo desarrollo o emergencias).',
                    ],

                    'avalpay' => [
                        'type'     => 'group',
                        'label'    => 'Aval Pay',
                        'children' => [
                            'login'      => ['type' => 'text',   'label' => 'Login (Identificador)',         'public' => false, 'description' => 'Campo "Identificador" de la consola de Aval Pay.'],
                            'secret_key' => ['type' => 'secret', 'label' => 'Secret Key (Llave transaccional)', 'public' => false, 'description' => 'Se almacena cifrada (AES-256-GCM). Déjalo en blanco al guardar para conservar el valor actual.'],
                            'base_url'   => ['type' => 'text',   'label' => 'URL base de la API',            'public' => false, 'description' => 'Producción: https://checkout.avalpaycenter.com — Pruebas: https://checkout.test.avalpaycenter.com. Las credenciales son distintas en cada entorno.'],
                        ],
                    ],

                    'wompi' => [
                        'type'     => 'group',
                        'label'    => 'Wompi',
                        'children' => [
                            'public_key'       => ['type' => 'text',   'label' => 'Llave pública',       'public' => false, 'description' => 'Empieza por pub_test_ (sandbox) o pub_prod_ (producción); el prefijo decide el entorno.'],
                            'integrity_secret' => ['type' => 'secret', 'label' => 'Secreto de integridad', 'public' => false, 'description' => 'Firma la redirección al checkout. Se almacena cifrado; déjalo en blanco al guardar para conservar el valor actual.'],
                            'events_secret'    => ['type' => 'secret', 'label' => 'Secreto de eventos',    'public' => false, 'description' => 'Verifica cada notificación en el webhook. Se almacena cifrado; déjalo en blanco al guardar para conservar el valor actual.'],
                        ],
                    ],

                    'payu' => [
                        'type'     => 'group',
                        'label'    => 'PayU',
                        'children' => [
                            'merchant_id'    => ['type' => 'text',   'label' => 'Merchant ID',         'public' => false],
                            'account_id'     => ['type' => 'text',   'label' => 'Account ID',          'public' => false],
                            'api_login'      => ['type' => 'text',   'label' => 'API Login',           'public' => false],
                            'api_key'        => ['type' => 'secret', 'label' => 'API Key',             'public' => false],
                            'webhook_secret' => ['type' => 'secret', 'label' => 'Secreto de webhook',  'public' => false],
                            'sandbox'        => ['type' => 'boolean','label' => 'Modo sandbox',        'default' => true, 'public' => false],
                        ],
                    ],

                    'bold' => [
                        'type'     => 'group',
                        'label'    => 'Bold',
                        'children' => [
                            'api_key'       => ['type' => 'secret', 'label' => 'API Key',              'public' => false],
                            'integrity_key' => ['type' => 'secret', 'label' => 'Clave de integridad',  'public' => false],
                            'sandbox'       => ['type' => 'boolean','label' => 'Modo sandbox',         'default' => true, 'public' => false],
                        ],
                    ],

                    'whatsapp_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar link de pago por WhatsApp',
                        'default'     => false,
                        'public'      => true,
                        'description' => 'Ofrece «Link de pago por WhatsApp» en el checkout, independiente de la pasarela. Sin cobro en línea: el pedido queda «En espera» y la tienda envía el link de pago al WhatsApp del cliente (el teléfono del checkout). Con el pago en línea apagado es el único medio, junto al efectivo si está activo.',
                    ],

                    'cash_enabled' => [
                        'type'        => 'boolean',
                        'label'       => 'Activar pago en efectivo',
                        'default'     => false,
                        'public'      => true,
                        'description' => 'Ofrece «Efectivo» en el checkout: el cliente paga al recibir el pedido. Usa la pasarela nativa de WooCommerce «Contra reembolso», que se activa o desactiva al guardar aquí.',
                    ],

                ],
            ],

        ];
    }
}
