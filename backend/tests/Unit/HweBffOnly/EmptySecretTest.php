<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\HweBffOnly;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EmptySecretTest extends HweBffOnlyTestCase
{
    protected function setUp(): void
    {
        define('HWE_REVALIDATION_SECRET', '   ');
        define('REST_REQUEST', true);
        parent::setUp();
    }

    public function testAnEmptyConfiguredSecretNeverMatchesAnEmptyHeader(): void
    {
        $this->sendSecret('');
        $this->assertFalse($this->graphqlAllowed());

        $this->sendSecret('   ');
        $this->assertFalse($this->graphqlAllowed());
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('/wc/store/v1/cart'));
    }
}
