/**
 * External dependencies
 */
import { Search, type SearchProps } from '@woocommerce/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsTransactionSearch } from './data';

type TransactionSearchOption = {
	label: string;
};

type TransactionSearchSelection = {
	key: string;
	label: string;
};

export const wooPaymentsTransactionSearchCompleter = {
	name: 'transactions',
	className: 'woocommerce-search__transactions-result',
	async options( query = '' ) {
		try {
			return await getWooPaymentsTransactionSearch( query );
		} catch {
			return [];
		}
	},
	getOptionIdentifier( option: TransactionSearchOption ) {
		return option.label;
	},
	getOptionKeywords( option: TransactionSearchOption ) {
		return [ option.label ];
	},
	getFreeTextOptions( query: string ) {
		return [
			{
				key: 'all',
				label: (
					<span className="woocommerce-search__result-name">
						{ sprintf(
							/* translators: %s: transaction search query. */
							__(
								'All transactions with customer names or billing emails that include %s',
								'woocommerce'
							),
							query
						) }
					</span>
				),
				value: { label: query },
			},
		] as [
			{
				key: string;
				label: JSX.Element;
				value: TransactionSearchOption;
			}
		];
	},
	getOptionLabel( option: TransactionSearchOption ) {
		return (
			<span
				className="woocommerce-search__result-name"
				aria-label={ option.label }
			>
				{ option.label }
			</span>
		);
	},
	getOptionCompletion( option: TransactionSearchOption ) {
		return {
			key: option.label,
			label: option.label,
		};
	},
} satisfies NonNullable< SearchProps[ 'autocompleter' ] >;

export const WooPaymentsTransactionSearch = ( {
	value,
	onChange,
}: {
	value: string;
	onChange: ( value: string ) => void;
} ) => {
	const selected = value ? [ { key: value, label: value } ] : [];
	const handleChange = ( values: unknown ) => {
		if ( ! Array.isArray( values ) || values.length === 0 ) {
			onChange( '' );
			return;
		}

		const selection = values[
			values.length - 1
		] as TransactionSearchSelection;
		onChange( typeof selection.label === 'string' ? selection.label : '' );
	};

	return (
		<Search
			allowFreeTextSearch
			ariaLabel={ __( 'Search transactions', 'woocommerce' ) }
			autocompleter={ wooPaymentsTransactionSearchCompleter }
			inlineTags
			onChange={ handleChange }
			placeholder={ __(
				'Search by order number, customer name, or billing email',
				'woocommerce'
			) }
			selected={ selected }
			showClearButton
			type="custom"
		/>
	);
};
