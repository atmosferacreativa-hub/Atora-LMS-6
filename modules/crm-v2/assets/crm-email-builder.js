(function () {
	const EMAIL_BLOCKS = {
		text: { icon: 'ti-text-size', label: 'Parrafo', defaultContent: 'Escribe tu mensaje aqui...' },
		heading: { icon: 'ti-heading', label: 'Encabezado', defaultContent: 'Titulo de seccion' },
		button: { icon: 'ti-click', label: 'Boton CTA', defaultContent: 'Ver ahora', defaultUrl: '' },
		divider: { icon: 'ti-line', label: 'Separador', defaultContent: '' },
		image: { icon: 'ti-photo', label: 'Imagen (URL)', defaultContent: '' },
		spacer: { icon: 'ti-arrows-vertical', label: 'Espacio', defaultContent: '' }
	};

	const DYNAMIC_VARS = [
		{ key: '{{nombre}}', label: 'Nombre del contacto' },
		{ key: '{{email}}', label: 'Email' },
		{ key: '{{curso}}', label: 'Nombre del curso' },
		{ key: '{{progreso}}', label: 'Porcentaje de progreso' },
		{ key: '{{fecha}}', label: 'Fecha actual' },
		{ key: '{{plataforma}}', label: 'Nombre de la plataforma' },
		{ key: '{{url_curso}}', label: 'URL del curso' },
		{ key: '{{url_login}}', label: 'URL de acceso' }
	];

	window.atoraEmailBuilder = window.atoraEmailBuilder || {
		EMAIL_BLOCKS,
		DYNAMIC_VARS
	};
})();
