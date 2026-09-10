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

		return is_array( $row ) ? $row : array();
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

		return $rows;
	}

	public function get_final_assessment( int $portfolio_id ): array {
		global $wpdb;

		$portfolio_id = absint( $portfolio_id );
		if ( ! $portfolio_id ) {
			return array();
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
			$this->touch_portfolio( absint( $item['portfolio_id'] ?? 0 ) );
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
			$this->touch_portfolio( absint( $item['portfolio_id'] ?? 0 ) );
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
}
