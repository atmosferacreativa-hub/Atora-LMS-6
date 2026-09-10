<?php
/**
 * Portafolios — Service (DB + permisos + helpers).
 *
 * @package ATORA_LMS
 * @since   6.17.0
 */

namespace ATORA\Portfolios;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Portfolios_Service {

	/** @var array<int,array> Cache por request (portfolio_id => row). */
	private static array $portfolio_cache = array();

	/** @var array<int,array> Cache por request (portfolio_id => items[]). */
	private static array $items_cache = array();

	/** @var array<string,array> Cache por request ("{portfolio_id}:{limit}" => feedback[]). */
	private static array $feedback_cache = array();

	/** @var array<int,array> Cache por request (portfolio_id => assessment row). */
	private static array $final_assessment_cache = array();

	private function table_portfolios(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_portfolios';
	}

	private function table_items(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_portfolio_items';
	}

	private function table_assessments(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_portfolio_assessments';
	}

	private function table_feedback(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_portfolio_feedback';
	}

	public function viewer_can_access_course( int $viewer_id, int $course_id ): bool {
		$viewer_id = absint( $viewer_id );
		$course_id = absint( $course_id );
		if ( ! $viewer_id || ! $course_id ) {
			return false;
		}

		if ( user_can( $viewer_id, 'manage_options' ) ) {
			return true;
		}

		if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
			if ( \CLMS_Helper::user_can_manage_lms( $course_id ) ) {
				return true;
			}
		}

		$author = absint( get_post_field( 'post_author', $course_id ) );
		if ( $author && $author === $viewer_id ) {
			return true;
		}

		$teacher_ids = array();
		$raw = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\s*,\s*/', trim( $raw ) );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $tid ) {
				$tid = absint( $tid );
				if ( $tid ) {
					$teacher_ids[] = $tid;
				}
			}
		}

		return in_array( $viewer_id, array_values( array_unique( $teacher_ids ) ), true );
	}

	public function get_portfolio( int $portfolio_id ): array {
		global $wpdb;
		$portfolio_id = absint( $portfolio_id );
		if ( ! $portfolio_id ) {
			return array();
		}

		if ( isset( self::$portfolio_cache[ $portfolio_id ] ) ) {
			return self::$portfolio_cache[ $portfolio_id ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, course_id, user_id, title, visibility, public_slug, created_at, updated_at
				 FROM {$this->table_portfolios()}
				 WHERE id = %d
				 LIMIT 1",
				$portfolio_id
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();
		self::$portfolio_cache[ $portfolio_id ] = $row;
		return $row;
	}

	public function get_or_create_portfolio( int $course_id, int $user_id ): array {
		global $wpdb;

		$course_id = absint( $course_id );
		$user_id   = absint( $user_id );
		if ( ! $course_id || ! $user_id ) {
			return array();
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, course_id, user_id, title, visibility, public_slug, created_at, updated_at
				 FROM {$this->table_portfolios()}
				 WHERE course_id = %d AND user_id = %d
				 LIMIT 1",
				$course_id,
				$user_id
			),
			ARRAY_A
		);
		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			self::$portfolio_cache[ absint( $existing['id'] ) ] = $existing;
			return $existing;
		}

		$title = sprintf(
			/* translators: %s: course title */
			__( 'Portafolio — %s', 'atora-lms' ),
			(string) get_the_title( $course_id )
		);

		$payload = array(
			'course_id'   => $course_id,
			'user_id'     => $user_id,
			'title'       => sanitize_text_field( $title ),
			'visibility'  => 'teachers',
			'public_slug' => null,
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->table_portfolios(), $payload );
		$id = absint( $wpdb->insert_id );
		return $id ? $this->get_portfolio( $id ) : array();
	}

	public function update_portfolio_settings( int $portfolio_id, array $data ): bool {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		if ( ! $portfolio_id ) {
			return false;
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		$visibility = isset( $data['visibility'] ) ? sanitize_key( (string) $data['visibility'] ) : '';

		$allowed_vis = array( 'private', 'teachers', 'public' );
		if ( '' !== $visibility && ! in_array( $visibility, $allowed_vis, true ) ) {
			$visibility = '';
		}

		$updates = array(
			'updated_at' => current_time( 'mysql' ),
		);
		if ( '' !== $title ) {
			$updates['title'] = $title;
		}
		if ( '' !== $visibility ) {
			$updates['visibility'] = $visibility;
		}

		$portfolio = $this->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			return false;
		}

		if ( 'public' === ( $updates['visibility'] ?? ( $portfolio['visibility'] ?? '' ) ) ) {
			$slug = (string) ( $portfolio['public_slug'] ?? '' );
			if ( '' === $slug ) {
				$slug = $this->generate_unique_public_slug();
				$updates['public_slug'] = $slug ?: null;
			}
		} elseif ( isset( $updates['visibility'] ) && 'public' !== $updates['visibility'] ) {
			$updates['public_slug'] = null;
		}

		$ok = false !== $wpdb->update( $this->table_portfolios(), $updates, array( 'id' => $portfolio_id ) );
		unset( self::$portfolio_cache[ $portfolio_id ] );
		return (bool) $ok;
	}

	private function generate_unique_public_slug(): string {
		global $wpdb;

		for ( $i = 0; $i < 5; $i++ ) {
			$slug = strtolower( wp_generate_password( 10, false, false ) );
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->table_portfolios()} WHERE public_slug = %s LIMIT 1",
					$slug
				)
			);
			if ( ! $exists ) {
				return $slug;
			}
		}

		return '';
	}

	public function list_my_portfolios( int $user_id, int $course_id = 0 ): array {
		global $wpdb;

		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id ) {
			return array();
		}

		$where = 'user_id = %d';
		$args  = array( $user_id );
		if ( $course_id ) {
			$where .= ' AND course_id = %d';
			$args[] = $course_id;
		}

		$sql = $wpdb->prepare(
			"SELECT id, course_id, user_id, title, visibility, public_slug, created_at, updated_at
			 FROM {$this->table_portfolios()}
			 WHERE {$where}
			 ORDER BY updated_at DESC
			 LIMIT 200",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function list_course_portfolios( int $course_id, int $limit = 200 ): array {
		global $wpdb;
		$course_id = absint( $course_id );
		$limit     = max( 1, min( 500, absint( $limit ) ) );
		if ( ! $course_id ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT id, course_id, user_id, title, visibility, public_slug, created_at, updated_at
			 FROM {$this->table_portfolios()}
			 WHERE course_id = %d
			 ORDER BY updated_at DESC
			 LIMIT %d",
			$course_id,
			$limit
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function list_items( int $portfolio_id ): array {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		if ( ! $portfolio_id ) {
			return array();
		}

		if ( isset( self::$items_cache[ $portfolio_id ] ) ) {
			return self::$items_cache[ $portfolio_id ];
		}

		$sql = $wpdb->prepare(
			"SELECT id, portfolio_id, submission_id, lesson_id, position, title_override, reflection, tags_json, created_at, updated_at
			 FROM {$this->table_items()}
			 WHERE portfolio_id = %d
			 ORDER BY position ASC, id ASC
			 LIMIT 500",
			$portfolio_id
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as &$row ) {
			$row['id'] = absint( $row['id'] ?? 0 );
			$row['portfolio_id'] = absint( $row['portfolio_id'] ?? 0 );
			$row['submission_id'] = absint( $row['submission_id'] ?? 0 );
			$row['lesson_id'] = absint( $row['lesson_id'] ?? 0 );
			$row['position'] = (int) ( $row['position'] ?? 0 );
			$row['title_override'] = sanitize_text_field( (string) ( $row['title_override'] ?? '' ) );
			$row['reflection'] = sanitize_textarea_field( (string) ( $row['reflection'] ?? '' ) );
			$row['tags'] = $row['tags_json'] ? json_decode( (string) $row['tags_json'], true ) : array();
			$row['tags'] = is_array( $row['tags'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $row['tags'] ) ) ) : array();
			unset( $row['tags_json'] );

			$submission_id = absint( $row['submission_id'] ?? 0 );
			$row['lesson_title'] = $row['lesson_id'] ? (string) get_the_title( $row['lesson_id'] ) : '';
			$row['submission_created_at'] = $submission_id ? (string) get_post_field( 'post_date', $submission_id ) : '';
		}
		unset( $row );

		self::$items_cache[ $portfolio_id ] = $rows;
		return $rows;
	}

	public function get_final_assessment( int $portfolio_id ): array {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		if ( ! $portfolio_id ) {
			return array();
		}

		if ( isset( self::$final_assessment_cache[ $portfolio_id ] ) ) {
			return self::$final_assessment_cache[ $portfolio_id ];
		}

		$table = $this->table_assessments();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, portfolio_id, rubric_id, assessed_by, is_final, total_percent, scores_json, comment, created_at
				 FROM {$this->table_assessments()}
				 WHERE portfolio_id = %d AND is_final = 1
				 ORDER BY id DESC
				 LIMIT 1",
				$portfolio_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array();
		}

		$row['id']           = absint( $row['id'] ?? 0 );
		$row['portfolio_id'] = absint( $row['portfolio_id'] ?? 0 );
		$row['rubric_id']    = absint( $row['rubric_id'] ?? 0 );
		$row['assessed_by']  = absint( $row['assessed_by'] ?? 0 );
		$row['is_final']     = ! empty( $row['is_final'] );
		$row['total_percent']= absint( $row['total_percent'] ?? 0 );
		$row['scores']       = $row['scores_json'] ? json_decode( (string) $row['scores_json'], true ) : array();
		$row['scores']       = is_array( $row['scores'] ) ? $row['scores'] : array();
		unset( $row['scores_json'] );
		$row['comment']      = sanitize_textarea_field( (string) ( $row['comment'] ?? '' ) );
		$row['created_at']   = sanitize_text_field( (string) ( $row['created_at'] ?? '' ) );

		$assessor = $row['assessed_by'] ? get_userdata( $row['assessed_by'] ) : null;
		$row['assessed_by_name'] = $assessor ? (string) ( $assessor->display_name ? $assessor->display_name : $assessor->user_login ) : '';

		self::$final_assessment_cache[ $portfolio_id ] = $row;
		return $row;
	}

	/**
	 * Guarda evaluación final (override): inserta nueva fila y desmarca previas.
	 *
	 * @param int $portfolio_id
	 * @param int $rubric_id
	 * @param array $scores
	 * @param string $comment
	 * @param int $assessed_by
	 * @return array Evaluación final.
	 */
	public function save_final_assessment( int $portfolio_id, int $rubric_id, array $scores, string $comment, int $assessed_by ): array {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		$rubric_id    = absint( $rubric_id );
		$assessed_by  = absint( $assessed_by );
		$comment      = sanitize_textarea_field( (string) $comment );
		$scores       = is_array( $scores ) ? $scores : array();

		if ( ! $portfolio_id || ! $rubric_id || ! $assessed_by ) {
			return array();
		}

		if ( 'clms_rubric' !== get_post_type( $rubric_id ) ) {
			return array();
		}

		$criteria = class_exists( '\CLMS_Rubric' ) ? (array) \CLMS_Rubric::get_criteria( $rubric_id ) : array();
		$max      = class_exists( '\CLMS_Rubric' ) ? absint( \CLMS_Rubric::get_total_points( $rubric_id ) ) : 0;
		if ( $max <= 0 ) {
			$max = 100;
		}

		$total = 0;
		$normalized = array();
		foreach ( $criteria as $i => $criterion ) {
			$key = (string) $i;
			$max_points = isset( $criterion['max_points'] ) ? absint( $criterion['max_points'] ) : 0;
			$raw = $scores[ $key ] ?? null;
			if ( null === $raw || '' === (string) $raw || ! is_numeric( $raw ) ) {
				$normalized[ $key ] = 0;
				continue;
			}
			$pts = (int) round( (float) $raw );
			$pts = max( 0, min( $max_points, $pts ) );
			$normalized[ $key ] = $pts;
			$total += $pts;
		}

		$percent = $max > 0 ? min( 100, absint( round( ( $total / $max ) * 100 ) ) ) : 0;

		$table = $this->table_assessments();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return array();
		}

		// Clear previous finals.
		$wpdb->update(
			$table,
			array( 'is_final' => 0 ),
			array( 'portfolio_id' => $portfolio_id, 'is_final' => 1 )
		);

		$wpdb->insert(
			$table,
			array(
				'portfolio_id'   => $portfolio_id,
				'rubric_id'      => $rubric_id,
				'assessed_by'    => $assessed_by,
				'is_final'       => 1,
				'total_percent'  => $percent,
				'scores_json'    => wp_json_encode( $normalized ),
				'comment'        => $comment,
				'created_at'     => current_time( 'mysql' ),
				)
			);

		unset( self::$final_assessment_cache[ $portfolio_id ] );
		return $this->get_final_assessment( $portfolio_id );
	}

	public function add_item( int $portfolio_id, int $submission_id, array $data = array() ): array {
		global $wpdb;

		$portfolio_id  = absint( $portfolio_id );
		$submission_id = absint( $submission_id );
		if ( ! $portfolio_id || ! $submission_id ) {
			return array();
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );

		$position = isset( $data['position'] ) ? (int) $data['position'] : null;
		if ( null === $position ) {
			$max = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(position) FROM {$this->table_items()} WHERE portfolio_id = %d",
					$portfolio_id
				)
			);
			$position = is_numeric( $max ) ? ( (int) $max + 1 ) : 1;
		}

		$tags = isset( $data['tags'] ) && is_array( $data['tags'] ) ? $data['tags'] : array();
		$tags = array_values( array_filter( array_map( 'sanitize_text_field', (array) $tags ) ) );

		$payload = array(
			'portfolio_id'   => $portfolio_id,
			'submission_id'  => $submission_id,
			'lesson_id'      => $lesson_id,
			'position'       => (int) $position,
			'title_override' => isset( $data['title_override'] ) ? sanitize_text_field( (string) $data['title_override'] ) : null,
			'reflection'     => isset( $data['reflection'] ) ? sanitize_textarea_field( (string) $data['reflection'] ) : null,
			'tags_json'      => $tags ? wp_json_encode( $tags ) : null,
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$inserted = $wpdb->insert( $this->table_items(), $payload );
		$id = absint( $wpdb->insert_id );

		if ( ! $inserted || ! $id ) {
			// Si ya existe (UNIQUE), devuelve el existente.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->table_items()} WHERE portfolio_id = %d AND submission_id = %d LIMIT 1",
					$portfolio_id,
					$submission_id
				)
			);
			$id = absint( $existing_id );
		}

		if ( $id ) {
			$this->touch_portfolio( $portfolio_id );
			unset( self::$items_cache[ $portfolio_id ] );
		}

		return $id ? $this->get_item( $id ) : array();
	}

	public function get_item( int $item_id ): array {
		global $wpdb;
		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, portfolio_id, submission_id, lesson_id, position, title_override, reflection, tags_json, created_at, updated_at
				 FROM {$this->table_items()}
				 WHERE id = %d
				 LIMIT 1",
				$item_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array();
		}

		$row['id'] = absint( $row['id'] ?? 0 );
		$row['portfolio_id'] = absint( $row['portfolio_id'] ?? 0 );
		$row['submission_id'] = absint( $row['submission_id'] ?? 0 );
		$row['lesson_id'] = absint( $row['lesson_id'] ?? 0 );
		$row['position'] = (int) ( $row['position'] ?? 0 );
		$row['title_override'] = sanitize_text_field( (string) ( $row['title_override'] ?? '' ) );
		$row['reflection'] = sanitize_textarea_field( (string) ( $row['reflection'] ?? '' ) );
		$row['tags'] = $row['tags_json'] ? json_decode( (string) $row['tags_json'], true ) : array();
		$row['tags'] = is_array( $row['tags'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $row['tags'] ) ) ) : array();
		unset( $row['tags_json'] );

		return $row;
	}

	public function update_item( int $item_id, array $data ): bool {
		global $wpdb;

		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return false;
		}

		$item = $this->get_item( $item_id );
		if ( empty( $item ) ) {
			return false;
		}

		$updates = array(
			'updated_at' => current_time( 'mysql' ),
		);

		if ( array_key_exists( 'title_override', $data ) ) {
			$updates['title_override'] = sanitize_text_field( (string) $data['title_override'] );
		}
		if ( array_key_exists( 'reflection', $data ) ) {
			$updates['reflection'] = sanitize_textarea_field( (string) $data['reflection'] );
		}
		if ( array_key_exists( 'tags', $data ) ) {
			$tags = is_array( $data['tags'] ) ? $data['tags'] : array();
			$tags = array_values( array_filter( array_map( 'sanitize_text_field', (array) $tags ) ) );
			$updates['tags_json'] = $tags ? wp_json_encode( $tags ) : null;
		}
		if ( array_key_exists( 'position', $data ) ) {
			$updates['position'] = (int) $data['position'];
		}

		$ok = false !== $wpdb->update( $this->table_items(), $updates, array( 'id' => $item_id ) );
		if ( $ok ) {
			$pid = absint( $item['portfolio_id'] ?? 0 );
			$this->touch_portfolio( $pid );
			if ( $pid ) {
				unset( self::$items_cache[ $pid ] );
				unset( self::$feedback_cache[ $pid . ':100' ] );
				unset( self::$feedback_cache[ $pid . ':200' ] );
				unset( self::$feedback_cache[ $pid . ':500' ] );
				unset( self::$final_assessment_cache[ $pid ] );
			}
		}
		return (bool) $ok;
	}

	public function delete_item( int $item_id ): bool {
		global $wpdb;
		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return false;
		}

		$item = $this->get_item( $item_id );
		if ( empty( $item ) ) {
			return false;
		}

		$ok = false !== $wpdb->delete( $this->table_items(), array( 'id' => $item_id ) );
		if ( $ok ) {
			$pid = absint( $item['portfolio_id'] ?? 0 );
			$this->touch_portfolio( $pid );
			if ( $pid ) {
				unset( self::$items_cache[ $pid ] );
				unset( self::$feedback_cache[ $pid . ':100' ] );
				unset( self::$feedback_cache[ $pid . ':200' ] );
				unset( self::$feedback_cache[ $pid . ':500' ] );
				unset( self::$final_assessment_cache[ $pid ] );
			}
		}
		return (bool) $ok;
	}

	public function reorder_items( int $portfolio_id, array $item_ids ): bool {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		$item_ids = is_array( $item_ids ) ? $item_ids : array();
		$item_ids = array_values( array_filter( array_map( 'absint', $item_ids ) ) );
		if ( ! $portfolio_id || empty( $item_ids ) ) {
			return false;
		}

		$pos = 1;
		foreach ( $item_ids as $item_id ) {
			$wpdb->update(
				$this->table_items(),
				array(
					'position'   => $pos,
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id'           => $item_id,
					'portfolio_id' => $portfolio_id,
				)
			);
			++$pos;
		}

		$this->touch_portfolio( $portfolio_id );
		unset( self::$items_cache[ $portfolio_id ] );
		unset( self::$feedback_cache[ $portfolio_id . ':100' ] );
		unset( self::$feedback_cache[ $portfolio_id . ':200' ] );
		unset( self::$feedback_cache[ $portfolio_id . ':500' ] );
		unset( self::$final_assessment_cache[ $portfolio_id ] );
		return true;
	}

	private function touch_portfolio( int $portfolio_id ): void {
		global $wpdb;
		$portfolio_id = absint( $portfolio_id );
		if ( ! $portfolio_id ) {
			return;
		}

		$wpdb->update(
			$this->table_portfolios(),
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $portfolio_id )
		);
		unset( self::$portfolio_cache[ $portfolio_id ] );
	}

	public function add_feedback( int $portfolio_id, int $author_id, string $comment, ?int $item_id = null, string $author_role = 'teacher' ): array {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		$author_id    = absint( $author_id );
		$item_id      = null !== $item_id ? absint( $item_id ) : null;
		$comment      = trim( (string) $comment );
		$author_role  = sanitize_key( $author_role );
		if ( ! $portfolio_id || ! $author_id || '' === $comment ) {
			return array();
		}
		if ( ! in_array( $author_role, array( 'teacher', 'peer', 'admin' ), true ) ) {
			$author_role = 'teacher';
		}

		$payload = array(
			'portfolio_id' => $portfolio_id,
			'item_id'      => $item_id ? $item_id : null,
			'author_id'    => $author_id,
			'author_role'  => $author_role,
			'comment'      => wp_kses_post( $comment ),
			'created_at'   => current_time( 'mysql' ),
		);

		$wpdb->insert( $this->table_feedback(), $payload );
		$id = absint( $wpdb->insert_id );

		if ( $id ) {
			$this->touch_portfolio( $portfolio_id );
			unset( self::$feedback_cache[ $portfolio_id . ':100' ] );
			unset( self::$feedback_cache[ $portfolio_id . ':200' ] );
			unset( self::$feedback_cache[ $portfolio_id . ':500' ] );
		}

		return $id ? $this->get_feedback( $id ) : array();
	}

	public function get_feedback( int $feedback_id ): array {
		global $wpdb;
		$feedback_id = absint( $feedback_id );
		if ( ! $feedback_id ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, portfolio_id, item_id, author_id, author_role, comment, created_at
				 FROM {$this->table_feedback()}
				 WHERE id = %d
				 LIMIT 1",
				$feedback_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array();
		}

		$row['id'] = absint( $row['id'] ?? 0 );
		$row['portfolio_id'] = absint( $row['portfolio_id'] ?? 0 );
		$row['item_id'] = isset( $row['item_id'] ) ? ( null !== $row['item_id'] ? absint( $row['item_id'] ) : null ) : null;
		$row['author_id'] = absint( $row['author_id'] ?? 0 );
		$row['author_role'] = sanitize_key( (string) ( $row['author_role'] ?? '' ) );
		$row['comment'] = wp_kses_post( (string) ( $row['comment'] ?? '' ) );
		$row['created_at'] = sanitize_text_field( (string) ( $row['created_at'] ?? '' ) );

		$u = $row['author_id'] ? get_userdata( $row['author_id'] ) : null;
		$row['author_name'] = $u ? (string) ( $u->display_name ? $u->display_name : $u->user_login ) : '';

		return $row;
	}

	public function list_feedback( int $portfolio_id, int $limit = 200 ): array {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		$limit        = max( 1, min( 500, absint( $limit ) ) );
		if ( ! $portfolio_id ) {
			return array();
		}

		$cache_key = $portfolio_id . ':' . $limit;
		if ( isset( self::$feedback_cache[ $cache_key ] ) ) {
			return self::$feedback_cache[ $cache_key ];
		}

		$sql = $wpdb->prepare(
			"SELECT id, portfolio_id, item_id, author_id, author_role, comment, created_at
			 FROM {$this->table_feedback()}
			 WHERE portfolio_id = %d
			 ORDER BY id DESC
			 LIMIT %d",
			$portfolio_id,
			$limit
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = $this->get_feedback( absint( $row['id'] ?? 0 ) );
		}

		self::$feedback_cache[ $cache_key ] = $out;
		return $out;
	}

	public function get_portfolio_by_public_slug( string $slug ): array {
		global $wpdb;
		$slug = sanitize_text_field( trim( $slug ) );
		if ( '' === $slug ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, course_id, user_id, title, visibility, public_slug, created_at, updated_at
				 FROM {$this->table_portfolios()}
				 WHERE public_slug = %s AND visibility = 'public'
				 LIMIT 1",
				$slug
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Export (MVP): genera un ZIP con snapshot del portafolio (BI-friendly).
	 *
	 * @param int $portfolio_id
	 * @return array{filename:string,content:string}|array
	 */
	public function export_portfolio_zip( int $portfolio_id ): array {
		$portfolio_id = absint( $portfolio_id );
		if ( ! $portfolio_id ) {
			return array();
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			return array();
		}

		$portfolio = $this->get_portfolio( $portfolio_id );
		if ( empty( $portfolio ) ) {
			return array();
		}

		$items      = $this->list_items( $portfolio_id );
		$feedback   = $this->list_feedback( $portfolio_id, 500 );
		$assessment = $this->get_final_assessment( $portfolio_id );

		$payload = array(
			'portfolio'  => $portfolio,
			'items'      => $items,
			'feedback'   => $feedback,
			'assessment' => $assessment,
			'exported_at'=> gmdate( 'Y-m-d H:i:s' ),
		);

		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'atora-portfolio-' . $portfolio_id . '.zip' ) : tempnam( sys_get_temp_dir(), 'atora-portfolio-' );
		if ( ! $tmp ) {
			return array();
		}

		$zip = new \ZipArchive();
		$opened = $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			return array();
		}

		$zip->addFromString( 'portfolio.json', wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		$zip->addFromString( 'README.txt', $this->build_export_readme( $portfolio ) );
		$zip->addFromString( 'portfolio.html', $this->build_export_html( $portfolio, $items, $assessment, $feedback ) );
		$zip->addFromString( 'items.csv', $this->build_items_csv( $items ) );
		$zip->addFromString( 'feedback.csv', $this->build_feedback_csv( $feedback ) );

		foreach ( (array) $items as $item ) {
			$item_id      = absint( $item['id'] ?? 0 );
			$pos          = (int) ( $item['position'] ?? 0 );
			$lesson_id    = absint( $item['lesson_id'] ?? 0 );
			$submission_id= absint( $item['submission_id'] ?? 0 );
			$lesson_title = sanitize_title( (string) ( $item['lesson_title'] ?? '' ) );
			$lesson_title = $lesson_title ? $lesson_title : 'lesson';

			$path = sprintf( 'items/%03d-%s-item-%d.txt', max( 0, $pos ), $lesson_title, $item_id );
			$zip->addFromString( $path, $this->build_item_txt( $item ) );

			if ( $submission_id ) {
				$post = get_post( $submission_id );
				if ( $post && 'clms_submission' === $post->post_type ) {
					$html_path = sprintf( 'submissions/submission-%d.html', $submission_id );
					$html = (string) $post->post_content;
					$zip->addFromString( $html_path, $html );

					$txt_path = sprintf( 'submissions/submission-%d.txt', $submission_id );
					$zip->addFromString( $txt_path, $this->normalize_text( wp_strip_all_tags( $html, true ) ) );
				}
			}

			if ( $lesson_id ) {
				$lesson_post = get_post( $lesson_id );
				if ( $lesson_post && 'lm_lesson' === $lesson_post->post_type ) {
					$zip->addFromString( sprintf( 'lessons/lesson-%d-title.txt', $lesson_id ), (string) $lesson_post->post_title );
				}
			}
		}

		$zip->close();

		$content = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_string( $content ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! is_string( $content ) || '' === $content ) {
			return array();
		}

		$course_id = absint( $portfolio['course_id'] ?? 0 );
		$user_id   = absint( $portfolio['user_id'] ?? 0 );
		$filename  = sanitize_file_name(
			sprintf(
				'atora-portfolio-course-%d-user-%d-%s.zip',
				$course_id,
				$user_id,
				gmdate( 'Ymd-His' )
			)
		);

		return array(
			'filename' => $filename,
			'content'  => $content,
		);
	}

	private function normalize_text( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return is_string( $text ) ? trim( $text ) : '';
	}

	private function build_export_readme( array $portfolio ): string {
		$course_id = absint( $portfolio['course_id'] ?? 0 );
		$user_id   = absint( $portfolio['user_id'] ?? 0 );
		$title     = sanitize_text_field( (string) ( $portfolio['title'] ?? '' ) );

		$lines = array(
			'ATORA LMS — Export de Portafolio (MVP)',
			'',
			'Título: ' . $title,
			'Curso: ' . ( $course_id ? (string) get_the_title( $course_id ) : '' ) . ' (#' . $course_id . ')',
			'Estudiante user_id: #' . $user_id,
			'Exportado (UTC): ' . gmdate( 'Y-m-d H:i:s' ),
			'',
			'Archivos:',
			'- portfolio.json (snapshot completo)',
			'- portfolio.html (vista imprimible / puedes guardar como PDF)',
			'- items.csv (evidencias)',
			'- feedback.csv (comentarios)',
			'- items/*.txt (detalle por evidencia: reflexión + tags + referencia a submission)',
			'- submissions/*.html y submissions/*.txt (contenido de la entrega, si existe)',
		);

		return implode( "\n", $lines ) . "\n";
	}

	private function build_items_csv( array $items ): string {
		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $stream ) {
			return '';
		}

		fputcsv( $stream, array( 'item_id', 'position', 'lesson_id', 'lesson_title', 'submission_id', 'tags', 'reflection' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( (array) $items as $item ) {
			$tags = isset( $item['tags'] ) && is_array( $item['tags'] ) ? implode( '|', array_map( 'sanitize_text_field', (array) $item['tags'] ) ) : '';
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$stream,
				array(
					absint( $item['id'] ?? 0 ),
					(int) ( $item['position'] ?? 0 ),
					absint( $item['lesson_id'] ?? 0 ),
					sanitize_text_field( (string) ( $item['lesson_title'] ?? '' ) ),
					absint( $item['submission_id'] ?? 0 ),
					$tags,
					$this->normalize_text( sanitize_textarea_field( (string) ( $item['reflection'] ?? '' ) ) ),
				)
			);
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_string( $csv ) ? $csv : '';
	}

	private function build_feedback_csv( array $feedback ): string {
		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $stream ) {
			return '';
		}

		fputcsv( $stream, array( 'feedback_id', 'portfolio_id', 'item_id', 'author_id', 'author_name', 'author_role', 'comment', 'created_at' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( (array) $feedback as $f ) {
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$stream,
				array(
					absint( $f['id'] ?? 0 ),
					absint( $f['portfolio_id'] ?? 0 ),
					isset( $f['item_id'] ) && null !== $f['item_id'] ? absint( $f['item_id'] ) : '',
					absint( $f['author_id'] ?? 0 ),
					sanitize_text_field( (string) ( $f['author_name'] ?? '' ) ),
					sanitize_key( (string) ( $f['author_role'] ?? '' ) ),
					$this->normalize_text( wp_strip_all_tags( (string) ( $f['comment'] ?? '' ), true ) ),
					sanitize_text_field( (string) ( $f['created_at'] ?? '' ) ),
				)
			);
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_string( $csv ) ? $csv : '';
	}

	private function build_item_txt( array $item ): string {
		$tags = isset( $item['tags'] ) && is_array( $item['tags'] ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $item['tags'] ) ) : '';
		$lines = array(
			'Item ID: #' . absint( $item['id'] ?? 0 ),
			'Orden: ' . (string) (int) ( $item['position'] ?? 0 ),
			'Lección: ' . sanitize_text_field( (string) ( $item['lesson_title'] ?? '' ) ) . ' (#' . absint( $item['lesson_id'] ?? 0 ) . ')',
			'Submission: #' . absint( $item['submission_id'] ?? 0 ),
			'Tags: ' . ( $tags ? $tags : '—' ),
			'',
			'Reflexión:',
			$this->normalize_text( sanitize_textarea_field( (string) ( $item['reflection'] ?? '' ) ) ),
			'',
		);

		return implode( "\n", $lines ) . "\n";
	}

	private function build_export_html( array $portfolio, array $items, array $assessment, array $feedback ): string {
		$course_id = absint( $portfolio['course_id'] ?? 0 );
		$user_id   = absint( $portfolio['user_id'] ?? 0 );
		$title     = sanitize_text_field( (string) ( $portfolio['title'] ?? '' ) );
		$course    = $course_id ? (string) get_the_title( $course_id ) : '';

		$html  = '<!doctype html><html><head><meta charset="utf-8">';
		$html .= '<title>' . esc_html( $title ) . '</title>';
		$html .= '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:900px;margin:24px auto;line-height:1.4}';
		$html .= 'h1,h2{margin:0 0 10px} .muted{color:#6b7280} .card{border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin:12px 0}';
		$html .= 'table{width:100%;border-collapse:collapse} td,th{border-top:1px solid #f3f4f6;padding:8px 6px;text-align:left;vertical-align:top}</style>';
		$html .= '</head><body>';
		$html .= '<h1>' . esc_html( $title ) . '</h1>';
		$html .= '<p class="muted">Curso: ' . esc_html( $course ) . ' (#' . esc_html( (string) $course_id ) . ') · Estudiante user_id #' . esc_html( (string) $user_id ) . '</p>';
		$html .= '<p class="muted">Exportado (UTC): ' . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . '</p>';

		if ( ! empty( $assessment ) ) {
			$rubric_title = absint( $assessment['rubric_id'] ?? 0 ) ? (string) get_the_title( absint( $assessment['rubric_id'] ?? 0 ) ) : '';
			$html .= '<div class="card"><h2>Evaluación</h2>';
			$html .= '<p><strong>Nota final:</strong> ' . esc_html( (string) absint( $assessment['total_percent'] ?? 0 ) ) . '/100';
			if ( $rubric_title ) {
				$html .= ' <span class="muted">(' . esc_html( $rubric_title ) . ')</span>';
			}
			$html .= '</p>';
			if ( ! empty( $assessment['comment'] ) ) {
				$html .= '<p><strong>Comentario:</strong><br>' . nl2br( esc_html( (string) $assessment['comment'] ) ) . '</p>';
			}
			$html .= '</div>';
		}

		$html .= '<div class="card"><h2>Evidencias</h2>';
		$html .= '<table><thead><tr><th>#</th><th>Lección</th><th>Reflexión</th><th>Tags</th></tr></thead><tbody>';
		foreach ( (array) $items as $item ) {
			$tags = isset( $item['tags'] ) && is_array( $item['tags'] ) ? implode( ', ', array_map( 'sanitize_text_field', (array) $item['tags'] ) ) : '';
			$html .= '<tr>';
			$html .= '<td>' . esc_html( (string) (int) ( $item['position'] ?? 0 ) ) . '</td>';
			$html .= '<td>' . esc_html( sanitize_text_field( (string) ( $item['lesson_title'] ?? '' ) ) ) . '</td>';
			$html .= '<td>' . nl2br( esc_html( $this->normalize_text( sanitize_textarea_field( (string) ( $item['reflection'] ?? '' ) ) ) ) ) . '</td>';
			$html .= '<td>' . esc_html( $tags ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';

		$html .= '<div class="card"><h2>Feedback</h2>';
		if ( empty( $feedback ) ) {
			$html .= '<p class="muted">No hay feedback.</p>';
		} else {
			$html .= '<table><thead><tr><th>Autor</th><th>Comentario</th><th>Fecha</th></tr></thead><tbody>';
			foreach ( (array) $feedback as $f ) {
				$html .= '<tr>';
				$html .= '<td>' . esc_html( sanitize_text_field( (string) ( $f['author_name'] ?? '' ) ) ) . '<br><span class="muted">' . esc_html( sanitize_key( (string) ( $f['author_role'] ?? '' ) ) ) . '</span></td>';
				$html .= '<td>' . nl2br( esc_html( $this->normalize_text( wp_strip_all_tags( (string) ( $f['comment'] ?? '' ), true ) ) ) ) . '</td>';
				$html .= '<td><span class="muted">' . esc_html( sanitize_text_field( (string) ( $f['created_at'] ?? '' ) ) ) . '</span></td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}
		$html .= '</div>';

		$html .= '</body></html>';
		return $html;
	}
}
