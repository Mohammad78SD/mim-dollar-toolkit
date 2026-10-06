<?php
/**
 * DEV ONLY: short-circuit wp_mail, log to wp-content/mail.log and save HTML to wp-content/mails/.
 */
add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		$to      = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
		$subject = (string) $atts['subject'];
		$body    = (string) $atts['message'];
		$dir     = WP_CONTENT_DIR . '/mails';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $subject ) ) ), '-' );
		$slug = substr( $slug ? $slug : 'mail', 0, 60 );
		$file = date( 'Ymd-His' ) . '-' . substr( md5( $to . $subject . microtime() ), 0, 6 ) . '-' . $slug . '.html';
		file_put_contents( $dir . '/' . $file, $body );
		$line = sprintf(
			"[%s] to=%s | subject=%s | file=mails/%s | body=%s\n",
			date( 'c' ),
			$to,
			$subject,
			$file,
			str_replace( array( "\r", "\n" ), ' ', mb_substr( wp_strip_all_tags( $body ), 0, 300 ) )
		);
		file_put_contents( WP_CONTENT_DIR . '/mail.log', $line, FILE_APPEND );
		return true;
	},
	10,
	2
);
