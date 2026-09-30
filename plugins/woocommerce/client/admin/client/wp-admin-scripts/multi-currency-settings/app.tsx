/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CardBody,
	CheckboxControl,
	ExternalLink,
	Modal,
	Notice,
	SearchControl,
	Spinner,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type {
	AutomaticRatesDescriptor,
	MultiCurrencyCurrency,
	StoreCurrenciesResponse,
} from './types';
import { SettingsSection } from './settings-section';
import { StoreLevelSettings } from './store-settings';
import { CurrencySettingsModal } from './currency-settings-modal';
import {
	getDependentPaymentMethods,
	RemoveCurrencyModal,
} from './remove-currency-modal';

const REST_BASE = '/wc/v3/payments/multi-currency';
const EMPTY_SELECTION_HINT_ID =
	'woocommerce-multi-currency-settings__empty-selection-hint';

// One source for the refusal wording, so what a screen reader is told when the
// selection empties cannot drift from what the button's description says.
const emptySelectionHint = (): string =>
	__(
		'Select at least one currency to update your enabled currencies. To stop offering a currency, remove it from the list of enabled currencies.',
		'woocommerce'
	);

const currencyValues = (
	currencies: Record< string, MultiCurrencyCurrency >
): MultiCurrencyCurrency[] => Object.values( currencies );

// Client 11.1.0 MultiCurrency.php:1724-1728 sorts currencies by name (PHP ksort), with the store currency first where it is listed.
const byName = (
	first: MultiCurrencyCurrency,
	second: MultiCurrencyCurrency
): number => {
	if ( Boolean( first.is_default ) !== Boolean( second.is_default ) ) {
		return first.is_default ? -1 : 1;
	}

	if ( first.name === second.name ) {
		return 0;
	}

	return first.name < second.name ? -1 : 1;
};

const normalizeEnabledCodes = (
	codes: string[],
	defaultCode: string,
	availableCurrencies: MultiCurrencyCurrency[]
): string[] => {
	const selectedLookup = new Set( [ defaultCode, ...codes ] );

	return availableCurrencies
		.map( ( currency ) => currency.code )
		.filter( ( code ) => selectedLookup.has( code ) );
};

// Client 11.1.0 enabled-currencies-list/list-item.js:58: "£ GBP", or "CHF" when the symbol is the code.
const formatSymbolAndCode = ( currency: MultiCurrencyCurrency ): string => {
	const symbol = decodeEntities( currency.symbol );

	return symbol === currency.code ? symbol : `${ symbol } ${ currency.code }`;
};

// Client 11.1.0 enabled-currencies-list/list-item.js:30-42: "1 USD → 0.88 EUR", or per 1,000 units of a zero-decimal store currency.
const formatExchangeRate = (
	currency: MultiCurrencyCurrency,
	defaultCurrency: MultiCurrencyCurrency
): string => {
	if ( currency.is_default ) {
		return __( 'Default currency', 'woocommerce' );
	}

	if ( currency.rate === null ) {
		return __( 'Manual rate required', 'woocommerce' );
	}

	if ( defaultCurrency.is_zero_decimal ) {
		return `1,000 ${ defaultCurrency.code } → ${ (
			currency.rate * 1000
		).toFixed( 2 ) } ${ currency.code }`;
	}

	return `1 ${ defaultCurrency.code } → ${ currency.rate.toFixed( 2 ) } ${
		currency.code
	}`;
};

// Only the outage and no-provider states get a notice: the client shows no rate-source notice (owner ruling N-251 R1).
const getAutomaticRatesNotice = (
	automaticRates: AutomaticRatesDescriptor
): string | null => {
	if ( automaticRates.source === null ) {
		return __(
			'No automatic-rate provider is available. Set manual rates for enabled currencies.',
			'woocommerce'
		);
	}

	if ( automaticRates.available ) {
		return null;
	}

	if ( automaticRates.source === 'woopayments' ) {
		return __(
			'WooPayments automatic rates are temporarily unavailable. You can use manual rates.',
			'woocommerce'
		);
	}

	return sprintf(
		/* translators: %s: Automatic-rate provider ID. */
		__(
			'Automatic rates from %s are temporarily unavailable. You can use manual rates.',
			'woocommerce'
		),
		automaticRates.source
	);
};

const updateCurrencyRecordRate = (
	currencies: Record< string, MultiCurrencyCurrency >,
	code: string,
	rate: number
): Record< string, MultiCurrencyCurrency > => {
	if ( ! currencies[ code ] ) {
		return currencies;
	}

	return {
		...currencies,
		[ code ]: {
			...currencies[ code ],
			rate,
		},
	};
};

export function MultiCurrencySettingsApp() {
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );
	const [ currencies, setCurrencies ] =
		useState< StoreCurrenciesResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ currencyToRemove, setCurrencyToRemove ] =
		useState< MultiCurrencyCurrency | null >( null );
	const [ managedCurrencyCode, setManagedCurrencyCode ] = useState<
		string | null
	>( null );
	const [ selectedCodes, setSelectedCodes ] = useState< string[] >( [] );
	const [ search, setSearch ] = useState( '' );
	const manageCurrenciesButtonRef = useRef< HTMLButtonElement | null >(
		null
	);
	const manageCurrencyButtonRef = useRef< HTMLButtonElement | null >( null );
	const shouldRestoreManagementFocusRef = useRef( false );
	const shouldRestoreManagedCurrencyFocusRef = useRef( false );

	useEffect( () => {
		let isMounted = true;

		apiFetch< StoreCurrenciesResponse >( {
			path: `${ REST_BASE }/currencies`,
		} )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				setCurrencies( response );
			} )
			.catch( () => {
				if ( ! isMounted ) {
					return;
				}

				createErrorNotice(
					__( 'Error loading currencies.', 'woocommerce' )
				);
			} )
			.finally( () => {
				if ( isMounted ) {
					setIsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ createErrorNotice ] );

	const availableCurrencies = useMemo(
		() => ( currencies ? currencyValues( currencies.available ) : [] ),
		[ currencies ]
	);
	const enabledCurrencies = useMemo(
		() => ( currencies ? currencyValues( currencies.enabled ) : [] ),
		[ currencies ]
	);
	const defaultCode = currencies?.default.code ?? '';
	const managedCurrency =
		managedCurrencyCode && currencies?.enabled[ managedCurrencyCode ]
			? currencies.enabled[ managedCurrencyCode ]
			: null;

	useEffect( () => {
		if (
			isSaving ||
			isModalOpen ||
			! shouldRestoreManagementFocusRef.current
		) {
			return;
		}

		shouldRestoreManagementFocusRef.current = false;
		manageCurrenciesButtonRef.current?.focus();
	}, [ isModalOpen, isSaving, currencies ] );

	useEffect( () => {
		if (
			managedCurrencyCode ||
			! shouldRestoreManagedCurrencyFocusRef.current
		) {
			return;
		}

		shouldRestoreManagedCurrencyFocusRef.current = false;
		manageCurrencyButtonRef.current?.focus();
	}, [ managedCurrencyCode ] );

	// The store default is always enabled and is filtered out of the modal
	// list, so it is never something the merchant selected. Reading the
	// selection without it is what makes "nothing is checked" answerable.
	const hasNoSelectedCurrency = useMemo(
		() => ! selectedCodes.some( ( code ) => code !== defaultCode ),
		[ selectedCodes, defaultCode ]
	);

	// Disabling the primary action is a state change nothing else announces:
	// focus stays on the checkbox the merchant just cleared, and the button is
	// two tab stops away, so a screen-reader user would meet a dead control
	// with no explanation. Announce on the edge only - not when the modal
	// opens on an already-empty selection, where the hint is read with the
	// rest of the dialog.
	const previousEmptySelectionRef = useRef< boolean | null >( null );

	useEffect( () => {
		if ( ! isModalOpen ) {
			previousEmptySelectionRef.current = null;
			return;
		}

		const wasEmptySelection = previousEmptySelectionRef.current;
		previousEmptySelectionRef.current = hasNoSelectedCurrency;

		if ( hasNoSelectedCurrency && wasEmptySelection === false ) {
			speak( emptySelectionHint(), 'polite' );
		}
	}, [ isModalOpen, hasNoSelectedCurrency ] );

	const filteredAvailableCurrencies = useMemo( () => {
		const query = search.toLocaleLowerCase();

		// Client 11.1.0 enabled-currencies-list/modal.js:45-54 matches the symbol, code and name.
		return [ ...availableCurrencies ]
			.sort( byName )
			.filter(
				( currency ) =>
					currency.code !== defaultCode &&
					`${ decodeEntities( currency.symbol ) } ${
						currency.code
					} ${ currency.name }`
						.toLocaleLowerCase()
						.includes( query )
			);
	}, [ availableCurrencies, defaultCode, search ] );

	const saveEnabledCurrencies = async ( codes: string[] ) => {
		if ( ! currencies || isSaving ) {
			return;
		}

		const enabled = normalizeEnabledCodes(
			codes,
			currencies.default.code,
			availableCurrencies
		);

		setIsSaving( true );

		try {
			const response = await apiFetch< StoreCurrenciesResponse >( {
				path: `${ REST_BASE }/update-enabled-currencies`,
				method: 'POST',
				data: { enabled },
			} );

			shouldRestoreManagementFocusRef.current = true;
			setCurrencies( response );
			setIsModalOpen( false );
			createSuccessNotice(
				__( 'Enabled currencies updated.', 'woocommerce' )
			);
		} catch ( error ) {
			createErrorNotice(
				__( 'Error updating enabled currencies.', 'woocommerce' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	const removeCurrency = ( code: string ) =>
		saveEnabledCurrencies(
			enabledCurrencies
				.map( ( enabledCurrency ) => enabledCurrency.code )
				.filter( ( enabledCode ) => enabledCode !== code )
		);

	// Client 11.1.0 delete-button.js:24-39: ask first only when enabled payment methods need the currency.
	const requestRemoval = ( currency: MultiCurrencyCurrency ) => {
		if (
			Object.keys( getDependentPaymentMethods( currency.code ) ).length
		) {
			setCurrencyToRemove( currency );
			return;
		}

		void removeCurrency( currency.code );
	};

	const openModal = () => {
		setSelectedCodes(
			enabledCurrencies.map( ( currency ) => currency.code )
		);
		setSearch( '' );
		setIsModalOpen( true );
	};

	const toggleCurrency = ( code: string, checked: boolean ) => {
		setSelectedCodes( ( currentCodes ) => {
			if ( checked ) {
				return currentCodes.includes( code )
					? currentCodes
					: [ ...currentCodes, code ];
			}

			return currentCodes.filter(
				( currentCode ) => currentCode !== code
			);
		} );
	};

	const closeCurrencySettingsModal = () => {
		shouldRestoreManagedCurrencyFocusRef.current = true;
		setManagedCurrencyCode( null );
	};

	const updateManagedCurrencyRate = (
		code: string,
		manualRate: number | null
	) => {
		if ( manualRate === null ) {
			return;
		}

		setCurrencies( ( currentCurrencies ) => {
			if ( ! currentCurrencies ) {
				return currentCurrencies;
			}

			return {
				...currentCurrencies,
				available: updateCurrencyRecordRate(
					currentCurrencies.available,
					code,
					manualRate
				),
				enabled: updateCurrencyRecordRate(
					currentCurrencies.enabled,
					code,
					manualRate
				),
			};
		} );
	};

	if ( isLoading ) {
		return (
			<p aria-live="polite">
				<Spinner />
				{ __( 'Loading currencies…', 'woocommerce' ) }
			</p>
		);
	}

	if ( ! currencies ) {
		return (
			<p role="alert">
				{ __(
					'Unable to load multi-currency settings.',
					'woocommerce'
				) }
			</p>
		);
	}

	const automaticRatesNotice = getAutomaticRatesNotice(
		currencies.automatic_rates
	);

	return (
		<div className="woocommerce-multi-currency-settings">
			{ automaticRatesNotice && (
				<Notice status="info" isDismissible={ false }>
					{ automaticRatesNotice }
				</Notice>
			) }

			<SettingsSection
				title={ __( 'Enabled currencies', 'woocommerce' ) }
				description={ createInterpolateElement(
					__(
						'Accept payments in multiple currencies. Prices are converted based on exchange rates and rounding rules. <learnMoreLink>Learn more</learnMoreLink>',
						'woocommerce'
					),
					{
						learnMoreLink: (
							<ExternalLink href="https://woocommerce.com/document/woopayments/currencies/multi-currency-setup/#enabled-currencies">
								<></>
							</ExternalLink>
						),
					}
				) }
			>
				<table className="widefat woocommerce-multi-currency-settings__currencies">
					<thead>
						<tr>
							<th scope="col">{ __( 'Name', 'woocommerce' ) }</th>
							<th scope="col">
								{ __( 'Exchange rate', 'woocommerce' ) }
							</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Actions', 'woocommerce' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ [ ...enabledCurrencies ]
							.sort( byName )
							.map( ( currency ) => (
								<tr
									key={ currency.code }
									className={
										currency.is_default
											? 'is-default'
											: undefined
									}
								>
									<th scope="row">
										{ /* Client 11.1.0 list-item.js:47-55: the flag, or the code when there is none. */ }
										<span
											className="woocommerce-multi-currency-settings__currency-flag"
											aria-hidden="true"
										>
											{ currency.flag || currency.code }
										</span>{ ' ' }
										{ currency.name }{ ' ' }
										<span className="woocommerce-multi-currency-settings__currency-code">
											({ formatSymbolAndCode( currency ) }
											)
										</span>
									</th>
									<td>
										{ formatExchangeRate(
											currency,
											currencies.default
										) }
									</td>
									<td>
										{ /* Client 11.1.0 list-item.js:64-94 and index.js:88-90: no actions on the default row. */ }
										{ ! currency.is_default && (
											<div className="woocommerce-multi-currency-settings__currency-actions">
												<Button
													variant="link"
													disabled={ isSaving }
													accessibleWhenDisabled
													aria-label={ sprintf(
														/* translators: %s: Currency name. */
														__(
															'Manage %s settings',
															'woocommerce'
														),
														currency.name
													) }
													onClick={ (
														event: React.MouseEvent< HTMLButtonElement >
													) => {
														manageCurrencyButtonRef.current =
															event.currentTarget;
														setManagedCurrencyCode(
															currency.code
														);
													} }
												>
													{ __(
														'Manage',
														'woocommerce'
													) }
												</Button>
												<Button
													// Client 11.1.0 delete-button.js:130-145: a trash icon.
													icon={ trash }
													size="small"
													disabled={ isSaving }
													accessibleWhenDisabled
													label={ sprintf(
														/* translators: %s: Currency name. */
														__(
															'Remove %s as an enabled currency',
															'woocommerce'
														),
														currency.name
													) }
													showTooltip={ false }
													onClick={ () =>
														requestRemoval(
															currency
														)
													}
												/>
											</div>
										) }
									</td>
								</tr>
							) ) }
					</tbody>
				</table>
				<CardBody className="woocommerce-multi-currency-settings__currencies-footer">
					<Button
						ref={ manageCurrenciesButtonRef }
						variant="secondary"
						aria-haspopup="dialog"
						aria-expanded={ isModalOpen }
						onClick={ openModal }
					>
						{ __( 'Add/remove currencies', 'woocommerce' ) }
					</Button>
				</CardBody>
			</SettingsSection>

			<StoreLevelSettings />

			{ currencyToRemove && (
				<RemoveCurrencyModal
					currency={ currencyToRemove }
					onCancel={ () => setCurrencyToRemove( null ) }
					onConfirm={ () => {
						setCurrencyToRemove( null );
						void removeCurrency( currencyToRemove.code );
					} }
				/>
			) }

			{ managedCurrency && currencies && (
				<CurrencySettingsModal
					currency={ managedCurrency }
					availableCurrency={
						currencies.available[ managedCurrency.code ] ??
						managedCurrency
					}
					defaultCurrency={ currencies.default }
					automaticRates={ currencies.automatic_rates }
					onClose={ closeCurrencySettingsModal }
					onSaved={ updateManagedCurrencyRate }
				/>
			) }

			{ isModalOpen && (
				<Modal
					title={ __( 'Add enabled currencies', 'woocommerce' ) }
					focusOnMount="firstContentElement"
					onRequestClose={ () => setIsModalOpen( false ) }
				>
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Search currencies', 'woocommerce' ) }
						placeholder={ __( 'Search currencies', 'woocommerce' ) }
						value={ search }
						onChange={ ( value ) => setSearch( value ) }
					/>
					<h3>
						{ search
							? sprintf(
									/* translators: %d: Number of currencies matching the search. */
									__(
										'Search results (%d currencies)',
										'woocommerce'
									),
									filteredAvailableCurrencies.length
							  )
							: __( 'All currencies', 'woocommerce' ) }
					</h3>
					<div className="woocommerce-multi-currency-settings__currency-choices">
						{ filteredAvailableCurrencies.map( ( currency ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ currency.code }
								// Client 11.1.0 modal-checkbox.js:34-56: flag, name, then symbol and code.
								label={ [
									currency.flag,
									currency.name,
									`(${ formatSymbolAndCode( currency ) })`,
								]
									.filter( Boolean )
									.join( ' ' ) }
								checked={ selectedCodes.includes(
									currency.code
								) }
								onChange={ ( checked ) =>
									toggleCurrency(
										currency.code,
										Boolean( checked )
									)
								}
							/>
						) ) }
					</div>
					{ hasNoSelectedCurrency && (
						<p id={ EMPTY_SELECTION_HINT_ID }>
							{ emptySelectionHint() }
						</p>
					) }
					<div className="woocommerce-multi-currency-settings__modal-actions">
						<Button
							variant="secondary"
							onClick={ () => setIsModalOpen( false ) }
						>
							{ __( 'Cancel', 'woocommerce' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ isSaving }
							// Submitting an empty selection is not an
							// update: it normalizes to the store default
							// alone and silently drops every additional
							// currency behind a success notice. Removing a
							// currency stays available per row, where the
							// merchant names the one they mean.
							disabled={ isSaving || hasNoSelectedCurrency }
							accessibleWhenDisabled
							aria-describedby={
								hasNoSelectedCurrency
									? EMPTY_SELECTION_HINT_ID
									: undefined
							}
							onClick={ () =>
								saveEnabledCurrencies( selectedCodes )
							}
						>
							{ __( 'Update selected', 'woocommerce' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
