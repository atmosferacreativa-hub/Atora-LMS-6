<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_SpeedGrade_Renderer {

	/**
	 * Renderiza la barra lateral de cola de SpeedGrade.
	 *
	 * @param array    $queue_items Lista de entregas en cola.
	 * @param int      $display_current Posición actual visible.
	 * @param int      $display_total Total visible.
	 * @param string   $return_url URL de retorno para conservar contexto.
	 * @param callable $speedgrade_url_callback Callback para generar URL de SpeedGrade.
	 * @return string
	 */
	public static function render_queue_sidebar( $queue_items, $display_current, $display_total, $return_url, $speedgrade_url_callback ) {
		$queue_items      = is_array( $queue_items ) ? $queue_items : array();
		$display_current  = max( 1, absint( $display_current ) );
		$display_total    = max( 1, absint( $display_total ) );
		$return_url       = is_string( $return_url ) ? $return_url : '';
		$callback_is_safe = is_callable( $speedgrade_url_callback );

		ob_start();
		?>
		<aside class="clms-sg-queue-sidebar" id="clms-sg-queue">
			<div class="clms-sg-queue-header">
				<span class="clms-sg-queue-counter" id="clms-sg-queue-counter">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: current queue item position, 2: total queue items. */
							__( '%1$d de %2$d', 'atora-lms' ),
							$display_current,
							$display_total
						)
					);
					?>
				</span>
				<select class="clms-sg-queue-filter" id="clms-sg-filter">
					<option value="pending"><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></option>
					<option value="in_review"><?php esc_html_e( 'En revisión', 'atora-lms' ); ?></option>
					<option value="all"><?php esc_html_e( 'Todas', 'atora-lms' ); ?></option>
				</select>
			</div>

			<ul class="clms-sg-queue-list" id="clms-sg-queue-list">
				<?php if ( empty( $queue_items ) ) : ?>
					<li class="clms-sg-queue-item">
						<a href="#">
							<strong><?php esc_html_e( 'Sin entregas en cola', 'atora-lms' ); ?></strong>
						</a>
					</li>
				<?php else : ?>
					<?php foreach ( $queue_items as $queue_item ) : ?>
						<?php
						$queue_item = is_array( $queue_item ) ? $queue_item : array();
						$item_id    = absint( $queue_item['id'] ?? 0 );
						$item_url   = '#';
						if ( $callback_is_safe && $item_id ) {
							$item_url = (string) call_user_func( $speedgrade_url_callback, $item_id, $return_url );
						}
						?>
						<li
							class="clms-sg-queue-item <?php echo ! empty( $queue_item['is_current'] ) ? 'is-active' : ''; ?>"
							data-id="<?php echo esc_attr( $item_id ); ?>"
							data-status="<?php echo esc_attr( (string) ( $queue_item['status'] ?? '' ) ); ?>"
						>
							<a href="<?php echo esc_url( $item_url ); ?>">
								<strong><?php echo esc_html( (string) ( $queue_item['student_name'] ?? '' ) ); ?></strong>
								<span><?php echo esc_html( (string) ( $queue_item['lesson_title'] ?? '' ) ); ?></span>
								<span class="clms-sg-queue-status"><?php echo esc_html( (string) ( $queue_item['status_label'] ?? '' ) ); ?></span>
								<span class="clms-sg-queue-priority <?php echo esc_attr( (string) ( $queue_item['priority_class'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $queue_item['priority_label'] ?? '' ) ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>

			<div class="clms-sg-queue-hint">
				<kbd>J</kbd> <?php esc_html_e( 'siguiente', 'atora-lms' ); ?> · <kbd>K</kbd> <?php esc_html_e( 'anterior', 'atora-lms' ); ?>
			</div>
		</aside>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renderiza acciones superiores de navegación.
	 *
	 * @param array    $adjacent IDs prev/next.
	 * @param string   $return_url URL de retorno.
	 * @param callable $speedgrade_url_callback Callback para URL.
	 * @return string
	 */
	public static function render_topbar_actions( $adjacent, $return_url, $speedgrade_url_callback ) {
		$adjacent         = is_array( $adjacent ) ? $adjacent : array();
		$return_url       = is_string( $return_url ) ? $return_url : '';
		$callback_is_safe = is_callable( $speedgrade_url_callback );
		$prev_url         = ( $callback_is_safe && ! empty( $adjacent['prev'] ) ) ? (string) call_user_func( $speedgrade_url_callback, absint( $adjacent['prev'] ), $return_url ) : '';
		$next_url         = ( $callback_is_safe && ! empty( $adjacent['next'] ) ) ? (string) call_user_func( $speedgrade_url_callback, absint( $adjacent['next'] ), $return_url ) : '';

		ob_start();
		?>
		<div class="clms-sg-topbar">
			<div class="clms-sg-topbar__actions">
				<?php if ( ! empty( $return_url ) ) : ?>
					<a class="clms-sg-btn clms-sg-btn--secondary" href="<?php echo esc_url( $return_url ); ?>"><?php esc_html_e( 'Volver', 'atora-lms' ); ?></a>
				<?php endif; ?>

				<?php if ( ! empty( $prev_url ) ) : ?>
					<a class="clms-sg-btn clms-sg-btn--secondary" href="<?php echo esc_url( $prev_url ); ?>"><?php esc_html_e( 'Anterior', 'atora-lms' ); ?></a>
				<?php endif; ?>

				<?php if ( ! empty( $next_url ) ) : ?>
					<a class="clms-sg-btn clms-sg-btn--secondary" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Siguiente', 'atora-lms' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
