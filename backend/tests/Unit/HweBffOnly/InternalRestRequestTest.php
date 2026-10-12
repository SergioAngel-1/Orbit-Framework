<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Un `rest_do_request()` interno de WordPress (precargas del editor, p. ej.) no es un
 * cliente HTTP: `REST_REQUEST` no está definida y no se filtra.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InternalRestRequestTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('HWE_REVALIDATION_SECRET', self::SECRET);
        parent::setUp();
    }

    public function testAnInternalDispatchIsNotFiltered(): void
    {
        $this->assertFalse(defined('REST_REQUEST'));
        $this->assertNull($this->dispatch('/wc/store/v1/cart'));
    }
}
