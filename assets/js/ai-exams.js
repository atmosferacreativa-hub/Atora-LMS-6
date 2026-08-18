/**
 * ATORA-LMS - AI Exams UI
 * 
 * Handles quiz generation interface in WordPress admin
 * 
 * @author @mundocap
 * @package ATORA_LMS
 * @version 1.0.0
 */

(function( $ ) {
    'use strict';

    const CLMSAIExams = {
        i18n: window.clmsAiExamsI18n || {},
        
        nonce: window.clms_nonce || '',
        apiRoot: window.wp.api.settings.root || '/wp-json/',
        currentCourseId: 0,

		t( key, fallback ) {
			return this.i18n[ key ] || fallback;
		},
        
        init() {
            this.cacheElements();
            this.bindEvents();
            this.loadQuizzes();
        },
        
        cacheElements() {
            this.$container = $( '.clms-ai-exams-container' );
            this.$uploadForm = this.$container.find( '.clms-upload-form' );
            this.$contentInput = this.$container.find( 'textarea[name="content"]' );
            this.$generateBtn = this.$container.find( '.btn-generate-quiz' );
            this.$quizzesList = this.$container.find( '.clms-quizzes-list' );
            this.$loading = this.$container.find( '.clms-loading' );
            this.$error = this.$container.find( '.clms-error-message' );
            this.$success = this.$container.find( '.clms-success-message' );
        },
        
        bindEvents() {
            this.$generateBtn.on( 'click', ( e ) => this.handleGenerate( e ) );
            this.$container.on( 'click', '.btn-preview-quiz', ( e ) => this.handlePreview( e ) );
            this.$container.on( 'click', '.btn-save-quiz', ( e ) => this.handleSave( e ) );
            this.$container.on( 'click', '.btn-delete-quiz', ( e ) => this.handleDelete( e ) );
        },
        
        /**
         * Generate Quiz from Content
         */
        handleGenerate( e ) {
            e.preventDefault();
            
            const content = this.$contentInput.val();
            const courseId = this.currentCourseId;
            const count = parseInt( this.$container.find( 'input[name="quiz_count"]' ).val() || 10 );
            const difficulty = this.$container.find( 'select[name="quiz_difficulty"]' ).val() || 'mixed';
            
            // Validation
            if ( ! content.trim() ) {
                this.showError( this.t( 'content_required', 'Por favor ingresa contenido (video, PDF o texto)' ) );
                return;
            }
            
            if ( ! courseId ) {
                this.showError( this.t( 'course_required', 'Por favor selecciona un curso' ) );
                return;
            }
            
            // Disable button and show loading
            this.$generateBtn.prop( 'disabled', true ).text( this.t( 'generating_quiz', 'Generando quiz...' ) );
            this.$loading.show();
            this.clearMessages();
            
            // Make API request
            const request = {
                content: content,
                course_id: courseId,
                count: count,
                difficulty: difficulty
            };
            
            $.ajax( {
                url: this.apiRoot + 'clms/v1/ai/generate',
                type: 'POST',
                headers: {
                    'X-WP-Nonce': this.nonce,
                    'Content-Type': 'application/json'
                },
                data: JSON.stringify( request ),
                success: ( response ) => this.handleGenerateSuccess( response ),
                error: ( xhr ) => this.handleGenerateError( xhr )
            } );
        },
        
        /**
         * Handle Generate Success
         */
        handleGenerateSuccess( response ) {
            this.$loading.hide();
            this.$generateBtn.prop( 'disabled', false ).text( this.t( 'generate_quiz', 'Generar Quiz' ) );
            
            if ( response.success ) {
                this.showSuccess( this.t( 'quiz_generated', 'Quiz generado exitosamente con %1$d preguntas. Costo: $%2$s' )
                    .replace( '%1$d', String( response.count ) )
                    .replace( '%2$s', String( response.cost.toFixed(2) ) ) );
                
                // Show preview
                this.showPreview( response.quiz_id, response.questions );
                
                // Reload quizzes list
                setTimeout( () => this.loadQuizzes(), 1000 );
            } else {
                this.showError( response.data || this.t( 'generate_error', 'Error generando quiz' ) );
            }
        },
        
        /**
         * Handle Generate Error
         */
        handleGenerateError( xhr ) {
            this.$loading.hide();
            this.$generateBtn.prop( 'disabled', false ).text( this.t( 'generate_quiz', 'Generar Quiz' ) );
            
            let errorMsg = this.t( 'generate_error', 'Error generando quiz' );
            
            try {
                const response = JSON.parse( xhr.responseText );
                errorMsg = response.message || response.data || errorMsg;
            } catch( e ) {
                errorMsg = xhr.statusText || errorMsg;
            }
            
            this.showError( errorMsg );
        },
        
        /**
         * Show Quiz Preview
         */
        showPreview( quizId, questions ) {
            let html = `
                <div class="clms-quiz-preview" data-quiz-id="${quizId}">
                    <h3>${this.escapeHtml( this.t( 'quiz_preview', 'Previsualización de Quiz' ) )}</h3>
                    <div class="quiz-preview-content">
            `;
            
            questions.forEach( ( q, index ) => {
                html += `
                    <div class="question-preview">
                        <div class="question-number"><strong>${this.escapeHtml( this.t( 'question_prefix', 'Pregunta' ) )} ${index + 1}:</strong></div>
                        <div class="question-text">${this.escapeHtml( q.question )}</div>
                        <div class="question-difficulty">
                            <span class="difficulty-badge difficulty-${q.difficulty}">
                                ${this.translateDifficulty( q.difficulty )}
                            </span>
                        </div>
                        <div class="question-options">
                `;
                
                q.options.forEach( ( option ) => {
                    const isCorrect = option.startsWith( q.correct_answer );
                    const class_name = isCorrect ? 'correct-option' : '';
                    html += `<div class="option ${class_name}">${this.escapeHtml( option )}</div>`;
                } );
                
                html += `
                        </div>
                        <div class="question-explanation">
                            <strong>${this.escapeHtml( this.t( 'explanation', 'Explicación' ) )}:</strong> ${this.escapeHtml( q.explanation )}
                        </div>
                    </div>
                `;
            } );
            
            html += `
                    </div>
                    <div class="quiz-preview-actions">
                        <button class="btn btn-primary btn-save-quiz" data-quiz-id="${quizId}">
                            ${this.escapeHtml( this.t( 'save_quiz', 'Guardar Quiz' ) )}
                        </button>
                        <button class="btn btn-secondary btn-delete-quiz" data-quiz-id="${quizId}">
                            ${this.escapeHtml( this.t( 'discard', 'Descartar' ) )}
                        </button>
                    </div>
                </div>
            `;
            
            this.$container.find( '.clms-quiz-preview' ).remove();
            this.$container.append( html );
            
            // Scroll to preview
            $( 'html, body' ).animate( {
                scrollTop: this.$container.find( '.clms-quiz-preview' ).offset().top - 100
            }, 300 );
        },
        
        /**
         * Handle Preview
         */
        handlePreview( e ) {
            e.preventDefault();
            
            const quizId = $( e.target ).data( 'quiz-id' );
            
            $.ajax( {
                url: this.apiRoot + `clms/v1/ai/quiz/${quizId}`,
                type: 'GET',
                headers: {
                    'X-WP-Nonce': this.nonce
                },
                success: ( response ) => {
                    this.showPreview( quizId, response.questions );
                },
                error: () => {
                    this.showError( this.t( 'load_quiz_error', 'Error cargando quiz' ) );
                }
            } );
        },
        
        /**
         * Handle Save
         */
        handleSave( e ) {
            e.preventDefault();
            
            const quizId = $( e.target ).data( 'quiz-id' );
            
            $.ajax( {
                url: this.apiRoot + 'clms/v1/ai/quiz/' + quizId,
                type: 'POST',
                headers: {
                    'X-WP-Nonce': this.nonce,
                    'Content-Type': 'application/json'
                },
                data: JSON.stringify( { action: 'save' } ),
                success: ( response ) => {
                    if ( response.success ) {
                        this.showSuccess( this.t( 'quiz_saved', 'Quiz guardado exitosamente' ) );
                        this.$container.find( '.clms-quiz-preview' ).remove();
                        this.loadQuizzes();
                    } else {
                        this.showError( this.t( 'save_quiz_error', 'Error guardando quiz' ) );
                    }
                },
                error: () => {
                    this.showError( this.t( 'save_quiz_error', 'Error guardando quiz' ) );
                }
            } );
        },
        
        /**
         * Handle Delete
         */
        handleDelete( e ) {
            e.preventDefault();
            
            const quizId = $( e.target ).data( 'quiz-id' );
            
            if ( ! confirm( this.t( 'confirm_delete_quiz', '¿Estás seguro de que deseas eliminar este quiz?' ) ) ) {
                return;
            }
            
            $.ajax( {
                url: this.apiRoot + `clms/v1/ai/quiz/${quizId}`,
                type: 'DELETE',
                headers: {
                    'X-WP-Nonce': this.nonce
                },
                success: ( response ) => {
                    if ( response.success ) {
                        this.showSuccess( this.t( 'quiz_deleted', 'Quiz eliminado' ) );
                        this.loadQuizzes();
                    } else {
                        this.showError( this.t( 'delete_quiz_error', 'Error eliminando quiz' ) );
                    }
                },
                error: () => {
                    this.showError( this.t( 'delete_quiz_error', 'Error eliminando quiz' ) );
                }
            } );
        },
        
        /**
         * Load Quizzes List
         */
        loadQuizzes() {
            if ( ! this.currentCourseId ) {
                return;
            }
            
            $.ajax( {
                url: this.apiRoot + `clms/v1/ai/quizzes/${this.currentCourseId}`,
                type: 'GET',
                headers: {
                    'X-WP-Nonce': this.nonce
                },
                success: ( response ) => {
                    this.renderQuizzesList( response );
                },
                error: () => {
                    this.showError( this.t( 'load_quizzes_error', 'Error cargando quizzes' ) );
                }
            } );
        },
        
        /**
         * Render Quizzes List
         */
        renderQuizzesList( quizzes ) {
            if ( quizzes.length === 0 ) {
                this.$quizzesList.html( '<p class="no-quizzes">' + this.escapeHtml( this.t( 'no_quizzes', 'No hay quizzes generados aún' ) ) + '</p>' );
                return;
            }
            
            let html = '<table class="clms-quizzes-table"><thead><tr>';
            html += '<th>' + this.escapeHtml( this.t( 'title', 'Título' ) ) + '</th><th>' + this.escapeHtml( this.t( 'questions', 'Preguntas' ) ) + '</th><th>' + this.escapeHtml( this.t( 'cost', 'Costo' ) ) + '</th><th>' + this.escapeHtml( this.t( 'reviewed', 'Revisado' ) ) + '</th><th>' + this.escapeHtml( this.t( 'actions', 'Acciones' ) ) + '</th>';
            html += '</tr></thead><tbody>';
            
            quizzes.forEach( ( quiz ) => {
                const reviewed = quiz.reviewed ? '✓' : '✗';
                const reviewClass = quiz.reviewed ? 'reviewed' : 'pending';
                
                html += `
                    <tr class="quiz-row ${reviewClass}">
                        <td>${this.escapeHtml( quiz.title )}</td>
                        <td>${quiz.count || '-'}</td>
                        <td>$${parseFloat( quiz.cost ).toFixed( 2 )}</td>
                        <td>${reviewed}</td>
                        <td>
                            <button class="btn btn-small btn-preview-quiz" data-quiz-id="${quiz.id}">
                                ${this.escapeHtml( this.t( 'preview', 'Vista Previa' ) )}
                            </button>
                            <button class="btn btn-small btn-delete-quiz" data-quiz-id="${quiz.id}">
                                ${this.escapeHtml( this.t( 'delete', 'Eliminar' ) )}
                            </button>
                        </td>
                    </tr>
                `;
            } );
            
            html += '</tbody></table>';
            this.$quizzesList.html( html );
        },
        
        /**
         * Show Error
         */
        showError( message ) {
            this.$error.text( message ).show();
            setTimeout( () => this.$error.fadeOut(), 5000 );
        },
        
        /**
         * Show Success
         */
        showSuccess( message ) {
            this.$success.text( message ).show();
            setTimeout( () => this.$success.fadeOut(), 5000 );
        },
        
        /**
         * Clear Messages
         */
        clearMessages() {
            this.$error.hide();
            this.$success.hide();
        },
        
        /**
         * Escape HTML
         */
        escapeHtml( text ) {
            const div = document.createElement( 'div' );
            div.textContent = text;
            return div.innerHTML;
        },
        
        /**
         * Translate Difficulty
         */
        translateDifficulty( difficulty ) {
            const map = {
                'easy': this.t( 'difficulty_easy', 'Fácil' ),
                'medium': this.t( 'difficulty_medium', 'Media' ),
                'hard': this.t( 'difficulty_hard', 'Difícil' ),
                'mixed': this.t( 'difficulty_mixed', 'Mixta' )
            };
            
            return map[ difficulty ] || difficulty;
        }
    };
    
    /**
     * Initialize on document ready
     */
    $( document ).ready( function() {
        if ( $( '.clms-ai-exams-container' ).length ) {
            CLMSAIExams.init();
        }
    } );

})( jQuery );
