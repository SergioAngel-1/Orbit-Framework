<?php
/**
 * Ventaja informativa (spec §7.7).
 *
 * Tipo sin lógica: el nivel guarda un título y un texto traducibles para contar una ventaja que
 * la tienda gestiona fuera del sistema (atención prioritaria, aliados, eventos…). El BFF decide
 * cómo mostrarla; aquí solo se devuelven los textos por idioma, sin componer frases.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefit_Info_Perk extends HWE_Memberships_Benefit {

	/** Clave estable del tipo. */
	public const KEY = 'info_perk';

	public function key(): string {
		return self::KEY;
	}

	public function fields(): array {
		return array(
			'title' => array(
				'type'    => self::FIELD_TRANSLATABLE,
				'label'   => function_exists( '__' ) ? __( 'Título', 'hwe' ) : 'Título',
				'default' => array(),
				'max'     => 120,
			),
			'text'  => array(
				'type'    => self::FIELD_TRANSLATABLE,
				'label'   => function_exists( '__' ) ? __( 'Texto', 'hwe' ) : 'Texto',
				'default' => array(),
				'max'     => 500,
			),
		);
	}

	public function publicData( int $levelId ): array {
		$config = $this->configFor( $levelId );

		return array(
			'key'   => $this->key(),
			'title' => isset( $config['title'] ) && is_array( $config['title'] ) ? $config['title'] : array(),
			'text'  => isset( $config['text'] ) && is_array( $config['text'] ) ? $config['text'] : array(),
		);
	}
}
