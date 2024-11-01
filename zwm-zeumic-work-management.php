<?php
/**
 * Plugin Name: ZWM Zeumic Work Management
 * Plugin URI: http://www.zeumic.com.au
 * Description: ZWM Free [Core]; Zeumic Work Management is a free todo list plugin that can work in tandem with WooCommerce to help you run your business. ZWM Zeumic Work Management allows you to manage and organise your staff, products or tasks, departments and clients.
 * Version: 1.11.18
 * Author: Zeumic
 * Author URI: http://www.zeumic.com.au
 * WC requires at least: 3.0.0
 * WC tested up to: 7
 * @package ZWM Zeumic Work Management
 * @author Zeumic
* */

global $zsc_dir;
$zsc_dir = __DIR__.'/zsc/';
require $zsc_dir . 'zsc-zeumic-suite-common.php';

add_filter('zsc_register_plugins', 'zwm_register_plugin');

function zwm_register_plugin($plugins) {
	$plugins->register('zwm', array(
		'file' => __FILE__,
		'require' => __DIR__.'/load.php',
		'class' => 'Zeumic\\ZWM\\Core\\Plugin',
		'semver' => 'minor',
		'deps' => array(
			'zsc' => '11.0',
			'wc' => '?7',
		),
	));
}
