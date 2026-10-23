<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use HWE\Shipping\Save;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guardado de la ciudad (`Save::saveRate`): saneado en el servidor, rechazo con motivo y borrador,
 * ids de opción estables y duplicados. WordPress se simula: `$meta` recoge lo que se escribe.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SaveCityTest extends TestCase
{
    private const POST_ID = 11;

    /** @var array<string,mixed> meta escrita en el post que se guarda */
    private array $meta = [];
    /** @var list<string> claves borradas */
    private array $deleted = [];
    /** @var list<array<string,mixed>> llamadas a wp_update_post */
    private array $updates = [];
    /** @var array<int,string> otros posts publicados: id => título */
    private array $published = [];
    private string $title = 'Madrid';
    private string $status = 'publish';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            '__' => static fn ($v) => $v,
            'wp_unslash' => static fn ($v) => $v,
            'sanitize_text_field' => static fn ($v) => trim(strip_tags((string) $v)),
            'wp_json_encode' => static fn ($v) => json_encode($v),
            'remove_accents' => static fn (string $s) => strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'n', 'Ñ' => 'N']),
            'wp_verify_nonce' => static fn () => 1,
            'current_user_can' => static fn () => true,
            'wp_cache_delete' => static fn () => true,
        ]);
        Functions\when('get_the_title')->alias(static fn (\WP_Post $post): string => $post->post_title);
        Functions\when('get_post')->alias(fn (int $id) => $this->postObject($id, $this->title, $this->status));
        Functions\when('get_posts')->alias(function (array $args): array {
            $out = [];
            foreach ($this->published as $id => $title) {
                if (in_array($id, $args['exclude'] ?? [], true)) {
                    continue;
                }
                $out[] = $this->postObject($id, $title, 'publish');
            }
            return $out;
        });
        // Como WordPress: `update_post_meta` quita las barras del valor (`wp_unslash`), así que un
        // JSON guardado sin `wp_slash` pierde sus escapes (tildes `\u00e1`, comillas `\"`).
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$key] = is_string($value) ? stripslashes($value) : $value;
            return true;
        });
        Functions\when('wp_slash')->alias(static fn ($value) => is_string($value) ? addslashes($value) : $value);
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            $this->deleted[] = $key;
            return true;
        });
        Functions\when('wp_update_post')->alias(function (array $args): int {
            $this->updates[] = $args;
            return (int) $args['ID'];
        });
        Functions\when('error_log')->justReturn(true);

        $this->published = [];
        $_POST = [];
        Functions\stubs([
            'plugin_dir_url' => static fn () => '',
            'register_activation_hook' => static fn () => null,
            'is_admin' => static fn () => false,
        ]);
        $this->loadOwnFile('plugins/hwe-shipping/hwe-shipping.php');

    }

    private function postObject(int $id, string $title, string $status): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_title = $title;
        $post->post_status = $status;
        return $post;
    }

    /** @param array<string,mixed> $fields */
    private function submit(array $fields, string $nonce = 'ok'): void
    {
        $_POST = ['hwe_shipping_rate_nonce' => $nonce] + $fields;
        Save::saveRate(self::POST_ID);
    }

    /** @return array<string,mixed> */
    private function fixedForm(array $options): array
    {
        return ['hwe_shipping_city_type' => 'fixed', 'hwe_shipping_city_options' => $options];
    }

    /** @return array<string,mixed> */
    private function distanceForm(array $options, array $extra = []): array
    {
        return array_merge([
            'hwe_shipping_city_type' => 'distance',
            'hwe_shipping_city_options' => $options,
            'hwe_shipping_city_origin_address' => 'Calle Mayor 1',
            'hwe_shipping_city_origin_lat' => '37.3891',
            'hwe_shipping_city_origin_lng' => '-5.9845',
            'hwe_shipping_city_origin_label' => 'Calle Mayor 1, Sevilla',
            'hwe_shipping_city_coverage_km' => '30',
        ], $extra);
    }

    /** @return array<string,string> */
    private function fixedRow(string $name, string $amount, string $id = '', string $free = ''): array
    {
        return ['id' => $id, 'name' => $name, 'amount' => $amount, 'free_eligible' => $free];
    }

    /** @return array<string,string> */
    private function kmRow(string $name, string $perKm, string $min = '', string $max = '', string $id = ''): array
    {
        return ['id' => $id, 'name' => $name, 'price_per_km' => $perKm, 'min_cost' => $min, 'max_cost' => $max];
    }

    /** @return list<array<string,mixed>> las opciones guardadas */
    private function storedOptions(): array
    {
        return json_decode((string) $this->meta['_hwe_shipping_city_options'], true);
    }

    /** @return list<string> */
    private function storedErrors(): array
    {
        return json_decode((string) $this->meta['_hwe_shipping_city_errors'], true);
    }

    private function assertRejected(string $fragment): void
    {
        $this->assertArrayNotHasKey('_hwe_shipping_city_type', $this->meta, 'no se escribe la configuración');
        $this->assertCount(1, $this->updates, 'pasa a borrador');
        $this->assertSame(['ID' => self::POST_ID, 'post_status' => 'draft'], $this->updates[0]);
        $errors = implode(' | ', $this->storedErrors());
        $this->assertStringContainsString($fragment, $errors);
    }

    // --- autorización ----------------------------------------------------------------------------

    public function testSinNonceNoGuardaNada(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);

        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5')]), 'malo');

        $this->assertSame([], $this->meta);
        $this->assertSame([], $this->updates);
    }

    public function testSinCapacidadDeEditarNoGuardaNada(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5')]));

        $this->assertSame([], $this->meta);
        $this->assertSame([], $this->updates);
    }

    // --- tipo, opciones y nombre -----------------------------------------------------------------

    public function testUnTipoInvalidoNoPublicaYDejaElBorradorConAviso(): void
    {
        $this->submit(['hwe_shipping_city_type' => 'gratis', 'hwe_shipping_city_options' => [$this->fixedRow('Estándar', '5')]]);

        $this->assertRejected('Elige el tipo de tarifa');
    }

    public function testUnaCiudadSinTipoElegidoNoPublica(): void
    {
        $this->submit(['hwe_shipping_city_options' => [$this->fixedRow('Estándar', '5')]]);

        $this->assertRejected('Elige el tipo de tarifa');
    }

    public function testUnaCiudadSinOpcionesNoPublica(): void
    {
        $this->submit($this->fixedForm([]));

        $this->assertRejected('Añade al menos una opción de entrega');
    }

    public function testUnaOpcionSinNombreNoPublica(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('  ', '5')]));

        $this->assertRejected('La opción 1 necesita un nombre');
    }

    public function testUnTituloVacioNoPublica(): void
    {
        $this->title = '   ';

        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5')]));

        $this->assertRejected('nombre de la ciudad');
    }

    public function testUnaCiudadNormalizadaDuplicadaNoPublica(): void
    {
        $this->published = [42 => 'Málaga'];
        $this->title = 'MALAGA ';

        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5')]));

        $this->assertRejected('Ya hay una tarifa publicada para esta ciudad');
    }

    public function testGuardarElMismoPostPublicadoNoSeConsideraDuplicado(): void
    {
        $this->published = [self::POST_ID => 'Madrid'];

        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5')]));

        $this->assertSame([], $this->updates);
        $this->assertSame('fixed', $this->meta['_hwe_shipping_city_type']);
    }

    // --- precios ----------------------------------------------------------------------------------

    public function testUnImporteFijoNegativoSeRechaza(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '-5')]));

        $this->assertRejected('no puede ser negativo');
    }

    public function testUnImporteFijoNoNumericoSeRechaza(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Estándar', 'cinco')]));

        $this->assertRejected('no es un número válido');
    }

    public function testUnaOpcionFijaSinImporteSeRechaza(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '')]));

        $this->assertRejected('necesita un importe');
    }

    public function testUnPrecioPorKmNegativoSeRechazaYUnMinimoNegativoTambien(): void
    {
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '-1')]));
        $this->assertRejected('precio por km');

        $this->updates = [];
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '900', '-10')]));
        $this->assertRejected('El mínimo de la opción 1 no puede ser negativo');
    }

    public function testUnMinimoMayorQueElMaximoSeRechazaSiAmbosSonPositivos(): void
    {
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '900', '500', '100')]));

        $this->assertRejected('el mínimo no puede ser mayor que el máximo');
    }

    public function testUnMaximoCeroEsSinLimiteYNoRechazaElMinimo(): void
    {
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '900', '500', '0')]));

        $this->assertSame([], $this->updates);
        $this->assertSame(500.0, (float) $this->storedOptions()[0]['min_cost']);
        $this->assertSame(0.0, (float) $this->storedOptions()[0]['max_cost']);
    }

    // --- ciudad por distancia ---------------------------------------------------------------------

    public function testUnaCiudadPorDistanciaSinCoordenadasNoPublica(): void
    {
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '900')], [
            'hwe_shipping_city_origin_lat' => '',
            'hwe_shipping_city_origin_lng' => '',
        ]));

        $this->assertRejected('dirección de referencia con coordenadas válidas');
    }

    public function testUnaCiudadPorDistanciaConCoordenadasBasuraNoPublica(): void
    {
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '900')], [
            'hwe_shipping_city_origin_lat' => 'NaN',
            'hwe_shipping_city_origin_lng' => '-5.9',
        ]));

        $this->assertRejected('dirección de referencia con coordenadas válidas');
    }

    /** @return array<string,array{0:string}> */
    public static function radiosNoValidos(): array
    {
        return ['cero' => ['0'], 'negativo' => ['-3'], 'vacío' => [''], 'texto' => ['veinte']];
    }

    #[DataProvider('radiosNoValidos')]
    public function testUnRadioNoPositivoNoPublica(string $radio): void
    {
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '900')], ['hwe_shipping_city_coverage_km' => $radio]));

        $this->assertRejected('radio de cobertura debe ser un número mayor que 0 km');
    }

    public function testUnaCiudadPorDistanciaCompletaSeGuardaConSuOrigenYRadio(): void
    {
        $this->submit($this->distanceForm([$this->kmRow('Estándar', '900', '0', '0')], ['hwe_shipping_city_coverage_km' => '25']));

        $this->assertSame('distance', $this->meta['_hwe_shipping_city_type']);
        $this->assertSame('37.3891', $this->meta['_hwe_shipping_city_origin_lat']);
        $this->assertSame('-5.9845', $this->meta['_hwe_shipping_city_origin_lng']);
        $this->assertSame('Calle Mayor 1', $this->meta['_hwe_shipping_city_origin_address']);
        $this->assertSame('25', $this->meta['_hwe_shipping_city_coverage_km']);
        $this->assertSame([], $this->updates);
    }

    public function testUnaCiudadFijaNoGuardaElOrigenNiElRadio(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5')]) + ['hwe_shipping_city_origin_lat' => '37.3']);

        $this->assertSame('', $this->meta['_hwe_shipping_city_origin_lat']);
        $this->assertSame('', $this->meta['_hwe_shipping_city_coverage_km']);
    }

    // --- opciones: ids, orden y renombrado --------------------------------------------------------

    public function testUnaOpcionExistenteConservaSuIdAlRenombrarla(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Express nuevo', '9', 'abcd1234')]));

        $options = $this->storedOptions();
        $this->assertSame('abcd1234', $options[0]['id']);
        $this->assertSame('Express nuevo', $options[0]['name']);
    }

    public function testUnaOpcionNuevaRecibeUnIdGeneradoEnMinusculas(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5', '')]));

        $this->assertMatchesRegularExpression('/^[a-z0-9]{4,32}$/D', $this->storedOptions()[0]['id']);
    }

    public function testUnIdRepetidoEnElFormularioSeRegeneraEnLaSegundaFila(): void
    {
        $this->submit($this->fixedForm([
            $this->fixedRow('Estándar', '5', 'abcd1234'),
            $this->fixedRow('Express', '9', 'abcd1234'),
        ]));

        $options = $this->storedOptions();
        $this->assertSame('abcd1234', $options[0]['id']);
        $this->assertNotSame('abcd1234', $options[1]['id']);
    }

    public function testUnIdConFormatoInvalidoSeRegenera(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '5', "ID con espacios\n")]));

        $this->assertMatchesRegularExpression('/^[a-z0-9]{4,32}$/D', $this->storedOptions()[0]['id']);
    }

    public function testElOrdenDelFormularioSeConserva(): void
    {
        $this->submit($this->fixedForm([
            $this->fixedRow('Express', '15', 'zzzz1111'),
            $this->fixedRow('Estándar', '8', 'aaaa2222'),
        ]));

        $this->assertSame(['Express', 'Estándar'], array_column($this->storedOptions(), 'name'));
    }

    public function testLosNombresConTildesYComillasSobrevivenAlGuardado(): void
    {
        $this->submit($this->fixedForm([
            $this->fixedRow('Rápido «24 h»', '8', 'aaaa1111'),
            $this->fixedRow('Envío "puerta a puerta" \\ nocturno', '15', 'bbbb2222'),
        ]));

        $this->assertSame(
            ['Rápido «24 h»', 'Envío "puerta a puerta" \\ nocturno'],
            array_column($this->storedOptions(), 'name')
        );
    }

    public function testLosErroresConTildesSeLeenIgualQueSeGuardaron(): void
    {
        $this->submit($this->fixedForm([]));

        foreach ($this->storedErrors() as $error) {
            $this->assertStringNotContainsString('u00', $error);
        }
    }

    public function testLaCasillaDeEnvioGratisSeGuardaPorOpcion(): void
    {
        $this->submit($this->fixedForm([
            $this->fixedRow('Estándar', '8', '', '1'),
            $this->fixedRow('Express', '15', '', ''),
        ]));

        $options = $this->storedOptions();
        $this->assertTrue($options[0]['free_eligible']);
        $this->assertFalse($options[1]['free_eligible']);
    }

    // --- guardado correcto ------------------------------------------------------------------------

    public function testUnaCiudadFijaValidaSeGuardaYBorraLosErroresYElModeloAnterior(): void
    {
        $this->submit($this->fixedForm([$this->fixedRow('Estándar', '8.5', '', '1')]));

        $this->assertSame('fixed', $this->meta['_hwe_shipping_city_type']);
        $this->assertSame([], $this->updates, 'no hace falta borrador');
        $this->assertContains('_hwe_shipping_city_errors', $this->deleted);
        $this->assertContains('_hwe_shipping_rate_amount', $this->deleted);
        $this->assertContains('_hwe_shipping_rate_free_eligible', $this->deleted);
        $this->assertSame(8.5, $this->storedOptions()[0]['amount']);
    }

    public function testElBorradorForzadoNoVuelveAEntrarEnBucle(): void
    {
        // wp_update_post dispara save_post otra vez: la segunda entrada no debe volver a guardar.
        Functions\when('wp_update_post')->alias(function (array $args): int {
            $this->updates[] = $args;
            Save::saveRate((int) $args['ID']);
            return (int) $args['ID'];
        });

        $this->submit(['hwe_shipping_city_type' => 'gratis']);

        $this->assertCount(1, $this->updates);
        $this->assertSame('draft', $this->updates[0]['post_status']);
    }

    public function testUnaCiudadYaEnBorradorNoSeVuelveAActualizarAlRechazarla(): void
    {
        $this->status = 'draft';

        $this->submit(['hwe_shipping_city_type' => 'gratis']);

        $this->assertSame([], $this->updates);
        $this->assertNotEmpty($this->storedErrors());
    }
}
