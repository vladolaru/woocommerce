import type { Reporter, TestCase, TestResult } from '@playwright/test/reporter';

import {
	KNOWN_GAP_ANNOTATION,
	KNOWN_GAP_SENTINEL,
} from '../utils/woopayments-native/known-gap';
import { RESOURCE_QUARANTINE_ANNOTATION } from '../utils/woopayments-native/resource-quarantine';

class WooPaymentsKnownGapsReporter implements Reporter {
	private violations: string[] = [];

	onTestEnd( test: TestCase, result: TestResult ): void {
		const knownGapAnnotations = result.annotations.filter(
			( annotation ) => annotation.type === KNOWN_GAP_ANNOTATION
		);

		for ( const annotation of knownGapAnnotations ) {
			const gapId = annotation.description?.split( '|' )[ 0 ] ?? '';
			const context = `${ test.title } (${ gapId })`;

			if ( test.expectedStatus !== 'failed' ) {
				this.violations.push(
					`${ context }: expected status is not failed.`
				);
			}
			if ( result.retry !== 0 ) {
				this.violations.push(
					`${ context }: known gaps cannot be accepted on retry ${ result.retry }.`
				);
			}
			if ( result.status !== 'failed' ) {
				this.violations.push(
					`${ context }: result status is not failed.`
				);
			}
			if ( result.errors.length !== 1 ) {
				this.violations.push(
					`${ context }: expected exactly one error, received ${ result.errors.length }.`
				);
			}
			if (
				! result.errors[ 0 ]?.message
					?.replace( /^Error: /, '' )
					.startsWith( `${ KNOWN_GAP_SENTINEL }${ gapId }]` )
			) {
				this.violations.push(
					`${ context }: error does not start with the matching known-gap sentinel.`
				);
			}
			if (
				result.annotations.some(
					( resultAnnotation ) =>
						resultAnnotation.type === RESOURCE_QUARANTINE_ANNOTATION
				)
			) {
				this.violations.push(
					`${ context }: known gaps cannot also be resource-quarantined.`
				);
			}
		}
	}

	onEnd(): { status: 'failed' } | undefined {
		if ( this.violations.length === 0 ) {
			return;
		}

		for ( const violation of this.violations ) {
			console.error( `WooPayments known-gap violation: ${ violation }` );
		}

		return { status: 'failed' };
	}
}

export default WooPaymentsKnownGapsReporter;
