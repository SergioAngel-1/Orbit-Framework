<?php

declare(strict_types=1);

/**
 * Lanzador de `composer test`.
 *
 * 809 de los 1.233 tests llevan `#[RunTestsInSeparateProcesses]`: cada uno arranca un
 * proceso PHP nuevo y paga por volver a compilar lo mismo. Aquí se preparan las dos cachés
 * en disco que abaratan ese arranque:
 *
 * - `.phpunit.cache/patchwork` la usa Patchwork desde `tests/bootstrap.php`.
 * - `.phpunit.cache/opcache` se pasa a PHP como `opcache.file_cache`, de modo que los
 *   procesos hijos reutilizan los opcodes ya compilados (PHPUnit, Patchwork, stubs…).
 *
 * `opcache.file_cache` exige una ruta ABSOLUTA y un directorio escribible; si la extensión
 * opcache no está cargada o el directorio no se pudo crear, se ejecuta PHPUnit sin esa
 * caché en vez de abortar: degradar nunca debe romper la suite.
 */
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$cacheDir = $root . '/.phpunit.cache';
$opcacheCache = $cacheDir . '/opcache';

foreach ([$cacheDir . '/patchwork', $opcacheCache] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

$command = escapeshellarg(PHP_BINARY);

if (extension_loaded('Zend OPcache') && is_dir($opcacheCache) && is_writable($opcacheCache)) {
    // `validate_timestamps` y `file_cache_consistency_checks` se fijan explícitamente para
    // que una entrada obsoleta se descarte aunque el php.ini las traiga apagadas.
    $command .= ' -d opcache.enable_cli=1'
        . ' -d opcache.file_cache=' . escapeshellarg($opcacheCache)
        . ' -d opcache.validate_timestamps=1'
        . ' -d opcache.file_cache_consistency_checks=1';
}

$command .= ' ' . escapeshellarg($root . '/vendor/bin/phpunit');

foreach (array_slice($argv, 1) as $arg) {
    $command .= ' ' . escapeshellarg($arg);
}

passthru($command, $exitCode);
exit($exitCode);
