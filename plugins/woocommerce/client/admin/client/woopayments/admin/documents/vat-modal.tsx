/**
 * External dependencies
 */
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Modal,
	Notice,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import {
	saveWooPaymentsVatDetails,
	validateWooPaymentsVatNumber,
} from './data';
import type {
	WooPaymentsVatDetails,
	WooPaymentsVatValidationResponse,
} from './types';
import './vat-modal.scss';

type WooPaymentsVatModalProps = {
	country?: string;
	onClose: () => void;
	onCompleted: ( details: WooPaymentsVatDetails ) => void;
};

// Country-specific tax ID wording, prefixes and hints, as in client 11.1.0
// `client/vat/form/tasks/vat-number-task.tsx:30-170`.
const getVatPrefix = ( country: string ) => {
	switch ( country ) {
		case 'AU':
		case 'JP':
		case 'NZ':
		case 'SG':
			// Countries do not have tax prefixes.
			return '';
		case 'GR':
			return 'EL ';
		case 'CH':
			return 'CHE ';
		default:
			return `${ country } `;
	}
};

const getTaxIdLabel = ( country: string ) => {
	switch ( country ) {
		case 'AU':
			return __( 'ABN', 'woocommerce' );
		case 'JP':
			return __( 'Corporate Number', 'woocommerce' );
		case 'NZ':
			return __( 'IRD Number', 'woocommerce' );
		case 'SG':
			return __( 'UEN or GST Registration Number', 'woocommerce' );
		default:
			return __( 'VAT Number', 'woocommerce' );
	}
};

const getTaxIdRequirementHint = ( country: string ): ReactNode => {
	switch ( country ) {
		case 'AU':
			return __(
				'By inputting your ABN number you confirm that you are going to account for the GST.',
				'woocommerce'
			);
		case 'JP':
			return '';
		case 'NO':
			return __(
				'By inputting your VAT number you confirm you are a Norway VAT registered business and that you are going to account for the VAT.',
				'woocommerce'
			);
		case 'NZ':
			return __(
				'By inputting your IRD number you confirm that you are going to account for the GST.',
				'woocommerce'
			);
		case 'SG':
			return __(
				'By providing your UEN or GST number you confirm you are a Singapore GST registered business and you are going to account for the GST.',
				'woocommerce'
			);
		default:
			return createInterpolateElement(
				__(
					'Tax registration rules vary by region. <learnMoreLink>Learn more about tax documents</learnMoreLink>.',
					'woocommerce'
				),
				{
					learnMoreLink: (
						// @ts-expect-error: children is provided when interpolating the component
						<ExternalLink href="https://woocommerce.com/document/woopayments/taxes/documents/" />
					),
				}
			);
	}
};

const getTaxIdValidationHint = ( country: string ) => {
	switch ( country ) {
		case 'AU':
			return __(
				'11-digit number, for example 12 345 678 901.',
				'woocommerce'
			);
		case 'JP':
			return __(
				'13-digit number, for example 1234567890123.',
				'woocommerce'
			);
		case 'NZ':
			return __(
				'8–digit or 9–digit number, for example 99–999–999 or 999–999–999.',
				'woocommerce'
			);
		case 'SG':
			return __(
				'Enter your UEN (e.g., 200312345A) or GST Registration Number (e.g., M91234567X).',
				'woocommerce'
			);
		default:
			return __(
				'8 to 12 digits with your country code prefix, for example DE 123456789.',
				'woocommerce'
			);
	}
};

const getErrorMessage = ( error: unknown, fallback: string ) => {
	if ( error instanceof Error && error.message ) {
		return error.message;
	}

	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error.message;
	}

	return fallback;
};

const getErrorCode = ( error: unknown ) =>
	error &&
	typeof error === 'object' &&
	'code' in error &&
	typeof error.code === 'string'
		? error.code
		: '';

const getInvalidTaxIdMessage = ( taxIdLabel: string ) =>
	sprintf(
		/* translators: %s: Tax ID label, such as VAT Number or Corporate Number. */
		__( 'The provided %s failed validation.', 'woocommerce' ),
		taxIdLabel
	);

// Client 11.1.0 maps these platform error codes to its own wording (`vat-number-task.tsx:149-171`).
const getValidationErrorMessage = ( error: unknown, taxIdLabel: string ) => {
	switch ( getErrorCode( error ) ) {
		case 'wcpay_invalid_tax_number':
			return getInvalidTaxIdMessage( taxIdLabel );
		case 'wcpay_unsupported_tax_docs_country':
			return __(
				"Your account's country is not supported for tax ID validation.",
				'woocommerce'
			);
		default:
			return getErrorMessage(
				error,
				sprintf(
					/* translators: %s: Tax ID label, such as VAT Number. */
					__(
						'There was a problem validating the %s.',
						'woocommerce'
					),
					taxIdLabel
				)
			);
	}
};

const normalizeValidationDetails = (
	response: WooPaymentsVatValidationResponse,
	vatNumber: string
): WooPaymentsVatDetails => ( {
	vat_number: response.vat_number || vatNumber,
	name: response.name || '',
	address: response.address || '',
} );

export const WooPaymentsVatModal = ( {
	country,
	onClose,
	onCompleted,
}: WooPaymentsVatModalProps ) => {
	const accountCountry = ( country || '' ).toUpperCase();
	const taxIdLabel = getTaxIdLabel( accountCountry );
	const vatNumberPrefix = getVatPrefix( accountCountry );
	const detailsFieldsRef = useRef< HTMLDivElement | null >( null );
	const isClosedRef = useRef( false );
	const hasFocusedDetailsFieldsRef = useRef( false );
	const [ hasValidVatNumber, setHasValidVatNumber ] = useState( false );
	const [ vatNumber, setVatNumber ] = useState( '' );
	const [ details, setDetails ] = useState< WooPaymentsVatDetails | null >(
		null
	);
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ isBusy, setIsBusy ] = useState( false );

	useEffect( () => {
		return () => {
			isClosedRef.current = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! details ) {
			hasFocusedDetailsFieldsRef.current = false;
			return;
		}

		if ( hasFocusedDetailsFieldsRef.current ) {
			return;
		}

		hasFocusedDetailsFieldsRef.current = true;
		detailsFieldsRef.current
			?.querySelector< HTMLInputElement >( 'input' )
			?.focus();
	}, [ details ] );

	const handleClose = () => {
		isClosedRef.current = true;
		onClose();
	};

	const handleHasValidVatNumberChange = ( checked: boolean ) => {
		setHasValidVatNumber( checked );
		setVatNumber( checked ? vatNumberPrefix : '' );
	};

	const handleVatNumberChange = ( value: string ) => {
		// Put the country prefix back when the merchant deletes it, as the client does.
		const prefix = vatNumberPrefix.trim();
		setVatNumber( value.trim().startsWith( prefix ) ? value : prefix );
	};

	const handleContinue = async () => {
		if ( ! hasValidVatNumber ) {
			setDetails( {
				vat_number: null,
				name: '',
				address: '',
			} );
			return;
		}

		const normalizedVatNumber = vatNumber.replace( vatNumberPrefix, '' );

		setIsBusy( true );
		setErrorMessage( null );

		try {
			const response =
				await validateWooPaymentsVatNumber( normalizedVatNumber );

			if ( response.valid === false ) {
				if ( isClosedRef.current ) {
					return;
				}

				setErrorMessage( getInvalidTaxIdMessage( taxIdLabel ) );
				return;
			}

			if ( isClosedRef.current ) {
				return;
			}

			setDetails(
				normalizeValidationDetails( response, normalizedVatNumber )
			);
		} catch ( error ) {
			if ( isClosedRef.current ) {
				return;
			}

			setErrorMessage( getValidationErrorMessage( error, taxIdLabel ) );
		} finally {
			if ( ! isClosedRef.current ) {
				setIsBusy( false );
			}
		}
	};

	const handleConfirm = async () => {
		if ( ! details ) {
			return;
		}

		setIsBusy( true );
		setErrorMessage( null );

		try {
			const savedDetails = await saveWooPaymentsVatDetails( details );

			if ( isClosedRef.current ) {
				return;
			}

			onCompleted( savedDetails );
		} catch ( error ) {
			if ( isClosedRef.current ) {
				return;
			}

			setErrorMessage(
				getErrorMessage(
					error,
					__(
						'There was a problem saving your tax details.',
						'woocommerce'
					)
				)
			);
		} finally {
			if ( ! isClosedRef.current ) {
				setIsBusy( false );
			}
		}
	};

	return (
		<Modal
			className="woocommerce-woopayments-documents-vat-modal"
			title={ __( 'Set your tax details', 'woocommerce' ) }
			onRequestClose={ handleClose }
		>
			{ errorMessage && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage }
				</Notice>
			) }
			{ ! details ? (
				<div className="woocommerce-woopayments-documents-vat-modal__content">
					<h2 className="woocommerce-woopayments-documents-vat-modal__step-title">
						{ sprintf(
							/* translators: %s: Tax ID label, such as VAT Number or Corporate Number. */
							__( 'Set your %s', 'woocommerce' ),
							taxIdLabel
						) }
					</h2>
					<p>
						{ __(
							"The information you provide here will be used for all of your account's tax documents.",
							'woocommerce'
						) }
					</p>
					<CheckboxControl
						label={ sprintf(
							/* translators: %s: Tax ID label, such as VAT Number. */
							__( 'I have a valid %s', 'woocommerce' ),
							taxIdLabel
						) }
						help={ getTaxIdRequirementHint( accountCountry ) }
						checked={ hasValidVatNumber }
						onChange={ handleHasValidVatNumberChange }
						__nextHasNoMarginBottom
					/>
					{ hasValidVatNumber && (
						<TextControl
							label={ taxIdLabel }
							help={ getTaxIdValidationHint( accountCountry ) }
							value={ vatNumber }
							onChange={ handleVatNumberChange }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					) }
					<div className="woocommerce-woopayments-documents-vat-modal__actions">
						<Button variant="tertiary" onClick={ handleClose }>
							{ __( 'Cancel', 'woocommerce' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ isBusy }
							accessibleWhenDisabled
							disabled={
								isBusy ||
								( hasValidVatNumber &&
									vatNumber.trimEnd() ===
										vatNumberPrefix.trimEnd() )
							}
							onClick={ handleContinue }
						>
							{ __( 'Continue', 'woocommerce' ) }
						</Button>
					</div>
				</div>
			) : (
				<div className="woocommerce-woopayments-documents-vat-modal__content">
					<h2 className="woocommerce-woopayments-documents-vat-modal__step-title">
						{ __( 'Confirm your business details', 'woocommerce' ) }
					</h2>
					<div ref={ detailsFieldsRef }>
						<TextControl
							label={ __( 'Business name', 'woocommerce' ) }
							value={ details.name }
							onChange={ ( name ) =>
								setDetails( {
									...details,
									name,
								} )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<TextareaControl
							label={ __( 'Address', 'woocommerce' ) }
							value={ details.address }
							onChange={ ( address ) =>
								setDetails( {
									...details,
									address,
								} )
							}
							__nextHasNoMarginBottom
						/>
					</div>
					<div className="woocommerce-woopayments-documents-vat-modal__actions">
						<Button variant="tertiary" onClick={ handleClose }>
							{ __( 'Cancel', 'woocommerce' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ isBusy }
							accessibleWhenDisabled
							disabled={
								isBusy ||
								! details.name.trim() ||
								! details.address.trim()
							}
							onClick={ handleConfirm }
						>
							{ __( 'Confirm', 'woocommerce' ) }
						</Button>
					</div>
				</div>
			) }
		</Modal>
	);
};
