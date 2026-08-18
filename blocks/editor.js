/* global wp */
(function () {
	'use strict';

	var blocks = wp.blocks;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var __ = wp.i18n.__;

	var InspectorControls = blockEditor.InspectorControls;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;
	var RichText = blockEditor.RichText;
	var useBlockProps = blockEditor.useBlockProps || function (props) { return props || {}; };

	var Button = components.Button;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;

	var CATEGORY = 'atora-lms';

	function editable(tagName, className, value, onChange, placeholder, allowedFormats) {
		return el(RichText, {
			tagName: tagName,
			className: className,
			value: value || '',
			onChange: onChange,
			placeholder: placeholder,
			allowedFormats: [],
		});
	}

	function sectionHeader(attributes, setAttributes, options) {
		options = options || {};

		return el(
			'div',
			{ className: 'atora-premium-editor__header' },
			editable('p', 'atora-premium-editor__eyebrow', attributes.eyebrow, function (value) {
				setAttributes({ eyebrow: value });
			}, __('Etiqueta superior', 'atora-lms')),
			editable('h2', 'atora-premium-editor__title', attributes.title, function (value) {
				setAttributes({ title: value });
			}, __('Título de la sección', 'atora-lms'), ['core/bold', 'core/italic']),
			options.subtitle ? editable('p', 'atora-premium-editor__subtitle', attributes.subtitle, function (value) {
				setAttributes({ subtitle: value });
			}, __('Subtítulo', 'atora-lms'), ['core/bold', 'core/italic']) : null
		);
	}

	function alignOptions() {
		return [
			{ label: __('Izquierda', 'atora-lms'), value: 'left' },
			{ label: __('Centro', 'atora-lms'), value: 'center' },
			{ label: __('Derecha', 'atora-lms'), value: 'right' },
		];
	}

	function imageShapeOptions() {
		return [
			{ label: __('Redondeada', 'atora-lms'), value: 'rounded' },
			{ label: __('Vertical', 'atora-lms'), value: 'vertical' },
			{ label: __('Cuadrada', 'atora-lms'), value: 'square' },
			{ label: __('Circular', 'atora-lms'), value: 'round' },
		];
	}

	function cloneItems(items) {
		return (items || []).map(function (item) {
			return Object.assign({}, item);
		});
	}

	function defaultItems(kind) {
		if (kind === 'faq') {
			return [
				{ question: __('¿Cómo se edita?', 'atora-lms'), answer: __('Directamente en el bloque, igual que cualquier bloque visual de Gutenberg.', 'atora-lms') },
				{ question: __('¿Cuántas preguntas puedo poner?', 'atora-lms'), answer: __('Las que necesites para aclarar la propuesta.', 'atora-lms') },
			];
		}

		if (kind === 'instructor') {
			return [
				{ name: __('Docente principal', 'atora-lms'), role: __('Especialista ATORA', 'atora-lms'), bio: __('Autoridad visible, bio breve y enfoque premium.', 'atora-lms'), image_url: '' },
			];
		}

		if (kind === 'curriculum') {
			return [
				{ title: __('Módulo 1', 'atora-lms'), text: __('Introducción y base conceptual.', 'atora-lms') },
				{ title: __('Módulo 2', 'atora-lms'), text: __('Aplicación práctica y seguimiento.', 'atora-lms') },
			];
		}

		return [];
	}

	function itemsFor(attributes, kind) {
		var items = cloneItems(attributes.items);
		return items.length ? items : cloneItems(defaultItems(kind));
	}

	function updateItem(attributes, setAttributes, kind, index, key, value) {
		var items = itemsFor(attributes, kind);
		items[index][key] = value;
		setAttributes({ items: items });
	}

	function addItem(attributes, setAttributes, kind) {
		var items = itemsFor(attributes, kind);
		var next;

		if (kind === 'faq') {
			next = { question: __('Nueva pregunta', 'atora-lms'), answer: __('Respuesta breve.', 'atora-lms') };
		} else if (kind === 'instructor') {
			next = { name: __('Nuevo docente', 'atora-lms'), role: __('Rol o especialidad', 'atora-lms'), bio: __('Bio breve.', 'atora-lms'), image_url: '' };
		} else {
			next = { title: __('Nuevo módulo', 'atora-lms'), text: __('Descripción breve.', 'atora-lms') };
		}

		items.push(next);
		setAttributes({ items: items });
	}

	function removeItem(attributes, setAttributes, kind, index) {
		var items = itemsFor(attributes, kind);
		items.splice(index, 1);
		setAttributes({ items: items.length ? items : defaultItems(kind) });
	}

	function mediaButton(value, onSelect, label) {
		if (!MediaUpload || !MediaUploadCheck) {
			return el(TextControl, {
				label: label,
				value: value || '',
				onChange: onSelect,
			});
		}

		return el(
			MediaUploadCheck,
			null,
			el(MediaUpload, {
				allowedTypes: ['image'],
				value: value || '',
				onSelect: function (media) {
					onSelect(media && media.url ? media.url : '');
				},
				render: function (obj) {
					return el(Button, { variant: 'secondary', onClick: obj.open }, label);
				},
			})
		);
	}

	function actions(attributes, setAttributes, mode) {
		if (mode === 'single') {
			return el(
				'div',
				{ className: 'atora-premium-editor-actions' },
				editable('span', 'atora-premium-editor-button is-primary', attributes.label, function (value) {
					setAttributes({ label: value });
				}, __('Texto del botón', 'atora-lms'))
			);
		}

		return el(
			'div',
			{ className: 'atora-premium-editor-actions' },
			editable('span', 'atora-premium-editor-button is-primary', attributes.primary_label, function (value) {
				setAttributes({ primary_label: value });
			}, __('CTA principal', 'atora-lms')),
			editable('span', 'atora-premium-editor-button is-secondary', attributes.secondary_label, function (value) {
				setAttributes({ secondary_label: value });
			}, __('CTA secundaria', 'atora-lms'))
		);
	}

	function heroEdit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps({
			className: 'atora-premium-editor-preview is-hero is-align-' + (attributes.align || 'left'),
		});
		var mediaPreview = attributes.media_url
			? el('img', { src: attributes.media_url, alt: '' })
			: el('div', { className: 'atora-premium-editor__media-placeholder' }, __('Añade imagen o video', 'atora-lms'));

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Ajustes del hero', 'atora-lms'), initialOpen: true },
					el(SelectControl, { label: __('Alineación', 'atora-lms'), value: attributes.align, options: alignOptions(), onChange: function (value) { setAttributes({ align: value }); } }),
					el(SelectControl, { label: __('Tipo de media', 'atora-lms'), value: attributes.media_type, options: [
						{ label: __('Imagen', 'atora-lms'), value: 'image' },
						{ label: __('Video', 'atora-lms'), value: 'video' },
						{ label: __('Comentario', 'atora-lms'), value: 'comment' },
					], onChange: function (value) { setAttributes({ media_type: value }); } }),
					el(SelectControl, { label: __('Forma de imagen', 'atora-lms'), value: attributes.image_shape, options: imageShapeOptions(), onChange: function (value) { setAttributes({ image_shape: value }); } }),
					el(TextControl, { label: __('URL media', 'atora-lms'), value: attributes.media_url, onChange: function (value) { setAttributes({ media_url: value }); } }),
					el(TextControl, { label: __('URL CTA principal', 'atora-lms'), value: attributes.primary_url, onChange: function (value) { setAttributes({ primary_url: value }); } }),
					el(TextControl, { label: __('URL CTA secundaria', 'atora-lms'), value: attributes.secondary_url, onChange: function (value) { setAttributes({ secondary_url: value }); } })
				)
			),
			el(
				'section',
				blockProps,
				el(
					'div',
					{ className: 'atora-premium-editor atora-premium-editor--hero' },
					sectionHeader(attributes, setAttributes, { subtitle: true }),
					actions(attributes, setAttributes),
					el(
						'div',
						{ className: 'atora-premium-editor-media is-' + (attributes.image_shape || 'rounded') },
						mediaPreview,
						el('div', { className: 'atora-premium-editor-media__actions' }, mediaButton(attributes.media_url, function (value) {
							setAttributes({ media_url: value });
						}, attributes.media_url ? __('Cambiar imagen', 'atora-lms') : __('Elegir imagen', 'atora-lms')))
					)
				)
			)
		);
	}

	function cardListEdit(kind, props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var items = itemsFor(attributes, kind);
		var title = kind === 'faq' ? __('Ajustes de FAQ', 'atora-lms') : kind === 'instructor' ? __('Ajustes de docentes', 'atora-lms') : __('Ajustes de contenido', 'atora-lms');
		var blockProps = useBlockProps({
			className: 'atora-premium-editor-preview is-list is-' + kind + ' is-align-' + (attributes.align || 'left'),
		});

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: title, initialOpen: true },
					el(SelectControl, { label: __('Alineación', 'atora-lms'), value: attributes.align, options: alignOptions(), onChange: function (value) { setAttributes({ align: value }); } }),
					kind === 'instructor' ? el(SelectControl, { label: __('Estilo de tarjeta', 'atora-lms'), value: attributes.card_style, options: imageShapeOptions(), onChange: function (value) { setAttributes({ card_style: value }); } }) : null,
					el(Button, { variant: 'primary', onClick: function () { addItem(attributes, setAttributes, kind); } }, __('Agregar item', 'atora-lms'))
				)
			),
			el(
				'section',
				blockProps,
				el(
					'div',
					{ className: 'atora-premium-editor' },
					sectionHeader(attributes, setAttributes),
					renderItems(kind, attributes, setAttributes, items)
				)
			)
		);
	}

	function renderItems(kind, attributes, setAttributes, items) {
		if (kind === 'faq') {
			return el(
				'div',
				{ className: 'atora-premium-list' },
				items.map(function (item, index) {
					return el(
						'div',
						{ className: 'atora-premium-faq', key: index },
						editable('h3', 'atora-premium-faq__question', item.question, function (value) {
							updateItem(attributes, setAttributes, kind, index, 'question', value);
						}, __('Pregunta', 'atora-lms'), ['core/bold', 'core/italic']),
						editable('p', 'atora-premium-faq__answer', item.answer, function (value) {
							updateItem(attributes, setAttributes, kind, index, 'answer', value);
						}, __('Respuesta', 'atora-lms'), ['core/bold', 'core/italic']),
						el(Button, { className: 'atora-premium-editor-remove', isDestructive: true, onClick: function () { removeItem(attributes, setAttributes, kind, index); } }, __('Eliminar', 'atora-lms'))
					);
				})
			);
		}

		if (kind === 'instructor') {
			return el(
				'div',
				{ className: 'atora-premium-grid' },
				items.map(function (item, index) {
					return el(
						'article',
						{ className: 'atora-premium-card', key: index },
						item.image_url ? el('img', { src: item.image_url, alt: '' }) : el('div', { className: 'atora-premium-card__placeholder' }, __('Imagen', 'atora-lms')),
						el('div', { className: 'atora-premium-card__media-control' }, mediaButton(item.image_url, function (value) {
							updateItem(attributes, setAttributes, kind, index, 'image_url', value);
						}, item.image_url ? __('Cambiar imagen', 'atora-lms') : __('Elegir imagen', 'atora-lms'))),
						editable('h3', 'atora-premium-card__title', item.name, function (value) {
							updateItem(attributes, setAttributes, kind, index, 'name', value);
						}, __('Nombre', 'atora-lms'), ['core/bold', 'core/italic']),
						editable('p', 'atora-premium-card__role', item.role, function (value) {
							updateItem(attributes, setAttributes, kind, index, 'role', value);
						}, __('Rol', 'atora-lms')),
						editable('p', 'atora-premium-card__bio', item.bio, function (value) {
							updateItem(attributes, setAttributes, kind, index, 'bio', value);
						}, __('Bio breve', 'atora-lms'), ['core/bold', 'core/italic']),
						el(Button, { className: 'atora-premium-editor-remove', isDestructive: true, onClick: function () { removeItem(attributes, setAttributes, kind, index); } }, __('Eliminar', 'atora-lms'))
					);
				})
			);
		}

		return el(
			'ol',
			{ className: 'atora-premium-timeline' },
			items.map(function (item, index) {
				return el(
					'li',
					{ key: index },
					editable('strong', 'atora-premium-timeline__title', item.title, function (value) {
						updateItem(attributes, setAttributes, kind, index, 'title', value);
					}, __('Título del módulo', 'atora-lms'), ['core/bold', 'core/italic']),
					editable('p', 'atora-premium-timeline__text', item.text, function (value) {
						updateItem(attributes, setAttributes, kind, index, 'text', value);
					}, __('Descripción breve', 'atora-lms'), ['core/bold', 'core/italic']),
					el(Button, { className: 'atora-premium-editor-remove', isDestructive: true, onClick: function () { removeItem(attributes, setAttributes, kind, index); } }, __('Eliminar', 'atora-lms'))
				);
			})
		);
	}

	function ctaEdit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps({
			className: 'atora-premium-editor-preview is-cta is-align-' + (attributes.align || 'center'),
		});

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Ajustes de CTA', 'atora-lms'), initialOpen: true },
					el(SelectControl, { label: __('Alineación', 'atora-lms'), value: attributes.align, options: alignOptions(), onChange: function (value) { setAttributes({ align: value }); } }),
					el(TextControl, { label: __('URL', 'atora-lms'), value: attributes.url, onChange: function (value) { setAttributes({ url: value }); } })
				)
			),
			el(
				'section',
				blockProps,
				el(
					'div',
					{ className: 'atora-premium-editor' },
					sectionHeader(attributes, setAttributes),
					actions(attributes, setAttributes, 'single')
				)
			)
		);
	}

	function templateEdit() {
		var blockProps = useBlockProps({
			className: 'atora-premium-editor-preview is-template',
		});

		return el(
			'section',
			blockProps,
			el('div', { className: 'atora-premium-editor__eyebrow' }, __('ATORA Premium', 'atora-lms')),
			el('h2', { className: 'atora-premium-editor__title' }, __('Template completo', 'atora-lms')),
			el('p', { className: 'atora-premium-editor__subtitle' }, __('El template se renderiza con hero, contenido, docentes, FAQ y CTA desde el engine de ATORA.', 'atora-lms'))
		);
	}

	function register(name, title, edit, icon) {
		blocks.registerBlockType(CATEGORY + '/' + name, {
			title: title,
			icon: icon,
			category: CATEGORY,
			supports: {
				html: false,
				inserter: false,
			},
			edit: edit,
			save: function () {
				return null;
			},
		});
	}

	register('section-hero', __('ATORA Premium: Hero', 'atora-lms'), heroEdit, 'cover-image');
	register('section-faq', __('ATORA Premium: FAQ', 'atora-lms'), function (props) { return cardListEdit('faq', props); }, 'editor-help');
	register('section-instructor', __('ATORA Premium: Docentes', 'atora-lms'), function (props) { return cardListEdit('instructor', props); }, 'groups');
	register('section-curriculum', __('ATORA Premium: Contenido', 'atora-lms'), function (props) { return cardListEdit('curriculum', props); }, 'list-view');
	register('section-cta', __('ATORA Premium: CTA', 'atora-lms'), ctaEdit, 'megaphone');
	register('course-template', __('ATORA Premium: Template completo', 'atora-lms'), templateEdit, 'layout');
}());
