<?php
/**
 * Pila de pega para probar `scripts/verify-security.sh` con tráfico HTTP real.
 *
 * Se sirve con `php -S 127.0.0.1:<puerto> tests/Fixtures/verify-security-stack.php` y
 * hace de WordPress (con `hwe-bff-only`) y de BFF (Next.js) a la vez. Se comporta como
 * una pila SEGURA salvo las fugas que se pidan en STACK_LEAK (lista separada por comas):
 *
 *   graphql   GraphQL responde sin el secreto interno
 *   store     la Store API responde sin el secreto
 *   auth      hwe/v1/auth/* responde sin el secreto
 *   webhook   /api/revalidate acepta peticiones sin firma
 *   origin    una escritura sin Origin atraviesa la barrera
 *   login     el login con 2FA devuelve tokens reales antes del segundo factor
 *   headers   el frontend no manda CSP ni nosniff
 *   qr        la CSP vuelve a permitir api.qrserver.com
 *   powered   el frontend manda X-Powered-By
 *   wp-headers  las rutas de WordPress no llevan cabeceras de seguridad
 *
 * Otras variables: STACK_SECRET (secreto interno), STACK_WC_SECRET (webhooks de Woo),
 * STACK_BROKEN=1 (WordPress rechaza TODO, incluso con el secreto: una pila rota).
 */

declare(strict_types=1);

$secret = (string) getenv('STACK_SECRET');
$wcSecret = (string) getenv('STACK_WC_SECRET');
$leaks = array_filter(explode(',', (string) getenv('STACK_LEAK')));
$leak = static fn (string $name): bool => in_array($name, $leaks, true);
$broken = getenv('STACK_BROKEN') === '1';

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$header = static fn (string $name): ?string => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
$body = file_get_contents('php://input') ?: '';

function reply(mixed $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) {
        header("$name: $value");
    }
    echo json_encode($data);
    exit;
}

$hasSecret = !$broken && $secret !== '' && hash_equals($secret, (string) $header('X-HWE-Internal-Secret'));
/** WordPress con `hwe-bff-only`: 404 sin el secreto (salvo la fuga pedida). */
$bffOnly = static function (string $leakName, mixed $okBody) use ($hasSecret, $leak, $broken): never {
    if ($broken) {
        reply(['errors' => [['message' => 'Not Found']]], 404);
    }
    if ($hasSecret || $leak($leakName)) {
        reply($okBody);
    }
    reply(['code' => 'rest_no_route', 'message' => 'No route was found.', 'data' => ['status' => 404]], 404);
};

// ───────────────────────────── WordPress ─────────────────────────────
if ($path === '/graphql' || isset($query['graphql'])) {
    $bffOnly('graphql', ['data' => ['__typename' => 'RootQuery']]);
}
if (str_starts_with($path, '/wp-json/wc/store') || str_starts_with((string) ($query['rest_route'] ?? ''), '/wc/store')) {
    $bffOnly('store', ['items' => []]);
}
if (str_starts_with($path, '/wp-json/hwe/v1/auth/')) {
    $bffOnly('auth', ['id' => 1]);
}
if ($path === '/wp-json/hwe/v1/config') {
    reply(['brand' => ['name' => 'Pila de pega']]);
}
if ($path === '/wp-login.php') {
    http_response_code(200);
    if (!$leak('wp-headers')) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    echo '<html>login</html>';
    exit;
}

// ───────────────────────────── BFF (Next.js) ─────────────────────────────
if ($path === '/api/health/live') {
    reply(['status' => 'ok']);
}

if ($path === '/api/csrf' && $method === 'GET') {
    reply(['csrfToken' => 'token-csrf-de-prueba'], 200, ['Set-Cookie' => 'hwe_csrf=token-csrf-de-prueba; Path=/; HttpOnly']);
}

if ($path === '/api/revalidate' && $method === 'POST') {
    $hwe = (string) $header('X-HWE-Signature');
    $wc = (string) $header('X-WC-Webhook-Signature');
    $validHwe = $secret !== '' && $hwe !== '' && hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $hwe);
    $validWc = $wcSecret !== '' && $wc !== '' && hash_equals(base64_encode(hash_hmac('sha256', $body, $wcSecret, true)), $wc);
    if ($validHwe || $validWc || $leak('webhook')) {
        reply(['revalidated' => true]);
    }
    reply(['error' => 'Firma de webhook inválida.'], 401);
}

if (str_starts_with($path, '/api/') && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    // Barrera de Origin del proxy: una escritura sin Origin/Referer se rechaza.
    if ($header('Origin') === null && $header('Referer') === null && !$leak('origin')) {
        reply(['error' => 'Origen no permitido.'], 403);
    }
}

if ($path === '/api/store/cart/items' && $method === 'POST') {
    reply(['items' => []]);
}

if ($path === '/api/auth/login' && $method === 'POST') {
    if ($header('X-CSRF-Token') !== 'token-csrf-de-prueba') {
        reply(['error' => 'Token CSRF inválido.'], 403);
    }
    $credentials = json_decode($body, true) ?: [];
    if (($credentials['username'] ?? '') === 'con-2fa' && ($credentials['password'] ?? '') === 'clave-correcta') {
        if ($leak('login')) {
            reply(['requires_2fa' => true, 'ephemeralToken' => 'eyJhbGciOiJIUzI1NiJ9.eyJhdXRoVG9rZW4iOiJ4In0.firma'], 200,
                ['Set-Cookie' => 'hwe_at=eyJ.real.token; Path=/; HttpOnly']);
        }
        reply(['requires_2fa' => true, 'ephemeralToken' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);
    }
    reply(['error' => 'Credenciales inválidas.'], 401);
}

if ($path === '/' && $method === 'GET') {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    if (!$leak('headers')) {
        $img = "img-src 'self' data: blob:" . ($leak('qr') ? ' https://api.qrserver.com' : '');
        header("Content-Security-Policy: default-src 'self'; $img; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
    }
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), browsing-topics=()');
    if ($leak('powered')) {
        header('X-Powered-By: Next.js');
    }
    echo '<html>inicio</html>';
    exit;
}

reply(['error' => 'No encontrado'], 404);
