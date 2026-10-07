<?php
/**
 * Proveedor de IA simulado (6.32.0): solo para PHPUnit y el WordPress temporal
 * del CI. Responde con textos fijos grabados (`fixtures/fake-responses.json`),
 * según la función (`source`). Nunca llama a un proveedor real.
 *
 * Se activa con la constante `ATORA_AI_FAKE` (wp-config del CI) o con
 * `ATORA_AI_Fake_Provider::enable()` en una prueba. Guarda la última petición
 * para que las pruebas revisen qué se habría enviado al proveedor.
 *
 * @package ATORA_LMS
 * @since 6.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_AI_Fake_Provider {

	private static bool $forced = false;

	/** @var array{messages:array,options:array}|null */
	public static ?array $last = null;

	/** @var array<string,array>|null Respuestas sustituidas por una prueba. */
	public static ?array $override = null;

	public static function enabled(): bool {
		return self::$forced || ( defined( 'ATORA_AI_FAKE' ) && ATORA_AI_FAKE );
	}

	public static function enable(): void {
		self::$forced = true;
		self::$last   = null;
	}

	public static function disable(): void {
		self::$forced   = false;
		self::$override = null;
	}

	public static function boot(): void {
		add_filter( 'atora_ai_pre_chat', array( __CLASS__, 'respond' ), 10, 3 );
	}

	/** @return array|null|WP_Error */
	public static function respond( $pre, array $messages, array $options ) {
		if ( null !== $pre || ! self::enabled() ) {
			return $pre;
		}
		self::$last = array( 'messages' => $messages, 'options' => $options );
		$source     = sanitize_key( (string) ( $options['source'] ?? 'assistant' ) );
		$fixtures   = self::$override ?? (array) json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fake-responses.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$fixture    = $fixtures[ $source ] ?? $fixtures['assistant'] ?? array( 'text' => '' );
		if ( ! empty( $fixture['error'] ) ) {
			return new WP_Error( 'atora_ai_fake_error', (string) $fixture['error'] );
		}
		return array(
			'text'     => (string) ( $fixture['text'] ?? '' ),
			'usage'    => (array) ( $fixture['usage'] ?? array() ),
			'raw'      => array(),
			'provider' => 'openai',
			'model'    => 'simulado',
		);
	}
}
