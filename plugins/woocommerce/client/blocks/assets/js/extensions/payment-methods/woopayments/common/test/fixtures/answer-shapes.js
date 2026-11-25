/**
 * Every answer the store's update_order_status can give after a 3D Secure confirmation, and what the shopper meets, as
 * the four WooPayments checkout scripts must agree on it. One copy sits with the Blocks tests and one with the classic
 * tests; the two are byte-identical, and a classic test checks that they stay so.
 *
 * The rule:
 * - only a 2xx answer with an http(s) return URL is followed;
 * - a readable, non-blank message is shown as it is, except a 5xx one after a payment that holds money;
 * - everything else is the owner's sentence after a payment that holds money, the surface's generic error after a
 *   setup intent.
 *
 * Each entry: `transport` is 'reject' (the request never reaches the store) or { status, contentType, body } with a raw
 * body; `expect.pi` is the outcome after a payment intent that holds money, `expect.si` after a setup intent; `message`
 * is the text a MESSAGE outcome shows.
 */
const SENTENCE = 'SENTENCE';
const GENERIC = 'GENERIC';
const MESSAGE = 'MESSAGE';
const FOLLOW = 'FOLLOW';

// A same-document address, so the classic suite can watch the script set window.location.href in jsdom.
const ORDER_PAGE = 'http://localhost/#order-received';
const STORE_REFUSED = 'Store refused';
// WooPaymentsCheckoutAjaxController: the nonce refusal (403), the unavailable platform (409) and any other error (500).
const REFRESH =
	"We're not able to process this payment. Please refresh the page and try again.";
const TRY_LATER =
	"We're not able to process this payment. Please try again later.";
// The 502 when the platform read of the intent fails: the API client's own text.
const TRANSPORT_TEXT =
	'Http request failed. Reason: cURL error 28: Operation timed out';

/**
 * @param {number} status      HTTP status.
 * @param {string} body        Raw body.
 * @param {string} contentType Content-Type header.
 */
const answer = ( status, body, contentType = 'application/json' ) => ( {
	status,
	contentType,
	body,
} );
/** @param {*} value Any JSON value. */
const json = ( value ) => JSON.stringify( value );

const ANSWER_SHAPES = [
	{
		id: 'R1 transport failure',
		transport: 'reject',
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R2a 200 HTML page',
		transport: answer(
			200,
			'<!DOCTYPE html><html><body>Error</body></html>',
			'text/html'
		),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R2a 200 JSON after a PHP warning',
		transport: answer(
			200,
			'<b>Warning</b>: in file.php on line 1<br />' +
				json( { return_url: ORDER_PAGE } )
		),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R2b 503 text',
		transport: answer( 503, 'Service Unavailable', 'text/plain' ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R2b 500 critical error page',
		transport: answer(
			500,
			'<p>There has been a critical error on this website.</p>',
			'text/html'
		),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R4a 200 readable message',
		transport: answer( 200, json( { error: { message: STORE_REFUSED } } ) ),
		expect: { pi: MESSAGE, si: MESSAGE },
		message: STORE_REFUSED,
	},
	{
		id: 'R4b 403 nonce refusal',
		transport: answer( 403, json( { error: { message: REFRESH } } ) ),
		expect: { pi: MESSAGE, si: MESSAGE },
		message: REFRESH,
	},
	{
		id: 'R4b 409 platform unavailable',
		transport: answer( 409, json( { error: { message: TRY_LATER } } ) ),
		expect: { pi: MESSAGE, si: MESSAGE },
		message: TRY_LATER,
	},
	{
		id: 'R4b 502 platform read failed',
		transport: answer(
			502,
			json( { error: { message: TRANSPORT_TEXT } } )
		),
		expect: { pi: SENTENCE, si: MESSAGE },
		message: TRANSPORT_TEXT,
	},
	{
		id: 'R4b 500 any other error',
		transport: answer( 500, json( { error: { message: TRY_LATER } } ) ),
		expect: { pi: SENTENCE, si: MESSAGE },
		message: TRY_LATER,
	},
	{
		id: 'R5a 200 whitespace message',
		transport: answer( 200, json( { error: { message: '   ' } } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R5a 200 empty message',
		transport: answer( 200, json( { error: { message: '' } } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R5a 200 non-string message',
		transport: answer( 200, json( { error: { message: 42 } } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R5b 409 whitespace message',
		transport: answer( 409, json( { error: { message: '   ' } } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R5b 409 empty message',
		transport: answer( 409, json( { error: { message: '' } } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R6a 200 return URL',
		transport: answer( 200, json( { return_url: ORDER_PAGE } ) ),
		expect: { pi: FOLLOW, si: FOLLOW },
	},
	{
		id: 'R6a 200 empty error and return URL',
		transport: answer( 200, json( { error: {}, return_url: ORDER_PAGE } ) ),
		expect: { pi: FOLLOW, si: FOLLOW },
	},
	{
		id: 'R6a 200 blank message and return URL',
		transport: answer(
			200,
			json( { error: { message: ' ' }, return_url: ORDER_PAGE } )
		),
		expect: { pi: FOLLOW, si: FOLLOW },
	},
	{
		id: 'R6b 503 return URL',
		transport: answer( 503, json( { return_url: ORDER_PAGE } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R6b 503 empty error and return URL',
		transport: answer( 503, json( { error: {}, return_url: ORDER_PAGE } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R6b 503 blank message and return URL',
		transport: answer(
			503,
			json( { error: { message: ' ' }, return_url: ORDER_PAGE } )
		),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R6b 403 return URL',
		transport: answer( 403, json( { return_url: ORDER_PAGE } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R6b 403 empty error and return URL',
		transport: answer( 403, json( { error: {}, return_url: ORDER_PAGE } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R6b 403 blank message and return URL',
		transport: answer(
			403,
			json( { error: { message: ' ' }, return_url: ORDER_PAGE } )
		),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R7a 200 readable message and return URL',
		transport: answer(
			200,
			json( {
				error: { message: STORE_REFUSED },
				return_url: ORDER_PAGE,
			} )
		),
		expect: { pi: MESSAGE, si: MESSAGE },
		message: STORE_REFUSED,
	},
	{
		id: 'R7b 403 readable message and return URL',
		transport: answer(
			403,
			json( {
				error: { message: STORE_REFUSED },
				return_url: ORDER_PAGE,
			} )
		),
		expect: { pi: MESSAGE, si: MESSAGE },
		message: STORE_REFUSED,
	},
	{
		id: 'R7b 500 readable message and return URL',
		transport: answer(
			500,
			json( {
				error: { message: STORE_REFUSED },
				return_url: ORDER_PAGE,
			} )
		),
		expect: { pi: SENTENCE, si: MESSAGE },
		message: STORE_REFUSED,
	},
	{
		id: 'R8a 200 {}',
		transport: answer( 200, '{}' ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R8a 200 empty error',
		transport: answer( 200, '{"error":{}}' ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R8a 200 null',
		transport: answer( 200, 'null' ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R8a 200 0',
		transport: answer( 200, '0' ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R8b 400 0 from admin-ajax',
		transport: answer( 400, '0', 'text/html' ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R8b 503 {}',
		transport: answer( 503, '{}' ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R10a 200 blank return URL',
		transport: answer( 200, json( { return_url: ' \n ' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R10b 503 blank return URL',
		transport: answer( 503, json( { return_url: ' \n ' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R11a 200 javascript: URL',
		transport: answer( 200, json( { return_url: 'javascript:alert(1)' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R11a 200 data: URL',
		transport: answer( 200, json( { return_url: 'data:text/html,x' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R11a 200 unparseable URL',
		transport: answer( 200, json( { return_url: 'http://[' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R11b 503 javascript: URL',
		transport: answer( 503, json( { return_url: 'javascript:alert(1)' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R11b 503 data: URL',
		transport: answer( 503, json( { return_url: 'data:text/html,x' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
	{
		id: 'R11b 503 unparseable URL',
		transport: answer( 503, json( { return_url: 'http://[' } ) ),
		expect: { pi: SENTENCE, si: GENERIC },
	},
];

// Every fixture runs after a succeeded payment intent and after a setup intent; this one also runs after the other two
// statuses that hold money.
const MONEY_STATUS_FIXTURE = 'R2b 503 text';

module.exports = {
	ANSWER_SHAPES,
	MONEY_STATUS_FIXTURE,
	ORDER_PAGE,
	SENTENCE,
	GENERIC,
	MESSAGE,
	FOLLOW,
};
