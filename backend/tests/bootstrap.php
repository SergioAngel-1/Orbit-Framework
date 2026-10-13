<?php
/**
 * Bootstrap de PHPUnit: permite probar el código PHP propio SIN WordPress.
 *
 * CÓMO SE CARGA UN ARCHIVO BAJO PRUEBA
 * ------------------------------------
 * - Clases con namespace (p. ej. `HWE\ControlCenter\SecretsStorage`): se
 *   autocargan con el mapeo PSR-4 de `autoload-dev` en composer.json. Al crear
 *   un namespace nuevo, añádelo allí y ejecuta `composer dump-autoload`.
 * - Archivos de mu-plugin/plugin con código de nivel superior (funciones
 *   globales, `add_action`…): extiende `HWE\Tests\TestCase` y llama a
 *   `$this->loadOwnFile('mu-plugins/hwe-auth.php')` DESPUÉS de declarar las
 *   expectativas (`Actions\expectAdded(...)`): el archivo se carga con las
 *   funciones de WordPress ya simuladas por Brain Monkey. Un archivo con
 *   funciones globales solo puede cargarse una vez por proceso; si dos tests
 *   lo necesitan, márcalos con `#[RunInSeparateProcess]`.
 * - Las funciones de WordPress que el código llame se simulan por test con
 *   `Brain\Monkey\Functions\when()/expect()`. Las clases de WordPress se
 *   simulan en tests/Stubs/ (hoy `WP_REST_Request`, `WP_REST_Response`, `WP_Error`, `WP_Post` y `WP_Term`); si un
 *   test necesita otra, añádela allí y cárgala al final de este
 *   archivo.
 * - Las constantes (`AUTH_KEY`, `HWE_SECRETS_KEY`…) NO se definen aquí a
 *   propósito: son globales al proceso y no se pueden deshacer, así que los
 *   tests que dependen de ellas (con y sin ellas) usan
 *   `#[RunInSeparateProcess]` + `#[PreserveGlobalState(false)]`.
 * - `error_log` es redefinible (backend/patchwork.json): comprueba los
 *   registros con `Functions\expect('error_log')`. Si otra función interna de
 *   PHP debe simularse, añádela a `redefinable-internals` en ese archivo.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Patchwork preprocesa el código que se incluye DESPUÉS de cargarlo, y solo el
// código preprocesado admite redefinir funciones internas como `error_log`.
// Brain Monkey lo carga de forma perezosa (al primer `Functions\when()`), que
// llega tarde: PHPUnit ya habría incluido los archivos de test. Por eso se carga
// aquí, antes que cualquier test. Lee su configuración de backend/patchwork.json.
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

// Raíz del backend y de wp-content, para `loadOwnFile()` y las rutas de los tests.
define('HWE_BACKEND_DIR', dirname(__DIR__));
define('HWE_WP_CONTENT_DIR', HWE_BACKEND_DIR . '/wp-content');

// Los archivos de WordPress empiezan con `if ( ! defined( 'ABSPATH' ) ) exit;`.
// La ruta es ficticia: nada de lo probado debe leer archivos de WordPress.
define('ABSPATH', __DIR__ . '/abspath-stub/');
define('WP_CONTENT_DIR', HWE_WP_CONTENT_DIR);

// Constantes de tiempo de WordPress (valores estables, definidos en wp-includes/default-constants.php).
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
define('MONTH_IN_SECONDS', 2592000);
define('YEAR_IN_SECONDS', 31536000);

// Clases de WordPress simuladas (ver tests/Stubs/).
require_once __DIR__ . '/Stubs/wp-rest.php';
require_once __DIR__ . '/Stubs/wp-error.php';
require_once __DIR__ . '/Stubs/wp-graphql.php';
require_once __DIR__ . '/Stubs/wp-post.php';
