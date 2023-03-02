<?php
/*
Plugin Name:  WP Sync Webspark
Description:  Плагин тестового задания
Version:      1.0
Author:       Ihor Polezhaiev
Requires PHP: 7.4
Requires at least: 6.0.0
Tested up to: 6.1.1
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
*/

const WPD_WC_PLUGIN_NAME = 'Woocommerce Import';

define('WPD_WC_PATH', plugin_dir_path(__FILE__));

require_once WPD_WC_PATH . 'Classes/WPSyncWebspark.php';

new \WC_Import\Classes\WPSyncWebspark();

/*add_action('init', function(){
	wp_clear_scheduled_hook('wpd_wc_import_start');
});*/
