<?php
/**
 * Pantalla "Planificación híbrida" (6.37.0) en el administrador. Funciona sin IA; usable en el teléfono.
 *
 * @package ATORA_LMS
 * @since 6.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Hybrid_Plan_Admin {

	const PAGE = 'atora-hybrid-planner';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Planificación híbrida', 'atora-lms' ),
			__( 'Planificación híbrida', 'atora-lms' ),
			'edit_lm_lessons',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_media();
		$version = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1';
		wp_enqueue_script( 'atora-hybrid-planner', plugins_url( 'assets/js/atora-hybrid-planner.js', ATORA_LMS_FILE ), array(), $version, true );
		wp_enqueue_style( 'atora-hybrid-planner', plugins_url( 'assets/css/atora-hybrid-planner.css', ATORA_LMS_FILE ), array(), $version );

		$rubrics = get_posts( array(
			'post_type'      => 'clms_rubric',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'author'         => current_user_can( 'edit_others_lm_lessons' ) ? '' : get_current_user_id(),
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		wp_localize_script( 'atora-hybrid-planner', 'atoraPlanner', array(
			'root'    => esc_url_raw( rest_url( ATORA_Hybrid_Plan_REST::NS . '/hybrid-plans' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'userId'  => get_current_user_id(),
			'empty'   => ATORA_Hybrid_Plan_Service::empty_data(),
			'rubrics' => array_map( static fn( $p ) => array( 'id' => (int) $p->ID, 'title' => $p->post_title ), $rubrics ),
			'i18n'    => self::strings(),
		) );
	}

	public static function render(): void {
		echo '<div class="wrap atora-planner-wrap"><h1>' . esc_html__( 'Planificación híbrida', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Prepara una semana o unidad: encuentro presencial, trabajo a distancia, recursos, evidencia y acompañamiento. Al publicar se crean (o actualizan) las lecciones del curso.', 'atora-lms' ) . '</p>';
		echo '<div id="atora-planner" aria-live="polite"><p>' . esc_html__( 'Cargando…', 'atora-lms' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'Esta pantalla necesita JavaScript.', 'atora-lms' ) . '</p></noscript></div>';
	}

	/** @return array<string,string> */
	private static function strings(): array {
		return array(
			'course'          => __( 'Curso', 'atora-lms' ),
			'chooseCourse'    => __( 'Elige un curso', 'atora-lms' ),
			'noCourses'       => __( 'No tienes cursos donde puedas planificar.', 'atora-lms' ),
			'plans'           => __( 'Planificaciones', 'atora-lms' ),
			'newPlan'         => __( 'Nueva planificación', 'atora-lms' ),
			'noPlans'         => __( 'Todavía no hay planificaciones en este curso.', 'atora-lms' ),
			'open'            => __( 'Abrir', 'atora-lms' ),
			'duplicate'       => __( 'Duplicar', 'atora-lms' ),
			'back'            => __( '← Volver a la lista', 'atora-lms' ),
			'draft'           => __( 'Borrador', 'atora-lms' ),
			'published'       => __( 'Publicada', 'atora-lms' ),
			'unpublished'     => __( 'Publicada · con cambios sin publicar', 'atora-lms' ),
			'stepObjective'   => __( 'Objetivo', 'atora-lms' ),
			'stepInPerson'    => __( 'Encuentro presencial', 'atora-lms' ),
			'stepRemote'      => __( 'Trabajo a distancia', 'atora-lms' ),
			'stepResources'   => __( 'Recursos', 'atora-lms' ),
			'stepEvidence'    => __( 'Evidencia', 'atora-lms' ),
			'stepSupport'     => __( 'Acompañamiento', 'atora-lms' ),
			'stepPreview'     => __( 'Vista previa y publicar', 'atora-lms' ),
			'title'           => __( 'Tema o título', 'atora-lms' ),
			'weekLabel'       => __( 'Semana o unidad (opcional)', 'atora-lms' ),
			'objective'       => __( 'Qué debe aprender el estudiante', 'atora-lms' ),
			'date'            => __( 'Fecha', 'atora-lms' ),
			'time'            => __( 'Hora', 'atora-lms' ),
			'place'           => __( 'Lugar', 'atora-lms' ),
			'activities'      => __( 'Qué se trabajará en el aula', 'atora-lms' ),
			'before'          => __( 'Antes del encuentro', 'atora-lms' ),
			'after'           => __( 'Después del encuentro', 'atora-lms' ),
			'addResource'     => __( 'Agregar recurso', 'atora-lms' ),
			'remove'          => __( 'Quitar', 'atora-lms' ),
			'type'            => __( 'Tipo', 'atora-lms' ),
			'url'             => __( 'Enlace (URL)', 'atora-lms' ),
			'file'            => __( 'Archivo', 'atora-lms' ),
			'chooseFile'      => __( 'Elegir archivo', 'atora-lms' ),
			'transcript'      => __( 'Transcripción (audio)', 'atora-lms' ),
			'typeLink'        => __( 'Enlace', 'atora-lms' ),
			'typeFile'        => __( 'Archivo', 'atora-lms' ),
			'typePdf'         => __( 'PDF / lectura', 'atora-lms' ),
			'typeAudio'       => __( 'Audio (MP3, M4A u OGG)', 'atora-lms' ),
			'typeVideo'       => __( 'Video', 'atora-lms' ),
			'evidenceEnabled' => __( 'Esta semana hay una evidencia o entrega', 'atora-lms' ),
			'evidenceTitle'   => __( 'Qué entregará', 'atora-lms' ),
			'instructions'    => __( 'Instrucciones', 'atora-lms' ),
			'dueDate'         => __( 'Fecha límite', 'atora-lms' ),
			'rubric'          => __( 'Rúbrica (opcional)', 'atora-lms' ),
			'noRubric'        => __( 'Sin rúbrica', 'atora-lms' ),
			'criteria'        => __( 'Criterios de evaluación', 'atora-lms' ),
			'evidenceLocked'  => __( 'Como asistente puedes preparar la planificación, pero la evidencia y su evaluación las define el docente.', 'atora-lms' ),
			'supportHow'      => __( 'Cómo recibirá orientación y retroalimentación', 'atora-lms' ),
			'supportWhen'     => __( 'Cuándo (horario, plazo de respuesta)', 'atora-lms' ),
			'prev'            => __( 'Anterior', 'atora-lms' ),
			'next'            => __( 'Siguiente', 'atora-lms' ),
			'saveServer'      => __( 'Guardar', 'atora-lms' ),
			'savedLocal'      => __( 'Borrador guardado en este navegador · %s (aún no en el servidor)', 'atora-lms' ),
			'savedServer'     => __( 'Guardado en el servidor · %s', 'atora-lms' ),
			'saving'          => __( 'Guardando…', 'atora-lms' ),
			'saveFailed'      => __( 'No se pudo guardar en el servidor. Tu borrador sigue en este navegador; vuelve a intentarlo.', 'atora-lms' ),
			'conflict'        => __( 'Otra persona guardó esta planificación. Recarga para ver su versión (tu borrador sigue en este navegador).', 'atora-lms' ),
			'reload'          => __( 'Recargar', 'atora-lms' ),
			'recoverQ'        => __( 'Hay un borrador en este navegador más reciente que lo guardado en el servidor.', 'atora-lms' ),
			'recover'         => __( 'Recuperar mi borrador', 'atora-lms' ),
			'discard'         => __( 'Descartar', 'atora-lms' ),
			'preview'         => __( 'Ver vista previa', 'atora-lms' ),
			'previewIntro'    => __( 'Al publicar, en el curso:', 'atora-lms' ),
			'willCreate'      => __( 'Se crea', 'atora-lms' ),
			'willUpdate'      => __( 'Se actualiza', 'atora-lms' ),
			'willKeep'        => __( 'Se mantiene (ya no está en la planificación; quítala a mano si hace falta)', 'atora-lms' ),
			'publish'         => __( 'Publicar en el curso', 'atora-lms' ),
			'publishing'      => __( 'Publicando…', 'atora-lms' ),
			'publishedOk'     => __( 'Publicado. Lecciones creadas o actualizadas: %s', 'atora-lms' ),
			'cannotPublish'   => __( 'Solo el docente del curso puede publicar. Déjala guardada para que la revise.', 'atora-lms' ),
			'saveFirst'       => __( 'Guarda los cambios antes de la vista previa.', 'atora-lms' ),
			'rubricKept'      => __( 'La evidencia ya tiene entregas: se mantuvo su rúbrica.', 'atora-lms' ),
			'aiSuggest'       => __( 'Sugerir con IA', 'atora-lms' ),
			'aiUse'           => __( 'Usar esta sugerencia', 'atora-lms' ),
			'aiNote'          => __( 'Sugerencia de la IA: revísala y edítala antes de guardar. No se publica sola.', 'atora-lms' ),
			'aiFailed'        => __( 'La IA no pudo sugerir. La planificación funciona igual sin ella.', 'atora-lms' ),
			'error'           => __( 'Ocurrió un error. Intenta de nuevo.', 'atora-lms' ),
		);
	}
}
