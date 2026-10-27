<?php

declare(strict_types=1);

namespace HWE\Tests\Unit\AdminUtilities;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HWE\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Selector manual del post destacado (`mu-plugins/hwe-featured-post.php`).
 *
 * El destacado es estado editorial de todo el sitio: la pantalla
 * `Entradas → Post Destacado` y su guardado exigen `edit_others_posts`
 * (`HWE_FEATURED_POST_CAP`), no el `edit_posts` que tienen Colaborador y Autor.
 * Una instancia puede conceder otra capacidad con el filtro
 * `hwe_featured_post_capability`. El nonce sigue siendo obligatorio y el id
 * solo se guarda si es un post `post` publicado; `0` limpia la selección.
 *
 * Cada test en su proceso: el archivo define funciones y constantes globales.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeaturedPostTest extends TestCase
{
    private const DENIED_MESSAGE = 'No tienes permiso para elegir el post destacado.';

    /** @var array<string,mixed> */
    private array $options = array();

    /** @var array<string,bool> */
    private array $caps = array();

    /** @var array<int,\WP_Post> */
    private array $posts = array();

    /** @var array<string,callable> */
    private array $actions = array();

    /** @var list<array<int,mixed>> */
    private array $submenus = array();

    /** @var list<string> */
    private array $logged = array();

    private string $nonce = 'nonce-valido';

    /** @var array<int,mixed>|null */
    private ?array $died = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options  = array();
        $this->caps     = array();
        $this->posts    = array();
        $this->actions  = array();
        $this->submenus = array();
        $this->logged   = array();
        $this->nonce    = 'nonce-valido';
        $this->died     = null;

        Functions\stubs(array(
            'esc_html__' => static fn ($value): string => (string) $value,
            'esc_html' => static fn ($value): string => (string) $value,
            'esc_attr' => static fn ($value): string => (string) $value,
            '__' => static fn ($value): string => (string) $value,
            'wp_unslash' => static fn ($value) => $value,
            'sanitize_text_field' => static fn ($value): string => trim((string) $value),
            'absint' => static fn ($value): int => abs((int) $value),
            'selected' => static fn (): string => '',
            'submit_button' => static fn (): string => '',
            'get_the_date' => static fn (): string => '2026-01-01',
            'wp_nonce_field' => static fn (): string => '',
        ));

        // Captura el callback de `admin_menu` para ejecutarlo en el test, como haría WordPress.
        Functions\when('add_action')->alias(function (string $hook, callable $callback): bool {
            $this->actions[$hook] = $callback;
            return true;
        });
        Functions\when('add_submenu_page')->alias(function (...$args): string {
            $this->submenus[] = $args;
            return 'hook';
        });
        Functions\when('current_user_can')->alias(
            fn (string $capability): bool => $this->caps[$capability] ?? false
        );
        Functions\when('get_option')->alias(
            fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default
        );
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);
            return true;
        });
        Functions\when('get_post')->alias(
            fn (int $id): ?\WP_Post => $this->posts[$id] ?? null
        );
        Functions\when('get_posts')->justReturn(array());
        Functions\when('wp_verify_nonce')->alias(
            fn (string $nonce): bool => $nonce === $this->nonce
        );
        Functions\when('wp_die')->alias(function (string $message, string $title = '', array $args = array()): void {
            $this->died = array($message, $title, $args);
            throw new FeaturedPostDenied();
        });
        Functions\when('error_log')->alias(function (string $message): bool {
            $this->logged[] = $message;
            return true;
        });
    }

    protected function tearDown(): void
    {
        $_POST = array();
        parent::tearDown();
    }

    private function loadPlugin(): void
    {
        $this->loadOwnFile('mu-plugins/hwe-featured-post.php');
    }

    private function openMenu(): void
    {
        $this->assertArrayHasKey('admin_menu', $this->actions, 'El mu-plugin registra el submenú.');
        ($this->actions['admin_menu'])();
    }

    /**
     * Ejecuta la pantalla capturando su salida y, si `wp_die` corta, la excepción.
     */
    private function render(): string
    {
        ob_start();
        try {
            hwe_featured_post_render_page();
        } catch (FeaturedPostDenied) {
            // Esperado cuando falta la capacidad: WordPress termina la petición.
        }
        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> */
    private function submit(int $postId): string
    {
        $_POST = array(
            'hwe_featured_post_nonce' => $this->nonce,
            'hwe_featured_post_id' => (string) $postId,
        );
        return $this->render();
    }

    private function publishedPost(int $id): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = 'post';
        $post->post_status = 'publish';
        $post->post_title = 'Entrada ' . $id;
        return $post;
    }

    public function testTheScreenRequiresAuthorityOverOtherAuthorsContent(): void
    {
        $this->loadPlugin();
        $this->openMenu();

        $this->assertCount(1, $this->submenus);
        $this->assertSame('edit.php', $this->submenus[0][0]);
        $this->assertSame('edit_others_posts', $this->submenus[0][3], 'Contributor y Author no pueden abrir la pantalla.');
        $this->assertSame('hwe-featured-post', $this->submenus[0][4]);
    }

    public function testContributorAndAuthorCannotChangeTheSiteWideFeaturedPost(): void
    {
        $this->loadPlugin();
        // Perfil de Colaborador/Autor: edita sus posts, no los de los demás ni opciones.
        $this->caps = array(
            'edit_posts' => true,
            'delete_posts' => true,
            'edit_others_posts' => false,
            'manage_options' => false,
        );
        $this->posts[4242] = $this->publishedPost(4242);

        $output = $this->submit(4242);

        $this->assertNotNull($this->died, 'Sin edit_others_posts la petición no se atiende.');
        $this->assertStringContainsString(self::DENIED_MESSAGE, (string) ($this->died[0] ?? ''));
        $this->assertSame(array(), $this->options, 'No se escribe el destacado global.');
        $this->assertStringNotContainsString('notice-success', $output);
    }

    public function testAnInstanceCanGrantAnotherCapabilityExplicitly(): void
    {
        Filters\expectApplied('hwe_featured_post_capability')
            ->twice()
            ->andReturn('curate_featured_post');
        $this->loadPlugin();
        $this->openMenu();

        $this->assertSame('curate_featured_post', $this->submenus[0][3], 'La pantalla usa la capacidad concedida.');

        $this->caps = array('curate_featured_post' => true);
        $this->posts[7] = $this->publishedPost(7);

        $this->submit(7);

        $this->assertSame(7, $this->options['hwe_featured_post_id']);
        $this->assertNull($this->died);
    }

    public function testTheNonceIsStillRequired(): void
    {
        $this->loadPlugin();
        $this->caps = array('edit_others_posts' => true);
        $this->posts[9] = $this->publishedPost(9);

        $_POST = array(
            'hwe_featured_post_nonce' => 'nonce-falso',
            'hwe_featured_post_id' => '9',
        );
        $this->render();

        $this->assertSame(array(), $this->options, 'Sin nonce válido no se guarda.');
    }

    public function testOnlyAPublishedPostOfTypePostIsStored(): void
    {
        $this->loadPlugin();
        $this->caps = array('edit_others_posts' => true);

        $page = $this->publishedPost(11);
        $page->post_type = 'page';
        $this->posts[11] = $page;

        $draft = $this->publishedPost(12);
        $draft->post_status = 'draft';
        $this->posts[12] = $draft;

        foreach (array(11, 12, 999999) as $invalidId) {
            $output = $this->submit($invalidId);

            $this->assertArrayNotHasKey(
                'hwe_featured_post_id',
                $this->options,
                "El id {$invalidId} no es un post publicado y no se guarda."
            );
            $this->assertStringContainsString('notice-error', $output, 'El rechazo se avisa en la pantalla.');
        }

        $this->assertNotEmpty($this->logged, 'El rechazo se registra para poder diagnosticarlo.');
        $this->assertStringContainsString('[hwe] featured-post:', $this->logged[0]);
    }

    public function testAPublishedPostIsStoredAndZeroClearsTheSelection(): void
    {
        $this->loadPlugin();
        $this->caps = array('edit_others_posts' => true);
        $this->posts[21] = $this->publishedPost(21);

        $output = $this->submit(21);

        $this->assertSame(21, $this->options['hwe_featured_post_id']);
        $this->assertStringContainsString('notice-success', $output);
        $this->assertNull($this->died);

        $this->submit(0);

        $this->assertArrayNotHasKey('hwe_featured_post_id', $this->options, '0 limpia la selección.');
    }
}

/** Corta la petición como `wp_die`, que en WordPress termina el proceso. */
final class FeaturedPostDenied extends \RuntimeException
{
}
