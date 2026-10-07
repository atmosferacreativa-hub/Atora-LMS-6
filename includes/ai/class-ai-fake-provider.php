<?php
/**
 * Proveedor de IA simulado (6.32.0): solo para PHPUnit y el WordPress temporal
 * del CI. Responde con textos fijos grabados (`fixtures/fake-responses.json`),
 * según la función (`source`). Nunca llama a un proveedor real.
 *
 * Se activa con la constante `ATORA_AI_FAKE` (wp-config del CI) o con
 * `ATORA_AI_Fake_Provider::enable()` en una prueba, y **nunca** si
 * `wp_get_environment_type()` es `production` (6.32.1). Guarda las peticiones
 * (`$last` y `$log`) para que las pruebas revisen qué se habría enviado.
 * Responde chat, embeddings, transcripción y `post_json` (6.32.1).
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

	/** @var array<int,array{kind:string,payload:mixed,options:array}> Todo lo que se habría enviado. */
	public static array $log = array();

	/** @var array<string,array>|null Respuestas sustituidas por una prueba. */
	public static ?array $override = null;

	public static function enabled(): bool {
		return self::allowed_in( wp_get_environment_type() ) && ( self::$forced || ( defined( 'ATORA_AI_FAKE' ) && ATORA_AI_FAKE ) );
	}

	/** En producción, nunca: ni con la constante ni desde una prueba. Sin filtro que lo cambie. */
	public static function allowed_in( string $environment ): bool {
		return 'production' !== $environment;
	}

	public static function enable(): void {
		self::$forced = true;
		self::$last   = null;
		self::$log    = array();
	}

	public static function disable(): void {
		self::$forced   = false;
		self::$override = null;
	}

	public static function boot(): void {
		add_filter( 'atora_ai_pre_chat', array( __CLASS__, 'respond' ), 10, 3 );
		add_filter( 'atora_ai_pre_request', array( __CLASS__, 'respond_request' ), 10, 4 );
	}

	/** Embeddings, transcripción y `post_json` (6.32.1). @return array|null */
	public static function respond_request( $pre, string $kind, $payload, array $options ) {
		if ( null !== $pre || ! self::enabled() ) {
			return $pre;
		}
		self::$log[] = array( 'kind' => $kind, 'payload' => $payload, 'options' => $options );
		if ( 'embeddings' === $kind ) {
			return array(
				'embeddings' => array_map( static fn() => array_fill( 0, 8, 0.1 ), (array) $payload ),
				'usage'      => array( 'prompt_tokens' => 40 * count( (array) $payload ) ),
			);
		}
		if ( 'transcription' === $kind ) {
			return array( 'text' => 'Transcripción simulada.', 'language' => 'es', 'duration' => 60.0, 'raw' => array() );
		}
		return array( 'status' => 200, 'body' => array( 'usage' => array( 'prompt_tokens' => 100, 'completion_tokens' => 20 ) ), 'raw' => '' );
	}

	/** @return array|null|WP_Error */
	public static function respond( $pre, array $messages, array $options ) {
		if ( null !== $pre || ! self::enabled() ) {
			return $pre;
		}
		self::$last  = array( 'messages' => $messages, 'options' => $options );
		self::$log[] = array( 'kind' => 'chat', 'payload' => $messages, 'options' => $options );
		$source     = sanitize_key( (string) ( $options['source'] ?? $options['feature'] ?? 'assistant' ) );
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
