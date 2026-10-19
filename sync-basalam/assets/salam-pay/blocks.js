( function() {
	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	var settings = window.wc.wcSettings.getSetting( 'salampay_data', {} );
	var decodeEntities = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ? window.wp.htmlEntities.decodeEntities : function( value ) {
		return value;
	};
	var el = window.wp.element.createElement;
	var label = decodeEntities( settings.title || 'پرداخت با سلام پی' );
	var description = decodeEntities( settings.description || '' );
	var icon = settings.icon ? el( 'img', {
		src: settings.icon,
		alt: '',
		style: {
			maxHeight: '22px',
			width: 'auto',
			marginLeft: '8px',
			verticalAlign: 'middle'
		}
	} ) : null;
	var labelElement = el( 'span', null, icon, label );
	var content = el( 'div', null, description );

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'salampay',
		label: labelElement,
		content: content,
		edit: content,
		canMakePayment: function() {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
} )();
