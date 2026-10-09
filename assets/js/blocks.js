(function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settingsApi ) {
		return;
	}

	var h = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var s = settingsApi.getSetting( '24pay_gateway_data', {} );
	var title = decode( s.title || '24-pay' );
	var description = decode( s.description || '' );

	var Label = function () {
		return h(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
			title,
			s.icon ? h( 'img', { src: s.icon, alt: title, style: { maxHeight: '24px' } } ) : null
		);
	};

	var Content = function () {
		return h( 'div', null, description );
	};

	registry.registerPaymentMethod( {
		name: '24pay_gateway',
		label: h( Label ),
		content: h( Content ),
		edit: h( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: { features: s.supports || [ 'products' ] },
	} );
})();

