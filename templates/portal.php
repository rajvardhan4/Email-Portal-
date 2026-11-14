<?php
/**
 * Client Portal page template: a blank, full-screen page that only loads the
 * LeadSift React app – no theme header, footer, menus or theme styles.
 *
 * @package LeadSift
 */

defined( 'ABSPATH' ) || exit;

LeadSift_Admin::enqueue( 'portal' );
nocache_headers();
$leadsift_site = get_bloginfo( 'name' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Client Portal – %s', 'leadsift' ), $leadsift_site ) ); ?></title>
	<?php
	if ( function_exists( 'get_site_icon_url' ) && get_site_icon_url() ) {
		echo '<link rel="icon" href="' . esc_url( get_site_icon_url( 64 ) ) . '">' . "\n";
	}
	wp_print_styles( array( 'leadsift-app' ) );
	?>
	<style>
		html, body { margin: 0; padding: 0; background: #f5f6f8; min-height: 100%; }
		body { -webkit-font-smoothing: antialiased; }
		#leadsift-root { min-height: 100vh; }
		.leadsift-boot { display: flex; align-items: center; justify-content: center; min-height: 100vh; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #7a8396; }
	</style>
</head>
<body class="leadsift-portal-body">
	<div id="leadsift-root" data-context="portal"><div class="leadsift-boot"><?php esc_html_e( 'Loading…', 'leadsift' ); ?></div></div>
	<noscript><p style="text-align:center;font-family:sans-serif"><?php esc_html_e( 'Please enable JavaScript to use the Client Portal.', 'leadsift' ); ?></p></noscript>
	<?php wp_print_scripts( array( 'leadsift-app' ) ); ?>
</body>
</html>
