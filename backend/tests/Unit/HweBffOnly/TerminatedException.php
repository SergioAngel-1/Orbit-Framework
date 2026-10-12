<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

/** Sustituye a `exit` en los tests: marca que el archivo habría terminado la petición. */
final class TerminatedException extends \RuntimeException
{
}
