'use strict';

const normalizedText = ( value ) =>
	String( value || '' )
		.replace( /\s+/g, ' ' )
		.trim();

const escapeRegExp = ( value ) =>
	value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

const rowHasExpectedAmount = ( rowText, amountMinor ) => {
	const normalizedMinor = Number( amountMinor );
	if ( ! Number.isSafeInteger( normalizedMinor ) || normalizedMinor < 0 ) {
		return false;
	}
	const amount = escapeRegExp( ( normalizedMinor / 100 ).toFixed( 2 ) );
	return new RegExp( `(?:^|[^0-9])${ amount }(?![0-9])` ).test(
		normalizedText( rowText )
	);
};

const badgeHasExpectedCount = ( badgeText, expectedCount ) => {
	const normalizedExpected = Number( expectedCount );
	const normalizedBadge = normalizedText( badgeText );
	return (
		Number.isSafeInteger( normalizedExpected ) &&
		normalizedExpected >= 0 &&
		/^(?:0|[1-9][0-9]*)$/.test( normalizedBadge ) &&
		Number( normalizedBadge ) === normalizedExpected
	);
};

const textHasExpectedIdentifier = ( text, expectedIdentifier ) => {
	const identifier = String( expectedIdentifier || '' );
	if ( ! identifier ) {
		return false;
	}
	return new RegExp(
		`(?:^|[^A-Za-z0-9_])${ escapeRegExp( identifier ) }(?![A-Za-z0-9_])`
	).test( normalizedText( text ) );
};

const rowHasExpectedOrderId = ( row, expectedOrderId ) => {
	const orderId = Number( expectedOrderId );
	if (
		! Number.isSafeInteger( orderId ) ||
		orderId <= 0 ||
		! row ||
		! Array.isArray( row.links )
	) {
		return false;
	}
	const orderText = new RegExp( `^#?\\s*${ orderId }$` );
	return row.links.some( ( link ) => {
		if (
			! link ||
			! orderText.test( normalizedText( link.text ) ) ||
			'string' !== typeof link.href
		) {
			return false;
		}
		try {
			const parsed = new URL( link.href );
			const actionMatches = parsed.searchParams.get( 'action' ) === 'edit';
			const hposMatches =
				parsed.pathname.endsWith( '/wp-admin/admin.php' ) &&
				parsed.searchParams.get( 'page' ) === 'wc-orders' &&
				parsed.searchParams.get( 'id' ) === String( orderId );
			const legacyMatches =
				parsed.pathname.endsWith( '/wp-admin/post.php' ) &&
				parsed.searchParams.get( 'post' ) === String( orderId );
			return actionMatches && ( hposMatches || legacyMatches );
		} catch {
			return false;
		}
	} );
};

module.exports = {
	badgeHasExpectedCount,
	rowHasExpectedAmount,
	rowHasExpectedOrderId,
	textHasExpectedIdentifier,
};
