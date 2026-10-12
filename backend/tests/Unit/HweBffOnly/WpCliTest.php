<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** WP-CLI (`wp hwe setup`, las semillas, `wp eval`) no pasa por HTTP y no lleva secreto. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WpCliTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('WP_CLI', true);
        define('REST_REQUEST', true);
        parent::setUp();
    }

    public function testWpCliPassesWithoutTheSecret(): void
    {
        $this->assertTrue($this->graphqlAllowed());
        $this->assertNull($this->dispatch('/wc/store/v1/cart'));
        $this->assertNull($this->dispatch('/hwe/v1/auth/me'));
    }
}
