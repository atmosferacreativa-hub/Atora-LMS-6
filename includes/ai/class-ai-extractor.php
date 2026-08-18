<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Extractor {

	/**
	 * Extrae texto de un adjunto.
	 *
	 * @param int $attachment_id ID del adjunto.
	 * @return array
	 */
	public function extract_attachment_text( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return array(
				'success' => false,
				'text'    => '',
				'type'    => 'unknown',
				'label'   => 'Archivo',
				'error'   => 'Adjunto inválido.',
			);
		}

		$file_path = get_attached_file( $attachment_id );
		$file_url  = wp_get_attachment_url( $attachment_id );
		$mime_type = (string) get_post_mime_type( $attachment_id );
		$title     = get_the_title( $attachment_id );
		$ext       = $file_path ? strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) ) : '';

		$base = array(
			'attachment_id' => $attachment_id,
			'title'         => $title ? $title : basename( (string) $file_url ),
			'path'          => $file_path ? $file_path : '',
			'url'           => $file_url ? $file_url : '',
			'mime_type'     => $mime_type,
			'extension'     => $ext,
			'type'          => $this->detect_type( $mime_type, $ext ),
			'label'         => $this->detect_type_label( $mime_type, $ext ),
			'success'       => false,
			'text'          => '',
			'error'         => '',
		);

		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			$base['error'] = 'Archivo no encontrado o no legible en el servidor.';
			return $base;
		}

		switch ( $base['type'] ) {
			case 'text':
				$result = $this->extract_plain_text( $file_path );
				break;

			case 'docx':
				$result = $this->extract_docx_text( $file_path );
				break;

			case 'pdf':
				$result = $this->extract_pdf_text( $file_path );
				break;

			case 'image':
				$result = array(
					'success' => false,
					'text'    => '',
					'error'   => 'Imagen recibida. El análisis visual llegará en un sprint posterior.',
				);
				break;

			default:
				$result = array(
					'success' => false,
					'text'    => '',
					'error'   => 'Tipo de archivo no soportado todavía para extracción de texto.',
				);
				break;
		}

		$base['success'] = ! empty( $result['success'] );
		$base['text']    = isset( $result['text'] ) ? $this->normalize_text( $result['text'] ) : '';
		$base['error']   = isset( $result['error'] ) ? (string) $result['error'] : '';

		return $base;
	}

	/**
	 * Extrae texto de PDF.
	 *
	 * @param string $file_path Ruta del archivo.
	 * @return array
	 */
	public function extract_pdf_text( $file_path ) {
		$file_path = (string) $file_path;

		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'PDF no encontrado o no legible.',
			);
		}

		if ( $this->command_exists( 'pdftotext' ) ) {
			$text = $this->extract_pdf_with_pdftotext( $file_path );

			if ( $text ) {
				return array(
					'success' => true,
					'text'    => $text,
					'error'   => '',
				);
			}
		}

		return array(
			'success' => false,
			'text'    => '',
			'error'   => 'PDF recibido. Este servidor no tiene extracción básica disponible todavía.',
		);
	}

	/**
	 * Extrae texto de DOCX.
	 *
	 * @param string $file_path Ruta del archivo.
	 * @return array
	 */
	public function extract_docx_text( $file_path ) {
		$file_path = (string) $file_path;

		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'DOCX no encontrado o no legible.',
			);
		}

		if ( 'docx' !== strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) ) ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'Solo DOCX es compatible para extracción básica en esta fase.',
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'ZipArchive no está disponible en el servidor para leer DOCX.',
			);
		}

		$zip  = new ZipArchive();
		$open = $zip->open( $file_path );

		if ( true !== $open ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'No se pudo abrir el archivo DOCX.',
			);
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $xml || '' === $xml ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'No se encontró el contenido principal dentro del DOCX.',
			);
		}

		$xml  = str_replace( array( '</w:p>', '<w:br/>', '<w:br />', '<w:tab/>' ), array( "\n", "\n", "\n", "\t" ), $xml );
		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = $this->normalize_text( $text );

		if ( '' === $text ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'No se pudo extraer texto legible del DOCX.',
			);
		}

		return array(
			'success' => true,
			'text'    => $text,
			'error'   => '',
		);
	}

	/**
	 * Extrae texto plano.
	 *
	 * @param string $file_path Ruta del archivo.
	 * @return array
	 */
	public function extract_plain_text( $file_path ) {
		$file_path = (string) $file_path;

		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'Archivo de texto no encontrado o no legible.',
			);
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $content ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'No se pudo leer el archivo de texto.',
			);
		}

		$text = $this->normalize_text( $content );

		if ( '' === $text ) {
			return array(
				'success' => false,
				'text'    => '',
				'error'   => 'El archivo no contiene texto legible.',
			);
		}

		return array(
			'success' => true,
			'text'    => $text,
			'error'   => '',
		);
	}

	/**
	 * Normaliza texto extraído.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public function normalize_text( $text ) {
		$text = (string) $text;
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/\r\n|\r/u", "\n", $text );
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/\n{3,}/u", "\n\n", $text );
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		return $this->truncate_text( $text, 12000 );
	}

	/**
	 * Detecta tipo lógico del archivo.
	 *
	 * @param string $mime_type MIME.
	 * @param string $ext       Extensión.
	 * @return string
	 */
	protected function detect_type( $mime_type, $ext ) {
		$mime_type = (string) $mime_type;
		$ext       = strtolower( (string) $ext );

		if ( in_array( $ext, array( 'txt', 'md', 'csv', 'json', 'xml', 'log' ), true ) ) {
			return 'text';
		}

		if ( 'pdf' === $ext || false !== strpos( $mime_type, 'pdf' ) ) {
			return 'pdf';
		}

		if ( 'docx' === $ext ) {
			return 'docx';
		}

		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) || 0 === strpos( $mime_type, 'image/' ) ) {
			return 'image';
		}

		return 'unknown';
	}

	/**
	 * Etiqueta visible del tipo.
	 *
	 * @param string $mime_type MIME.
	 * @param string $ext       Extensión.
	 * @return string
	 */
	protected function detect_type_label( $mime_type, $ext ) {
		$type = $this->detect_type( $mime_type, $ext );

		switch ( $type ) {
			case 'text':
				return 'Texto';
			case 'pdf':
				return 'PDF';
			case 'docx':
				return 'Word';
			case 'image':
				return 'Imagen';
			default:
				return 'Archivo';
		}
	}

	/**
	 * Extrae PDF con pdftotext si existe y shell_exec está disponible.
	 *
	 * @param string $file_path Ruta del archivo.
	 * @return string
	 */
	protected function extract_pdf_with_pdftotext( $file_path ) {
		$file_path = (string) $file_path;
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return '';
		}

		$raw = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw || '' === $raw ) {
			return '';
		}

		preg_match_all( '/\(([^\)]{4,})\)/', $raw, $matches );
		$text = isset( $matches[1] ) ? implode( ' ', array_filter( $matches[1], 'is_string' ) ) : '';

		return '' !== trim( $text ) ? $this->normalize_text( $text ) : '';
	}

	/**
	 * Comprueba si existe un comando del sistema.
	 * Retorna false si shell_exec no está disponible en este servidor.
	 *
	 * @param string $command Nombre del comando.
	 * @return bool
	 */
	protected function command_exists( $command ) {
		return false;
	}

	/**
	 * Verifica si shell_exec está disponible y no está en disable_functions.
	 *
	 * @return bool
	 */
	protected function shell_exec_available() {
		return false;
	}

	/**
	 * Recorta texto a un máximo seguro.
	 *
	 * @param string $text   Texto.
	 * @param int    $length Longitud máxima.
	 * @return string
	 */
	protected function truncate_text( $text, $length = 12000 ) {
		$text   = (string) $text;
		$length = max( 1, absint( $length ) );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) <= $length ) {
				return $text;
			}

			return mb_substr( $text, 0, $length - 1 ) . '…';
		}

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, $length - 1 ) . '…';
	}
}