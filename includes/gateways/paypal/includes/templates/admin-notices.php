<?php
/**
 * Show PayPal admin notices
 *
 * @package    SkyHS Hosting Solution
 * @subpackage Gateways/PayPal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( $notices as $notice_args ) {
	$notice_args = wp_parse_args( $notice_args, array(
		'type' => 'error',
		'text' => '',
	) );

	$css_class = 'notice notice-error';
	switch ( $notice_args['type'] ) {
		case 'warning':
			$css_class = 'notice notice-warning';
			break;
		case 'info':
			$css_class = 'notice notice-info';
			break;
		case 'confirmation':
			$css_class = 'notice notice-success';
			break;
	}
	printf( '<div class="%s"><p>%s</p></div>', esc_attr( $css_class ), wp_kses_post( $notice_args['text'] ) );
}
