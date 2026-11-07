<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use PHPUnit\Framework\TestCase;

/**
 * Builds the factories a controller takes through init(), handing out the services a test supplies.
 */
final class ServiceFactories {

	/**
	 * Build a service factory that returns the given services and builds every other one as the container's factory does.
	 *
	 * @param TestCase                  $test     Test case that builds the double.
	 * @param array<string,object|null> $services Services keyed by the creator that returns them; a null value is left to the real creator.
	 * @return ServiceFactory
	 */
	public static function with( TestCase $test, array $services ): ServiceFactory {
		$services = array_filter( $services, static fn( $service ): bool => null !== $service );
		$factory  = $test->getMockBuilder( ServiceFactory::class )->onlyMethods( array_keys( $services ) )->getMock();
		$factory->init( wc_get_container()->get( StateBuilderFactory::class ) );
		foreach ( $services as $creator => $service ) {
			$factory->method( $creator )->willReturn( $service );
		}

		return $factory;
	}

	/**
	 * Build a state builder factory that hands out the given builder.
	 *
	 * @param TestCase     $test          Test case that builds the double.
	 * @param StateBuilder $state_builder State builder to hand out.
	 * @return StateBuilderFactory
	 */
	public static function state_builder( TestCase $test, StateBuilder $state_builder ): StateBuilderFactory {
		$factory = $test->getMockBuilder( StateBuilderFactory::class )->onlyMethods( array( 'get_state_builder' ) )->getMock();
		$factory->method( 'get_state_builder' )->willReturn( $state_builder );

		return $factory;
	}
}
