const fs = require('node:fs');

const evidencePath =
	'/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4h-browser-probe.json';
const base =
	'http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout';
const routes = [
	{ id: 'provider-list', url: base, expected: /Payment providers|WooPayments/i },
	{
		id: 'settings',
		url: `${ base }&path=/woopayments/settings`,
		expected: /WooPayments settings|Loading WooPayments settings/i,
	},
	{
		id: 'overview',
		url: `${ base }&path=/woopayments/overview`,
		expected: /Overview|Balance|Payout|WooPayments/i,
	},
	{
		id: 'payouts',
		url: `${ base }&path=/woopayments/payouts`,
		expected: /Payouts|Deposit|Date|Amount/i,
	},
	{
		id: 'payout-details',
		url: `${ base }&path=/woopayments/payouts/details&id=po_missing`,
		expected: /Payout|not found|details|loading/i,
	},
	{
		id: 'transactions',
		url: `${ base }&path=/woopayments/transactions`,
		expected: /Transactions|Payment|Date|Amount/i,
	},
	{
		id: 'transaction-details',
		url: `${ base }&path=/woopayments/transactions/details&id=txn_missing`,
		expected: /Transaction|not found|details|loading/i,
	},
	{
		id: 'transaction-details-intent',
		url: `${ base }&path=/woopayments/transactions/details&id=pi_missing`,
		expected: /Transaction|not found|details|loading/i,
	},
	{
		id: 'transaction-details-charge',
		url: `${ base }&path=/woopayments/transactions/details&id=ch_missing`,
		expected: /Transaction|not found|details|loading/i,
	},
	{
		id: 'disputes',
		url: `${ base }&path=/woopayments/disputes`,
		expected: /Disputes|Payment dispute|Date|Amount/i,
	},
	{
		id: 'dispute-challenge',
		url: `${ base }&path=/woopayments/disputes/challenge&id=dp_missing`,
		expected: /Dispute|Evidence|not found|loading/i,
	},
	{
		id: 'card-readers',
		url: `${ base }&path=/woopayments/card-readers`,
		expected: /Card readers|reader|location/i,
	},
	{
		id: 'capital',
		url: `${ base }&path=/woopayments/loans`,
		expected: /Capital|loan|No Capital loans found/i,
	},
];

function filteredConsole(messages) {
	return messages.filter((entry) => {
		const text = entry.text || '';
		return (
			/(error|warning|failed|uncaught|exception)/i.test(text) &&
			!/ResizeObserver loop|deprecated|source map|JQMIGRATE/i.test(text)
		);
	});
}

function isAllowedMissingSentinelResponse(response) {
	const url = response.url || '';
	return (
		(response.status === 500 && /\/wc\/v3\/payments\/deposits\/po_missing(?:\?|$)/.test(url)) ||
		(response.status === 404 && /\/wc\/v3\/payments\/transactions\/txn_missing(?:\?|$)/.test(url)) ||
		(response.status === 404 && /\/wc\/v3\/payments\/payment_intents\/pi_missing(?:\?|$)/.test(url)) ||
		(response.status === 404 && /\/wc\/v3\/payments\/charges\/ch_missing(?:\?|$)/.test(url)) ||
		(response.status === 404 && /\/wc\/v3\/payments\/disputes\/dp_missing(?:\?|$)/.test(url))
	);
}

async function ensureLoggedIn(page) {
	if (!/wp-login\.php/.test(page.url())) {
		return;
	}

	await page.locator('#user_login').fill('admin');
	await page.locator('#user_pass').fill('password');
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null),
		page.locator('#wp-submit').click(),
	]);
}

state.page = state.page || (await context.newPage());
const page = state.page;
await page.setViewportSize({ width: 1440, height: 1000 });
page.removeAllListeners('console');
page.removeAllListeners('response');

state.a4hConsole = [];
state.a4hResponses = [];
page.on('console', (message) => {
	state.a4hConsole.push({
		type: message.type(),
		text: message.text(),
		url: page.url(),
	});
});
page.on('response', (response) => {
	const url = response.url();
	if (/store8889\.localhost:8889|assets\/client\/admin|wc\/v3\/payments/.test(url)) {
		state.a4hResponses.push({
			status: response.status(),
			url,
			route: page.url(),
		});
	}
});

const results = [];
for (const route of routes) {
	console.log(`[a4h-browser] opening ${ route.id }`);
	await page.goto(route.url, { waitUntil: 'domcontentloaded', timeout: 30000 });
	await ensureLoggedIn(page);
	if (!page.url().includes('page=wc-settings')) {
		await page.goto(route.url, { waitUntil: 'domcontentloaded', timeout: 30000 });
	}
	await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => null);
	await page.waitForTimeout(1200);

	const bodyText = await page.locator('body').innerText({ timeout: 10000 }).catch((error) => {
		return `BODY_TEXT_ERROR: ${ error.message }`;
	});
	const failedResponses = state.a4hResponses.filter(
		(response) =>
			response.route === page.url() &&
			response.status >= 400 &&
			!/admin-ajax\.php\?action=woocommerce_payments_get_onboarding_data/.test(response.url)
	);
	const unexpectedFailedResponses = failedResponses.filter(
		(response) => !isAllowedMissingSentinelResponse(response)
	);
	const pluginDistScripts = await page
		.locator('script[src*="woocommerce-payments/dist"]')
		.evaluateAll((scripts) => scripts.map((script) => script.src))
		.catch(() => []);
	const nativeRouteChunks = await page.evaluate(() =>
		performance
			.getEntriesByType('resource')
			.map((entry) => entry.name)
			.filter((name) => /assets\/client\/admin\/chunks\/settings-payments/.test(name))
	);
	const registry = await page.evaluate(() => ({
		wpDataPresent: !!window.wp?.data,
		nativeSettingsStoreInHostRegistry: !!window.wp?.data?.select?.('wc/payments/settings'),
	}));
	const matchedExpected = route.expected.test(bodyText);

	results.push({
		id: route.id,
		url: page.url(),
		title: await page.title(),
		matchedExpected,
		bodySnippet: bodyText.slice(0, 800),
		failedResponses,
		unexpectedFailedResponses,
		pluginDistScripts,
		nativeRouteChunks,
		registry,
	});
}

const failedResponses = state.a4hResponses.filter((response) => response.status >= 400);
const evidence = {
	generatedAt: new Date().toISOString(),
	routes: results,
	console: filteredConsole(state.a4hConsole),
	failedResponses,
	unexpectedFailedResponses: failedResponses.filter(
		(response) => !isAllowedMissingSentinelResponse(response)
	),
};

fs.writeFileSync(evidencePath, `${ JSON.stringify(evidence, null, 2) }\n`, 'utf8');
console.log(`[a4h-browser] wrote ${ evidencePath }`);
console.log(
	JSON.stringify(
		{
			routeCount: results.length,
			unmatched: results.filter((route) => !route.matchedExpected).map((route) => route.id),
			routesWithFailedResponses: results
				.filter((route) => route.failedResponses.length > 0)
				.map((route) => route.id),
			routesWithUnexpectedFailedResponses: results
				.filter((route) => route.unexpectedFailedResponses.length > 0)
				.map((route) => route.id),
			routesWithPluginDistScripts: results
				.filter((route) => route.pluginDistScripts.length > 0)
				.map((route) => route.id),
			consoleCount: evidence.console.length,
		},
		null,
		2
	)
);
