<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Loyalty;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Plugin `hwe-loyalty` (módulo `loyalty`), bono por producto (spec §5.5 y §6.2).
 *
 * El bono es un campo de producto declarativo (`hwe_product_fields`), entero ≥ 0 y no traducible.
 * Se prueba la definición del campo y la lectura saneada del meta `_hwe_loyalty_referrer_bonus`:
 * un valor negativo, decimal o no numérico nunca se convierte en puntos.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProductBonusTest extends TestCase
{
    /** @var array<int,array<string,mixed>> Meta de producto en memoria. */
    private array $postMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->postMeta = [];
        Functions\when('get_post_meta')->alias(
            fn (int $postId, string $key, bool $single = false) => $this->postMeta[$postId][$key] ?? ''
        );
        Functions\when('error_log')->justReturn(true);

        $this->loadOwnFile('plugins/hwe-loyalty/includes/ProductBonus.php');
    }

    public function test_the_field_is_a_non_translatable_number_in_its_own_box(): void
    {
        $fields = \HWE_Loyalty_ProductBonus::field(['otro' => ['label' => 'Otro campo']]);

        $this->assertArrayHasKey('loyalty_referrer_bonus', $fields);
        $this->assertArrayHasKey('otro', $fields, 'No pisa los campos de otras instancias.');

        $field = $fields['loyalty_referrer_bonus'];
        $this->assertSame('number', $field['type']);
        $this->assertFalse($field['translatable'], 'Un bono es un número: no se traduce.');
        $this->assertSame('Fidelización', $field['box']);
        $this->assertNotSame('', $field['label']);
        $this->assertNotSame('', $field['description']);
    }

    public function test_a_field_with_the_same_key_is_respected(): void
    {
        $existing = ['loyalty_referrer_bonus' => ['label' => 'Declarado antes', 'type' => 'text']];

        $this->assertSame($existing, \HWE_Loyalty_ProductBonus::field($existing));
    }

    public function test_register_adds_the_product_fields_filter(): void
    {
        Filters\expectAdded('hwe_product_fields')
            ->once()
            ->with([\HWE_Loyalty_ProductBonus::class, 'field']);

        \HWE_Loyalty_ProductBonus::register();
    }

    public function test_the_bonus_is_read_from_its_meta_floored_and_never_negative(): void
    {
        $this->postMeta[101]['_hwe_loyalty_referrer_bonus'] = '5';
        $this->postMeta[102]['_hwe_loyalty_referrer_bonus'] = '2.9';
        $this->postMeta[103]['_hwe_loyalty_referrer_bonus'] = '-3';
        $this->postMeta[104]['_hwe_loyalty_referrer_bonus'] = 'no es un número';
        $this->postMeta[105]['_hwe_loyalty_referrer_bonus'] = '';
        $this->postMeta[106]['_hwe_loyalty_referrer_bonus'] = '0';

        $this->assertSame(5, \HWE_Loyalty_ProductBonus::bonusFor(101));
        $this->assertSame(2, \HWE_Loyalty_ProductBonus::bonusFor(102), 'Se redondea hacia abajo.');
        $this->assertSame(0, \HWE_Loyalty_ProductBonus::bonusFor(103));
        $this->assertSame(0, \HWE_Loyalty_ProductBonus::bonusFor(104));
        $this->assertSame(0, \HWE_Loyalty_ProductBonus::bonusFor(105));
        $this->assertSame(0, \HWE_Loyalty_ProductBonus::bonusFor(106));
        $this->assertSame(0, \HWE_Loyalty_ProductBonus::bonusFor(999), 'Sin meta no hay bono.');
        $this->assertSame(0, \HWE_Loyalty_ProductBonus::bonusFor(0), 'Un id inválido no consulta nada.');
    }
}
