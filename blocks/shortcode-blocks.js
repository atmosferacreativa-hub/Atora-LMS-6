/* global wp, ATORA_SHORTCODE_BLOCKS */
(function () {
	'use strict';

	var config = window.ATORA_SHORTCODE_BLOCKS || {};
	var blockConfigs = config.blocks || [];

	if (!blockConfigs.length) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var blocks = wp.blocks;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var __ = wp.i18n.__;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps || function (props) { return props || {}; };
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var ServerSideRender = wp.serverSideRender;

	function getAttrs(attributes, shortcodeConfig) {
		return Object.assign({}, shortcodeConfig.defaults || {}, attributes.attrs || {});
	}

	function updateAttr(attributes, setAttributes, shortcodeConfig, key, value) {
		var attrs = getAttrs(attributes, shortcodeConfig);
		attrs[key] = value;
		setAttributes({ attrs: attrs });
	}

	function renderField(field, value, onChange) {
		var common = {
			key: field.key,
			label: field.label,
			value: value === undefined || value === null ? '' : String(value),
			onChange: onChange,
		};

		if (field.type === 'textarea') {
			return el(TextareaControl, common);
		}

		if (field.type === 'toggle') {
			return el(ToggleControl, {
				key: field.key,
				label: field.label,
				checked: value === true || value === '1' || value === 1 || value === 'true',
				onChange: function (checked) {
					onChange(checked ? '1' : '0');
				},
			});
		}

		if (field.type === 'select') {
			return el(SelectControl, Object.assign(common, {
				options: field.options || [],
			}));
		}

		if (field.type === 'number') {
			return el(TextControl, Object.assign(common, { type: 'number' }));
		}

		if (field.type === 'url') {
			return el(TextControl, Object.assign(common, { type: 'url' }));
		}

		return el(TextControl, common);
	}

	function shortcodeText(shortcodeConfig, attributes) {
		var attrs = getAttrs(attributes, shortcodeConfig);
		var parts = [];

		(shortcodeConfig.fields || []).forEach(function (field) {
			var value = attrs[field.key];
			if (value === undefined || value === null || String(value) === '') {
				return;
			}
			parts.push(field.key + '="' + String(value).replace(/"/g, '&quot;') + '"');
		});

		if (attributes.extraAttrs) {
			parts.push(attributes.extraAttrs);
		}

		var attrText = parts.length ? ' ' + parts.join(' ') : '';

		if (shortcodeConfig.hasContent) {
			return '[' + shortcodeConfig.tag + attrText + ']' + (attributes.content || '') + '[/' + shortcodeConfig.tag + ']';
		}

		return '[' + shortcodeConfig.tag + attrText + ']';
	}

	function editFactory(shortcodeConfig) {
		return function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var attrs = getAttrs(attributes, shortcodeConfig);
			var blockProps = useBlockProps({
				className: 'atora-shortcode-block-editor',
			});

			function renderExtraControls() {
				var controls = [
					el(TextareaControl, {
						key: 'extraAttrs',
						label: __('Atributos adicionales', 'atora-lms'),
						help: __('Opcional. Ejemplo: foo="bar" otra="1".', 'atora-lms'),
						value: attributes.extraAttrs || '',
						onChange: function (value) {
							setAttributes({ extraAttrs: value });
						},
					}),
				];

				if (shortcodeConfig.hasContent) {
					controls.push(el(TextareaControl, {
						key: 'content',
						label: __('Contenido interno', 'atora-lms'),
						value: attributes.content || '',
						onChange: function (value) {
							setAttributes({ content: value });
						},
					}));
				}

				return controls;
			}

			var inspectorFields = (shortcodeConfig.fields || []).map(function (field) {
				return renderField(field, attrs[field.key], function (value) {
					updateAttr(attributes, setAttributes, shortcodeConfig, field.key, value);
				});
			});
			var preview = ServerSideRender
				? el(
					'div',
					{ className: 'atora-shortcode-block-editor__preview' },
					el(ServerSideRender, {
						block: shortcodeConfig.name,
						attributes: attributes,
						EmptyResponsePlaceholder: function () {
							return el('p', { className: 'atora-shortcode-block-editor__preview-empty' }, __('El shortcode no devolvio contenido para esta configuracion.', 'atora-lms'));
						},
						ErrorResponsePlaceholder: function () {
							return el('p', { className: 'atora-shortcode-block-editor__preview-empty' }, __('No se pudo renderizar la vista previa en el editor.', 'atora-lms'));
						},
					})
				)
				: null;

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: shortcodeConfig.title, initialOpen: true },
						inspectorFields,
						renderExtraControls(),
						el('code', { className: 'atora-shortcode-block-editor__code' }, shortcodeText(shortcodeConfig, attributes))
					)
				),
				el(
					'section',
					blockProps,
					el(
						'div',
						{ className: 'atora-shortcode-block-editor__toolbar' },
						el('span', { className: 'atora-shortcode-block-editor__eyebrow' }, __('ATORA LMS', 'atora-lms')),
						el('strong', { className: 'atora-shortcode-block-editor__title' }, shortcodeConfig.title)
					),
					preview,
					!preview ? el('code', { className: 'atora-shortcode-block-editor__code' }, shortcodeText(shortcodeConfig, attributes)) : null
				)
			);
		};
	}

	blockConfigs.forEach(function (shortcodeConfig) {
		blocks.registerBlockType(shortcodeConfig.name, {
			title: shortcodeConfig.title,
			description: shortcodeConfig.description,
			icon: shortcodeConfig.icon || 'shortcode',
			category: config.category || 'atora-lms',
			attributes: {
				attrs: {
					type: 'object',
					default: shortcodeConfig.defaults || {},
				},
				className: {
					type: 'string',
					default: '',
				},
				style: {
					type: 'object',
				},
				textColor: {
					type: 'string',
				},
				backgroundColor: {
					type: 'string',
				},
				fontSize: {
					type: 'string',
				},
				extraAttrs: {
					type: 'string',
					default: '',
				},
				content: {
					type: 'string',
					default: '',
				},
			},
			supports: {
				html: false,
				inserter: shortcodeConfig.inserter !== false,
				color: {
					text: true,
					background: true,
				},
				typography: {
					fontSize: true,
				},
				spacing: {
					margin: true,
					padding: true,
				},
			},
			edit: editFactory(shortcodeConfig),
			save: function () {
				return null;
			},
		});
	});
}());
