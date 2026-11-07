<?php
/**
 * PHPStan stub for the WooCommerce Subscriptions background repairer classes, read from WooCommerce Subscriptions 9.0.1.
 */

abstract class WCS_Background_Updater {
	protected $time_limit;
	protected $scheduled_hook;
	public function init() {}
	abstract protected function get_items_to_update();
	abstract protected function update_item( $item );
	public function run_update() {}
	protected function schedule_background_update() {}
	protected function unschedule_background_updates() {}
	protected function is_wp_cli_request() {}
}

abstract class WCS_Background_Upgrader extends WCS_Background_Updater {
	protected $logger;
	protected $log_handle;
	public function schedule_repair() {}
	protected function log( $message ) {}
}

abstract class WCS_Background_Repairer extends WCS_Background_Upgrader {
	protected $repair_hook;
	protected $items_to_repair = array();
	public function init() {}
	public function schedule_repair() {}
	protected function get_items_to_update() {}
	public function run_update() {}
	protected function update_item( $item ) {}
	protected function get_page() {}
	protected function set_page( $page ) {}
	protected function get_unprocessed_items() {}
	protected function save_unprocessed_items() {}
	protected function clear_unprocessed_items_cache() {}
	protected function unschedule_background_updates() {}
	abstract protected function repair_item( $item );
	abstract protected function get_items_to_repair( $page );
}
