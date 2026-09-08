/* global wp, ATORA_EDITOR_TEMPLATES */
(function () {
	'use strict';

	var config = window.ATORA_EDITOR_TEMPLATES || {};
	var templates = config.templates || [];
	var labels = config.labels || {};

	if (!templates.length || typeof wp === 'undefined' || !wp.blocks || !wp.data) {
		return;
	}

	var parse = wp.blocks.parse;
	var dispatch = wp.data.dispatch;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (text) { return text; };
	var el = wp.element && wp.element.createElement;
	var Fragment = wp.element && wp.element.Fragment;
	var useState = wp.element && wp.element.useState;
	var Button = wp.components && wp.components.Button;
	var PluginDocumentSettingPanel = wp.editPost && wp.editPost.PluginDocumentSettingPanel;
	var PluginSidebar = wp.editPost && wp.editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = wp.editPost && wp.editPost.PluginSidebarMoreMenuItem;
	var sidebarRegistered = false;

	function ready(callback) {
		if (wp.domReady) {
			wp.domReady(callback);
			return;
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}

		callback();
	}

	function isInserterTabs(tabs) {
		var text = (tabs.textContent || '').toLowerCase();
		return (
			(text.indexOf('bloques') !== -1 && text.indexOf('patrones') !== -1) ||
			(text.indexOf('blocks') !== -1 && text.indexOf('patterns') !== -1)
		);
	}

	function findContentPanel(tabs) {
		var panel = tabs.parentElement;

		while (panel && !panel.querySelector('.components-tab-panel__tab-content')) {
			panel = panel.parentElement;
			if (panel && panel.classList && panel.classList.contains('interface-interface-skeleton')) {
				break;
			}
		}

		return panel ? panel.querySelector('.components-tab-panel__tab-content') : null;
	}

	function nativeTabs(tabs) {
		return Array.prototype.slice.call(tabs.querySelectorAll('button, [role="tab"]')).filter(function (button) {
			return !button.classList.contains('atora-editor-templates-tab');
		});
	}

	function ensureTemplateTab(tabs) {
		if (!tabs || tabs.dataset.atoraTemplatesReady === '1' || !isInserterTabs(tabs)) {
			return;
		}

		var firstTab = nativeTabs(tabs)[0];
		if (!firstTab) {
			return;
		}

		var tab = document.createElement('button');
		tab.type = 'button';
		tab.className = firstTab.className + ' atora-editor-templates-tab';
		tab.textContent = labels.tab || 'Templates';
		tab.setAttribute('role', 'tab');
		tab.setAttribute('aria-selected', 'false');

		tab.addEventListener('click', function () {
			activateTemplateTab(tabs, tab);
		});

		nativeTabs(tabs).forEach(function (button) {
			button.addEventListener('click', function () {
				deactivateTemplateTab(tabs, tab);
			});
		});

		tabs.appendChild(tab);
		tabs.dataset.atoraTemplatesReady = '1';
	}

	function activateTemplateTab(tabs, tab) {
		var content = findContentPanel(tabs);
		if (!content) {
			return;
		}

		nativeTabs(tabs).forEach(function (button) {
			button.setAttribute('aria-selected', 'false');
			button.classList.remove('is-active');
		});

		tab.setAttribute('aria-selected', 'true');
		tab.classList.add('is-active');
		content.dataset.atoraHidden = '1';
		content.style.display = 'none';

		var panel = getOrCreatePanel(tabs, content);
		panel.hidden = false;
		renderTemplatePanel(panel, '');
	}

	function deactivateTemplateTab(tabs, tab) {
		var content = findContentPanel(tabs);
		var panel = getPanel(tabs);

		tab.setAttribute('aria-selected', 'false');
		tab.classList.remove('is-active');

		if (content && content.dataset.atoraHidden === '1') {
			content.style.display = '';
			delete content.dataset.atoraHidden;
		}

		if (panel) {
			panel.hidden = true;
		}
	}

	function panelIdForTabs(tabs) {
		if (!tabs.dataset.atoraTemplatesId) {
			tabs.dataset.atoraTemplatesId = 'atora-editor-templates-' + Math.random().toString(16).slice(2);
		}

		return tabs.dataset.atoraTemplatesId;
	}

	function getPanel(tabs) {
		return document.getElementById(panelIdForTabs(tabs));
	}

	function getOrCreatePanel(tabs, content) {
		var panel = getPanel(tabs);

		if (panel) {
			return panel;
		}

		panel = document.createElement('div');
		panel.id = panelIdForTabs(tabs);
		panel.className = 'atora-editor-templates-panel';
		panel.hidden = true;
		content.parentNode.insertBefore(panel, content.nextSibling);

		return panel;
	}

	function renderTemplatePanel(panel, query) {
		var normalizedQuery = (query || '').toLowerCase();
		var filtered = filteredTemplates(normalizedQuery);

		panel.innerHTML = '';

		var search = document.createElement('input');
		search.type = 'search';
		search.className = 'atora-editor-templates-search';
		search.placeholder = labels.search || __('Buscar templates', 'atora-lms');
		search.value = query || '';
		search.addEventListener('input', function () {
			renderTemplatePanel(panel, search.value);
		});
		panel.appendChild(search);

			var help = document.createElement('p');
			help.className = 'atora-editor-templates-help';
			help.textContent = labels.help || __('Tambien estan disponibles en Patrones > Atora Theme.', 'atora-lms');
			panel.appendChild(help);

		if (!filtered.length) {
			var empty = document.createElement('p');
			empty.className = 'atora-editor-templates-empty';
			empty.textContent = labels.empty || __('No hay templates con ese filtro.', 'atora-lms');
			panel.appendChild(empty);
			return;
		}

		groupByType(filtered).forEach(function (group) {
			var title = document.createElement('h3');
			title.className = 'atora-editor-templates-group-title';
			title.textContent = group.type;
			panel.appendChild(title);

			var list = document.createElement('div');
			list.className = 'atora-editor-templates-grid';
			group.items.forEach(function (template) {
				list.appendChild(templateCard(template));
			});
			panel.appendChild(list);
		});
	}

	function groupByType(items) {
		var groups = [];
		var index = {};

		items.forEach(function (item) {
			var type = item.type || __('Templates', 'atora-lms');
			if (!index[type]) {
				index[type] = { type: type, items: [] };
				groups.push(index[type]);
			}
			index[type].items.push(item);
		});

		return groups;
	}

	function filteredTemplates(query) {
		var normalizedQuery = (query || '').toLowerCase();

		return templates.filter(function (template) {
			var haystack = [template.title, template.description, template.type].join(' ').toLowerCase();
			return !normalizedQuery || haystack.indexOf(normalizedQuery) !== -1;
		});
	}

	function actionButton(primary, text, onClick) {
		if (Button) {
			return el(Button, { variant: primary ? 'primary' : 'secondary', onClick: onClick }, text);
		}

		return el(
			'button',
			{ type: 'button', className: primary ? 'components-button is-primary' : 'components-button is-secondary', onClick: onClick },
			text
		);
	}

	function TemplateBrowser(props) {
		var state = useState('');
		var query = state[0];
		var setQuery = state[1];
		var compact = props && props.compact;
		var filtered = filteredTemplates(query);

		return el(
			'div',
			{ className: compact ? 'atora-editor-templates-panel atora-editor-templates-panel--compact' : 'atora-editor-templates-panel' },
			el('input', {
				type: 'search',
				className: 'atora-editor-templates-search',
				placeholder: labels.search || __('Buscar templates', 'atora-lms'),
				value: query,
				onChange: function (event) {
					setQuery(event.target.value);
				},
			}),
			el('p', { className: 'atora-editor-templates-help' }, labels.help || __('Tambien estan disponibles en Patrones > Atora Theme.', 'atora-lms')),
			!filtered.length
				? el('p', { className: 'atora-editor-templates-empty' }, labels.empty || __('No hay templates con ese filtro.', 'atora-lms'))
				: groupByType(filtered).map(function (group) {
					return el(
						'section',
						{ key: group.type, className: 'atora-editor-templates-group' },
						el('h3', { className: 'atora-editor-templates-group-title' }, group.type),
						el(
							'div',
							{ className: 'atora-editor-templates-grid' },
							group.items.map(function (template) {
								return templateCardView(template, compact);
							})
						)
					);
				})
		);
	}

	function templateCardView(template, compact) {
		return el(
			'article',
			{ key: template.id, className: 'atora-editor-template-card atora-editor-template-card--' + (template.tone || 'light') },
			compact ? null : el(
				'div',
				{ className: 'atora-editor-template-card__preview' },
				el('span', null),
				el('span', null),
				el('span', null)
			),
			el('span', { className: 'atora-editor-template-card__type' }, template.type || 'Template'),
			el('h4', null, template.title),
			el('p', null, template.description || ''),
			el(
				'div',
				{ className: 'atora-editor-template-card__actions' },
				actionButton(true, labels.insert || __('Insertar', 'atora-lms'), function () {
					insertTemplate(template, false);
				}),
				actionButton(false, labels.replace || __('Reemplazar contenido', 'atora-lms'), function () {
					insertTemplate(template, true);
				})
			)
		);
	}

	function registerTemplateSidebar() {
		if (sidebarRegistered || !el || !useState || !wp.plugins || !wp.plugins.registerPlugin || !wp.editPost) {
			return;
		}

		sidebarRegistered = true;

		wp.plugins.registerPlugin('atora-editor-templates', {
			render: function () {
				return el(
					Fragment,
					null,
					PluginDocumentSettingPanel
						? el(
								PluginDocumentSettingPanel,
								{
									name: 'atora-editor-templates-panel',
									title: labels.panel || __('Atora Theme', 'atora-lms'),
									className: 'atora-editor-templates-settings-panel',
								},
								el(TemplateBrowser, { compact: true })
						)
						: null,
					PluginSidebar && PluginSidebarMoreMenuItem
						? el(
							Fragment,
							null,
								el(PluginSidebarMoreMenuItem, {
									target: 'atora-editor-templates-sidebar',
									icon: 'layout',
								}, labels.panel || __('Atora Theme', 'atora-lms')),
								el(
									PluginSidebar,
									{
										name: 'atora-editor-templates-sidebar',
										title: labels.panel || __('Atora Theme', 'atora-lms'),
										icon: 'layout',
									},
									el(TemplateBrowser, null)
								)
						)
						: null
				);
			},
		});
	}

	function templateCard(template) {
		var card = document.createElement('article');
		card.className = 'atora-editor-template-card atora-editor-template-card--' + (template.tone || 'light');

		var preview = document.createElement('div');
		preview.className = 'atora-editor-template-card__preview';
		preview.innerHTML = '<span></span><span></span><span></span>';

		var type = document.createElement('span');
		type.className = 'atora-editor-template-card__type';
		type.textContent = template.type || 'Template';

		var title = document.createElement('h4');
		title.textContent = template.title;

		var description = document.createElement('p');
		description.textContent = template.description || '';

		var actions = document.createElement('div');
		actions.className = 'atora-editor-template-card__actions';

		var insert = document.createElement('button');
		insert.type = 'button';
		insert.className = 'components-button is-primary';
		insert.textContent = labels.insert || __('Insertar', 'atora-lms');
		insert.addEventListener('click', function () {
			insertTemplate(template, false);
		});

		var replace = document.createElement('button');
		replace.type = 'button';
		replace.className = 'components-button is-secondary';
		replace.textContent = labels.replace || __('Reemplazar contenido', 'atora-lms');
		replace.addEventListener('click', function () {
			insertTemplate(template, true);
		});

		actions.appendChild(insert);
		actions.appendChild(replace);

		card.appendChild(preview);
		card.appendChild(type);
		card.appendChild(title);
		card.appendChild(description);
		card.appendChild(actions);

		return card;
	}

	function insertTemplate(template, replace) {
		var blocks = parse(template.content || '');
		if (!blocks.length) {
			return;
		}

		var blockEditor = dispatch('core/block-editor');

		if (replace) {
			if (window.confirm(labels.confirm || __('Esto reemplazara los bloques actuales. Continuar?', 'atora-lms'))) {
				blockEditor.resetBlocks(blocks);
			}
			return;
		}

		blockEditor.insertBlocks(blocks);
	}

	function scan() {
		Array.prototype.slice.call(document.querySelectorAll('.components-tab-panel__tabs')).forEach(ensureTemplateTab);
	}

	ready(function () {
		registerTemplateSidebar();
		scan();
		var observer = new MutationObserver(scan);
		observer.observe(document.body, { childList: true, subtree: true });
	});
}());
