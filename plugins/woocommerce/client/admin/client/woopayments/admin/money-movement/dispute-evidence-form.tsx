/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import {
	Button,
	Card,
	CardBody,
	ExternalLink,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { chevronRight } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { Stepper } from '@woocommerce/components';
import InfoOutlineIcon from 'gridicons/dist/info-outline';
import { recordEvent } from '@woocommerce/tracks';
import type { ElementType, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { updateWooPaymentsDispute } from './data';
import {
	generateDisputeCoverLetter,
	getCoverLetterMerchantDetails,
} from './dispute-evidence-cover-letter';
import { DisputeEvidenceFileUpload } from './dispute-evidence-file-upload';
import {
	DOCUMENT_EVIDENCE_FIELDS,
	OPTIONAL_TEXT_EVIDENCE_FIELDS,
	PRODUCT_TYPE_OPTIONS,
	SHIPPING_EVIDENCE_FIELDS,
	type DocumentEvidenceField,
	type EvidenceFileMap,
	type EvidenceField,
	type RecommendedDocumentField,
	buildEvidencePayload,
	getEvidenceFileByteTotal,
	getRecommendedDocumentFields,
	getRecommendedShippingDocumentFields,
	isVisaComplianceDispute,
	isDisputeActionable,
	needsShipping,
} from './dispute-evidence-fields';
import type { WooPaymentsDispute, WooPaymentsDisputeFile } from './types';
import {
	formatLabel,
	getBankName,
	getDisputeId,
	getErrorMessage,
} from './utils';
import { WC_ASSET_URL } from '~/utils/admin-settings';
import { getSettingsPaymentsProviderRouteUrl } from '../utils';
import { useGetSettings } from '../../settings/data/hooks';
import { OrderLink } from './transactions-list-fields';
import {
	DisputeNotice,
	DisputeSummaryRow,
} from './transaction-dispute-details';
import './dispute-evidence.scss';

type DisputeEvidenceFormProps = {
	dispute: WooPaymentsDispute;
	fileDetails?: EvidenceFileMap;
	onDisputeUpdated?: ( dispute: WooPaymentsDispute ) => void;
};

type NoticeState = {
	type: 'success' | 'error';
	message: string;
} | null;

type EvidenceStep = 'basics' | 'shipping' | 'review' | 'confirmation';

const getStringEvidenceValue = (
	dispute: WooPaymentsDispute,
	field: EvidenceField
) => {
	const value = dispute.evidence?.[ field ];

	return typeof value === 'string' ? value : '';
};

const getInitialEvidenceState = ( dispute: WooPaymentsDispute ) => {
	const evidence = {} as Record< EvidenceField, string >;

	[
		...DOCUMENT_EVIDENCE_FIELDS,
		...SHIPPING_EVIDENCE_FIELDS,
		...OPTIONAL_TEXT_EVIDENCE_FIELDS,
	].forEach( ( field ) => {
		evidence[ field ] = getStringEvidenceValue( dispute, field );
	} );
	// Client 11.1.0 `new-evidence/index.tsx:584`: the order's IP address is sent as the purchase IP.
	evidence.customer_purchase_ip =
		dispute.order?.ip_address || evidence.customer_purchase_ip;

	return evidence;
};

// Client 11.1.0 `new-evidence/customer-details.tsx`: who bought, read-only.
const CustomerDetails = ( { dispute }: { dispute: WooPaymentsDispute } ) => {
	const charge =
		typeof dispute.charge === 'object' ? dispute.charge : undefined;
	const billing = charge?.billing_details;
	const name = billing?.name || dispute.order?.customer_name || '';
	const email = billing?.email || dispute.order?.customer_email || '';
	const address = ( billing?.formatted_address || '' )
		.split( /<br\s*\/?>/i )
		.map( ( line ) => line.replace( /<[^>]*>/g, '' ).trim() )
		.filter( Boolean );
	const item = ( label: string, value: ReactNode ) => (
		<div>
			<div className="woocommerce-woopayments-dispute-evidence-customer__label">
				{ label }
			</div>
			{ value }
		</div>
	);

	return (
		<section className="woocommerce-woopayments-dispute-evidence-customer">
			<h3>{ __( 'Customer details', 'woocommerce' ) }</h3>
			<div className="woocommerce-woopayments-dispute-evidence-customer__row">
				{ item(
					__( 'NAME', 'woocommerce' ),
					name ? (
						<a
							href={ getSettingsPaymentsProviderRouteUrl(
								addQueryArgs( '/woopayments/transactions', {
									search: email
										? `${ name } (${ email })`
										: name,
								} )
							) }
						>
							{ name }
						</a>
					) : (
						<span>-</span>
					)
				) }
				{ item(
					__( 'PHONE', 'woocommerce' ),
					<span>{ billing?.phone || '-' }</span>
				) }
				{ item(
					__( 'EMAIL', 'woocommerce' ),
					email ? (
						<a href={ `mailto:${ email }` }>{ email }</a>
					) : (
						<span>-</span>
					)
				) }
				{ item(
					__( 'IP ADDRESS', 'woocommerce' ),
					<span>{ dispute.order?.ip_address || '-' }</span>
				) }
			</div>
			{ item(
				__( 'BILLING ADDRESS', 'woocommerce' ),
				<div className="woocommerce-woopayments-dispute-evidence-customer__address">
					{ address.length
						? address.map( ( line ) => (
								<div key={ line }>{ line }</div>
						  ) )
						: '-' }
				</div>
			) }
		</section>
	);
};

// Client 11.1.0 `new-evidence/resolve-product-type.ts`: with the additional evidence types (on by default),
// a saved "multiple" reads as Other.
const getInitialProductType = ( dispute: WooPaymentsDispute ) => {
	const productType =
		dispute.metadata?.__product_type ||
		dispute.order?.suggested_product_type ||
		'physical_product';

	return productType === 'multiple' ? 'other' : productType;
};

const getStepLabel = ( step: EvidenceStep ) => {
	switch ( step ) {
		case 'basics':
			return __( "Let's gather the basics", 'woocommerce' );
		case 'shipping':
			return __( 'Add your shipping details', 'woocommerce' );
		case 'review':
			return __( 'Review your cover letter', 'woocommerce' );
		case 'confirmation':
			return __( 'Thanks for sharing your response!', 'woocommerce' );
	}
};

// Client 11.1.0 `components/inline-notice`: the info-outline gridicon; the shared typings omit its class name.
const OutcomeIcon = InfoOutlineIcon as ElementType< { className?: string } >;

// Client 11.1.0 new-evidence/index.tsx:1035-1073 and confirmation-screen.tsx:168-196: who decides the outcome.
const getOutcomeNoticeText = (
	isVisaCompliance: boolean,
	bankName?: string | null
) => {
	if ( isVisaCompliance ) {
		return __(
			'<strong>The outcome of this dispute will be determined by Visa.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.',
			'woocommerce'
		);
	}
	return bankName
		? sprintf(
				/* translators: %s: the customer's bank. */
				__(
					'<strong>The outcome of this dispute will be determined by %s.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.',
					'woocommerce'
				),
				bankName
		  )
		: __(
				"<strong>The outcome of this dispute will be determined by the cardholder's bank.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.",
				'woocommerce'
		  );
};

// Client 11.1.0 new-evidence/index.tsx:473-479: the stepper labels.
const getStepTabLabel = ( step: EvidenceStep ) => {
	switch ( step ) {
		case 'shipping':
			return __( 'Shipping details', 'woocommerce' );
		case 'review':
			return __( 'Review', 'woocommerce' );
		default:
			return __( 'Purchase info', 'woocommerce' );
	}
};

const getStepDescription = ( step: EvidenceStep ) => {
	switch ( step ) {
		// Client 11.1.0 `disputes/new-evidence/index.tsx:79-96`.
		case 'basics':
			return __(
				"The more info you can provide, the stronger your case will be. To speed things up, we've prefilled some fields for you — please check for accuracy and upload any relevant documents.",
				'woocommerce'
			);
		case 'shipping':
			return __(
				"We've prefilled some of this for you — please check that it's correct and upload the recommended document.",
				'woocommerce'
			);
		case 'review':
			return __(
				"Using the information you've provided, we've automatically generated a cover letter for you. Before submitting to your customer's bank, please check all of the details are correct and make any required changes.",
				'woocommerce'
			);
		case 'confirmation':
			return __(
				"We'll update this dispute when the bank reviews the submitted evidence.",
				'woocommerce'
			);
	}
};

const getPreviousStep = (
	currentStep: EvidenceStep,
	includeShippingStep: boolean
): EvidenceStep => {
	if ( currentStep === 'review' ) {
		return includeShippingStep ? 'shipping' : 'basics';
	}

	return 'basics';
};

const getNextStep = (
	currentStep: EvidenceStep,
	includeShippingStep: boolean
): EvidenceStep | null => {
	if ( currentStep === 'basics' ) {
		return includeShippingStep ? 'shipping' : 'review';
	}

	if ( currentStep === 'shipping' ) {
		return 'review';
	}

	return null;
};

export const DisputeEvidenceForm = ( {
	dispute,
	fileDetails = {},
	onDisputeUpdated,
}: DisputeEvidenceFormProps ) => {
	const [ evidence, setEvidence ] = useState( () =>
		getInitialEvidenceState( dispute )
	);
	const [ productType, setProductType ] = useState( () =>
		getInitialProductType( dispute )
	);
	const [ refundStatus, setRefundStatus ] = useState(
		'refund_has_been_issued'
	);
	const [ duplicateStatus, setDuplicateStatus ] = useState( 'is_duplicate' );
	const [ filesByField, setFilesByField ] =
		useState< EvidenceFileMap >( fileDetails );
	const [ currentStep, setCurrentStep ] =
		useState< EvidenceStep >( 'basics' );
	// Client 11.1.0 new-evidence/index.tsx:481-483: the summary is open only on the first step.
	const [ isSummaryOpen, setIsSummaryOpen ] = useState( true );
	useEffect( () => {
		setIsSummaryOpen( currentStep === 'basics' );
	}, [ currentStep ] );
	const [ isCoverLetterManuallyEdited, setIsCoverLetterManuallyEdited ] =
		useState(
			() => !! getStringEvidenceValue( dispute, 'uncategorized_text' )
		);
	const [ notice, setNotice ] = useState< NoticeState >( null );
	const [ saveInProgress, setSaveInProgress ] = useState<
		'draft' | 'submit' | null
	>( null );
	const [ uploadingFields, setUploadingFields ] = useState<
		Partial< Record< DocumentEvidenceField, boolean > >
	>( {} );
	const formContainerRef = useRef< HTMLDivElement | null >( null );
	const stepHeadingRef = useRef< HTMLHeadingElement | null >( null );
	const previousStepRef = useRef< EvidenceStep >( currentStep );
	const noticeRef = useRef< HTMLDivElement | null >( null );
	const previousFileDetailsRef = useRef< EvidenceFileMap >( fileDetails );
	const disputeId = getDisputeId( dispute );
	const refundIssuedControlId = `${ disputeId }-refund-status-issued`;
	const refundNotOwedControlId = `${ disputeId }-refund-status-not-owed`;
	const duplicateControlId = `${ disputeId }-duplicate-status-duplicate`;
	const notDuplicateControlId = `${ disputeId }-duplicate-status-not-duplicate`;
	const readOnly = ! isDisputeActionable( dispute );
	const disputeCharge =
		typeof dispute.charge === 'object' ? dispute.charge : undefined;
	const chargePaymentMethod = disputeCharge?.payment_method_details;
	const bankName = getBankName( chargePaymentMethod );
	const isUploadingEvidence =
		Object.values( uploadingFields ).some( Boolean );
	const formLocked = readOnly || !! saveInProgress || isUploadingEvidence;
	const totalFileBytes = useMemo(
		() => getEvidenceFileByteTotal( filesByField ),
		[ filesByField ]
	);
	const isVisaCompliance = isVisaComplianceDispute(
		dispute.reason,
		dispute.enhanced_eligibility_types
	);
	const includeShippingStep = needsShipping( dispute.reason, productType );
	const recommendedDocuments = useMemo(
		() =>
			getRecommendedDocumentFields( {
				reason: dispute.reason,
				productType,
				refundStatus,
				duplicateStatus,
				enhancedEligibilityTypes: dispute.enhanced_eligibility_types,
				evidence,
			} ),
		[
			duplicateStatus,
			dispute.enhanced_eligibility_types,
			dispute.reason,
			evidence,
			productType,
			refundStatus,
		]
	);
	const recommendedShippingDocuments = useMemo(
		() =>
			includeShippingStep
				? getRecommendedShippingDocumentFields(
						dispute.reason,
						productType
				  )
				: [],
		[ dispute.reason, includeShippingStep, productType ]
	);
	const recommendedDocumentLabels = useMemo(
		() =>
			[ ...recommendedDocuments, ...recommendedShippingDocuments ].reduce<
				Partial<
					Record< DocumentEvidenceField, RecommendedDocumentField >
				>
			>(
				( labels, document ) => ( {
					...labels,
					[ document.key ]: document,
				} ),
				{}
			),
		[ recommendedDocuments, recommendedShippingDocuments ]
	);
	const documentFieldsToRender = useMemo( () => {
		const fields = recommendedDocuments.map( ( document ) => document.key );

		DOCUMENT_EVIDENCE_FIELDS.forEach( ( field ) => {
			if ( field === 'shipping_documentation' ) {
				return;
			}

			if (
				! fields.includes( field ) &&
				( filesByField[ field ] || evidence[ field ] )
			) {
				fields.push( field );
			}
		} );

		return fields;
	}, [ evidence, filesByField, recommendedDocuments ] );
	const shippingDocumentFieldsToRender = useMemo( () => {
		const fields = recommendedShippingDocuments.map(
			( document ) => document.key
		);

		DOCUMENT_EVIDENCE_FIELDS.forEach( ( field ) => {
			if (
				! fields.includes( field ) &&
				field === 'shipping_documentation' &&
				( filesByField[ field ] || evidence[ field ] )
			) {
				fields.push( field );
			}
		} );

		return fields;
	}, [ evidence, filesByField, recommendedShippingDocuments ] );
	// Client 11.1.0 new-evidence/index.tsx:132-133 reads the payments settings and the charge's bank.
	const settings = useGetSettings();
	const generatedCoverLetter = useMemo( () => {
		const charge =
			typeof dispute.charge === 'object' ? dispute.charge : undefined;

		return generateDisputeCoverLetter( {
			...getCoverLetterMerchantDetails( settings ),
			dispute,
			bankName: getBankName( charge?.payment_method_details ),
			productType,
			evidence,
			refundStatus,
			duplicateStatus,
		} );
	}, [
		dispute,
		duplicateStatus,
		evidence,
		productType,
		refundStatus,
		settings,
	] );
	const disputeTracksProperties = useMemo(
		() => ( {
			dispute_id: disputeId,
			dispute_status: dispute.status,
			dispute_reason: dispute.reason,
		} ),
		[ disputeId, dispute.reason, dispute.status ]
	);

	useEffect( () => {
		setEvidence( getInitialEvidenceState( dispute ) );
		setProductType( getInitialProductType( dispute ) );
		setIsCoverLetterManuallyEdited(
			!! getStringEvidenceValue( dispute, 'uncategorized_text' )
		);
	}, [ dispute ] );

	useEffect( () => {
		if ( isVisaCompliance || isCoverLetterManuallyEdited ) {
			return;
		}

		setEvidence( ( currentEvidence ) => {
			if ( currentEvidence.uncategorized_text === generatedCoverLetter ) {
				return currentEvidence;
			}

			return {
				...currentEvidence,
				uncategorized_text: generatedCoverLetter,
			};
		} );
	}, [
		generatedCoverLetter,
		isCoverLetterManuallyEdited,
		isVisaCompliance,
	] );

	useEffect( () => {
		const previousFileDetails = previousFileDetailsRef.current;

		setFilesByField( ( currentFiles ) => {
			const nextFiles = { ...currentFiles };

			DOCUMENT_EVIDENCE_FIELDS.forEach( ( field ) => {
				const previousFileId = previousFileDetails[ field ]?.id || '';
				const currentFileId = currentFiles[ field ]?.id || '';
				const nextFile = fileDetails[ field ];

				if ( currentFileId !== previousFileId ) {
					return;
				}

				if ( nextFile ) {
					nextFiles[ field ] = nextFile;
					return;
				}

				delete nextFiles[ field ];
			} );

			return nextFiles;
		} );
		previousFileDetailsRef.current = fileDetails;
	}, [ fileDetails ] );

	const updateNotice = useCallback( ( nextNotice: NoticeState ) => {
		setNotice( nextNotice );

		if ( ! nextNotice ) {
			return;
		}

		speak(
			nextNotice.message,
			nextNotice.type === 'error' ? 'assertive' : 'polite'
		);
		setTimeout( () => noticeRef.current?.focus(), 0 );
	}, [] );

	useEffect( () => {
		if ( previousStepRef.current === currentStep ) {
			return;
		}

		previousStepRef.current = currentStep;

		const ownerDocument = formContainerRef.current?.ownerDocument;
		const activeElement = ownerDocument?.activeElement;
		const focusIsInsideForm =
			activeElement instanceof HTMLElement &&
			!! formContainerRef.current?.contains( activeElement );

		if ( activeElement === ownerDocument?.body || focusIsInsideForm ) {
			stepHeadingRef.current?.focus();
		}
	}, [ currentStep ] );

	const updateEvidenceField = ( field: EvidenceField, value: string ) => {
		setEvidence( ( currentEvidence ) => ( {
			...currentEvidence,
			[ field ]: value,
		} ) );
	};

	const updateUploadState = useCallback(
		( field: DocumentEvidenceField, isUploading: boolean ) => {
			setUploadingFields( ( currentUploadingFields ) => {
				const nextUploadingFields = {
					...currentUploadingFields,
				};

				if ( isUploading ) {
					nextUploadingFields[ field ] = true;
				} else {
					delete nextUploadingFields[ field ];
				}

				return nextUploadingFields;
			} );
		},
		[]
	);

	const handleUploaded = (
		field: DocumentEvidenceField,
		file: WooPaymentsDisputeFile
	) => {
		updateNotice( null );
		setFilesByField( ( currentFiles ) => ( {
			...currentFiles,
			[ field ]: file,
		} ) );
		updateEvidenceField( field, file.id || '' );
	};

	const handleRemoveFile = ( field: DocumentEvidenceField ) => {
		setFilesByField( ( currentFiles ) => {
			const nextFiles = { ...currentFiles };
			delete nextFiles[ field ];
			return nextFiles;
		} );
		updateEvidenceField( field, '' );
	};

	const handleError = ( message: string ) => {
		updateNotice( {
			type: 'error',
			message,
		} );
	};

	const handleProductTypeChange = ( nextProductType: string ) => {
		recordEvent( 'wcpay_dispute_product_selected', {
			...disputeTracksProperties,
			selection: nextProductType,
		} );
		setProductType( nextProductType );
		setIsCoverLetterManuallyEdited( false );
	};

	const handleRefundStatusChange = ( nextRefundStatus: string ) => {
		setRefundStatus( nextRefundStatus );
		setIsCoverLetterManuallyEdited( false );
	};

	const handleDuplicateStatusChange = ( nextDuplicateStatus: string ) => {
		setDuplicateStatus( nextDuplicateStatus );
		setIsCoverLetterManuallyEdited( false );
	};

	const handleSave = async (
		submit: boolean,
		{
			notify = true,
			refreshDispute = true,
			trackSuccess = true,
		}: {
			notify?: boolean;
			refreshDispute?: boolean;
			trackSuccess?: boolean;
		} = {}
	) => {
		if ( readOnly || saveInProgress ) {
			return null;
		}

		if ( isUploadingEvidence ) {
			updateNotice( {
				type: 'error',
				message: __(
					'Please wait until file upload is finished',
					'woocommerce'
				),
			} );
			return null;
		}

		if ( submit ) {
			// eslint-disable-next-line no-alert -- The reference dispute flow confirms final submission with a browser confirmation.
			const confirmed = window.confirm(
				__(
					'Are you sure you’re ready to submit this evidence? Evidence submissions are final.',
					'woocommerce'
				)
			);

			if ( ! confirmed ) {
				return null;
			}
		}

		const eventPrefix = submit
			? 'wcpay_dispute_submit_evidence'
			: 'wcpay_dispute_save_evidence';

		recordEvent( `${ eventPrefix }_clicked`, disputeTracksProperties );
		updateNotice( null );
		setSaveInProgress( submit ? 'submit' : 'draft' );

		try {
			const nextEvidence = {
				...evidence,
				uncategorized_text:
					evidence.uncategorized_text ||
					( isVisaCompliance ? '' : generatedCoverLetter ),
			};
			const updatedDispute = await updateWooPaymentsDispute(
				disputeId,
				buildEvidencePayload(
					{
						reason: dispute.reason,
						productType,
						refundStatus,
						duplicateStatus,
						evidence: nextEvidence,
						existingEvidence: dispute.evidence,
						metadata: dispute.metadata,
					},
					submit
				)
			);
			if ( trackSuccess ) {
				recordEvent(
					`${ eventPrefix }_success`,
					disputeTracksProperties
				);
			}
			if ( submit ) {
				setCurrentStep( 'confirmation' );
			}
			if ( notify ) {
				updateNotice( {
					type: 'success',
					message: submit
						? __( 'Evidence submitted!', 'woocommerce' )
						: __( 'Evidence saved!', 'woocommerce' ),
				} );
			}
			if ( refreshDispute ) {
				onDisputeUpdated?.( updatedDispute );
			}

			return updatedDispute;
		} catch ( error ) {
			const message = getErrorMessage(
				error,
				submit
					? __( 'Unable to submit dispute evidence.', 'woocommerce' )
					: __( 'Unable to save dispute evidence.', 'woocommerce' )
			);

			recordEvent( `${ eventPrefix }_failed`, disputeTracksProperties );
			updateNotice( {
				type: 'error',
				message,
			} );

			return null;
		} finally {
			setSaveInProgress( null );
		}
	};

	const handleContinue = async () => {
		const nextStep = getNextStep( currentStep, includeShippingStep );

		if ( ! nextStep ) {
			return;
		}

		if ( readOnly ) {
			setCurrentStep( nextStep );
			return;
		}

		const updatedDispute = await handleSave( false, {
			notify: false,
			refreshDispute: false,
			trackSuccess: false,
		} );

		if ( updatedDispute ) {
			setCurrentStep( nextStep );
		}
	};

	const handleBack = () => {
		setCurrentStep( getPreviousStep( currentStep, includeShippingStep ) );
	};

	const handleCoverLetterChange = ( value: string ) => {
		setIsCoverLetterManuallyEdited( true );
		updateEvidenceField( 'uncategorized_text', value );
	};

	const handleVisaComplianceDetailsChange = ( value: string ) => {
		if ( value.length > 20000 ) {
			return;
		}

		handleCoverLetterChange( value );
	};

	const renderRecommendedDocumentsSection = (
		fieldsToRender = documentFieldsToRender
	) => (
		<fieldset className="woocommerce-woopayments-dispute-evidence__section">
			<legend>{ __( 'Recommended documents', 'woocommerce' ) }</legend>
			<p className="woocommerce-woopayments-dispute-evidence__section-description">
				{ __(
					'While optional, we strongly recommend providing as many of these documents as possible. The following file types are supported: PDF, JPEG, and PNG.',
					'woocommerce'
				) }
			</p>
			<p className="woocommerce-woopayments-dispute-evidence__section-description">
				<ExternalLink href="https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#challenge-or-accept">
					{ __( 'Learn more about documents', 'woocommerce' ) }
				</ExternalLink>
			</p>
			{ fieldsToRender.map( ( field ) => {
				const recommendedDocument = recommendedDocumentLabels[ field ];
				const label =
					recommendedDocument?.label || formatLabel( field );

				return (
					<div
						key={ field }
						className="woocommerce-woopayments-dispute-evidence__document"
					>
						<DisputeEvidenceFileUpload
							field={ field }
							label={ label }
							file={ filesByField[ field ] }
							totalFileBytes={ totalFileBytes }
							disabled={
								readOnly ||
								!! saveInProgress ||
								( isUploadingEvidence &&
									! uploadingFields[ field ] )
							}
							disputeTracksProperties={ disputeTracksProperties }
							onUploaded={ handleUploaded }
							onRemove={ handleRemoveFile }
							onError={ handleError }
							onUploadStateChange={ updateUploadState }
							description={ recommendedDocument?.description }
						/>
					</div>
				);
			} ) }
		</fieldset>
	);

	return (
		<div
			ref={ formContainerRef }
			className="woocommerce-woopayments-dispute-evidence"
		>
			{ /* Client 11.1.0 new-evidence/index.tsx:1543-1575: the dispute summary, open on the first step. */ }
			{ ! isVisaCompliance && (
				<Panel className="woocommerce-woopayments-dispute-evidence__summary">
					<PanelBody
						title={ __( 'Challenge dispute', 'woocommerce' ) }
						opened={ isSummaryOpen }
						onToggle={ () => setIsSummaryOpen( ! isSummaryOpen ) }
					>
						<DisputeNotice
							dispute={ dispute }
							paymentMethod={ chargePaymentMethod?.type }
							bankName={ bankName }
						/>
						<DisputeSummaryRow
							dispute={ dispute }
							extraItems={ [
								{
									title: __( 'Order', 'woocommerce' ),
									content: (
										<OrderLink order={ dispute.order } />
									),
								},
							] }
						/>
					</PanelBody>
				</Panel>
			) }
			{ notice && (
				<div
					ref={ noticeRef }
					className={ `woocommerce-woopayments-dispute-evidence__notice is-${ notice.type }` }
					role={ notice.type === 'error' ? 'alert' : 'status' }
					aria-live={
						notice.type === 'error' ? 'assertive' : 'polite'
					}
					tabIndex={ -1 }
				>
					{ notice.message }
				</div>
			) }
			{ currentStep === 'confirmation' ? (
				// Client 11.1.0 `new-evidence/confirmation-screen.tsx`.
				<div className="woocommerce-woopayments-dispute-evidence__confirmation">
					<div className="woocommerce-woopayments-dispute-evidence__confirmation-content">
						<img
							className="woocommerce-woopayments-dispute-evidence__confirmation-image"
							src={ `${
								WC_ASSET_URL || ''
							}images/settings-payments/dispute-evidence-submitted.svg` }
							alt={ __(
								'Evidence submitted successfully',
								'woocommerce'
							) }
						/>
						<h2 ref={ stepHeadingRef } tabIndex={ -1 }>
							{ getStepLabel( 'confirmation' ) }
						</h2>
						<p className="woocommerce-woopayments-dispute-evidence__confirmation-subtitle">
							{ isVisaCompliance
								? __(
										'Your response has been submitted under Visa’s compliance process.',
										'woocommerce'
								  )
								: __(
										"Your evidence has been sent to the cardholder's bank for review.",
										'woocommerce'
								  ) }
						</p>
						<h3>{ __( 'What’s next?', 'woocommerce' ) }</h3>
						<ul>
							<li>
								{ isVisaCompliance
									? __(
											'Visa will review your submission under its network rules and determine the outcome of the dispute.',
											'woocommerce'
									  )
									: __(
											'The cardholder’s bank will review your response. Please be patient — this usually takes a few weeks, but in some cases it can take up to 3 months.',
											'woocommerce'
									  ) }
							</li>
							{ isVisaCompliance && (
								<li>
									{ __(
										'This review typically takes several weeks, but in some cases may take up to 3 months.',
										'woocommerce'
									) }
								</li>
							) }
							<li>
								{ createInterpolateElement(
									__(
										"You'll be informed of any updates via email, or you can check the status of your case at any time in your <disputesPageLink>Disputes area</disputesPageLink>.",
										'woocommerce'
									),
									{
										disputesPageLink: (
											// eslint-disable-next-line jsx-a11y/anchor-has-content -- Content is interpolated.
											<a
												href={ getSettingsPaymentsProviderRouteUrl(
													'/woopayments/disputes'
												) }
											/>
										),
									}
								) }
							</li>
						</ul>
						<h3>{ __( 'Useful resources', 'woocommerce' ) }</h3>
						<ul>
							<li>
								{ createInterpolateElement(
									__(
										'Help prevent any further disputes by <learnMoreLink>following the advice in our guide</learnMoreLink>',
										'woocommerce'
									),
									{
										learnMoreLink: (
											<ExternalLink href="https://woocommerce.com/document/woopayments/fraud-and-disputes/preventing-disputes/">
												{ '' }
											</ExternalLink>
										),
									}
								) }
							</li>
							<li>
								{ createInterpolateElement(
									__(
										'Learn more about the dispute process using <learnMoreLink>our resources</learnMoreLink>',
										'woocommerce'
									),
									{
										learnMoreLink: (
											<ExternalLink href="https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#how-they-work">
												{ '' }
											</ExternalLink>
										),
									}
								) }
							</li>
						</ul>
						<Notice
							status="info"
							isDismissible={ false }
							className="woocommerce-woopayments-dispute-evidence__outcome"
						>
							<OutcomeIcon className="woocommerce-woopayments-dispute-evidence__outcome-icon" />
							<span>
								{ createInterpolateElement(
									getOutcomeNoticeText(
										isVisaCompliance,
										bankName
									),
									{ strong: <strong /> }
								) }
							</span>
						</Notice>
						<div className="woocommerce-woopayments-dispute-evidence__actions">
							<Button
								variant="secondary"
								href={ getSettingsPaymentsProviderRouteUrl(
									'/woopayments/disputes'
								) }
							>
								{ __( 'Return to disputes', 'woocommerce' ) }
							</Button>
							<Button
								variant="primary"
								href={ getSettingsPaymentsProviderRouteUrl(
									`/woopayments/disputes/challenge?id=${ encodeURIComponent(
										disputeId
									) }`
								) }
							>
								{ __(
									'View submitted dispute',
									'woocommerce'
								) }
							</Button>
						</div>
					</div>
				</div>
			) : (
				// Client 11.1.0 new-evidence/index.tsx:1583-1597: the steps and the step content in one card.
				<Card className="woocommerce-woopayments-dispute-evidence__stepper-card">
					{ ! isVisaCompliance && (
						<Stepper
							className="woocommerce-woopayments-dispute-evidence__stepper"
							currentStep={ currentStep }
							steps={ ( includeShippingStep
								? ( [
										'basics',
										'shipping',
										'review',
								  ] as const )
								: ( [ 'basics', 'review' ] as const )
							).map( ( step ) => ( {
								key: step,
								label: getStepTabLabel( step ),
								description: '',
								content: null,
							} ) ) }
						/>
					) }
					<CardBody className="woocommerce-woopayments-dispute-evidence__stepper-content">
						<form className="woocommerce-woopayments-dispute-evidence__form">
							<div
								className="woocommerce-woopayments-dispute-evidence__step"
								aria-current="step"
							>
								<h2 ref={ stepHeadingRef } tabIndex={ -1 }>
									{ isVisaCompliance
										? __(
												'Dispute information',
												'woocommerce'
										  )
										: getStepLabel( currentStep ) }
								</h2>
								<p>
									{ isVisaCompliance
										? __(
												'Tell us about this compliance dispute and upload any relevant documents.',
												'woocommerce'
										  )
										: getStepDescription( currentStep ) }
								</p>
							</div>
							{ isVisaCompliance && (
								<>
									<fieldset className="woocommerce-woopayments-dispute-evidence__section">
										<legend>
											{ __(
												'Dispute details',
												'woocommerce'
											) }
										</legend>
										<h4>
											{ __(
												'Tell us about the dispute',
												'woocommerce'
											) }
										</h4>
										<p className="woocommerce-woopayments-dispute-evidence__section-description">
											{ __(
												'This is a compliance case and the issuer has indicated network rules have been violated. Please check for accuracy and upload any relevant documents.',
												'woocommerce'
											) }
										</p>
										<TextareaControl
											label={ __(
												'Why do you disagree with this dispute?',
												'woocommerce'
											) }
											help={ __(
												'Please enter any relevant details here.',
												'woocommerce'
											) }
											value={
												evidence.uncategorized_text
											}
											readOnly={ formLocked }
											maxLength={ 20000 }
											rows={ 10 }
											__nextHasNoMarginBottom
											onChange={
												handleVisaComplianceDetailsChange
											}
										/>
									</fieldset>
									{ renderRecommendedDocumentsSection() }
								</>
							) }
							{ ! isVisaCompliance &&
								currentStep === 'basics' && (
									<>
										<CustomerDetails dispute={ dispute } />
										<fieldset className="woocommerce-woopayments-dispute-evidence__section">
											<legend>
												{ __(
													'Product or service details',
													'woocommerce'
												) }
											</legend>
											<p className="woocommerce-woopayments-dispute-evidence__section-description">
												{ __(
													'Please ensure the product or service type and description have been entered accurately.',
													'woocommerce'
												) }
											</p>
											<SelectControl
												label={ __(
													'Product or service type',
													'woocommerce'
												) }
												value={ productType }
												options={ PRODUCT_TYPE_OPTIONS }
												disabled={ formLocked }
												__next40pxDefaultSize
												__nextHasNoMarginBottom
												onChange={
													handleProductTypeChange
												}
											/>
											<TextareaControl
												label={ __(
													'Product or service description',
													'woocommerce'
												) }
												value={
													evidence.product_description
												}
												readOnly={ formLocked }
												__nextHasNoMarginBottom
												onChange={ ( value ) =>
													updateEvidenceField(
														'product_description',
														value
													)
												}
											/>
										</fieldset>
										{ dispute.reason ===
											'credit_not_processed' && (
											<fieldset className="woocommerce-woopayments-dispute-evidence__section">
												<legend>
													{ __(
														'Refund status',
														'woocommerce'
													) }
												</legend>
												<div className="woocommerce-woopayments-dispute-evidence__radio-group">
													<label
														htmlFor={
															refundIssuedControlId
														}
													>
														<input
															id={
																refundIssuedControlId
															}
															type="radio"
															name="woocommerce-woopayments-dispute-refund-status"
															value="refund_has_been_issued"
															checked={
																refundStatus ===
																'refund_has_been_issued'
															}
															disabled={
																formLocked
															}
															onChange={ () =>
																handleRefundStatusChange(
																	'refund_has_been_issued'
																)
															}
														/>
														{ __(
															'Refund has been issued',
															'woocommerce'
														) }
													</label>
													<label
														htmlFor={
															refundNotOwedControlId
														}
													>
														<input
															id={
																refundNotOwedControlId
															}
															type="radio"
															name="woocommerce-woopayments-dispute-refund-status"
															value="refund_was_not_owed"
															checked={
																refundStatus ===
																'refund_was_not_owed'
															}
															disabled={
																formLocked
															}
															onChange={ () =>
																handleRefundStatusChange(
																	'refund_was_not_owed'
																)
															}
														/>
														{ __(
															'Refund was not owed',
															'woocommerce'
														) }
													</label>
												</div>
											</fieldset>
										) }
										{ dispute.reason === 'duplicate' && (
											<fieldset className="woocommerce-woopayments-dispute-evidence__section">
												<legend>
													{ __(
														'Was this charge a duplicate?',
														'woocommerce'
													) }
												</legend>
												<div className="woocommerce-woopayments-dispute-evidence__radio-group">
													<label
														htmlFor={
															duplicateControlId
														}
													>
														<input
															id={
																duplicateControlId
															}
															type="radio"
															name="woocommerce-woopayments-dispute-duplicate-status"
															value="is_duplicate"
															checked={
																duplicateStatus ===
																'is_duplicate'
															}
															disabled={
																formLocked
															}
															onChange={ () =>
																handleDuplicateStatusChange(
																	'is_duplicate'
																)
															}
														/>
														{ __(
															'It was a duplicate',
															'woocommerce'
														) }
													</label>
													<label
														htmlFor={
															notDuplicateControlId
														}
													>
														<input
															id={
																notDuplicateControlId
															}
															type="radio"
															name="woocommerce-woopayments-dispute-duplicate-status"
															value="is_not_duplicate"
															checked={
																duplicateStatus ===
																'is_not_duplicate'
															}
															disabled={
																formLocked
															}
															onChange={ () =>
																handleDuplicateStatusChange(
																	'is_not_duplicate'
																)
															}
														/>
														{ __(
															'It was not a duplicate',
															'woocommerce'
														) }
													</label>
												</div>
											</fieldset>
										) }
										{ renderRecommendedDocumentsSection() }
									</>
								) }
							{ ! isVisaCompliance &&
								currentStep === 'shipping' && (
									<>
										<fieldset className="woocommerce-woopayments-dispute-evidence__section">
											<legend>
												{ __(
													'Shipping details',
													'woocommerce'
												) }
											</legend>
											{ SHIPPING_EVIDENCE_FIELDS.map(
												( field ) => (
													<TextControl
														key={ field }
														label={ formatLabel(
															field
														) }
														value={
															evidence[ field ]
														}
														readOnly={ formLocked }
														__next40pxDefaultSize
														__nextHasNoMarginBottom
														onChange={ ( value ) =>
															updateEvidenceField(
																field,
																value
															)
														}
													/>
												)
											) }
										</fieldset>
										{ renderRecommendedDocumentsSection(
											shippingDocumentFieldsToRender
										) }
									</>
								) }
							{ ! isVisaCompliance &&
								currentStep === 'review' && (
									<fieldset className="woocommerce-woopayments-dispute-evidence__section">
										<legend>
											{ __( 'Review', 'woocommerce' ) }
										</legend>
										<TextareaControl
											label={ __(
												'Cover letter',
												'woocommerce'
											) }
											value={
												evidence.uncategorized_text ||
												generatedCoverLetter
											}
											readOnly={ formLocked }
											__nextHasNoMarginBottom
											onChange={ handleCoverLetterChange }
										/>
									</fieldset>
								) }
							{ /* Client 11.1.0 new-evidence/index.tsx:1035-1059: who decides the outcome. */ }
							{ ! isVisaCompliance && (
								<Notice
									status="info"
									isDismissible={ false }
									className="woocommerce-woopayments-dispute-evidence__outcome"
								>
									<OutcomeIcon className="woocommerce-woopayments-dispute-evidence__outcome-icon" />
									<span>
										{ createInterpolateElement(
											getOutcomeNoticeText(
												false,
												bankName
											),
											{ strong: <strong /> }
										) }
									</span>
								</Notice>
							) }
							{ /* Client 11.1.0 new-evidence/index.tsx:1348-1533: Cancel or Back on the left, Save for later and Next or Submit on the right. */ }
							<div className="woocommerce-woopayments-dispute-evidence__actions">
								{ isVisaCompliance ||
								currentStep === 'basics' ? (
									<Button
										variant="secondary"
										href={ getSettingsPaymentsProviderRouteUrl(
											`/woopayments/disputes/details?id=${ encodeURIComponent(
												disputeId
											) }`
										) }
									>
										{ __( 'Cancel', 'woocommerce' ) }
									</Button>
								) : (
									<Button
										variant="secondary"
										type="button"
										accessibleWhenDisabled
										disabled={
											!! saveInProgress ||
											isUploadingEvidence
										}
										onClick={ handleBack }
									>
										{ __( 'Back', 'woocommerce' ) }
									</Button>
								) }
								<div className="woocommerce-woopayments-dispute-evidence__actions-right">
									{ ! readOnly && (
										<Button
											variant="tertiary"
											type="button"
											isBusy={
												saveInProgress === 'draft'
											}
											accessibleWhenDisabled
											disabled={
												!! saveInProgress ||
												isUploadingEvidence
											}
											onClick={ () =>
												handleSave( false )
											}
										>
											{ __(
												'Save for later',
												'woocommerce'
											) }
										</Button>
									) }
									{ ! isVisaCompliance &&
										getNextStep(
											currentStep,
											includeShippingStep
										) && (
											<Button
												variant="primary"
												type="button"
												icon={ chevronRight }
												iconPosition="right"
												isBusy={
													saveInProgress === 'draft'
												}
												accessibleWhenDisabled
												disabled={
													!! saveInProgress ||
													isUploadingEvidence
												}
												onClick={ handleContinue }
											>
												{ __( 'Next', 'woocommerce' ) }
											</Button>
										) }
									{ ! readOnly &&
										( isVisaCompliance ||
											currentStep === 'review' ) && (
											<Button
												variant="primary"
												type="button"
												isBusy={
													saveInProgress === 'submit'
												}
												accessibleWhenDisabled
												disabled={
													!! saveInProgress ||
													isUploadingEvidence
												}
												onClick={ () =>
													handleSave( true )
												}
											>
												{ __(
													'Submit',
													'woocommerce'
												) }
											</Button>
										) }
								</div>
							</div>
						</form>
					</CardBody>
				</Card>
			) }
		</div>
	);
};
