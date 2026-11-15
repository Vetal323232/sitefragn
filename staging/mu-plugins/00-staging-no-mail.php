<?php
/* Staging safety: block all outgoing e-mail and record it to a log instead. */
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	error_log( '[staging mail blocked] to=' . ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . ' subject=' . $atts['subject'] );
	return true;
}, 1, 2 );
