/**
 * External dependencies
 */
import { ExternalLink, VisuallyHidden } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, caution, published } from '@wordpress/icons';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import {
	RECOMMENDATIONS_CATALOG,
	type Recommendation,
	type RecommendationOutcome,
	type RecommendationUrgency,
} from './dispute-recommendation-catalog';
import {
	WooPaymentsAccordion,
	WooPaymentsDisputeStepItem,
} from './dispute-steps';
import type { WooPaymentsDispute } from './types';

type Section = 'whats_working_well' | 'what_could_help';

const VISIBLE_PER_SECTION = 3;
const LEARN_MORE_HREF =
	'https://woocommerce.com/document/managing-payment-disputes/';

// Client 11.1.0 `payment-details/dispute-recommendations/utils.ts:18-24`: inquiries have no outcome.
const OUTCOME_BY_STATUS: Record< string, RecommendationOutcome > = {
	lost: 'could_help',
	won: 'keep_doing',
};

// Client 11.1.0 `disputes/new-evidence/resolve-product-type.ts`, with additional evidence types on (its default).
const getProductType = ( dispute: WooPaymentsDispute ) => {
	const productType =
		dispute.metadata?.__product_type ||
		dispute.order?.suggested_product_type ||
		'';
	return productType === 'multiple' ? 'other' : productType;
};

// Client 11.1.0 `disputes/new-evidence/utils.ts:6-19`.
const hasMeaningfulValue = ( value: unknown ): boolean => {
	if ( value === undefined || value === null ) {
		return false;
	}
	if ( typeof value === 'string' ) {
		return value.trim().length > 0;
	}
	if ( typeof value === 'object' ) {
		return Object.values( value as Record< string, unknown > ).some(
			hasMeaningfulValue
		);
	}
	return Boolean( value );
};

type CountPredicate = { keys: string[]; min?: number; max?: number };

// Client 11.1.0 `disputes/new-evidence/recommendations.ts:20-30`.
const matchesCount = (
	predicate: CountPredicate,
	condition: ( key: string ) => boolean
) => {
	const count = predicate.keys.filter( condition ).length;
	const min = predicate.min ?? ( predicate.max !== undefined ? 0 : 1 );
	const max = predicate.max ?? predicate.keys.length;
	return count >= min && count <= max;
};

/**
 * The catalog entries for a resolved dispute. Client 11.1.0 `disputes/new-evidence/recommendations.ts:37-93`
 * and `payment-details/dispute-recommendations/utils.ts:30-45`.
 *
 * @param dispute The dispute.
 */
const getDisputeRecommendations = (
	dispute: WooPaymentsDispute
): Recommendation[] => {
	const outcome = OUTCOME_BY_STATUS[ dispute.status || '' ];
	if ( ! outcome ) {
		return [];
	}
	const productType = getProductType( dispute );
	const evidence = ( dispute.evidence || {} ) as Record< string, unknown >;
	const isProvided = ( key: string ) => hasMeaningfulValue( evidence[ key ] );
	const matched = RECOMMENDATIONS_CATALOG.filter( ( { retired, when } ) => {
		return (
			! retired &&
			when.outcome === outcome &&
			when.reasonIn.includes( dispute.reason || '' ) &&
			( ! when.productTypeIn ||
				when.productTypeIn.includes( productType ) ) &&
			( ! when.requireProvided ||
				matchesCount( when.requireProvided, isProvided ) ) &&
			( ! when.requireMissing ||
				matchesCount(
					when.requireMissing,
					( key ) => ! isProvided( key )
				) )
		);
	} );
	const suppressor = matched.find( ( entry ) => entry.suppressOthers );
	return suppressor ? [ suppressor ] : matched;
};

// Client 11.1.0 `utils.ts:48-61`: higher lift first, unmeasured entries last in catalog order.
const sortByLift = ( a: Recommendation, b: Recommendation ) => {
	if ( typeof a.lift !== 'number' ) {
		return typeof b.lift !== 'number' ? 0 : 1;
	}
	return typeof b.lift !== 'number' ? -1 : b.lift - a.lift;
};

// Client 11.1.0 `payment-details/dispute-outcome/tracks.ts`: once per page session, across remounts.
const seenOutcomeViews = new Set< string >();
const seenSectionViews = new Set< string >();

export const resetDisputeOutcomeTrackingForTests = () => {
	seenOutcomeViews.clear();
	seenSectionViews.clear();
};

const getTracksProperties = ( dispute: WooPaymentsDispute ) => {
	const productType = getProductType( dispute );
	return {
		dispute_id: dispute.id,
		dispute_status: dispute.status,
		dispute_reason: dispute.reason,
		...( productType ? { product_type: productType } : {} ),
	};
};

const recordAction = (
	dispute: WooPaymentsDispute,
	props: { action: string; section: Section; link_href?: string }
) => {
	if ( dispute.id ) {
		recordEvent( 'wcpay_dispute_outcome_action_clicked', {
			...getTracksProperties( dispute ),
			...props,
		} );
	}
};

const getUrgencyLabel = ( urgency: RecommendationUrgency ) => {
	switch ( urgency ) {
		case 'critical':
			return __( 'Important:', 'woocommerce' );
		case 'tip':
			return __( 'Tip:', 'woocommerce' );
		default:
			return __( 'Working well:', 'woocommerce' );
	}
};

const renderItem = ( recommendation: Recommendation ) => (
	<WooPaymentsDisputeStepItem
		key={ recommendation.id }
		as="article"
		titleAs="h3"
		className={ `woocommerce-woopayments-dispute-recommendation is-${ recommendation.urgency }` }
		icon={
			<Icon
				icon={
					recommendation.urgency === 'positive' ? published : caution
				}
				size={ 24 }
			/>
		}
		titlePrefix={ getUrgencyLabel( recommendation.urgency ) }
		title={ recommendation.title }
		description={ recommendation.body }
	/>
);

const RecommendationSection = ( {
	dispute,
	section,
	title,
	description,
	items,
	learnMoreHref,
}: {
	dispute: WooPaymentsDispute;
	section: Section;
	title: string;
	description: string;
	items: Recommendation[];
	learnMoreHref?: string;
} ) => {
	const sorted = [ ...items ].sort( sortByLift );
	const sortedIds = sorted.map( ( { id } ) => id ).join( '|' );

	useEffect( () => {
		const key = `${ dispute.id }:${ section }`;
		if ( ! sortedIds || ! dispute.id || seenSectionViews.has( key ) ) {
			return;
		}
		recordEvent( 'wcpay_dispute_outcome_recommendations_section_viewed', {
			...getTracksProperties( dispute ),
			section,
			recommendation_count: sortedIds.split( '|' ).length,
			visible_count: Math.min(
				VISIBLE_PER_SECTION,
				sortedIds.split( '|' ).length
			),
			recommendation_ids: sortedIds.split( '|' ),
		} );
		seenSectionViews.add( key );
	}, [ dispute, section, sortedIds ] );

	if ( ! items.length ) {
		return null;
	}

	const visible = sorted.slice( 0, VISIBLE_PER_SECTION );
	const hidden = sorted.slice( VISIBLE_PER_SECTION );

	return (
		<WooPaymentsAccordion
			className="woocommerce-woopayments-dispute-recommendations"
			title={ title }
			subtitleNode={
				<>
					{ description }
					{ learnMoreHref && (
						<>
							{ ' ' }
							<ExternalLink
								href={ learnMoreHref }
								onClick={ () =>
									recordAction( dispute, {
										action: 'learn_more_clicked',
										section,
										link_href: learnMoreHref,
									} )
								}
							>
								{ __( 'Learn more', 'woocommerce' ) }
								<VisuallyHidden>
									{ ' ' +
										__(
											'about managing payment disputes',
											'woocommerce'
										) }
								</VisuallyHidden>
							</ExternalLink>
						</>
					) }
				</>
			}
		>
			{ visible.map( renderItem ) }
			{ hidden.length > 0 && (
				<details
					className="woocommerce-woopayments-dispute-recommendations__show-more"
					onToggle={ ( event ) => {
						if ( event.currentTarget.open ) {
							recordAction( dispute, {
								action: 'show_more_expanded',
								section,
							} );
						}
					} }
				>
					<summary>
						{ sprintf(
							/* translators: %d: number of hidden recommendations. */
							_n(
								'Show %d more',
								'Show %d more',
								hidden.length,
								'woocommerce'
							),
							hidden.length
						) }
					</summary>
					{ hidden.map( renderItem ) }
				</details>
			) }
		</WooPaymentsAccordion>
	);
};

/**
 * "What's working well" and "What could help next time" for a won or lost dispute.
 * Client 11.1.0 `payment-details/dispute-recommendations/index.tsx`.
 *
 * @param props         Component props.
 * @param props.dispute The dispute.
 */
export const WooPaymentsDisputeRecommendations = ( {
	dispute,
}: {
	dispute: WooPaymentsDispute;
} ) => {
	const recommendations = getDisputeRecommendations( dispute );
	if ( ! recommendations.length ) {
		return null;
	}
	const isPositive = ( { urgency }: Recommendation ) =>
		urgency === 'positive';

	return (
		<>
			<RecommendationSection
				dispute={ dispute }
				section="whats_working_well"
				title={ __( "What's working well", 'woocommerce' ) }
				description={ __(
					'These are the evidence strengths that supported your dispute response.',
					'woocommerce'
				) }
				items={ recommendations.filter( isPositive ) }
			/>
			<RecommendationSection
				dispute={ dispute }
				section="what_could_help"
				title={
					// A won dispute can still carry tips; "What could help" reads as criticism there.
					OUTCOME_BY_STATUS[ dispute.status || '' ] === 'keep_doing'
						? __( 'Tips for future disputes', 'woocommerce' )
						: __( 'What could help next time', 'woocommerce' )
				}
				description={ __(
					'Strengthen future dispute responses by adding these details to your evidence before submitting.',
					'woocommerce'
				) }
				items={ recommendations.filter(
					( recommendation ) => ! isPositive( recommendation )
				) }
				learnMoreHref={ LEARN_MORE_HREF }
			/>
		</>
	);
};

// Client 11.1.0 `summary/index.tsx:982-989`: accepting a dispute is a choice, so no coaching after it.
const qualifiesForCard = ( dispute: WooPaymentsDispute ) =>
	( dispute.status === 'won' || dispute.status === 'lost' ) &&
	dispute.metadata?.__closed_by_merchant !== '1';

/**
 * The outcome view of a charge's disputes: one recommendations card per distinct set, and one
 * outcome view event per dispute. Client 11.1.0 `payment-details/summary/index.tsx:990-1067`.
 *
 * @param props          Component props.
 * @param props.disputes The charge's disputes.
 */
export const WooPaymentsDisputeOutcome = ( {
	disputes,
}: {
	disputes: WooPaymentsDispute[];
} ) => {
	const outcomeViews = disputes
		.filter( ( dispute ) =>
			[ 'won', 'lost', 'warning_closed' ].includes( dispute.status || '' )
		)
		.map( ( dispute ) => ( {
			dispute,
			hasRecommendations:
				qualifiesForCard( dispute ) &&
				getDisputeRecommendations( dispute ).length > 0,
		} ) );
	const outcomeViewKey = outcomeViews
		.map(
			( { dispute, hasRecommendations } ) =>
				`${ dispute.id }:${ hasRecommendations }`
		)
		.join( ',' );

	useEffect( () => {
		outcomeViews.forEach( ( { dispute, hasRecommendations } ) => {
			if ( ! dispute.id || seenOutcomeViews.has( dispute.id ) ) {
				return;
			}
			recordEvent( 'wcpay_dispute_outcome_viewed', {
				...getTracksProperties( dispute ),
				has_recommendations: hasRecommendations,
			} );
			seenOutcomeViews.add( dispute.id );
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- The key captures the views.
	}, [ outcomeViewKey ] );

	const seenSignatures = new Set< string >();
	const cardDisputes = disputes.filter( ( dispute ) => {
		if ( ! qualifiesForCard( dispute ) ) {
			return false;
		}
		const signature = getDisputeRecommendations( dispute )
			.map( ( { id } ) => id )
			.sort()
			.join( '|' );
		if ( seenSignatures.has( signature ) ) {
			return false;
		}
		seenSignatures.add( signature );
		return true;
	} );

	return (
		<>
			{ cardDisputes.map( ( dispute ) => (
				<WooPaymentsDisputeRecommendations
					key={ dispute.id }
					dispute={ dispute }
				/>
			) ) }
		</>
	);
};
