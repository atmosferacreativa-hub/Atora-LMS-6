<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Loader_Module_Groups_Trait {
	protected function get_module_groups() {
		$groups = array(
			'core' => array(
				array(
					'file'         => 'includes/class-access.php',
					'class'        => 'CLMS_Access',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/class-security.php',
					'class'        => 'ATORA_Security',
					'dependencies' => array( 'CLMS_Access' ),
				),
				array(
					'file'         => 'includes/class-email-gateway.php',
					'class'        => 'ATORA_Email_Gateway',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/class-shortcodes.php',
					'class'        => 'CLMS_Shortcodes',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_CPT' ),
				),
				array(
					'file'         => 'includes/class-submission.php',
					'class'        => 'CLMS_Submission',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_CPT', 'CLMS_Lesson' ),
				),
				array(
					'file'         => 'includes/class-grading.php',
					'class'        => 'CLMS_Grading',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Submission' ),
				),
				array(
					'file'         => 'includes/class-assessment-engine.php',
					'class'        => 'CLMS_Assessment_Engine',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Submission', 'CLMS_Grading' ),
				),
				array(
					'file'         => 'includes/class-clms-db-migration.php',
					'class'        => 'CLMS_DB_Migration',
					'dependencies' => array(),
				),
				array(
					// PT-1 (6.11.0): sin condicionamiento a ningún módulo --
					// carga siempre, sea cual sea el estado del módulo 'crm'.
					// Ver el docblock de la clase para el porqué.
					'file'         => 'includes/contacts-core/class-contacts-core-service.php',
					'class'        => 'CLMS_Contacts_Core_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-clms-grading-engine.php',
					'class'        => 'CLMS_Grading_Engine',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Assessment_Engine' ),
				),
				array(
					'file'         => 'includes/class-clms-feedback-loop.php',
					'class'        => 'CLMS_Feedback_Loop',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Assessment_Engine' ),
				),
				array(
					'file'         => 'includes/academic/class-competency-service.php',
					'class'        => 'CLMS_Competency_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/academic/class-evidence-service.php',
					'class'        => 'CLMS_Evidence_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Competency_Service' ),
				),
				array(
					'file'         => 'includes/academic/class-academic-status-service.php',
					'class'        => 'CLMS_Academic_Status_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Grading', 'CLMS_Competency_Service', 'CLMS_Evidence_Service' ),
				),
				array(
					'file'         => 'includes/academic/class-improvement-plan-service.php',
					'class'        => 'CLMS_Improvement_Plan_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/academic/class-academic-diagnostics-service.php',
					'class'        => 'CLMS_Academic_Diagnostics_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Competency_Service', 'CLMS_Evidence_Service' ),
				),
				array(
					'file'         => 'includes/cohorts/class-cohort-service.php',
					'class'        => 'CLMS_Cohort_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/academic/class-academic-report-service.php',
					'class'        => 'CLMS_Academic_Report_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Status_Service', 'CLMS_Academic_Diagnostics_Service' ),
				),
				array(
					'file'         => 'includes/academic/class-student-activity-tracker.php',
					'class'        => 'CLMS_Student_Activity_Tracker',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/academic/class-student-inactivity-reminder-service.php',
					'class'        => 'CLMS_Student_Inactivity_Reminder_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Student_Activity_Tracker', 'CLMS_Email' ),
				),
				array(
					'file'         => 'includes/academic/class-assignment-due-reminder-service.php',
					'class'        => 'CLMS_Assignment_Due_Reminder_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/academic/class-teacher-digest-service.php',
					'class'        => 'CLMS_Teacher_Digest_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/library/class-academic-library-policy.php',
					'class'        => 'CLMS_Academic_Library_Policy',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/library/class-academic-library-service.php',
					'class'        => 'CLMS_Academic_Library_Service',
					'dependencies' => array( 'CLMS_Academic_Library_Policy', 'CLMS_Competency_Service', 'CLMS_Evidence_Service' ),
				),
				array(
					'file'         => 'includes/library/class-academic-library-rest-controller.php',
					'class'        => 'CLMS_Academic_Library_REST_Controller',
					'dependencies' => array( 'CLMS_Academic_Library_Service' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-normalizer.php',
					'class'        => 'CLMS_Gradebook_Normalizer',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/gradebook/class-academic-status-map.php',
					'class'        => 'CLMS_Academic_Status_Map',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/gradebook/class-institutional-gradebook-policy.php',
					'class'        => 'CLMS_Institutional_Gradebook_Policy',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/gradebook/class-institutional-gradebook-service.php',
					'class'        => 'CLMS_Institutional_Gradebook_Service',
					'dependencies' => array( 'CLMS_Institutional_Gradebook_Policy' ),
				),
				array(
					'file'         => 'includes/gradebook/class-institutional-gradebook-rest-controller.php',
					'class'        => 'CLMS_Institutional_Gradebook_REST_Controller',
					'dependencies' => array( 'CLMS_Institutional_Gradebook_Service' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-audit-service.php',
					'class'        => 'CLMS_Gradebook_Audit_Service',
					'dependencies' => array( 'CLMS_Academic_Status_Map', 'CLMS_Gradebook_Bridge_Service' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-schema-service.php',
					'class'        => 'CLMS_Gradebook_Schema_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-calculation-service.php',
					'class'        => 'CLMS_Gradebook_Calculation_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Grading' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-grid-service.php',
					'class'        => 'CLMS_Gradebook_Grid_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Assessment_Engine', 'CLMS_Gradebook_Schema_Service', 'CLMS_Gradebook_Calculation_Service' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-service.php',
					'class'        => 'CLMS_Gradebook_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Gradebook_Grid_Service', 'CLMS_Gradebook_Calculation_Service', 'CLMS_Gradebook_Schema_Service' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-save-service.php',
					'class'        => 'CLMS_Gradebook_Save_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Assessment_Engine', 'CLMS_Grading_Engine' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-intelligence-service.php',
					'class'        => 'CLMS_Gradebook_Intelligence_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Gradebook_Service' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-bridge-service.php',
					'class'        => 'CLMS_Gradebook_Bridge_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Grading_Engine' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-certificate-eligibility-service.php',
					'class'        => 'CLMS_Gradebook_Certificate_Eligibility_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Gradebook_Service' ),
				),
				array(
					'file'         => 'includes/gradebook/class-gradebook-export-service.php',
					'class'        => 'CLMS_Gradebook_Export_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Gradebook_Service', 'CLMS_Gradebook_Certificate_Eligibility_Service' ),
				),
				array(
					'file'         => 'includes/admin/class-admin-operations-service.php',
					'class'        => 'CLMS_Admin_Operations_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Report_Service', 'CLMS_Academic_Status_Service' ),
				),
				array(
					'file'         => 'includes/academic/class-academic-wizard-service.php',
					'class'        => 'CLMS_Academic_Wizard_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Competency_Service' ),
				),
				array(
					'file'         => 'includes/academic/class-academic-wizard-step-renderer.php',
					'class'        => 'CLMS_Academic_Wizard_Step_Renderer',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Diagnostics_Service', 'CLMS_Evidence_Service', 'CLMS_Competency_Service' ),
				),
				array(
					'file'         => 'includes/academic/class-academic-wizard-step-save-service.php',
					'class'        => 'CLMS_Academic_Wizard_Step_Save_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Evidence_Service' ),
				),
				array(
					'file'         => 'includes/dashboard/class-student-dashboard-data.php',
					'class'        => 'CLMS_Student_Dashboard_Data',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Status_Service' ),
				),
				array(
					'file'         => 'includes/dashboard/class-student-next-step-service.php',
					'class'        => 'CLMS_Student_Next_Step_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Status_Service' ),
				),
				array(
					'file'         => 'includes/dashboard/class-teacher-dashboard-data.php',
					'class'        => 'CLMS_Teacher_Dashboard_Data',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/dashboard/class-teacher-priority-queue-service.php',
					'class'        => 'CLMS_Teacher_Priority_Queue_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/today/class-today-aggregator-service.php',
					'class'        => 'CLMS_Today_Aggregator_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/today/class-activity-feed-service.php',
					'class'        => 'CLMS_UI_Activity_Feed_Service',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/speedgrade/class-speedgrade-context-service.php',
					'class'        => 'CLMS_Speedgrade_Context_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Status_Service', 'CLMS_Improvement_Plan_Service' ),
				),
				array(
					'file'         => 'includes/class-clms-quick-wins.php',
					'class'        => 'CLMS_Quick_Wins',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-progress.php',
					'class'        => 'CLMS_Progress',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Grading' ),
				),
				array(
					'file'         => 'includes/modularity/class-modularity-config-service.php',
					'class'        => 'CLMS_Modularity_Config_Service',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/gamification/class-gamification-rules.php',
					'class'        => 'CLMS_Gamification_Rules',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'module:gamification',
				),
				array(
					'file'         => 'includes/gamification/class-gamification-summary-service.php',
					'class'        => 'CLMS_Gamification_Summary_Service',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'module:gamification',
				),
				array(
					'file'         => 'includes/class-gamification-core.php',
					'class'        => 'CLMS_Gamification_Core',
					'dependencies' => array( 'CLMS_Gamification_Rules' ),
					'condition'    => 'module:gamification',
				),
				array(
					'file'         => 'includes/gamification/class-student-badge-service.php',
					'class'        => 'CLMS_Student_Badge_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Status_Service' ),
					'condition'    => 'module:gamification',
				),
			),

			'experience' => array(
				array(
					'file'         => 'includes/class-dashboard.php',
					'class'        => 'CLMS_Dashboard',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-teacher-dashboard.php',
					'class'        => 'CLMS_Teacher_Dashboard',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Grading', 'CLMS_Submission' ),
				),
				array(
					'file'         => 'includes/class-notifications.php',
					'class'        => 'CLMS_Notifications',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-messaging.php',
					'class'        => 'CLMS_Messaging',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Notifications' ),
				),
				array(
					'file'         => 'includes/class-lesson-sidebar.php',
					'class'        => 'CLMS_Lesson_Sidebar',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Lesson' ),
				),
				array(
					'file'         => 'includes/class-quiz.php',
					'class'        => 'CLMS_Quiz',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Lesson' ),
				),
				array(
					'file'         => 'includes/class-frontend.php',
					'class'        => 'CLMS_Frontend',
					'dependencies' => array( 'CLMS_Helper' ),
				),
			),

			'integrations' => array(
				array(
					'file'         => 'includes/class-rest-api.php',
					'class'        => 'CLMS_REST_API',
					'dependencies' => array( 'CLMS_Helper' ),
				),

				// Nueva rama commerce.
					array(
						'file'         => 'includes/commerce/class-commerce.php',
						'class'        => 'CLMS_Commerce',
						'dependencies' => array( 'CLMS_Helper' ),
					),

				// Compatibilidad + metabox producto/curso.
				array(
					'file'         => 'includes/class-woocommerce.php',
					'class'        => 'CLMS_WooCommerce',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'woocommerce',
				),

				array(
					'file'         => 'includes/class-drip.php',
					'class'        => 'CLMS_Drip',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-peer-review.php',
					'class'        => 'CLMS_Peer_Review',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Submission' ),
				),
				array(
					'file'         => 'includes/credentials/class-credential-policy.php',
					'class'        => 'CLMS_Credential_Policy',
					'dependencies' => array(),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/credentials/class-credential-service.php',
					'class'        => 'CLMS_Credential_Service',
					'dependencies' => array( 'CLMS_Credential_Policy' ),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/credentials/class-credential-rest-controller.php',
					'class'        => 'CLMS_Credential_REST_Controller',
					'dependencies' => array( 'CLMS_Credential_Service' ),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/credentials/class-credential-qr.php',
					'class'        => 'CLMS_Credential_QR',
					'dependencies' => array( 'CLMS_Credential_Service' ),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/certificates/class-certificate-rules.php',
					'class'        => 'CLMS_Certificate_Rules',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/certificates/class-program-certificate-service.php',
					'class'        => 'CLMS_Program_Certificate_Service',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Certificate_Rules' ),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/certificates/class-certificate-presentation-service.php',
					'class'        => 'CLMS_Certificate_Presentation_Service',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/class-certificates.php',
					'class'        => 'CLMS_Certificates',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Certificate_Rules', 'CLMS_Program_Certificate_Service', 'CLMS_Certificate_Presentation_Service' ),
					'condition'    => 'module:certificates',
				),
				array(
					'file'         => 'includes/class-student-profile.php',
					'class'        => 'CLMS_Student_Profile',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-ai-knowledge-base.php',
					'class'        => 'CLMS_AI_Knowledge_Base',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Transcription' ),
					'condition'    => 'module:ai',
				),
				array(
					'file'         => 'includes/class-ai-copilots.php',
					'class'        => 'CLMS_AI_Copilots',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'module:ai',
				),
				array(
					'file'         => 'includes/class-ai-log.php',
					'class'        => 'CLMS_AI_Log',
					'dependencies' => array(),
					'condition'    => 'module:ai',
				),
				array(
					'file'         => 'includes/class-student-assistant.php',
					'class'        => 'CLMS_Student_Assistant',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_AI_Knowledge_Base' ),
					'condition'    => 'module:ai',
				),
				array(
					'file'         => 'includes/class-settings.php',
					'class'        => 'CLMS_Settings',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-ai-alerts.php',
					'class'        => 'CLMS_AI_Alerts',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'module:ai',
				),
				array(
					'file'         => 'includes/class-ai-grading.php',
					'class'        => 'CLMS_AI_Grading',
					'dependencies' => array( 'CLMS_Helper' ),
					'condition'    => 'module:ai',
				),
				array(
					'file'         => 'includes/class-student-memory.php',
					'class'        => 'CLMS_Student_Memory',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-learning-path.php',
					'class'        => 'CLMS_Learning_Path',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Student_Memory' ),
				),
				array(
					'file'         => 'includes/class-sentiment.php',
					'class'        => 'CLMS_Sentiment',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-whisper-live.php',
					'class'        => 'CLMS_Whisper_Live',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-analytics.php',
					'class'        => 'CLMS_Analytics',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-admin-menu.php',
					'class'        => 'CLMS_Admin_Menu',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/academic/class-academic-admin-tools.php',
					'class'        => 'CLMS_Academic_Admin_Tools',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Academic_Report_Service', 'CLMS_Academic_Wizard_Service', 'CLMS_Academic_Wizard_Step_Renderer', 'CLMS_Academic_Wizard_Step_Save_Service' ),
				),
				array(
					'file'         => 'includes/class-instructor.php',
					'class'        => 'CLMS_Instructor',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-enrollment-manager.php',
					'class'        => 'CLMS_Enrollment_Manager',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/class-maintenance.php',
					'class'        => 'CLMS_Maintenance',
					'dependencies' => array( 'CLMS_Helper' ),
				),
			),

			'ai' => array(
				array(
					'file'         => 'includes/class-ai-manager.php',
					'class'        => 'CLMS_AI_Manager',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/ai/class-ai-provider-interface.php',
					'class'        => 'CLMS_AI_Provider_Interface',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/ai/class-ai-provider-base.php',
					'class'        => 'CLMS_AI_Provider_Base',
					'dependencies' => array(),
				),
				array(
					'file'         => 'includes/ai/class-ai-provider-openai.php',
					'class'        => 'CLMS_AI_Provider_OpenAI',
					'dependencies' => array( 'CLMS_AI_Provider_Base' ),
				),
				array(
					'file'         => 'includes/ai/class-ai-provider-anthropic.php',
					'class'        => 'CLMS_AI_Provider_Anthropic',
					'dependencies' => array( 'CLMS_AI_Provider_Base' ),
				),
				array(
					'file'         => 'includes/ai/class-ai-provider-gemini.php',
					'class'        => 'CLMS_AI_Provider_Gemini',
					'dependencies' => array( 'CLMS_AI_Provider_Base' ),
				),
				array(
					'file'         => 'includes/ai/class-ai-extractor.php',
					'class'        => 'CLMS_AI_Extractor',
					'dependencies' => array( 'CLMS_Helper' ),
				),
				array(
					'file'         => 'includes/ai/class-ai-admin.php',
					'class'        => 'CLMS_AI_Admin',
					'dependencies' => array( 'CLMS_Settings' ),
				),
				array(
					'file'         => 'includes/ai/class-ai.php',
					'class'        => 'CLMS_AI',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_Submission', 'CLMS_AI_Extractor' ),
				),
				array(
					'file'         => 'includes/class-ai-exams.php',
					'class'        => 'CLMS_AI_Exams',
					'dependencies' => array( 'CLMS_Helper', 'CLMS_AI' ),
				),
			),
		);

		// PT-2 (6.3.0): todo el grupo 'ai' cuelga del módulo 'ai' del registro.
		foreach ( $groups['ai'] as &$module ) {
			if ( ! isset( $module['condition'] ) ) {
				$module['condition'] = 'module:ai';
			}
		}
		unset( $module );

		return $groups;
	}

}
