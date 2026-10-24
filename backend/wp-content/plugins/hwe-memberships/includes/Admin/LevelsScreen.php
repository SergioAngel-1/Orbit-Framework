<?php
/**
 * Portada del módulo «Membresías»: la tabla de niveles.
 *
 * Es una pantalla de solo lectura: resume cada nivel (rango, estado, compra y beneficios
 * activos) y ofrece los accesos para editarlo, configurar sus beneficios y ver la comparativa.
 * El listado nativo del CPT sigue disponible como submenú «Todos los niveles».
 *
 * Toda la salida va escapada. La tabla no escribe nada, así que con `hwe_memberships_view`
 * alcanza; el botón de alta y los enlaces de edición solo se pintan con
 * `hwe_memberships_manage`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Levels_Screen {

	/** Pinta la portada del módulo. La usa el menú como callback de la página principal. */
	public static function render(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			wp_die( esc_html__( 'No tienes permiso para ver las membresías.', 'hwe' ) );
			return;
		}

		$levels  = HWE_Memberships_Levels::all( true );
		$canEdit = HWE_Memberships_Admin_Capabilities::canManage();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Niveles', 'hwe' ) . '</h1>';

		if ( $canEdit ) {
			echo ' <a class="page-title-action" href="'
				. esc_url( admin_url( 'post-new.php?post_type=' . HWE_Memberships_Levels::POST_TYPE ) )
				. '">' . esc_html__( 'Añadir nivel', 'hwe' ) . '</a>';
		}

		echo '<hr class="wp-header-end">';

		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		echo '<p>' . esc_html__( 'Los niveles los crea el editor: no hay ninguno predefinido. El rango ordena el acceso (mayor = más acceso) y los beneficios se configuran nivel a nivel.', 'hwe' ) . '</p>';

		if ( array() === $levels ) {
			echo '<p><strong>' . esc_html__( 'Todavía no hay niveles.', 'hwe' ) . '</strong> ';
			if ( $canEdit ) {
				echo '<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . HWE_Memberships_Levels::POST_TYPE ) ) . '">'
					. esc_html__( 'Crea el primero', 'hwe' ) . '</a>.';
			}
			echo '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Nivel', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Rango', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Estado', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Compra', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Beneficios activos', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Acciones', 'hwe' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $levels as $level ) {
			$levelId = (int) $level['id'];
			$status  = ! empty( $level['published'] )
				? esc_html__( 'Publicado', 'hwe' )
				: esc_html__( 'Borrador', 'hwe' );
			$buy     = ! empty( $level['purchasable'] )
				? esc_html__( 'Sí', 'hwe' )
				: esc_html__( 'No', 'hwe' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $level['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( (string) (int) $level['rank'] ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( $buy ) . '</td>';
			echo '<td>' . esc_html( (string) self::enabledBenefits( $levelId ) ) . '</td>';
			echo '<td>';

			if ( $canEdit ) {
				echo '<a href="' . esc_url( admin_url( 'post.php?post=' . $levelId . '&action=edit' ) ) . '">'
					. esc_html__( 'Editar', 'hwe' ) . '</a> | ';
			}

			echo '<a href="' . esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::BENEFITS, array( HWE_Memberships_Admin_Benefits_Screen::LEVEL_QUERY => $levelId ) ) ) . '">'
				. esc_html__( 'Beneficios', 'hwe' ) . '</a>';

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::COMPARISON ) ) . '">'
			. esc_html__( 'Ver la comparativa de niveles y beneficios', 'hwe' ) . '</a></p>';
		echo '</div>';
	}

	/** Cuántos tipos de beneficio están activados en el nivel. */
	private static function enabledBenefits( int $levelId ): int {
		$enabled = 0;
		foreach ( HWE_Memberships_Levels::benefitsConfig( $levelId ) as $config ) {
			if ( is_array( $config ) && ! empty( $config['enabled'] ) ) {
				$enabled++;
			}
		}

		return $enabled;
	}
}
