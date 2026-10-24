/**
 * External dependencies
 */
import { validatePhoneNumber } from 'woocommerce-phone-number-validation';

// Core's mobile phone validation (packages/js/components phone-number-input/validation.ts), built once here for the
// Blocks and classic WooPay save-user sections, which load this script when the shopper opts in.
window.wcWooPaymentsPhoneValidation = { validatePhoneNumber };
