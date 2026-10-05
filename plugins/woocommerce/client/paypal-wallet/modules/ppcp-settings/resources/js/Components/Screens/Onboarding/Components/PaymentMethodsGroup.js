import { Separator } from '@ppcp-settings/Components/ReusableComponents/Elements';

const PaymentMethodItem = ( { Component, learnMore, showSeparator } ) => {
	return (
		<>
			<Component learnMore={ learnMore } />
			{ showSeparator && (
				<Separator className="ppcp-r-payment-method--separator" />
			) }
		</>
	);
};

const PaymentMethodsGroup = ( { methods, learnMoreConfig } ) => {
	return (
		<>
			{ methods.map( ( method, index ) => (
				<PaymentMethodItem
					key={ method.name }
					{ ...method }
					learnMore={ learnMoreConfig[ method.name ] }
					showSeparator={ index < methods.length - 1 }
				/>
			) ) }
		</>
	);
};

export default PaymentMethodsGroup;
