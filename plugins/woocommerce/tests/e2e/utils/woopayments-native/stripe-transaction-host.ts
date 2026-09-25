export function isStripeTransactionHost( hostname: string ): boolean {
	return hostname === 'api.stripe.com';
}
