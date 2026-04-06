<?php
/**
 * Generador de Certificado PDF de Retribución de Cosecha Colectiva
 * 
 * Genera un PDF con el certificado legal para cada orden/retiro
 * 
 * @package Starter
 * @since 1.0.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class Starter_Order_Certificate_PDF {
    
    /**
     * Instancia de TCPDF
     */
    private $pdf;
    
    /**
     * Orden de WooCommerce
     */
    private $order;
    
    /**
     * Datos del usuario
     */
    private $user_data;
    
    /**
     * Configuración de la empresa
     */
    private static $company_config = [
        'name' => '', // Se resuelve dinámicamente en get_company_config()
        'nit' => '000.000.000', // Reemplazar con NIT/ID fiscal real
        'guide_url' => 'https://example.com/guia-requisa',
    ];

    /**
     * Obtener configuración de empresa con valores dinámicos de Site Settings
     */
    private static function get_company_config() {
        $config = self::$company_config;
        if (empty($config['name']) && function_exists('site_get_option')) {
            $config['name'] = strtoupper(site_get_option('site_name', 'Mi Tienda'));
        } elseif (empty($config['name'])) {
            $config['name'] = 'MI TIENDA';
        }
        return $config;
    }
    
    /**
     * Colores del tema (se cargan dinámicamente desde Site Settings)
     */
    private static $colors = null;

    /**
     * Obtener colores del tema, cargándolos dinámicamente desde Site Settings
     */
    private static function get_colors() {
        if (self::$colors !== null) {
            return self::$colors;
        }

        if (function_exists('site_hex_to_rgb') && function_exists('site_get_primary_color')) {
            $primary_hex   = site_get_primary_color();
            $secondary_hex = site_get_secondary_color();
            self::$colors = [
                'primary'  => site_hex_to_rgb($primary_hex),
                'secondary' => site_hex_to_rgb($secondary_hex),
                'accent'   => site_hex_to_rgb(site_darken_color($primary_hex, -40)),
                'text'     => [58, 58, 58],
                'light_bg' => site_hex_to_rgb(site_darken_color($primary_hex, -85)),
            ];
        } else {
            self::$colors = [
                'primary'  => [22, 163, 74],
                'secondary' => [15, 122, 47],
                'accent'   => [143, 216, 185],
                'text'     => [58, 58, 58],
                'light_bg' => [230, 245, 235],
            ];
        }

        return self::$colors;
    }
    
    /**
     * Constructor
     * 
     * @param WC_Order $order Instancia de la orden
     */
    public function __construct(WC_Order $order) {
        $this->order = $order;
        $this->load_user_data();
    }
    
    /**
     * Cargar datos del usuario
     */
    private function load_user_data() {
        $customer_id = $this->order->get_customer_id();
        
        if ($customer_id) {
            $user = get_userdata($customer_id);
            $this->user_data = [
                'id' => $customer_id,
                'cedula' => get_user_meta($customer_id, 'cedula', true) ?: get_user_meta($customer_id, 'billing_cedula', true) ?: 'No registrada',
                'name' => $this->order->get_billing_first_name() . ' ' . $this->order->get_billing_last_name(),
                'email' => $this->order->get_billing_email(),
            ];
        } else {
            $this->user_data = [
                'id' => 0,
                'cedula' => 'No registrada',
                'name' => $this->order->get_billing_first_name() . ' ' . $this->order->get_billing_last_name(),
                'email' => $this->order->get_billing_email(),
            ];
        }
    }
    
    /**
     * Generar el PDF del certificado
     * 
     * @return string|false Ruta del archivo PDF generado o false si falla
     */
    public function generate() {
        // Verificar si TCPDF está disponible
        if (!$this->load_tcpdf()) {
            error_log('[Starter Certificate] TCPDF no está disponible. Theme dir: ' . get_template_directory());
            return false;
        }
        
        try {
            $this->init_pdf();
            $this->add_page_1();
            $this->add_page_2();
            
            $result = $this->save_pdf();
            if (!$result) {
                error_log('[Starter Certificate] save_pdf() retornó false para retiro #' . $this->order->get_order_number());
            }
            return $result;
        } catch (\Throwable $e) {
            error_log('[Starter Certificate] Error al generar PDF: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return false;
        }
    }
    
    /**
     * Cargar librería TCPDF
     */
    private function load_tcpdf() {
        if (class_exists('TCPDF')) {
            return true;
        }
        
        // Buscar TCPDF relativo a este archivo (__DIR__ = .../Starter/inc/order-certificate)
        $local_tcpdf = dirname(__DIR__) . '/lib/tcpdf/tcpdf.php';
        if (file_exists($local_tcpdf)) {
            require_once $local_tcpdf;
            return class_exists('TCPDF');
        }
        
        // Fallback: buscar en el tema activo
        $theme_tcpdf = get_template_directory() . '/inc/lib/tcpdf/tcpdf.php';
        if (file_exists($theme_tcpdf)) {
            require_once $theme_tcpdf;
            return class_exists('TCPDF');
        }
        
        // Fallback: buscar en plugins
        $plugin_tcpdf = ABSPATH . 'wp-content/plugins/tcpdf/tcpdf.php';
        if (file_exists($plugin_tcpdf)) {
            require_once $plugin_tcpdf;
            return class_exists('TCPDF');
        }
        
        return false;
    }
    
    /**
     * Inicializar instancia de TCPDF
     */
    private function init_pdf() {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Información del documento
        $company = self::get_company_config();
        $this->pdf->SetCreator($company['name']);
        $this->pdf->SetAuthor($company['name']);
        $this->pdf->SetTitle('Certificado de Retribución - Retiro #' . $this->order->get_order_number());
        $this->pdf->SetSubject('Certificado de Retribución de Cosecha Colectiva');
        $this->pdf->SetKeywords('Certificado, Retribución, Cosecha, Starter');
        
        // Eliminar encabezado y pie de página predeterminados
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        
        // Márgenes
        $this->pdf->SetMargins(20, 20, 20);
        $this->pdf->SetAutoPageBreak(true, 20);
        
        // Protección: solo permitir impresión, bloquear edición/copia/reemplazo
        $this->pdf->SetProtection(['print'], '', null, 0, null);
    }
    
    /**
     * Agregar logo al PDF
     */
    private function add_logo() {
        $logo_path = $this->get_logo_path();
        
        if ($logo_path && file_exists($logo_path)) {
            $this->pdf->Image($logo_path, 85, 15, 40, 0, '', '', '', false, 300, '', false, false, 0);
        } else {
            // Logo de texto como respaldo
            $this->pdf->SetFont('helvetica', 'B', 16);
            $this->pdf->SetTextColor(self::get_colors()['primary'][0], self::get_colors()['primary'][1], self::get_colors()['primary'][2]);
            $company_name = function_exists('site_get_option') ? site_get_option('site_name', 'Mi Tienda') : 'Mi Tienda';
            $this->pdf->Cell(0, 10, strtoupper($company_name), 0, 1, 'C');
        }
        
        $this->pdf->Ln(25);
    }
    
    /**
     * Obtener ruta del logo
     */
    private function get_logo_path() {
        // Prioridad 1: Logo configurado en Site Settings (descarga URL a path local)
        if (function_exists('site_get_logo_url')) {
            $logo_url = site_get_logo_url('full');
            if (!empty($logo_url)) {
                $attachment_id = site_get_option('branding_logo', '');
                if (!empty($attachment_id) && is_numeric($attachment_id)) {
                    $local_path = get_attached_file((int) $attachment_id);
                    if ($local_path && file_exists($local_path)) {
                        return $local_path;
                    }
                }
            }
        }

        // Prioridad 2: Fallback a imágenes en el tema
        $paths = [
            get_template_directory() . '/assets/images/logo.png',
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return false;
    }
    
    /**
     * Agregar título principal
     */
    private function add_title($title) {
        $this->pdf->SetFont('helvetica', 'B', 22);
        $this->pdf->SetTextColor(self::get_colors()['primary'][0], self::get_colors()['primary'][1], self::get_colors()['primary'][2]);
        $this->pdf->MultiCell(0, 12, $title, 0, 'C');
        $this->pdf->Ln(5);
    }
    
    /**
     * Agregar subtítulo/sección
     */
    private function add_section_title($number, $title) {
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->pdf->SetTextColor(self::get_colors()['primary'][0], self::get_colors()['primary'][1], self::get_colors()['primary'][2]);
        $this->pdf->Cell(0, 10, $number . '. ' . $title, 0, 1, 'L');
        $this->pdf->Ln(2);
    }
    
    /**
     * Agregar párrafo de texto
     */
    private function add_paragraph($text, $bold_terms = []) {
        $this->pdf->SetFont('helvetica', '', 11);
        $this->pdf->SetTextColor(self::get_colors()['text'][0], self::get_colors()['text'][1], self::get_colors()['text'][2]);
        
        // Reemplazar términos en negrita
        foreach ($bold_terms as $term) {
            $text = str_replace($term, '<b>' . $term . '</b>', $text);
        }
        
        $this->pdf->writeHTML($text, true, false, true, false, 'J');
        $this->pdf->Ln(3);
    }
    
    /**
     * Agregar lista con viñetas
     */
    private function add_bullet_list($items, $numbered = false) {
        $this->pdf->SetFont('helvetica', '', 11);
        $this->pdf->SetTextColor(self::get_colors()['text'][0], self::get_colors()['text'][1], self::get_colors()['text'][2]);
        
        $tag = $numbered ? 'ol' : 'ul';
        $html = '<' . $tag . ' style="padding-left: 15px;">';
        foreach ($items as $item) {
            $html .= '<li>' . $item . '</li>';
        }
        $html .= '</' . $tag . '>';
        
        $this->pdf->writeHTML($html, true, false, true, false, 'L');
        $this->pdf->Ln(2);
    }
    
    /**
     * Página 1: Información principal del certificado
     */
    private function add_page_1() {
        $this->pdf->AddPage();
        
        // Logo
        $this->add_logo();
        
        // Título principal
        $this->add_title('CERTIFICADO DE RETRIBUCIÓN DE COSECHA COLECTIVA');
        
        $this->pdf->Ln(5);
        
        // Datos del encabezado
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->SetTextColor(self::get_colors()['text'][0], self::get_colors()['text'][1], self::get_colors()['text'][2]);
        
        $order_date = $this->order->get_date_created() 
            ? $this->order->get_date_created()->date_i18n('d/m/Y') 
            : current_time('d/m/Y');
        
        $company = self::get_company_config();
        $header_lines = [
            $company['name'],
            '<b>NIT:</b> ' . $company['nit'],
            '<b>FECHA DE EMISIÓN:</b> ' . $order_date,
            '<b>SOCIO REGISTRADO:</b> ' . $this->user_data['name'] . ' (ID #' . $this->user_data['id'] . ')',
            '<b>CÉDULA DE SOCIO:</b> ' . $this->user_data['cedula'],
            '<b>N° DE SOLICITUD (RETIRO):</b> ' . $this->order->get_order_number(),
        ];
        
        foreach ($header_lines as $line) {
            $this->pdf->writeHTML($line, true, false, true, false, 'L');
        }
        
        $this->pdf->Ln(8);
        
        // Sección 1: Declaración de origen y destino
        $this->add_section_title('1', 'Declaración de Origen y Destino');
        
        $this->add_paragraph(
            'El presente certificado acredita que el contenido del paquete adjunto constituye una <b>Retribución de Cosecha Compartida</b>, entregada al socio identificado anteriormente. Este material vegetal ha sido producido en los centros de cultivo de la Asociación bajo el modelo de <b>Autocultivo Colectivo</b>, donde el socio delegó previamente su derecho de cultivo de hasta veinte (20) plantas de presencia legal (Ley 30 de 1986).',
            ['Retribución de Cosecha Compartida', 'Autocultivo Colectivo']
        );
        
        $this->add_paragraph(
            'Adicionalmente, la Asociación lleva un sistema interno de trazabilidad y control del cultivo colectivo, mediante el cual cada planta es asignada previamente a uno o varios socios adherentes, en ejercicio del derecho individual de autocultivo delegado. Dicha trazabilidad permite identificar el origen del material vegetal, su ciclo de cultivo y su destinación exclusiva a la retribución de cosecha del socio correspondiente.'
        );
        
        $this->pdf->Ln(3);
        
        // Sección 2: Especificaciones técnicas
        $this->add_section_title('2', 'Especificaciones Técnicas (Retribución)');
        
        // Calcular total en Virtual Coins
        $total = $this->order->get_total();
        $this->pdf->SetFont('helvetica', '', 11);
        $this->pdf->SetTextColor(self::get_colors()['text'][0], self::get_colors()['text'][1], self::get_colors()['text'][2]);
        
        $this->pdf->writeHTML('<ul><li><b>Aporte de Gestión: ' . number_format($total, 0, ',', '.') . '</b> Virtual Coins.</li></ul>', true, false, true, false, 'L');
        
        $this->add_paragraph(
            'El aporte de gestión no constituye precio, venta ni contraprestación comercial.'
        );
        
        $this->pdf->Ln(3);
        
        // Sección 3: Fundamento jurídico
        $this->add_section_title('3', 'Fundamento Jurídico Aplicable');
        
        $this->add_paragraph(
            'El transporte y tenencia del presente material se encuentra amparado por el marco legal colombiano vigente:'
        );
        
        $legal_items = [
            '<b>Acto Legislativo 02 de 2009:</b> Reconoce que el consumo no es un delito.',
            '<b>Ley 30 de 1986 (Art. 2 y 33):</b> Establece que el cultivo de hasta 20 plantas y el porte de la dosis personal (20g) no constituyen actividad ilícita.',
            '<b>Sentencia C-221 de 1994 (Corte Constitucional):</b> Protege el Libre Desarrollo de la Personalidad en el marco del consumo de sustancias.',
            '<b>Sentencia C-253 de 2019:</b> Reitera la prohibición de sanciones por consumo en espacios privados o el transporte de la dosis personal.',
        ];
        
        $this->add_bullet_list($legal_items, true);
        
        $this->pdf->SetFont('helvetica', 'I', 10);
        $this->pdf->SetTextColor(self::get_colors()['secondary'][0], self::get_colors()['secondary'][1], self::get_colors()['secondary'][2]);
        $link_color = function_exists('site_get_secondary_color') ? site_get_secondary_color() : '#333333';
        $this->pdf->writeHTML('Para más información, consulta nuestro marco legal completo en: <a href="https://example.com/marco-legal" style="color: ' . $link_color . ';">example.com/marco-legal</a>', true, false, true, false, 'L');
        
        $this->pdf->Ln(3);
        
        // Sección 4: Cláusula de privacidad
        $this->add_section_title('4', 'Cláusula de Privacidad y Seguridad');
        
        $this->add_paragraph(
            'Este paquete es para el <b>uso exclusivo y privado</b> del socio receptor. La Asociación prohíbe explícitamente la comercialización de esta retribución a terceros. El transporte se realiza como un servicio interno de mensajería privada entre la sede de la asociación y el domicilio del socio adherente.',
            ['uso exclusivo y privado']
        );
    }
    
    /**
     * Página 2: Sistema de trazabilidad y guía de actuación
     */
    private function add_page_2() {
        $this->pdf->Ln(5);
        
        // Sección 5: Sistema de trazabilidad
        $this->add_section_title('5', 'Sistema de Trazabilidad y Control Interno');
        
        $this->add_paragraph(
            'La ' . self::get_company_config()['name'] . ' implementa un sistema interno de trazabilidad del cultivo colectivo, mediante el cual se registra:'
        );
        
        $traceability_items = [
            'La cesión voluntaria del derecho de cultivo del socio adherente.',
            'La asignación de plantas específicas al cultivo colectivo.',
            'El seguimiento del ciclo de vida de la planta (siembra, crecimiento, cosecha y retribución).',
            'La correspondencia entre la cosecha obtenida y la retribución entregada al socio.',
        ];
        
        $this->add_bullet_list($traceability_items);
        
        $this->add_paragraph(
            'Este sistema tiene fines exclusivamente organizativos, de transparencia interna y de garantía de cumplimiento del marco legal vigente, y no constituye actividad comercial ni de distribución a terceros.'
        );
        
        $this->pdf->Ln(5);
        
        // Sección 6: Guía de actuación
        $this->add_section_title('6', 'Guía de Actuación en Caso de Requisa o Verificación');
        
        $this->add_paragraph(
            'En caso de requerimiento, requisa o verificación por parte de autoridades competentes, la Asociación ha dispuesto una guía pública de verificación y contexto legal, donde se explica:'
        );
        
        $guide_items = [
            'La naturaleza jurídica del autocultivo colectivo.',
            'El concepto de retribución de cosecha compartida.',
            'El marco constitucional y legal aplicable.',
            'El rol del socio adherente y de la Asociación.',
        ];
        
        $this->add_bullet_list($guide_items);
        
        $this->add_paragraph(
            'Dicha guía puede ser consultada por cualquier autoridad en el siguiente enlace oficial:'
        );
        
        // Link a la guía
        $this->pdf->SetFont('helvetica', '', 11);
        $this->pdf->SetTextColor(self::get_colors()['accent'][0], self::get_colors()['accent'][1], self::get_colors()['accent'][2]);
        $company = self::get_company_config();
        $guide_link_color = function_exists('site_get_secondary_color') ? site_get_secondary_color() : '#333333';
        $this->pdf->writeHTML('👉 <a href="' . $company['guide_url'] . '" style="color: ' . $guide_link_color . ';">' . $company['guide_url'] . '</a>', true, false, true, false, 'L');
        
        $this->pdf->Ln(5);
        
        $this->pdf->SetTextColor(self::get_colors()['text'][0], self::get_colors()['text'][1], self::get_colors()['text'][2]);
        
        $this->add_section_title('7', 'Notas Legales y Limitaciones');
        
        $order_number = $this->order->get_order_number();
        
        $this->add_paragraph(
            'El presente certificado se emite bajo el principio constitucional de buena fe y con fines de transparencia asociativa. Su función es exclusivamente informativa y declarativa; no constituye salvoconducto, permiso estatal ni autorización administrativa. Podrá servir como elemento contextual o probatorio complementario dentro de un análisis jurídico integral, cuya valoración corresponde únicamente a la autoridad competente.'
        );
        
        $this->add_paragraph(
            'Este certificado hace referencia exclusivamente a la tenencia derivada del retiro asociativo N° <b>' . $order_number . '</b>, realizado en la fecha indicada. No es reutilizable, transferible ni aplicable a retiros anteriores o posteriores, y no regula ni autoriza conductas posteriores al momento de la entrega.'
        );
        
        $this->pdf->Ln(5);
        
        // Notas complementarias
        $this->pdf->SetFont('helvetica', 'I', 9);
        $this->pdf->SetTextColor(100, 100, 100);
        
        $this->pdf->writeHTML(
            'La Asociación no conserva copia física ni digital de este certificado, el cual es generado de forma automática y entregado exclusivamente al socio para su información personal. Cualquier uso distinto al aquí descrito, así como cualquier alteración, modificación, recorte o edición del certificado, invalida automáticamente su contenido, carece de validez jurídica y exonera a la Asociación de toda responsabilidad.',
            true, false, true, false, 'L'
        );
        
        $this->pdf->Ln(10);
        
        // Pie de página con información adicional
        $this->pdf->SetFont('helvetica', 'I', 9);
        $this->pdf->SetTextColor(150, 150, 150);
        $this->pdf->Cell(0, 5, 'Certificado generado automáticamente el ' . current_time('d/m/Y H:i') . ' - Retiro #' . $order_number, 0, 1, 'C');
        $this->pdf->Cell(0, 5, 'Este certificado es válido únicamente para el socio identificado en el mismo.', 0, 1, 'C');
        $this->pdf->Ln(3);
        $this->pdf->writeHTML(
            '<a href="https://example.com/terminos" style="color: #999999;">Términos y condiciones</a> &nbsp;|&nbsp; <a href="https://example.com/privacidad" style="color: #999999;">Política de privacidad</a>',
            true, false, true, false, 'C'
        );
    }
    
    /**
     * Guardar el PDF en el servidor
     * 
     * @return string|false Ruta del archivo o false si falla
     */
    private function save_pdf() {
        $upload_dir = wp_upload_dir();
        
        // Verificar que wp_upload_dir() no haya reportado error
        if (!empty($upload_dir['error'])) {
            error_log('[Starter Certificate] wp_upload_dir() error: ' . $upload_dir['error']);
            return false;
        }
        
        $pdf_dir = $upload_dir['basedir'] . '/order-certificates/';
        
        // Crear directorio si no existe
        if (!file_exists($pdf_dir)) {
            $created = wp_mkdir_p($pdf_dir);
            if (!$created) {
                error_log('[Starter Certificate] No se pudo crear directorio: ' . $pdf_dir);
                return false;
            }
            
            // Crear .htaccess para proteger el directorio
            @file_put_contents($pdf_dir . '.htaccess', "Order deny,allow\nDeny from all\n");
        }
        
        // Verificar que el directorio sea escribible
        if (!is_writable($pdf_dir)) {
            error_log('[Starter Certificate] Directorio no escribible: ' . $pdf_dir);
            return false;
        }
        
        // Nombre del archivo
        $file_name = 'certificado-retiro-' . $this->order->get_order_number() . '-' . time() . '.pdf';
        $file_path = $pdf_dir . $file_name;
        
        // Guardar PDF
        $this->pdf->Output($file_path, 'F');
        
        if (file_exists($file_path) && filesize($file_path) > 0) {
            // Guardar referencia en la orden (compatible con HPOS)
            $this->order->update_meta_data('_starter_certificate_path', $file_path);
            $this->order->update_meta_data('_starter_certificate_generated', current_time('mysql'));
            $this->order->save();
            
            return $file_path;
        }
        
        error_log('[Starter Certificate] El archivo PDF no se creó o está vacío: ' . $file_path);
        return false;
    }
    
    /**
     * Obtener la ruta del certificado si ya existe
     * 
     * @return string|false Ruta del archivo o false si no existe
     */
    public function get_existing_certificate() {
        $path = $this->order->get_meta('_starter_certificate_path', true);
        
        if ($path && file_exists($path) && filesize($path) > 0) {
            return $path;
        }
        
        return false;
    }
    
    /**
     * Método estático para generar certificado desde order_id
     * 
     * @param int $order_id ID de la orden
     * @return string|false Ruta del archivo o false si falla
     */
    public static function generate_for_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return false;
        }
        
        $generator = new self($order);
        return $generator->generate();
    }
}
