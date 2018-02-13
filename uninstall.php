<?php
//if uninstall not called from WordPress exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit ();
}

require_once "wp-indeed-jobs.php";

remove_shortcode( Indeed_Jobs::$SHORT_CODE );

foreach ( Indeed_Jobs::getOptions() as $option ) {
	delete_option( $option );
}

flush_rewrite_rules();