/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, Icon } from '@wordpress/components';
import { closeSmall, cloudUpload } from '@wordpress/icons';
import clsx from 'clsx';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import type { ChangeEvent, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { uploadWooPaymentsDisputeFile } from './data';
import {
	EVIDENCE_FILE_ACCEPT_ATTRIBUTE,
	MAX_EVIDENCE_FILE_BYTES,
	type DocumentEvidenceField,
	getEvidenceFileName,
	isAcceptedEvidenceFile,
} from './dispute-evidence-fields';
import type { WooPaymentsDisputeFile } from './types';
import { getErrorMessage } from './utils';

type DisputeEvidenceFileUploadProps = {
	field: DocumentEvidenceField;
	label: string;
	/** The document's explanation, under its name. */
	description?: ReactNode;
	file?: WooPaymentsDisputeFile;
	totalFileBytes: number;
	disabled?: boolean;
	disputeTracksProperties: Record< string, string | undefined >;
	onUploaded: (
		field: DocumentEvidenceField,
		file: WooPaymentsDisputeFile
	) => void;
	onRemove: ( field: DocumentEvidenceField ) => void;
	onError: ( message: string ) => void;
	onUploadStateChange?: (
		field: DocumentEvidenceField,
		isUploading: boolean
	) => void;
};

export const DisputeEvidenceFileUpload = ( {
	field,
	label,
	description,
	file,
	totalFileBytes,
	disabled = false,
	disputeTracksProperties,
	onUploaded,
	onRemove,
	onError,
	onUploadStateChange,
}: DisputeEvidenceFileUploadProps ) => {
	const [ isUploading, setIsUploading ] = useState( false );
	const rowRef = useRef< HTMLDivElement | null >( null );
	const inputRef = useRef< HTMLInputElement | null >( null );
	const inputId = `woocommerce-woopayments-dispute-evidence-${ field }`;
	const fileName = getEvidenceFileName( file );
	const currentFileSize = file?.size || 0;
	const labelAlreadyContainsUpload = label
		.toLowerCase()
		.startsWith( 'upload ' );
	const uploadLabel = labelAlreadyContainsUpload
		? label
		: sprintf(
				/* translators: %s: evidence file field label. */
				__( 'Upload %s', 'woocommerce' ),
				label.toLowerCase()
		  );
	const removeFieldLabel = labelAlreadyContainsUpload
		? label.replace( /^upload\s+/i, '' )
		: label;
	const removeLabel = sprintf(
		/* translators: %s: evidence file field label. */
		__( 'Remove %s', 'woocommerce' ),
		removeFieldLabel.toLowerCase()
	);
	const isControlDisabled = disabled || isUploading;

	useEffect( () => {
		return () => onUploadStateChange?.( field, false );
	}, [ field, onUploadStateChange ] );

	const setUploading = ( nextIsUploading: boolean ) => {
		setIsUploading( nextIsUploading );
		onUploadStateChange?.( field, nextIsUploading );
	};

	const handleFileChange = async (
		event: ChangeEvent< HTMLInputElement >
	) => {
		const selectedFile = event.target.files?.[ 0 ];
		event.target.value = '';

		if ( ! selectedFile ) {
			return;
		}

		if ( ! isAcceptedEvidenceFile( selectedFile ) ) {
			onError(
				__(
					'Upload a PDF, PNG, or JPEG file for dispute evidence.',
					'woocommerce'
				)
			);
			return;
		}

		const nextTotal = totalFileBytes - currentFileSize + selectedFile.size;

		if ( nextTotal > MAX_EVIDENCE_FILE_BYTES ) {
			onError(
				__(
					'The selected files exceed the 4.5 MB dispute evidence limit.',
					'woocommerce'
				)
			);
			return;
		}

		recordEvent( 'wcpay_dispute_file_upload_started', {
			...disputeTracksProperties,
			type: field,
		} );
		setUploading( true );

		const body = new FormData();
		body.append( 'file', selectedFile );
		body.append( 'purpose', 'dispute_evidence' );

		let uploadSucceeded = false;

		try {
			const uploadedFile = await uploadWooPaymentsDisputeFile( body );
			uploadSucceeded = true;
			onUploaded( field, uploadedFile );
			speak(
				sprintf(
					/* translators: %s: evidence file field label. */
					__( '%s uploaded.', 'woocommerce' ),
					label
				),
				'polite'
			);
			recordEvent( 'wcpay_dispute_file_upload_success', {
				...disputeTracksProperties,
				type: field,
			} );
		} catch ( error ) {
			const message = getErrorMessage(
				error,
				__( 'Unable to upload dispute evidence file.', 'woocommerce' )
			);
			onError( message );
			recordEvent( 'wcpay_dispute_file_upload_failed', {
				...disputeTracksProperties,
				message,
			} );
		} finally {
			setUploading( false );
			if ( uploadSucceeded ) {
				setTimeout( () => {
					const ownerDocument = rowRef.current?.ownerDocument;
					const activeElement = ownerDocument?.activeElement;
					const focusIsStillInUploadRow =
						activeElement instanceof HTMLElement &&
						!! rowRef.current?.contains( activeElement );

					if (
						activeElement === ownerDocument?.body ||
						focusIsStillInUploadRow
					) {
						inputRef.current?.focus();
					}
				}, 0 );
			}
		}
	};

	const handleRemove = () => {
		onRemove( field );
		speak(
			sprintf(
				/* translators: %s: evidence file field label. */
				__( '%s removed.', 'woocommerce' ),
				label
			),
			'polite'
		);
		setTimeout( () => inputRef.current?.focus(), 0 );
	};

	// Client 11.1.0 `disputes/new-evidence/file-upload-control.tsx`: the document name and description, the
	// uploaded file as a chip, and an upload icon button. The file input stays the accessible control; the
	// button is its label, so a click opens the file dialog and the keyboard focuses the input.
	return (
		<div
			ref={ rowRef }
			className="woocommerce-woopayments-dispute-evidence-file"
		>
			<div className="woocommerce-woopayments-dispute-evidence-file__main">
				<span className="woocommerce-woopayments-dispute-evidence-file__label">
					{ removeFieldLabel }
				</span>
				{ fileName && (
					<span className="woocommerce-woopayments-dispute-evidence-file__chip">
						<span className="woocommerce-woopayments-dispute-evidence-file__name">
							{ fileName }
						</span>
						{ ! disabled && (
							<Button
								icon={ closeSmall }
								size="small"
								label={ removeLabel }
								accessibleWhenDisabled
								disabled={ isControlDisabled }
								onClick={ handleRemove }
							/>
						) }
					</span>
				) }
				{ description && (
					<p className="woocommerce-woopayments-dispute-evidence__document-description">
						{ description }
					</p>
				) }
			</div>
			<div className="woocommerce-woopayments-dispute-evidence-file__controls">
				<input
					ref={ inputRef }
					id={ inputId }
					className="woocommerce-woopayments-dispute-evidence-file__input"
					type="file"
					accept={ EVIDENCE_FILE_ACCEPT_ATTRIBUTE }
					disabled={ isControlDisabled }
					onChange={ handleFileChange }
				/>
				<label
					htmlFor={ inputId }
					className={ clsx(
						'components-button is-primary has-icon woocommerce-woopayments-dispute-evidence-file__upload',
						{
							'is-busy': isUploading,
							'is-disabled': isControlDisabled,
						}
					) }
				>
					<Icon icon={ cloudUpload } size={ 24 } />
					<span className="screen-reader-text">{ uploadLabel }</span>
				</label>
			</div>
		</div>
	);
};
