<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module;

/**
 * @package Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module
 */
interface Module
{
    /**
     * Unique identifier for your Module.
     *
     * @return string
     */
    public function id(): string;
}
