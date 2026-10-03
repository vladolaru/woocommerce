<?php
/**
 * The Patch collection object.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

/**
 * Class PatchCollection
 */
class PatchCollection {

	/**
	 * The patches.
	 *
	 * @var Patch[]
	 */
	private $patches;

	/**
	 * PatchCollection constructor.
	 *
	 * @param Patch ...$patches The patches.
	 */
	public function __construct( Patch ...$patches ) {
		$this->patches = $patches;
	}

	/**
	 * Returns the patches.
	 *
	 * @return Patch[]
	 */
	public function patches(): array {
		return $this->patches;
	}

	/**
	 * Returns the object as array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array_map(
			static function ( Patch $patch ): array {
				return $patch->to_array();
			},
			$this->patches()
		);
	}
}
