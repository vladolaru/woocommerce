'use strict';

const deriveAssertions = ( facts ) => ( {
	functional: {
		authenticated_admin: facts?.authenticatedAdmin === true,
		exact_dispute_row: facts?.exactDisputeRow === true,
		response_action_discovered: facts?.responseActionDiscovered === true,
		exact_submit_false_post:
			facts?.requestSeen === true &&
			facts?.requestMethod === 'POST' &&
			facts?.requestPathMatches === true &&
			facts?.submitFalse === true &&
			facts?.descriptionMatches === true,
		customer_name_preserved: facts?.payloadCustomerNameMatches === true,
		no_files_attached: facts?.noFilesAttached === true,
		save_feedback:
			facts?.responseOk === true && facts?.saveFeedback === true,
		description_reloaded: facts?.reloadedDescriptionMatches === true,
		description_editable: facts?.descriptionEditable === true,
	},
	ux: {
		save_for_later_copy: facts?.saveButtonLabel === 'Save for later',
		customer_name_visible: facts?.customerNameVisible === true,
	},
} );

const canonicalCustomerNameJson = ( value ) => JSON.stringify( String( value ?? '' ).trim() );

const isAllowedTimelineFailure = ( failure ) =>
	failure?.store === 'ref' &&
	failure?.status === 500 &&
	failure?.method === 'GET' &&
	failure?.origin === failure?.expectedOrigin &&
	/^(?:ch|py)_[A-Za-z0-9_]+$/.test( String( failure?.chargeId || '' ) ) &&
	String( failure?.path || '' ) === `/wp-json/wc/v3/payments/timeline/${ failure.chargeId }` &&
	[ 'list', 'details' ].includes( failure?.phase );

const isAllowedTimelineConsole = ( event ) => {
	let parsed;
	try {
		parsed = new URL( String( event?.url || '' ) );
	} catch {
		return false;
	}
	return (
		event?.store === 'ref' &&
		[ 'list', 'details' ].includes( event?.phase ) &&
		event?.type === 'error' &&
		parsed.origin === event?.expectedOrigin &&
		/^(?:ch|py)_[A-Za-z0-9_]+$/.test( String( event?.chargeId || '' ) ) &&
		/^Failed to load resource: .*status of 500/i.test( String( event?.text || '' ) ) &&
		parsed.pathname === `/wp-json/wc/v3/payments/timeline/${ event.chargeId }`
	);
};

module.exports = {
	canonicalCustomerNameJson,
	deriveAssertions,
	isAllowedTimelineFailure,
	isAllowedTimelineConsole,
};
