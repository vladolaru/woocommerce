<?php
/**
 * Stub file for the PayPal wallet's aliased DTO class names.
 *
 * Analysis-only: it is listed in `scanFiles` in phpstan.neon and is never loaded at runtime. At runtime
 * SerializedClasses/load.php defines these three names with class_alias(), pointing them at the classes the
 * PayPal Payments extension stores in its options, and PHPStan cannot follow that call. The stub declares each alias
 * as a subclass of the real class, so the properties and constructors PHPStan reads are the real ones.
 *
 * @package WooCommerce\Stubs
 */

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO;

class LocationStylingDTO extends \WooCommerce\PayPalCommerce\Settings\DTO\LocationStylingDTO {
}

class PayLaterMessagingDTO extends \WooCommerce\PayPalCommerce\Settings\DTO\PayLaterMessagingDTO {
}

class OAuthConnectionDTO extends \WooCommerce\PayPalCommerce\Settings\DTO\OAuthConnectionDTO {
}
