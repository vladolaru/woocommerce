<?php
/**
 * Tests for the abstract settings data model.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\AbstractDataModel;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use RuntimeException;

/**
 * What every settings model does on load and save, over a minimal subclass and a real option.
 *
 * @group paypal-wallet
 */
class AbstractDataModelTest extends WalletTestCase {

	private const OPTION = 'test_option_key';
	private const ACTION = 'woocommerce_paypal_payments_settings_saved';

	/**
	 * A minimal concrete model with one field, stored under self::OPTION.
	 *
	 * @return AbstractDataModel
	 */
	private function create_model(): AbstractDataModel {
		return new class() extends AbstractDataModel {
			protected const OPTION_KEY = 'test_option_key';

			/**
			 * The one field of the model.
			 *
			 * @return array
			 */
			protected function get_defaults(): array {
				return array( 'foo' => '' );
			}
		};
	}

	/**
	 * Given a listener on the "settings saved" action that calls save() again, the nested save still writes the data,
	 * and the action is dispatched once overall, so a listener cannot start a notification loop.
	 *
	 * @testdox Should still persist on a re-entrant save but fire the saved action only once.
	 */
	public function test_reentrant_save_still_persists_but_fires_action_only_once(): void {
		$this->set_wallet_option( self::OPTION, array() );
		$writes = $this->spy_filter( 'pre_update_option_' . self::OPTION );

		$model          = $this->create_model();
		$listener_calls = 0;
		add_action(
			self::ACTION,
			static function ( $saved_model ) use ( &$listener_calls ) {
				++$listener_calls;
				$saved_model->save();
			}
		);

		$model->save();

		$this->assertCount( 2, $writes, 'Both the initial and the re-entrant save should persist the data' );
		$this->assertSame( 1, $listener_calls, 'The saved action must not be dispatched again by the re-entrant save' );
		$this->assertSame( array( 'foo' => '' ), get_option( self::OPTION ) );
	}

	/**
	 * @testdox Should fire the saved action with the model that was saved, and again on a later save.
	 */
	public function test_save_fires_the_saved_action_with_the_model_on_every_save(): void {
		$this->set_wallet_option( self::OPTION, array() );
		$calls = $this->spy_filter( self::ACTION );
		$model = $this->create_model();

		$model->save();
		$model->save();

		$this->assertCount( 2, $calls, 'The re-entrancy guard must be released once a save is done' );
		$this->assertSame( $model, $calls[0][0] );
	}

	/**
	 * @testdox Should load only the keys the model declares, and keep the defaults of the others.
	 */
	public function test_load_ignores_stored_keys_the_model_does_not_declare(): void {
		$this->set_wallet_option(
			self::OPTION,
			array(
				'foo'     => 'stored',
				'unknown' => 'ignored',
			)
		);

		$model = $this->create_model();

		$this->assertSame( array( 'foo' => 'stored' ), $model->to_array() );
	}

	/**
	 * @testdox Should delete the stored option on purge.
	 */
	public function test_purge_deletes_the_option(): void {
		$this->set_wallet_option( self::OPTION, array( 'foo' => 'stored' ) );

		$this->create_model()->purge();

		$this->assertFalse( get_option( self::OPTION ) );
	}

	/**
	 * @testdox Should refuse to build a model that has no option key.
	 */
	public function test_a_model_without_an_option_key_cannot_be_built(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'OPTION_KEY must be defined in child class.' );

		new class() extends AbstractDataModel {
			/**
			 * The one field of the model.
			 *
			 * @return array
			 */
			protected function get_defaults(): array {
				return array( 'foo' => '' );
			}
		};
	}
}
