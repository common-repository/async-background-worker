<?php
/*
Plugin Name: Async Background Worker
Description: Aysinchrounous Background Worker for WordPress
Author: todiadiyatmo
Author URI: http://todiadiyatmo.com/
Version: 1.0
Text Domain: awb
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

/**
 * Run Pheanstalkd Queue.
 *
 * Returns an error if the option didn't exist.
 *
 * ## OPTIONS
 *
 * <listen>
 * : Listen mode.
 *
 * ## EXAMPLES
 *
 * $ wp background-worker
 */
require_once( plugin_dir_path( __FILE__ ) . 'admin-page.php' );

define( 'ABW_PLUGIN_DIR', plugin_dir_url( __FILE__ ) );
define( 'ABW_ADMIN_MENU_SLUG', 'background_worker' );

define( 'ABW_DB_VERSION', 15 );
define( 'ABW_DB_NAME', 'bg_jobs' );

if ( ! defined( 'ABW_SLEEP' ) ) {
	define( 'ABW_SLEEP', 750000 );
}

if ( ! defined( 'ABW_TIMELIMIT' ) ) {
	define( 'ABW_TIMELIMIT', 60 );
}

if ( ! defined( 'ABW_DEBUG' ) ) {
	define( 'ABW_DEBUG', false );
}

if ( ! defined( 'ABW_QUEUE_NAME' ) ) {
	define( 'ABW_QUEUE_NAME', 'default' );
}

$installed_version = intval( get_option( 'ABW_DB_VERSION' ) );

if ( $installed_version < ABW_DB_VERSION ) {
	// drop and re create
	if ( $installed_version <= 5 ) {
		global $wpdb;

		$db_name = $wpdb->prefix . 'jobs';

		$sql = 'DROP TABLE ' . $db_name . ';';
		$wpdb->query( $sql );

		async_background_worker_install_db();
	}

	// drop and re create
	if ( $installed_version <= 10 ) {
		global $wpdb;

		$db_name = $wpdb->prefix . ABW_DB_NAME;

		$sql = "ALTER TABLE {$db_name} ADD `created_datetime` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `attempts`;";
		$wpdb->query( $sql );
	}

	update_option( 'ABW_DB_VERSION', ABW_DB_VERSION, 'no' );
}


function async_background_worker_install_db() {
	global $wpdb;

	$db_name = $wpdb->prefix . ABW_DB_NAME;

	// create db table
	$charset_collate = $wpdb->get_charset_collate();

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$db_name'" ) != $db_name ) {
		$sql = 'CREATE TABLE ' . $db_name . "
				( `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				  `queue` varchar(255) NOT NULL,
				  `payload` longtext NOT NULL,
				  `attempts` tinyint(4) UNSIGNED NOT NULL,
					`created_datetime` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  PRIMARY KEY  (`id`)
			  ) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	update_option( 'ABW_DB_VERSION', ABW_DB_VERSION, 'no' );
}

// run the install scripts upon plugin activation
register_activation_hook( __FILE__,'async_background_worker_install_db' );

/**
 * Add settings button on plugin actions
 */
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'async_background_worker_add_settings_link' );
function async_background_worker_add_settings_link( $links ) {
	$menu_page = ABW_ADMIN_MENU_SLUG;
	$settings_link = '<a href="tools.php?page=' . $menu_page . '">' . __( 'Settings' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

if ( ! function_exists( 'get_current_url' ) ) {
	function get_current_url() {
		$url = @( $_SERVER['HTTPS'] != 'on' ) ? 'http://' . $_SERVER['SERVER_NAME'] : 'https://' . $_SERVER['SERVER_NAME'];
		$url .= $_SERVER['REQUEST_URI'];

		return $url;
	}
}

if ( ! function_exists( 'bw_number_format' ) ) {
	function bw_number_format( $number ) {
		return number_format( $number, 0, ',', '.' );
	}
}

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

function add_async_job( $job, $queue = ABW_QUEUE_NAME ) {
	global $wpdb;

	$table_name = $wpdb->prefix . ABW_DB_NAME;

	// Serialize class
	$job_data = serialize( $job );

	$wpdb->insert( 
		$table_name, 
		array( 
			'queue' 			=> $queue, 
			'created_datetime' 	=> current_time('mysql'), 
			'payload' 			=> $job_data, 
			'attempts' 			=> 0 
		), 
		array( '%s', '%s', '%s', '%d' ) 
	);
}

// alias
function wp_background_add_job( $job, $queue = ABW_QUEUE_NAME ) {
	add_async_job( $job, $queue );
}

function async_background_worker_execute_job( $queue = ABW_QUEUE_NAME ) {
	global $wpdb;

	$table_name = $wpdb->prefix . ABW_DB_NAME;

	$wpdb->query('LOCK TABLES '.$table_name.' WRITE');

	$job = $wpdb->get_row( $wpdb->prepare( 
		"
		SELECT * FROM $table_name WHERE attempts <= %d AND queue=%s ORDER BY id ASC 
		", array( 2, $queue ) 
	) );

	if( !$job ) {
		$job = $wpdb->get_row( 'SELECT * FROM ' . $wpdb->prefix . ABW_DB_NAME . " WHERE attempts <= 2 AND queue='$queue' ORDER BY id ASC" );
	}

	// No Job
	if ( ! $job ) {
		$wpdb->query("UNLOCK TABLES");
		async_background_worker_debug( 'No job available..' );
		return;
	}

	$job_data = unserialize( @$job->payload );

	if ( ! $job_data ) {

		async_background_worker_debug("Delete malformated job..");

		$wpdb->delete( 
			$table_name, 
			array( 'id' => $job->id ), 
			array( '%d' ) 
		);

		$wpdb->query("UNLOCK TABLES");

		return;
	}

	async_background_worker_debug( "Working on job ID = {$job->id}" );

	$wpdb->update( 
		$table_name, 
		array( 
			'attempts' => (int) $job->attempts + 1 
		), 
		array( 'id' => $job->id ) 
	);

	$wpdb->query("UNLOCK TABLES");

	try { 
		$function = $job_data->function;
		$data = is_null( $job_data->user_data ) ? false : $job_data->user_data;

		if ( is_callable( $function ) ) {
			$function($data);
		} else {
			call_user_func_array( $function, $data );
		}

		// delete data
		$wpdb->delete( 
			$table_name, 
			array( 'id' => $job->id ), 
			array( '%d' ) 
		);
	} catch (Exception $e) { 
		 WP_CLI::error( "Caught exception: ".$e->getMessage() );
	} 
}

/**
 * Run background worker listener.
 *
 * listen = Running the listener, WordPress is reboot after each job exectuion
 * listen-loop = Running the listener in daemon mode, WordPress is not reboot after each job execution
 */

$background_worker_cmd = function( $args = array() ) {

	if ( ( isset( $args[0] ) && 'listen' === $args[0] ) ) { 
		$listen = true;
	} else { 
		$listen = false;
	} 

	if ( $listen && ! function_exists( 'exec' ) ) {
		async_background_worker_debug( 'Cannot run WordPress background worker on `listen` mode, please use `listen-loop` instead' );
	}

	if ( isset( $args[0] ) && 'listen-loop' === $args[0] ) { 
		$listen_loop = true;
	} else { 
		$listen_loop = false;
	} 

	if ( ! $listen && ! $listen_loop ) {
		if ( function_exists( 'set_time_limit' ) ) {
			 set_time_limit( ABW_TIMELIMIT );
		} elseif ( function_exists( 'ini_set' ) ) {
			ini_set( 'max_execution_time', ABW_TIMELIMIT );
		}
	}

	// listen-loop mode
	// @todo max execution time on listen_loop
	if ( $listen_loop ) { 
		while ( true ) { 
			async_background_worker_check_memory();

			usleep( ABW_SLEEP );
			async_background_worker_execute_job();
		} 
	} elseif ( $listen ) {
		// start daemon
		while ( true ) {

			$output = array();

			async_background_worker_check_memory();
			$args = array();

			usleep( ABW_SLEEP );
			async_background_worker_debug( 'Spawn next worker' );

			$_ = $_SERVER['argv'][0]; // or full path to php binary

			array_unshift( $args, 'background-worker' );

			if ( function_exists( 'posix_geteuid' ) && posix_geteuid() == 0 && ! in_array( '--allow-root', $args ) ) {
				array_unshift( $args, '--allow-root' );
			}

			$args = implode( ' ', $args );
			$cmd = $_ . ' ' . $args . ' 2>&1';

			exec( $cmd ,$output );

			foreach ( $output as $echo ) {
				WP_CLI::log( $echo );
			}

			async_background_worker_output_buffer_check();

		}
	} else {
		async_background_worker_execute_job();
	}

	async_background_worker_output_buffer_check();
	exit();
};

function async_background_worker_check_memory() {

	if ( ABW_DEBUG ) {
		$usage = memory_get_usage() / 1024 / 1024;

		async_background_worker_debug( 'Memory Usage : ' . round( $usage, 2 ) . 'MB' );
	}

	if ( ( memory_get_usage() / 1024 / 1024) >= WP_MEMORY_LIMIT ) { 
		WP_CLI::log( 'Memory limit execeed' );
		exit();
	}
}

WP_CLI::add_command( 'background-worker', $background_worker_cmd );

function async_background_worker_debug( $msg ) {

	if ( WP_BG_WORKER_DEBUG ) {
		WP_CLI::log( $msg );
	}
}

function async_background_worker_output_buffer_check() {

	@ob_flush();
	@flush();
}
