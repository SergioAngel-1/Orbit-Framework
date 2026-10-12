<?php

namespace HWE\ControlCenter\FieldTypes;

/**
 * Un tipo de campo del Control Center: sabe pintar su control, sanear lo recibido y declarar
 * dónde se persiste.
 *
 * Los tipos del núcleo (`text`, `textarea`, `email`, `url`, `color`, `boolean`, `select`,
 * `secret`, `group`…) viajan por el mismo filtro que los de una instancia,
 * `hwe_control_center_field_types`: el filtro recibe el mapa del núcleo (id → FieldType) y
 * puede AÑADIR ids. Sobrescribir o eliminar un id del núcleo se descarta y se registra: el
 * saneado del núcleo no se puede debilitar desde un filtro (ver `Registry`).
 *
 * Persistencia (`persistence()`):
 *  · `config`   → se guarda en `hwe_config` y puede salir por la API pública si el nodo es
 *    `public => true`;
 *  · `secret`   → se cifra en el almacén de secretos y jamás sale por la API;
 *  · `external` → NO se persiste aquí: lo gestiona su módulo al recibir
 *    `hwe_control_center_saved` (ver `Registry::EXTERNAL` y la tarea 28).
 */
interface FieldType {

    /** No persistir este valor (p. ej. un tipo desconocido que no llegó en el POST). */
    public const SKIP = "\x00hwe_skip\x00";

    public const CONFIG   = 'config';
    public const SECRET   = 'secret';
    public const EXTERNAL = 'external';

    /** Id del tipo, tal como aparece en `'type'` de cada nodo del esquema. */
    public function id(): string;

    /** @return self::CONFIG|self::SECRET|self::EXTERNAL */
    public function persistence(): string;

    /**
     * HTML del control dentro de la fila del formulario (el `<div class="hwe-field">` con su
     * `<label>` lo pone el walker).
     *
     * @param array<string,mixed> $field   Nodo del esquema (label, default, options…).
     * @param string              $id      Id HTML único (`hwe_config_grupo_campo`).
     * @param string              $name    Nombre del input (`hwe_config[grupo][campo]`).
     * @param mixed               $value   Valor actual (ya fusionado con defaults).
     * @param list<string>        $path    Ruta del campo en el esquema.
     * @param array<string,mixed> $context Datos de render (p. ej. `['secrets' => mapa]`).
     */
    public function render(array $field, string $id, string $name, mixed $value, array $path, array $context = []): string;

    /**
     * Sanea un valor recibido del formulario o de `wp hwe setup`.
     *
     * Puede devolver `self::SKIP` para no persistir nada (el llamante omite la clave).
     *
     * @param array<string,mixed> $field Nodo del esquema.
     * @param mixed               $raw   Valor tal como llegó (o null si no llegó).
     */
    public function sanitize(array $field, mixed $raw): mixed;
}
