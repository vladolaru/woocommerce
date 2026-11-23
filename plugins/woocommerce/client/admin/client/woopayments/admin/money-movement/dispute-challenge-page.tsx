/**
 * External dependencies
 */
import { Flex, FlexItem, Spinner } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useLocation } from 'react-router-dom';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import {
	getWooPaymentsDispute,
	getWooPaymentsDisputeFileDetails,
} from './data';
import { DisputeEvidenceForm } from './dispute-evidence-form';
import {
	type DocumentEvidenceField,
	type EvidenceFileMap,
	MAX_EVIDENCE_FILE_BYTES,
	extractSavedEvidenceFileIds,
} from './dispute-evidence-fields';
import type { WooPaymentsDispute } from './types';
import { getErrorMessage } from './utils';
import { LiveStatusMessage, StatusMessage } from './table';
import { WooPaymentsTestModeNotice } from '../test-mode-notice';
import '../style.scss';

const DisputeChallenge = ( { disputeId }: { disputeId: string } ) => {
	const { createErrorNotice } = useDispatch( noticesStore );
	const [ dispute, setDispute ] = useState< WooPaymentsDispute | null >(
		null
	);
	const [ fileDetails, setFileDetails ] = useState< EvidenceFileMap >( {} );
	const [ isLoading, setIsLoading ] = useState( !! disputeId );
	const missingIdMessage = disputeId
		? ''
		: __( 'A dispute ID is required.', 'woocommerce' );
	const [ fileDetailsWarning, setFileDetailsWarning ] = useState<
		string | null
	>( null );
	const loadingMessage = __( 'Loading dispute…', 'woocommerce' );
	let liveStatusMessage = '';

	if ( missingIdMessage ) {
		liveStatusMessage = missingIdMessage;
	} else if ( isLoading ) {
		liveStatusMessage = loadingMessage;
	} else if ( fileDetailsWarning ) {
		liveStatusMessage = fileDetailsWarning;
	} else if ( dispute ) {
		liveStatusMessage = __(
			'Dispute evidence form loaded.',
			'woocommerce'
		);
	}

	useEffect( () => {
		let isMounted = true;

		const loadDispute = async () => {
			if ( ! disputeId ) {
				return;
			}

			try {
				const nextDispute = await getWooPaymentsDispute( disputeId );
				const savedFileIds = extractSavedEvidenceFileIds( nextDispute );
				const fallbackFileDetails = Object.entries(
					savedFileIds
				).reduce< EvidenceFileMap >(
					( files, [ field, fileId ] ) => ( {
						...files,
						[ field as DocumentEvidenceField ]: {
							id: fileId,
							filename: fileId,
							size: MAX_EVIDENCE_FILE_BYTES,
						},
					} ),
					{}
				);

				if ( isMounted ) {
					setDispute( nextDispute );
					setFileDetails( fallbackFileDetails );
					setFileDetailsWarning( null );
					setIsLoading( false );
				}

				const savedFileEntries = Object.entries( savedFileIds );

				if ( ! savedFileEntries.length ) {
					return;
				}

				const settledFileDetails = await Promise.allSettled(
					savedFileEntries.map(
						async ( [ field, fileId ] ) =>
							[
								field,
								await getWooPaymentsDisputeFileDetails(
									fileId
								),
							] as const
					)
				);

				if ( ! isMounted ) {
					return;
				}

				let hasFileDetailsFailure = false;
				const nextFileDetails =
					settledFileDetails.reduce< EvidenceFileMap >(
						( files, result, index ) => {
							const [ field, fileId ] = savedFileEntries[ index ];

							if ( result.status === 'fulfilled' ) {
								return {
									...files,
									[ field as DocumentEvidenceField ]:
										result.value[ 1 ],
								};
							}

							hasFileDetailsFailure = true;
							recordEvent( 'wcpay_dispute_file_details_failed', {
								dispute_id: disputeId,
								dispute_status: nextDispute.status,
								dispute_reason: nextDispute.reason,
								field,
								file_id: fileId,
								message: getErrorMessage(
									result.reason,
									__(
										'Unable to load dispute evidence file details.',
										'woocommerce'
									)
								),
							} );

							return files;
						},
						fallbackFileDetails
					);

				setFileDetails( nextFileDetails );
				setFileDetailsWarning(
					hasFileDetailsFailure
						? __(
								'Some saved evidence files could not be verified. They count toward the full 4.5 MB evidence limit until details can be loaded or the files are replaced.',
								'woocommerce'
							)
						: null
				);
			} catch ( error ) {
				if ( isMounted ) {
					const message = ( error as { message?: unknown } | null )
						?.message;
					void createErrorNotice(
						typeof message === 'string' ? message : String( error )
					);
					setIsLoading( false );
				}
			}
		};

		void loadDispute();

		return () => {
			isMounted = false;
		};
	}, [ disputeId, createErrorNotice ] );

	return (
		<section
			className="woocommerce-woopayments-money-movement"
			aria-busy={ isLoading }
		>
			{ /* Client 11.1.0 disputes/new-evidence/index.tsx:1540, once the dispute has loaded. */ }
			{ ! isLoading && (
				<WooPaymentsTestModeNotice
					currentPage="disputes"
					isDetailsView
				/>
			) }
			{ liveStatusMessage && (
				<LiveStatusMessage
					isError={ !! missingIdMessage || !! fileDetailsWarning }
				>
					{ liveStatusMessage }
				</LiveStatusMessage>
			) }
			{ /* Client 11.1.0 disputes/new-evidence/index.tsx:780-806: a centred spinner. */ }
			{ isLoading && (
				<Flex
					direction="column"
					align="center"
					justify="center"
					className="woocommerce-woopayments-dispute-challenge__loading"
					aria-busy="true"
				>
					<FlexItem>
						<Spinner />
					</FlexItem>
					<FlexItem>{ loadingMessage }</FlexItem>
				</Flex>
			) }
			{ missingIdMessage && (
				<StatusMessage isError>{ missingIdMessage }</StatusMessage>
			) }
			{ fileDetailsWarning && (
				<StatusMessage isError>{ fileDetailsWarning }</StatusMessage>
			) }
			{ ! isLoading && disputeId && (
				<DisputeEvidenceForm
					dispute={ dispute }
					fileDetails={ fileDetails }
					onDisputeUpdated={ setDispute }
				/>
			) }
		</section>
	);
};

export const WooPaymentsDisputeChallengePage = () => {
	const location = useLocation();
	const disputeId = new URLSearchParams( location.search ).get( 'id' ) || '';
	return <DisputeChallenge key={ disputeId } disputeId={ disputeId } />;
};
