/* global module */
( function ( root ) {
	'use strict';

	var cacheKeyPrefix = 'wcpay_appearance_';
	var fontRuleDomains = [
		'fonts.googleapis.com',
		'fonts.gstatic.com',
		'use.typekit.net',
		'fonts.bunny.net',
		'fonts.wp.com',
	];
	var colorFunctionPatterns = [
		/color\(\s*srgb\s+[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?(?:\s*\/\s*[+-]?\d*\.?\d+%?)?\s*\)/gi,
		/rgba?\(\s*[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?(?:\s*\/\s*[+-]?\d*\.?\d+%?)?\s*\)/gi,
		/rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0?(\.\d+)?|1?(\.0+)?))?\s*\)/gi,
	];

	function getCacheKey( location ) {
		return cacheKeyPrefix + location;
	}

	function parseNumber( value ) {
		var number = Number.parseFloat( value );
		return Number.isNaN( number ) ? null : number;
	}

	function parseRgbChannel( value ) {
		var number = parseNumber( value );
		if ( number === null ) {
			return null;
		}

		return value.trim().slice( -1 ) === '%'
			? ( number / 100 ) * 255
			: number;
	}

	function parseSrgbChannel( value ) {
		var number = parseNumber( value );
		if ( number === null ) {
			return null;
		}

		return value.trim().slice( -1 ) === '%'
			? ( number / 100 ) * 255
			: number * 255;
	}

	function parseAlpha( value ) {
		var number;
		var alpha;

		if ( value === undefined ) {
			return 1;
		}

		number = parseNumber( value );
		if ( number === null ) {
			return 1;
		}

		alpha = value.trim().slice( -1 ) === '%' ? number / 100 : number;
		return Math.max( 0, Math.min( 1, alpha ) );
	}

	function buildParsedColor( channels, alpha ) {
		return {
			r: channels[ 0 ],
			g: channels[ 1 ],
			b: channels[ 2 ],
			a: alpha === undefined ? 1 : alpha,
		};
	}

	function parseColor( color ) {
		var value = String( color || '' ).trim();
		var rgbMatch = value.match(
			/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0?(\.\d+)?|1?(\.0+)?))?\s*\)$/i
		);
		var modernRgbMatch;
		var srgbMatch;
		var hexMatch;
		var hex;

		if ( rgbMatch ) {
			return buildParsedColor(
				rgbMatch.slice( 1, 4 ).map( function ( channel ) {
					return Number( channel );
				} ),
				parseAlpha( rgbMatch[ 4 ] )
			);
		}

		modernRgbMatch = value.match(
			/^rgba?\(\s*([+-]?\d*\.?\d+%?)\s+([+-]?\d*\.?\d+%?)\s+([+-]?\d*\.?\d+%?)(?:\s*\/\s*([+-]?\d*\.?\d+%?))?\s*\)$/i
		);
		if ( modernRgbMatch ) {
			return buildParsedColor(
				modernRgbMatch
					.slice( 1, 4 )
					.map( parseRgbChannel )
					.filter( function ( channel ) {
						return channel !== null;
					} ),
				parseAlpha( modernRgbMatch[ 4 ] )
			);
		}

		srgbMatch = value.match(
			/^color\(\s*srgb\s+([+-]?\d*\.?\d+%?)\s+([+-]?\d*\.?\d+%?)\s+([+-]?\d*\.?\d+%?)(?:\s*\/\s*([+-]?\d*\.?\d+%?))?\s*\)$/i
		);
		if ( srgbMatch ) {
			return buildParsedColor(
				srgbMatch
					.slice( 1, 4 )
					.map( parseSrgbChannel )
					.filter( function ( channel ) {
						return channel !== null;
					} ),
				parseAlpha( srgbMatch[ 4 ] )
			);
		}

		hexMatch = value.match( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i );
		if ( hexMatch ) {
			hex =
				hexMatch[ 1 ].length === 3
					? hexMatch[ 1 ].replace( /./g, function ( character ) {
							return character + character;
					  } )
					: hexMatch[ 1 ];

			return {
				r: parseInt( hex.slice( 0, 2 ), 16 ),
				g: parseInt( hex.slice( 2, 4 ), 16 ),
				b: parseInt( hex.slice( 4, 6 ), 16 ),
				a: 1,
			};
		}

		return null;
	}

	function compositeAgainstWhite( color ) {
		return {
			r: Math.round( color.r * color.a + 255 * ( 1 - color.a ) ),
			g: Math.round( color.g * color.a + 255 * ( 1 - color.a ) ),
			b: Math.round( color.b * color.a + 255 * ( 1 - color.a ) ),
			a: 1,
		};
	}

	function toRgbString( color ) {
		return (
			'rgb(' +
			Math.round( color.r ) +
			', ' +
			Math.round( color.g ) +
			', ' +
			Math.round( color.b ) +
			')'
		);
	}

	function normalizeParsedColorForStripe( color ) {
		return toRgbString( color.a < 1 ? compositeAgainstWhite( color ) : color );
	}

	function containsAlphaColor( value ) {
		if ( typeof value !== 'string' ) {
			return false;
		}

		return colorFunctionPatterns.some( function ( pattern ) {
			var match;
			var parsedColor;

			pattern.lastIndex = 0;
			match = pattern.exec( value );

			while ( match ) {
				parsedColor = parseColor( match[ 0 ] );
				if ( parsedColor && parsedColor.a < 1 ) {
					return true;
				}

				match = pattern.exec( value );
			}

			return false;
		} );
	}

	function normalizeAppearanceValueForStripe( value ) {
		if ( typeof value !== 'string' ) {
			return value;
		}

		return value
			.replace(
				/color\(\s*srgb\s+[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?(?:\s*\/\s*[+-]?\d*\.?\d+%?)?\s*\)/gi,
				function ( color ) {
					var parsedColor = parseColor( color );
					return parsedColor
						? normalizeParsedColorForStripe( parsedColor )
						: color;
				}
			)
			.replace(
				/rgba?\(\s*[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?\s+[+-]?\d*\.?\d+%?(?:\s*\/\s*[+-]?\d*\.?\d+%?)?\s*\)/gi,
				function ( color ) {
					var parsedColor = parseColor( color );
					return parsedColor
						? normalizeParsedColorForStripe( parsedColor )
						: color;
				}
			);
	}

	function normalizeAppearanceForStripe( value ) {
		if ( Array.isArray( value ) ) {
			return value.map( normalizeAppearanceForStripe );
		}

		if ( value && typeof value === 'object' ) {
			return Object.keys( value ).reduce( function ( normalized, key ) {
				normalized[ key ] = normalizeAppearanceForStripe( value[ key ] );
				return normalized;
			}, {} );
		}

		return normalizeAppearanceValueForStripe( value );
	}

	function getCachedAppearance( location, version ) {
		var raw;
		var cached;

		try {
			raw = root.localStorage.getItem( getCacheKey( location ) );
			if ( ! raw ) {
				return null;
			}

			cached = JSON.parse( raw );
			if ( cached && cached.version === version ) {
				return normalizeAppearanceForStripe( cached.appearance );
			}
		} catch ( error ) {}

		return null;
	}

	function setCachedAppearance( location, version, appearance ) {
		try {
			root.localStorage.setItem(
				getCacheKey( location ),
				JSON.stringify( {
					version: version,
					appearance: appearance,
				} )
			);
		} catch ( error ) {}
	}

	function dispatchAppearanceEvent( appearance, elementsLocation ) {
		root.document.dispatchEvent(
			new root.CustomEvent( 'wcpay_elements_appearance', {
				detail: {
					appearance: appearance,
					elementsLocation: elementsLocation,
				},
			} )
		);
	}

	function isAppearanceValid( appearance ) {
		var inputRules =
			appearance && appearance.rules && appearance.rules[ '.Input' ];
		return !! ( inputRules && Object.keys( inputRules ).length );
	}

	function getFontRulesFromPage() {
		return Array.prototype.slice
			.call( root.document.styleSheets || [] )
			.map( function ( sheet ) {
				var url;

				if ( ! sheet.href ) {
					return null;
				}

				try {
					url = new root.URL( sheet.href, root.location.href );
					if ( fontRuleDomains.indexOf( url.hostname ) === -1 ) {
						return null;
					}
				} catch ( error ) {
					return null;
				}

				return {
					cssSrc: sheet.href,
				};
			} )
			.filter( Boolean );
	}

	root.wcpayAppearance = {
		containsAlphaColor: containsAlphaColor,
		dispatchAppearanceEvent: dispatchAppearanceEvent,
		getCachedAppearance: getCachedAppearance,
		getFontRulesFromPage: getFontRulesFromPage,
		isAppearanceValid: isAppearanceValid,
		normalizeAppearanceForStripe: normalizeAppearanceForStripe,
		normalizeAppearanceValueForStripe: normalizeAppearanceValueForStripe,
		parseColor: parseColor,
		setCachedAppearance: setCachedAppearance,
	};

	if ( typeof module === 'object' && module.exports ) {
		module.exports = root.wcpayAppearance;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
