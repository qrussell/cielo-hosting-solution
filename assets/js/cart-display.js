( function () {
	'use strict';

	var blocksCheckout = window.wc && window.wc.blocksCheckout;
	if ( ! blocksCheckout || typeof blocksCheckout.registerCheckoutFilters !== 'function' ) {
		return;
	}

	function getSubsData( arg ) {
		if ( arg && arg.skyhs && arg.skyhs.billing_period ) {
			return arg.skyhs;
		}
		if ( arg && arg.extensions && arg.extensions.skyhs && arg.extensions.skyhs.billing_period ) {
			return arg.extensions.skyhs;
		}
		return null;
	}

	function appendPeriod( price, subs ) {
		if ( ! subs ) return price;
		var period = 1 === subs.billing_interval
			? ' / ' + subs.billing_period
			: ' / ' + subs.billing_interval + ' ' + subs.billing_period + 's';
		return price + period;
	}

	blocksCheckout.registerCheckoutFilters( 'skyhs-hosting-solution', {
		saleBadgePriceFormat: function ( price, extensionsOrCartItem, contextOrArgs ) {
			var subs = getSubsData( extensionsOrCartItem );
			if ( ! subs && contextOrArgs && contextOrArgs.cartItem ) {
				subs = getSubsData( contextOrArgs.cartItem );
			}
			return appendPeriod( price, subs );
		},

		cartItemPrice: function ( price, extensionsOrCartItem, contextOrArgs ) {
			var subs = getSubsData( extensionsOrCartItem );
			if ( ! subs && contextOrArgs && contextOrArgs.cartItem ) {
				subs = getSubsData( contextOrArgs.cartItem );
			}
			return appendPeriod( price, subs );
		}
	} );
} )();
