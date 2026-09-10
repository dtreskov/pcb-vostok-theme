<?php
/**
 * @var $ucmm_wpbrigade_array get_option
 * @since 1.0.0
 */
$ucmm_wpbrigade_array = (array) get_option( 'ucmm_wpbrigade_customization' );

/**
 * If a certain value exists in the settings array, return the value 
 *
 * @param [string] $ucmm_key
 * @param [array] $ucmm_wpbrigade_array
 * 
 * @return void
 */
function ucmm_wpbrigade_option_key( $ucmm_key, $ucmm_wpbrigade_array ) {

	if ( array_key_exists( $ucmm_key, $ucmm_wpbrigade_array ) ) {

		return $ucmm_wpbrigade_array[ $ucmm_key ];

	}
	// else {
	// return false;
	// }
}
/**
 * ucmm_wpbrigade_default_option_key
 *
 * @since 1.0.2
 * @version 1.5.0
 */
function ucmm_wpbrigade_default_option_key( $ucmm_key, $ucmm_wpbrigade_array, $default = true ) {

	if ( array_key_exists( $ucmm_key, $ucmm_wpbrigade_array ) ) {

		return $ucmm_wpbrigade_array[ $ucmm_key ];

	} else {
		return $default;
	}
}

/**
 * Output one social icon anchor for the maintenance page.
 *
 * @param string $network_key ucmm_facebook, ucmm_twitter, etc.
 * @param string $url         Profile URL (may be empty in preview).
 * @param bool   $force       If true, output even when URL is empty (Customizer preview).
 * @return void
 * 
 * @since 3.0.0
 */
function ucmm_wpbrigade_print_social_icon( $network_key, $url, $force ) {
	if ( ! $force && '' === (string) $url ) {
		return;
	}
	$href       = esc_url( $url );
	$link_attrs = ' target="_blank" rel="noopener noreferrer"';
	switch ( $network_key ) {
		case 'ucmm_facebook':
			echo '<a class="ucmm-facebook-icon ucmm-icon" href="' . $href . '"' . $link_attrs . '><i class="fab fa-facebook-f"></i></a>';
			break;
		case 'ucmm_twitter':
			echo '<a class="ucmm-twitter-icon ucmm-icon" href="' . $href . '"' . $link_attrs . '><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20" height="20" viewBox="0 0 30 30"><path d="M26.37,26l-8.795-12.822l0.015,0.012L25.52,4h-2.65l-6.46,7.48L11.28,4H4.33l8.211,11.971L12.54,15.97L3.88,26h2.65 l7.182-8.322L19.42,26H26.37z M10.23,6l12.34,18h-2.1L8.12,6H10.23z"></path></svg></a>';
			break;
		case 'ucmm_linkedin':
			echo '<a class="ucmm-linkedin-icon ucmm-icon" href="' . $href . '"' . $link_attrs . '><i class="fab fa-linkedin"></i></a>';
			break;
		case 'ucmm_youtube':
			echo '<a class="ucmm-youtube-icon ucmm-icon" href="' . $href . '"' . $link_attrs . '><i class="fab fa-youtube"></i></a>';
			break;
		case 'ucmm_instagram':
			echo '<a class="ucmm-instagram-icon ucmm-icon" href="' . $href . '"' . $link_attrs . '><i class="fab fa-instagram"></i></a>';
			break;
		case 'ucmm_pinterest':
			echo '<a class="ucmm-pinterest-icon ucmm-icon" href="' . $href . '"' . $link_attrs . '><i class="fab fa-pinterest"></i></a>';
			break;
		case 'ucmm_codepen':
			echo '<a class="ucmm-codepen-icon ucmm-icon" href="' . $href . '"' . $link_attrs . '><i class="fab fa-codepen"></i></a>';
			break;
		default:
			break;
	}
}

/**
 * Customizer preview only: open social links in a new tab.
 *
 * External sites (Facebook, etc.) send X-Frame-Options headers, so navigating
 * inside the Customizer iframe shows "refused to connect". This script runs only
 * in the preview iframe, not on the public maintenance page.
 *
 * @return void
 *
 * @since 3.0.0
 */
function ucmm_wpbrigade_customizer_social_links_script() {
	if ( ! is_customize_preview() ) {
		return;
	}
	?>
	<script>
	(function () {
		function ucmmOpenSocialInNewTab(event) {
			var href = event.currentTarget.getAttribute("href");

			if (!href || "#" === href) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();
			window.open(href, "_blank", "noopener,noreferrer");
		}

		function ucmmBindCustomizerSocialLinks() {
			document.querySelectorAll("a.ucmm-icon").forEach(function (anchor) {
				anchor.setAttribute("target", "_blank");
				anchor.setAttribute("rel", "noopener noreferrer");
				anchor.removeEventListener("click", ucmmOpenSocialInNewTab, true);
				anchor.addEventListener("click", ucmmOpenSocialInNewTab, true);
			});
		}

		ucmmBindCustomizerSocialLinks();
		document.addEventListener("ucmmSocialLinksUpdated", ucmmBindCustomizerSocialLinks);
	}());
	</script>
	<?php
}

$ucmm_bg                = ucmm_wpbrigade_option_key( 'setting_background', $ucmm_wpbrigade_array );
$ucmm_bg_is_removed       = array_key_exists( 'setting_background', $ucmm_wpbrigade_array )
	&& ( null === $ucmm_bg || '' === $ucmm_bg );
$ucmm_bg_src              = ( null !== $ucmm_bg && '' !== $ucmm_bg )
	? $ucmm_bg
	: plugins_url( 'img/coming-soon.png', __FILE__ );
$ucmm_background_color  = ucmm_wpbrigade_option_key( 'ucmm_background_color', $ucmm_wpbrigade_array );
$ucmm_bg_cover_allowed    = array( 'auto', 'cover', 'contain', 'initial', 'inherit' );
$ucmm_bg_repeat_allowed   = array( 'repeat', 'repeat-x', 'repeat-y', 'no-repeat', 'initial', 'inherit' );
$ucmm_bg_position_allowed    = array( 'left', 'center', 'bottom', 'top', 'right', 'initial', 'inherit', 'unset' );
$ucmm_bg_attachment_allowed  = array( 'scroll', 'fixed' );
$ucmm_bg_cover_raw           = ucmm_wpbrigade_default_option_key( 'background_cover', $ucmm_wpbrigade_array, 'cover' );
$ucmm_bg_repeat_raw          = ucmm_wpbrigade_default_option_key( 'background_repeat', $ucmm_wpbrigade_array, 'no-repeat' );
$ucmm_bg_position_raw        = ucmm_wpbrigade_default_option_key( 'background_position', $ucmm_wpbrigade_array, 'center' );
$ucmm_bg_attachment_raw      = ucmm_wpbrigade_default_option_key( 'background_attachment', $ucmm_wpbrigade_array, 'scroll' );
$ucmm_bg_cover            = is_string( $ucmm_bg_cover_raw ) && in_array( $ucmm_bg_cover_raw, $ucmm_bg_cover_allowed, true )
	? $ucmm_bg_cover_raw
	: 'cover';
$ucmm_bg_repeat           = is_string( $ucmm_bg_repeat_raw ) && in_array( $ucmm_bg_repeat_raw, $ucmm_bg_repeat_allowed, true )
	? $ucmm_bg_repeat_raw
	: 'no-repeat';
$ucmm_bg_position         = is_string( $ucmm_bg_position_raw ) && in_array( $ucmm_bg_position_raw, $ucmm_bg_position_allowed, true )
	? $ucmm_bg_position_raw
	: 'center';
$ucmm_bg_attachment       = is_string( $ucmm_bg_attachment_raw ) && in_array( $ucmm_bg_attachment_raw, $ucmm_bg_attachment_allowed, true )
	? $ucmm_bg_attachment_raw
	: 'scroll';
$ucmm_vector_image      = ucmm_wpbrigade_option_key( 'ucmm_vector_image', $ucmm_wpbrigade_array );
$ucmm_vector_is_removed   = array_key_exists( 'ucmm_vector_image', $ucmm_wpbrigade_array )
	&& ( null === $ucmm_vector_image || '' === $ucmm_vector_image );
$ucmm_vector_src          = ( null !== $ucmm_vector_image && '' !== $ucmm_vector_image )
	? $ucmm_vector_image
	: plugins_url( 'img/coming-soon-vector.png', __FILE__ );
$ucmm_logo              = ucmm_wpbrigade_option_key( 'ucmm_logo', $ucmm_wpbrigade_array );
$ucmm_logo_is_removed     = array_key_exists( 'ucmm_logo', $ucmm_wpbrigade_array )
	&& ( null === $ucmm_logo || '' === $ucmm_logo );
$ucmm_logo_src            = ( null !== $ucmm_logo && '' !== $ucmm_logo )
	? $ucmm_logo
	: plugins_url( 'img/logo-img.png', __FILE__ );
$ucmm_header            = ucmm_wpbrigade_option_key( 'header_text', $ucmm_wpbrigade_array );
$ucmm_footer            = ucmm_wpbrigade_option_key( 'footer_text', $ucmm_wpbrigade_array );
$ucmm_logo_width        = ucmm_wpbrigade_option_key( 'ucmm_logo_width', $ucmm_wpbrigade_array );
$ucmm_logo_height       = ucmm_wpbrigade_option_key( 'ucmm_logo_height', $ucmm_wpbrigade_array );
$ucmm_seo_defaults      = UCMM_WPBrigade_Entities::ucmm_wpbrigade_seo_defaults();
$ucmm_seo_title         = ucmm_wpbrigade_default_option_key( 'ucmm_seo_title', $ucmm_wpbrigade_array, $ucmm_seo_defaults['ucmm_seo_title'] );
$ucmm_seo_description   = ucmm_wpbrigade_default_option_key( 'ucmm_seo_description', $ucmm_wpbrigade_array, $ucmm_seo_defaults['ucmm_seo_description'] );
$ucmm_seo_url           = ucmm_wpbrigade_default_option_key( 'ucmm_seo_url', $ucmm_wpbrigade_array, $ucmm_seo_defaults['ucmm_seo_url'] );
$ucmm_seo_sitename      = ucmm_wpbrigade_default_option_key( 'ucmm_seo_sitename', $ucmm_wpbrigade_array, $ucmm_seo_defaults['ucmm_seo_sitename'] );
$ucmm_logo_alt          = '';
if ( ! $ucmm_logo_is_removed ) {
	$ucmm_logo_attachment_id = attachment_url_to_postid( $ucmm_logo_src );
	if ( $ucmm_logo_attachment_id ) {
		$ucmm_logo_attachment_alt = get_post_meta( $ucmm_logo_attachment_id, '_wp_attachment_image_alt', true );
		if ( is_string( $ucmm_logo_attachment_alt ) && '' !== trim( $ucmm_logo_attachment_alt ) ) {
			$ucmm_logo_alt = $ucmm_logo_attachment_alt;
		}
	}
}
if ( '' === $ucmm_logo_alt && ! empty( $ucmm_seo_sitename ) ) {
	$ucmm_logo_alt = $ucmm_seo_sitename;
}
if ( '' === $ucmm_logo_alt ) {
	$ucmm_logo_alt = get_bloginfo( 'name', 'display' );
}
if ( '' === $ucmm_logo_alt ) {
	$ucmm_logo_alt = __( 'Under Construction Logo', 'ucmm-wpbrigade' );
}
$ucmm_seo_admin         = ucmm_wpbrigade_default_option_key( 'ucmm_seo_admin', $ucmm_wpbrigade_array, $ucmm_seo_defaults['ucmm_seo_admin'] );
$ucmm_seo_keywords      = ucmm_wpbrigade_default_option_key( 'ucmm_seo_keywords', $ucmm_wpbrigade_array, $ucmm_seo_defaults['ucmm_seo_keywords'] );
$ucmm_custom_css        = ucmm_wpbrigade_option_key( 'ucmm_custom_css', $ucmm_wpbrigade_array );
$ucmm_ga_tracking_code  = ucmm_wpbrigade_option_key( 'ucmm_ga_tracking_code', $ucmm_wpbrigade_array );
$ucmm_footer_love       = ucmm_wpbrigade_default_option_key( 'ucmm_display_footer_text', $ucmm_wpbrigade_array );
$ucmm_show_end_time       = ucmm_wpbrigade_option_key( 'ucmm_schedule_show_end_time', $ucmm_wpbrigade_array );
$ucmm_schedule_start_time = ucmm_wpbrigade_option_key( 'ucmm_schedule_start', $ucmm_wpbrigade_array );
$ucmm_schedule_end_time   = ucmm_wpbrigade_option_key( 'ucmm_schedule_end', $ucmm_wpbrigade_array );
$ucmm_schedule_start_ts   = ucmm_wpbrigade_schedule_to_timestamp( $ucmm_schedule_start_time );
$ucmm_schedule_end_ts     = ucmm_wpbrigade_schedule_to_timestamp( $ucmm_schedule_end_time );
$ucmm_schedule_countdown_state = 'hidden';
$ucmm_countdown_remaining      = 0;
$ucmm_cd_days                  = 0;
$ucmm_cd_hours                 = 0;
$ucmm_cd_minutes               = 0;
$ucmm_cd_seconds               = 0;

if ( $ucmm_show_end_time && $ucmm_schedule_end_time ) {
	$ucmm_schedule_countdown_state = ucmm_wpbrigade_get_schedule_countdown_state( $ucmm_schedule_start_time, $ucmm_schedule_end_time );
	if ( 'countdown' === $ucmm_schedule_countdown_state ) {
		$ucmm_countdown_remaining = max( 0, $ucmm_schedule_end_ts - time() );
		$ucmm_cd_days             = (int) floor( $ucmm_countdown_remaining / DAY_IN_SECONDS );
		$ucmm_cd_hours            = (int) floor( ( $ucmm_countdown_remaining % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$ucmm_cd_minutes          = (int) floor( ( $ucmm_countdown_remaining % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
		$ucmm_cd_seconds          = (int) ( $ucmm_countdown_remaining % MINUTE_IN_SECONDS );
	}
}
$ucmm_time_text_color 	= ucmm_wpbrigade_option_key( 'ucmm_schedule_text_color', $ucmm_wpbrigade_array );
$ucmm_header_text_color = ucmm_wpbrigade_option_key( 'ucmm_header_text_color', $ucmm_wpbrigade_array );
$ucmm_footer_text_color = ucmm_wpbrigade_option_key( 'ucmm_footer_text_color', $ucmm_wpbrigade_array );
$ucmm_love_position     = ucmm_wpbrigade_option_key( 'ucmm_display_footer_text_position', $ucmm_wpbrigade_array );
$ucmm_love_text_color   = ucmm_wpbrigade_option_key( 'ucmm_love_text_color', $ucmm_wpbrigade_array );
$ucmm_love_hover_color  = ucmm_wpbrigade_option_key( 'ucmm_love_hover_color', $ucmm_wpbrigade_array );
$ucmm_social_icons_position_raw = ucmm_wpbrigade_option_key( 'ucmm_social_icons_position', $ucmm_wpbrigade_array );
$ucmm_social_icons_allowed      = array( 'top', 'right', 'bottom', 'left' );
$ucmm_social_icons_position     = 'bottom';
if ( is_string( $ucmm_social_icons_position_raw ) && in_array( $ucmm_social_icons_position_raw, $ucmm_social_icons_allowed, true ) ) {
	$ucmm_social_icons_position = $ucmm_social_icons_position_raw;
}

$ucmm_social_icons_style_raw = ucmm_wpbrigade_option_key( 'ucmm_social_icons_style', $ucmm_wpbrigade_array );
$ucmm_social_icons_style     = 'classic';
if ( is_string( $ucmm_social_icons_style_raw ) && in_array( $ucmm_social_icons_style_raw, array( 'classic', 'new' ), true ) ) {
	$ucmm_social_icons_style = $ucmm_social_icons_style_raw;
}

$ucmm_body_classes = array(
	'ucmm-body',
	'ucmm-social-pos-' . $ucmm_social_icons_position,
	'ucmm-social-style-' . $ucmm_social_icons_style,
);
if ( ! $ucmm_logo_is_removed ) {
	$ucmm_body_classes[] = 'ucmm-has-logo';
}
if ( $ucmm_footer_love ) {
	$ucmm_body_classes[] = 'ucmm-has-footer-love';
	$ucmm_love_pos_class = in_array( $ucmm_love_position, array( 'left', 'right', 'center' ), true )
		? $ucmm_love_position
		: 'right';
	$ucmm_body_classes[] = 'ucmm-love-pos-' . $ucmm_love_pos_class;
}

$social_network = array( 'ucmm_facebook', 'ucmm_twitter', 'ucmm_linkedin', 'ucmm_youtube', 'ucmm_instagram', 'ucmm_pinterest', 'ucmm_codepen' );

$social_links = array();

foreach ( $social_network as $key => $value ) {

	$ucmm_social_links[ $value ] = ucmm_wpbrigade_option_key( $value, $ucmm_wpbrigade_array );

}

$ucmm_social_icons_order_keys = UCMM_WPBrigade_Entities::ucmm_wpbrigade_parse_social_order(
	ucmm_wpbrigade_option_key( 'ucmm_social_icons_order', $ucmm_wpbrigade_array )
);
$ucmm_has_any_social = false;
foreach ( $ucmm_social_icons_order_keys as $ucmm_soc_k ) {
	if ( ! empty( $ucmm_social_links[ $ucmm_soc_k ] ) ) {
		$ucmm_has_any_social = true;
		break;
	}
}

?>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<!-- <link rel="icon" href="<?php // echo site_icon_url(); ?>" sizes="32x32" /> -->
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="title" content="<?php echo esc_attr( $ucmm_seo_title ); ?>" />
	<meta name="description" content="<?php echo esc_attr( $ucmm_seo_description ); ?>" />
	<meta name="url" content="<?php echo esc_url( $ucmm_seo_url ); ?>" />
	<meta name="site_name" content="<?php echo esc_attr( $ucmm_seo_sitename ); ?>" />
	<meta name="author" content="<?php echo esc_attr( $ucmm_seo_admin ); ?>">
	<meta name="keywords" content="<?php echo esc_attr( $ucmm_seo_keywords ); ?>">
	<title><?php echo get_bloginfo( 'name' ); ?></title>

	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;600;700&display=swap" rel="stylesheet">

	<link href="<?php echo plugins_url( 'assets/css/fa-brands.min.css', __FILE__ ); ?>" rel="stylesheet">
	<link href="<?php echo plugins_url( 'assets/css/fontawesome.min.css', __FILE__ ); ?>" rel="stylesheet">

	<?php
	$ucmm_ga_tracking_code = UCMM_WPBrigade_Entities::ucmm_sanitize_tracking_scripts( $ucmm_ga_tracking_code );
	?>
	<?php if ( ! empty( $ucmm_ga_tracking_code ) ) : ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized tracking snippet.
		echo $ucmm_ga_tracking_code;
		?>
	<?php endif; ?>

	<style media="screen">
	html{
		min-height: 100%;
	}
	.ucmm-body *{
		box-sizing: border-box;
	}
	body{
		display: flex;
		flex-direction: column;
		place-items: center;
		text-align: center;
		<?php if ( ! $ucmm_bg_is_removed ) : ?>
		background-color: transparent;
		background-image: url(<?php echo esc_url( $ucmm_bg_src ); ?>);
		background-size: <?php echo esc_attr( $ucmm_bg_cover ); ?>;
		background-position: <?php echo esc_attr( $ucmm_bg_position ); ?>;
		background-repeat: <?php echo esc_attr( $ucmm_bg_repeat ); ?>;
		background-attachment: <?php echo esc_attr( $ucmm_bg_attachment ); ?>;
		<?php elseif ( ! empty( $ucmm_background_color ) ) : ?>
		background-color: <?php echo sanitize_hex_color( $ucmm_background_color ); ?>;
		background-image: none;
		<?php else : ?>
		background-color: transparent;
		background-image: none;
		<?php endif; ?>
		margin: 0;
		min-height: 100dvh;

	}
	.ucmm-bottom-vector-container {
		flex: 1;
		height: calc(100dvh - 425px);
		max-height: 450px;
		min-height: 300px;
	}
	.ucmm-bottom-vector-container img {
		max-height: 100%;
	}
	.ucmm-main {
		align-items: center;
		box-sizing: border-box;
		display: flex;
		flex-direction: column;
		justify-content: flex-start;
		max-width: 100%;
		width: 100%;
		margin:auto;
	}
	/* Top / bottom social icons follow page flow (below logo, above footer). */
	body.ucmm-social-pos-top .ucmm-logo,
	body.ucmm-social-pos-bottom .ucmm-logo {
		flex-shrink: 0;
		order: 1;
		width: 100%;
	}
	body.ucmm-social-pos-top .ucmm-social-icons {
		flex-shrink: 0;
		order: 2;
		padding: 10px 16px 6px;
		position: static;
		text-align: center;
		width: 100%;
		z-index: auto;
	}
	body.ucmm-social-pos-top .ucmm-main {
		order: 3;
	}
	body.ucmm-social-pos-top .ucmm-bottom-vector-container {
		order: 4;
	}
	body.ucmm-social-pos-top .footer-love {
		flex-shrink: 0;
		order: 5;
	}
	body.ucmm-social-pos-bottom .ucmm-main {
		order: 2;
	}
	body.ucmm-social-pos-bottom .ucmm-bottom-vector-container {
		order: 3;
	}
	body.ucmm-social-pos-bottom .ucmm-social-icons {
		flex-shrink: 0;
		order: 4;
		padding: 10px 16px;
		position: static;
		text-align: center;
		width: 100%;
		z-index: auto;
	}
	body.ucmm-social-pos-bottom .footer-love {
		flex-shrink: 0;
		order: 5;
	}
	body.ucmm-social-pos-bottom.ucmm-has-footer-love .ucmm-social-icons {
		padding-bottom: 12px;
	}
	body.ucmm-social-pos-bottom.ucmm-has-footer-love.ucmm-love-pos-center .ucmm-social-icons {
		margin-bottom: 6px;
		padding-bottom: 0px;
	}
	body.ucmm-social-pos-left {
		grid-template-columns: auto 1fr;
		grid-template-rows: 1fr auto;
	}
	body.ucmm-social-pos-left .ucmm-social-icons {
		align-self: center;
		flex-direction: column;
		justify-content: center;
		justify-self: center;
		padding: 8px 10px 8px 50px;
		width: auto;
		top: 50%;
		left: 0px;
		transform: translateY(-50%);
		position:fixed;
	}
	body.ucmm-social-pos-left .ucmm-main {
		align-self: center;
		grid-column: 2;
		grid-row: 1;
		justify-self: center;
	}
	body.ucmm-social-pos-left .footer-love {
		grid-column: 2;
		grid-row: 2;
		justify-self: stretch;
	}
	body.ucmm-social-pos-right .ucmm-main {
		align-self: center;
		grid-column: 1;
		grid-row: 1;
		justify-self: center;
	}
	body.ucmm-social-pos-right .ucmm-social-icons {
		align-self: center;
		flex-direction: column;
		justify-content: center;
		justify-self: center;
		padding: 8px 50px 8px 10px;
		width: auto;
		right: 0px;
		transform: translateY(-50%);
		position:fixed;
		top: 50%;
	}
	body.ucmm-social-pos-right .footer-love {
		grid-column: 1;
		grid-row: 2;
		justify-self: stretch;
	}
	.ucmm-logo img {
		display: block;
		height: auto;
		margin: 0 auto;
		max-width: 100%;
		<?php if ( ! empty( $ucmm_logo_width ) ) : ?>
		width: <?php echo esc_attr( $ucmm_logo_width ); ?>;
		<?php endif; ?>
		<?php if ( ! empty( $ucmm_logo_height ) ) : ?>
		height: <?php echo esc_attr( $ucmm_logo_height ); ?>;
		<?php endif; ?>
	}
	.ucmm-content h1{
		margin: 0;
		box-sizing: border-box;
		color: <?php echo esc_attr( ! empty( $ucmm_header_text_color ) ? $ucmm_header_text_color : '#1F2557' ); ?>;
		font-family: "Oswald",Impact,sans-serif;
		font-size: min(14vh, 6.25rem);
		font-weight: 700;
		line-height: 1.2;
		min-height: 0;
		max-width: 610px;
		width: 100%;
		margin-top: 0em;
		opacity: 1;
		text-align: center;
	}

	h1{
		box-sizing: border-box;
		color: <?php echo esc_attr( ! empty( $ucmm_header_text_color ) ? $ucmm_header_text_color : '#1F2557' ); ?>;
		font-family: "Oswald", sans-serif;
		font-size: clamp( 2.25rem, 8vw, 6.25rem );
		font-weight: 600;
		line-height: 81px;
		min-height: 81px;
		max-width: 610px;
		width: 100%;
		margin: clamp( 1.5rem, 12vh, 137px ) auto 0;
		opacity: 1;
		text-align: center;
	}
	.ucmm-content .ucmm-subheading{
		box-sizing: border-box;
		color: <?php echo isset( $ucmm_footer_text_color ) ? esc_attr( $ucmm_footer_text_color ) : '#1F2557'; ?>;
		font-family: "Oswald", sans-serif;
		font-size: 24.41px;
		font-style: normal;
		font-weight: 300;
		letter-spacing: 0;
		line-height: 100%;
		margin: 42px auto 32px;
		max-width: 610px;
		width: 100%;
	}
	.ucmm-subheading a{
		color: inherit;
	}
	.footer-love {
		color: <?php echo ! empty( $ucmm_love_text_color ) ? sanitize_hex_color( $ucmm_love_text_color ) : '#1F2557'; ?>;
		font-family: "Oswald", Impact, sans-serif;
		padding: 3px;
		padding-bottom: 5px;
		width: 100%;
		box-sizing: border-box;
		text-align: <?php echo isset( $ucmm_love_position ) ? sanitize_text_field( $ucmm_love_position ) : 'right'; ?>
	}
	.footer-love a{
		text-decoration: none;
		color: inherit;
		font-family: inherit;
	}
	.footer-love a:hover{
		color: <?php echo ! empty( $ucmm_love_hover_color ) ? sanitize_hex_color( $ucmm_love_hover_color ) : '#3BB9FF'; ?>;
	}
	/* Icons style start here */
	.ucmm-social-icons{
		align-items: center;
		display: flex;
		flex-direction: row;
		flex-wrap: wrap;
		justify-content: center;
	}
	.ucmm-icon{
		width: 40px;
		height: 40px;
		display: inline-block;
		line-height: 40px;
		border-radius: 50%;
		margin: 8px;
	}
	.ucmm-icon .fab{
		color: #fff;
		vertical-align: middle;
		font-size: 18px;
		line-height: 40px;
	}
	.ucmm-facebook-icon{
		background: #4266C9;
		<?php if( empty( $ucmm_social_links['ucmm_facebook'] ) ) { ?>
		display: none;
		<?php } ?>
	}
	<?php if ( ! $ucmm_footer_love ) { ?>
		#customize-control-ucmm_wpbrigade_customization-ucmm_display_footer_text_position {
			display: none !important;
		}
	<?php } ?>
	.ucmm-twitter-icon{
		background: #000;
		align-items: center;
		justify-content: center;
		vertical-align: middle;
		<?php if ( empty( $ucmm_social_links['ucmm_twitter'] ) ) { ?>
		display: none;
		<?php } else { ?>
		display: inline-flex;
		<?php } ?>
	}
	.ucmm-linkedin-icon{
		background: #2867B2;
		<?php if( empty( $ucmm_social_links['ucmm_linkedin'] ) ) { ?>
		display: none;
		<?php } ?>
	}
	.ucmm-google-icon{
		background: #d34836;
		<?php if( empty( $ucmm_social_links['ucmm_google'] ) ) { ?>
		display: none;
		<?php } ?>
	}
	.ucmm-youtube-icon{
		background: #FF0000;
		<?php if( empty( $ucmm_social_links['ucmm_youtube'] ) ) { ?>
		display: none;
		<?php } ?>
	}
	.ucmm-instagram-icon{
		background: #DD2A7B;
		<?php if( empty( $ucmm_social_links['ucmm_instagram'] ) ) { ?>
		display: none;
		<?php } ?>
	}
	.ucmm-pinterest-icon{
		background: #BD081C;
		<?php if( empty( $ucmm_social_links['ucmm_pinterest'] ) ) { ?>
		display: none;
		<?php } ?>
	}
	.ucmm-codepen-icon{
		background: #000;
		<?php if( empty( $ucmm_social_links['ucmm_codepen'] ) ) { ?>
		display: none;
		<?php } ?>
	}
	body.ucmm-social-style-new .ucmm-facebook-icon,
	body.ucmm-social-style-new .ucmm-twitter-icon,
	body.ucmm-social-style-new .ucmm-linkedin-icon,
	body.ucmm-social-style-new .ucmm-google-icon,
	body.ucmm-social-style-new .ucmm-youtube-icon,
	body.ucmm-social-style-new .ucmm-instagram-icon,
	body.ucmm-social-style-new .ucmm-pinterest-icon,
	body.ucmm-social-style-new .ucmm-codepen-icon{
		background: #ffffff;
		box-shadow: 4px 4px 8px 0 #0000000D;
	}
	body.ucmm-social-style-new .ucmm-icon .fab{
		color: #707070;
	}
	body.ucmm-social-style-new .ucmm-twitter-icon svg path{
		fill: #707070;
	}

	/*----- schedule countdown -----*/
	.ucmm_schedule_time{
		align-self: start;
		box-sizing: border-box;
		color: <?php echo isset( $ucmm_time_text_color ) ? esc_attr( $ucmm_time_text_color ) : '#000000'; ?>;
		display: flex;
		justify-content: center;
		padding-bottom: 20px;
		padding-top: 8px;
		width: 100%;
	}
	.ucmm-schedule-expired{
		background-color: #dc3232;
		border-radius: 6px;
		color: #fff;
		display: inline-block;
		padding: 12px 20px;
	}
	.ucmm_schedule_time .ucmm-schedule-notice{
		background-color: #fcf0f0;
		border-left: 4px solid #d63638;
		box-sizing: border-box;
		color: #3c434a;
		display: inline-block;
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
		font-size: 13px;
		font-weight: 400;
		line-height: 1.4em;
		margin: 0 auto;
		max-width: min(100%, 520px);
		padding: 8px 12px;
		text-align: center;
		width: auto;
	}
	.ucmm_schedule_time .ucmm-schedule-notice--info{
		background-color: #f0f6fc;
		border-left-color: #2271b1;
	}
	.ucmm-countdown{
		align-items: stretch;
		display: flex;
		flex-wrap: nowrap;
		gap: 12px;
		justify-content: center;
		max-width: 100%;
		width: 100%;
	}
	.ucmm-countdown__unit{
		align-items: center;
		background: #F3B415;
		border-radius: 10px;
		box-sizing: border-box;
		color: inherit;
		display: flex;
		flex: 0 1 auto;
		flex-direction: column;
		justify-content: center;
		min-width: 72px;
		padding: 12px 14px;
		text-align: center;
	}
	.ucmm-countdown__num{
		font-family: "Oswald", sans-serif;
		font-size: 36.61px;
		font-weight: 700;
		letter-spacing: 0;
		line-height: 100%;
	}
	.ucmm-countdown__lbl{
		font-family: "Oswald", sans-serif;
		font-size: 14.64px;
		font-weight: 400;
		letter-spacing: 0.03em;
		line-height: 100%;
		margin-top: 6px;
		text-transform: capitalize;
	}
	.ucmm-twitter-icon svg path{
		fill: #fff;
	}
	.ucmm-twitter-icon svg{
		width: 20px;
		height: 20px;
		vertical-align: middle;
	}
	@media only screen and (max-width: 782px) {
		body.ucmm-social-pos-left,
		body.ucmm-social-pos-right {
			grid-template-columns: 1fr;
			grid-template-rows: auto auto auto;
		}
		body:has(.ucmm-social-icons) .ucmm-main {
			padding-inline: 55px;
		}
		body.ucmm-social-pos-left .ucmm-main,
		body.ucmm-social-pos-right .ucmm-main {
			grid-column: 1;
			grid-row: 1;
		}
		body.ucmm-social-pos-left .footer-love,
		body.ucmm-social-pos-right .footer-love {
			grid-column: 1;
			grid-row: 3;
		}
		.ucmm-countdown {
			flex-wrap: nowrap;
			gap: clamp( 6px, 2vw, 12px );
		}
		.ucmm-countdown__unit {
			flex: 1 1 0;
			min-width: 0;
			padding: clamp( 8px, 2vw, 12px ) clamp( 6px, 1.5vw, 14px );
		}
		.ucmm-countdown__num {
			font-size: clamp( 22px, 5.5vw, 36.61px );
		}
		.ucmm-countdown__lbl {
			font-size: clamp( 10px, 2.5vw, 14.64px );
			margin-top: 4px;
		}
		.ucmm-content h1 {
			font-family: Oswald;
			font-weight: 700;
			font-size: 47px;
			line-height: 100%;
			letter-spacing: 3%;

		}
		.ucmm-content .ucmm-subheading {
			margin-block: 15px 25px;
			font-family: Oswald;
			font-weight: 300;
			font-size: 18px;
			line-height: 140%;
			letter-spacing: 0%;
			text-align: center;
			text-wrap: balance;

		}
		.ucmm-main {
			padding-inline: 30px;
		}

		body.ucmm-social-pos-right .ucmm-social-icons,
		body.ucmm-social-pos-left .ucmm-social-icons {
			padding: 0px;
		}
	}
	@media only screen and (max-width: 600px) {
		.ucmm-logo h1{
			font-size: 2.25rem;
			line-height: 1.15;
			min-height: 0;
		}
		.ucmm-bottom-vector-container {
			flex: none;
			height: auto;
			max-height: none;
		}
		.ucmm-bottom-vector {
			max-width: 100%;
			height: auto;
		}
		.ucmm-countdown {
			gap: 6px;
		}
		.ucmm-countdown__unit {
			border-radius: 8px;
			padding: 8px 4px;
		}
		.ucmm-countdown__num {
			font-size: clamp( 18px, 5vw, 28px );
		}
		.ucmm-countdown__lbl {
			font-size: clamp( 8px, 2.2vw, 12px );
			margin-top: 3px;
		}
	}

	<?php if ( ! empty( $ucmm_custom_css ) ) : ?>
		<?php echo $ucmm_custom_css; ?>
	<?php endif; ?>

	<?php if ( is_user_logged_in() && ! is_customize_preview() ) : ?>
		body.admin-bar.ucmm-body {
			min-height: calc(100dvh - 32px);
		}
		@media screen and (max-width: 782px) {
			body.admin-bar.ucmm-body {
				min-height: calc(100dvh - 46px);
			}
		}
	<?php endif; ?>
	
	</style>

	<?php if ( is_user_logged_in() && ! is_customize_preview() ) : ?>
	<?php wp_head(); ?>
	<?php endif; ?>
</head>
<body class="<?php echo esc_attr( implode( ' ', $ucmm_body_classes ) ); ?>">
<?php if ( is_user_logged_in() && ! is_customize_preview() ) : ?>
<?php wp_body_open(); ?>
<?php endif; ?>
<header class="ucmm-logo">
<img src="<?php echo esc_url( $ucmm_logo_src ); ?>" alt="<?php echo esc_attr( $ucmm_logo_alt ); ?>"<?php echo $ucmm_logo_is_removed ? ' style="display:none;"' : ''; ?>>
	</header>
	<div class="ucmm-main">
	<div class="ucmm-content" role="main">
		<h1>
		<?php
		if ( isset( $ucmm_header ) ) {
			echo esc_html( $ucmm_header );
		} else{
			echo __( 'COMING SOON', 'ucmm-wpbrigade' );
		}
		?>
		</h1>
		<div class="ucmm-subheading">
		<?php
		if ( isset( $ucmm_footer ) ) {
			echo force_balance_tags( wp_kses_post( $ucmm_footer ) );
		} else {
			echo esc_html( __( "We're not quite ready yet, Something is coming very soon", 'ucmm-wpbrigade' ) );
		}
		?>
		</div>
	</div>

	<?php if ( is_customize_preview() || ( $ucmm_show_end_time && $ucmm_schedule_end_time ) ) : ?>
	<div class="ucmm_schedule_time"<?php echo ( is_customize_preview() && ! $ucmm_show_end_time ) ? ' style="display:none;"' : ''; ?>>
		<?php if ( 'invalid' === $ucmm_schedule_countdown_state && is_customize_preview() ) : ?>
			<div class="ucmm-schedule-notice ucmm-schedule-notice--error" role="alert"><?php esc_html_e( 'End time must be later than the start time. This schedule cannot run until corrected.', 'ucmm-wpbrigade' ); ?></div>
		<?php elseif ( 'expired' === $ucmm_schedule_countdown_state ) : ?>
			<?php if ( is_customize_preview() ) : ?>
				<span class="ucmm-schedule-expired"><?php esc_html_e( 'Maintenance schedule has been expired. Please update the time or turn it off.', 'ucmm-wpbrigade' ); ?></span>
			<?php else : ?>
				<span class="ucmm-schedule-expired"><?php echo esc_html( apply_filters( 'ucmm_redirect_message', __( 'Website is now LIVE! will be redirected to homepage shortly. If not, please refresh the page.', 'ucmm-wpbrigade' ) ) ); ?></span>
			<?php endif; ?>
		<?php elseif ( is_customize_preview() && false !== $ucmm_schedule_start_ts && time() < $ucmm_schedule_start_ts && 'countdown' === $ucmm_schedule_countdown_state ) : ?>
			<div class="ucmm-schedule-notice ucmm-schedule-notice--info" role="status"><?php esc_html_e( 'Maintenance has not started yet. The countdown timer will begin at the scheduled start time.', 'ucmm-wpbrigade' ); ?></div>
		<?php elseif ( 'countdown' === $ucmm_schedule_countdown_state ) : ?>
		<div class="ucmm-countdown" role="timer" aria-live="polite">
			<div class="ucmm-countdown__unit">
				<span class="ucmm-countdown__num" id="ucmm-cd-days"><?php echo esc_html( (string) $ucmm_cd_days ); ?></span>
				<span class="ucmm-countdown__lbl"><?php esc_html_e( 'Days', 'ucmm-wpbrigade' ); ?></span>
			</div>
			<div class="ucmm-countdown__unit">
				<span class="ucmm-countdown__num" id="ucmm-cd-hours"><?php echo esc_html( str_pad( (string) $ucmm_cd_hours, 2, '0', STR_PAD_LEFT ) ); ?></span>
				<span class="ucmm-countdown__lbl"><?php esc_html_e( 'Hours', 'ucmm-wpbrigade' ); ?></span>
			</div>
			<div class="ucmm-countdown__unit">
				<span class="ucmm-countdown__num" id="ucmm-cd-minutes"><?php echo esc_html( str_pad( (string) $ucmm_cd_minutes, 2, '0', STR_PAD_LEFT ) ); ?></span>
				<span class="ucmm-countdown__lbl"><?php esc_html_e( 'Mins', 'ucmm-wpbrigade' ); ?></span>
			</div>
			<div class="ucmm-countdown__unit">
				<span class="ucmm-countdown__num" id="ucmm-cd-seconds"><?php echo esc_html( str_pad( (string) $ucmm_cd_seconds, 2, '0', STR_PAD_LEFT ) ); ?></span>
				<span class="ucmm-countdown__lbl"><?php esc_html_e( 'Secs', 'ucmm-wpbrigade' ); ?></span>
			</div>
		</div>
		<?php endif; ?>
	</div>
	<?php else : ?>
	<div class="ucmm_schedule_time" style="display:none" aria-hidden="true"></div>
	<?php endif; ?>

	</div>

	<?php
	/*
	$social_icons  =array( 'ucmm_facebook_c', 'ucmm_twitter_c', 'ucmm_linkedin_c', 'ucmm_google_c', 'ucmm_youtube_c', 'ucmm_instagram_c', 'ucmm_pinterest_c', 'ucmm_codepen_c' );
	$social_links = array( 'ucmm_facebook', 'ucmm_twitter', 'ucmm_linkedin', 'ucmm_google', 'ucmm_youtube', 'ucmm_instagram', 'ucmm_pinterest', 'ucmm_codepen' );
	*/
	if ( is_customize_preview() ) {
		echo '<div class="ucmm-social-icons">';
		foreach ( $ucmm_social_icons_order_keys as $ucmm_soc_k ) {
			$ucmm_soc_url = isset( $ucmm_social_links[ $ucmm_soc_k ] ) ? $ucmm_social_links[ $ucmm_soc_k ] : '';
			ucmm_wpbrigade_print_social_icon( $ucmm_soc_k, $ucmm_soc_url, true );
		}
		echo '</div>';
	} elseif ( $ucmm_has_any_social ) {
		echo '<div class="ucmm-social-icons">';
		foreach ( $ucmm_social_icons_order_keys as $ucmm_soc_k ) {
			$ucmm_soc_url = isset( $ucmm_social_links[ $ucmm_soc_k ] ) ? $ucmm_social_links[ $ucmm_soc_k ] : '';
			ucmm_wpbrigade_print_social_icon( $ucmm_soc_k, $ucmm_soc_url, false );
		}
		echo '</div>';
	}
	?>
	<div class="ucmm-bottom-vector-container"<?php echo $ucmm_vector_is_removed ? ' style="display:none;"' : ''; ?>>
		<img
			class="ucmm-bottom-vector"
			src="<?php echo esc_url( $ucmm_vector_src ); ?>"
			alt="<?php esc_attr_e( 'Construction vector', 'ucmm-wpbrigade' ); ?>"
		>
	</div>
	

	<?php if ( $ucmm_footer_love ) : ?>
		<footer class="footer-love">
			<?php _e( 'Powered by:', 'ucmm-wpbrigade' ); ?> <a href="https://wpbrigade.com/wordpress/plugins/under-construction-maintenance-mode/" target="_blank">WPBrigade</a>
		</footer>
	<?php endif; ?>

<?php if ( is_customize_preview() || ( $ucmm_show_end_time && $ucmm_schedule_end_time ) ) : // statr if  to show counter add counter script 
?>


<script>
(function () {
	window.ucmmScheduleUtcOffset = <?php echo (int) ucmm_wpbrigade_schedule_utc_offset(); ?>;

	var ucmmScheduleMessages = <?php
		echo wp_json_encode(
			array(
				'invalid' => __( 'End time must be later than the start time. This schedule cannot run until corrected.', 'ucmm-wpbrigade' ),
				'expired' => is_customize_preview()
					? __( 'Maintenance schedule has been expired. Please update the time or turn it off.', 'ucmm-wpbrigade' )
					: apply_filters( 'ucmm_redirect_message', __( 'Website is now LIVE! will be redirected to homepage shortly. If not, please refresh the page.', 'ucmm-wpbrigade' ) ),
				'pending' => __( 'Maintenance has not started yet. The countdown timer will begin at the scheduled start time.', 'ucmm-wpbrigade' ),
			)
		);
	?>;

	function ucmmPad2( n ) {
		n = parseInt( n, 10 );
		if ( isNaN( n ) ) {
			return '00';
		}
		return ( n < 10 ? '0' : '' ) + n;
	}

	function ucmmScheduleToTimestamp( datetimeLocal ) {
		if ( ! datetimeLocal || typeof datetimeLocal !== 'string' ) {
			return null;
		}
		var parts = datetimeLocal.match( /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/ );
		if ( ! parts ) {
			return null;
		}
		var offsetSec = typeof window.ucmmScheduleUtcOffset === 'number'
			? window.ucmmScheduleUtcOffset
			: parseInt( window.ucmmScheduleUtcOffset, 10 ) || 0;
		return Date.UTC(
			parseInt( parts[1], 10 ),
			parseInt( parts[2], 10 ) - 1,
			parseInt( parts[3], 10 ),
			parseInt( parts[4], 10 ),
			parseInt( parts[5], 10 )
		) - ( offsetSec * 1000 );
	}

	function ucmmGetScheduleState( startMs, endMs, isCustomizer ) {
		if ( null === startMs || null === endMs || endMs <= startMs ) {
			return 'invalid';
		}
		if ( Date.now() > endMs ) {
			return 'expired';
		}
		if ( isCustomizer && null !== startMs && Date.now() < startMs ) {
			return 'pending';
		}
		return 'countdown';
	}

	function ucmmRenderScheduleMessage( wrap, className, message, role ) {
		wrap.innerHTML = '';
		var notice = document.createElement( 'div' );
		notice.className = className;
		notice.setAttribute( 'role', role || 'status' );
		notice.textContent = message;
		wrap.appendChild( notice );
	}

	function ucmmRenderCountdownMarkup( wrap ) {
		wrap.innerHTML =
			'<div class="ucmm-countdown" role="timer" aria-live="polite">' +
				'<div class="ucmm-countdown__unit"><span class="ucmm-countdown__num" id="ucmm-cd-days">0</span><span class="ucmm-countdown__lbl"><?php echo esc_js( __( 'Days', 'ucmm-wpbrigade' ) ); ?></span></div>' +
				'<div class="ucmm-countdown__unit"><span class="ucmm-countdown__num" id="ucmm-cd-hours">00</span><span class="ucmm-countdown__lbl"><?php echo esc_js( __( 'Hours', 'ucmm-wpbrigade' ) ); ?></span></div>' +
				'<div class="ucmm-countdown__unit"><span class="ucmm-countdown__num" id="ucmm-cd-minutes">00</span><span class="ucmm-countdown__lbl"><?php echo esc_js( __( 'Mins', 'ucmm-wpbrigade' ) ); ?></span></div>' +
				'<div class="ucmm-countdown__unit"><span class="ucmm-countdown__num" id="ucmm-cd-seconds">00</span><span class="ucmm-countdown__lbl"><?php echo esc_js( __( 'Secs', 'ucmm-wpbrigade' ) ); ?></span></div>' +
			'</div>';
	}

	window.ucmmInitScheduleCountdown = function( config ) {
		if ( window.ucmmCountdownInterval ) {
			clearInterval( window.ucmmCountdownInterval );
			window.ucmmCountdownInterval = null;
		}

		var wrap = document.querySelector( '.ucmm_schedule_time' );
		if ( ! wrap ) {
			return;
		}

		config = config || {};
		var startMs = typeof config.startMs === 'number' ? config.startMs : ucmmScheduleToTimestamp( config.start || '' );
		var endMs = typeof config.endMs === 'number' ? config.endMs : ucmmScheduleToTimestamp( config.end || '' );
		var isCustomizer = !!config.isCustomizer;

		if ( isCustomizer && ( ! config.end || '' === String( config.end ).trim() ) ) {
			wrap.innerHTML = '';
			return;
		}

		var state = ucmmGetScheduleState( startMs, endMs, isCustomizer );

		if ( 'invalid' === state ) {
			if ( isCustomizer ) {
				ucmmRenderScheduleMessage( wrap, 'ucmm-schedule-notice ucmm-schedule-notice--error', ucmmScheduleMessages.invalid, 'alert' );
			} else {
				wrap.innerHTML = '';
			}
			return;
		}

		if ( 'pending' === state ) {
			ucmmRenderScheduleMessage( wrap, 'ucmm-schedule-notice ucmm-schedule-notice--info', ucmmScheduleMessages.pending, 'status' );
			window.ucmmCountdownInterval = setInterval( function () {
				if ( Date.now() >= startMs ) {
					window.ucmmInitScheduleCountdown( config );
				}
			}, 1000 );
			return;
		}

		if ( 'expired' === state ) {
			ucmmRenderScheduleMessage( wrap, 'ucmm-schedule-expired', ucmmScheduleMessages.expired );
			if ( ! isCustomizer && ! window.ucmmReloadScheduled ) {
				window.ucmmReloadScheduled = true;
				setTimeout( function () {
					location.reload( true );
				}, 3000 );
			}
			return;
		}

		ucmmRenderCountdownMarkup( wrap );

		function ucmmTickCountdown() {
			var distance = endMs - Date.now();
			var daysEl = document.getElementById( 'ucmm-cd-days' );
			var hoursEl = document.getElementById( 'ucmm-cd-hours' );
			var minutesEl = document.getElementById( 'ucmm-cd-minutes' );
			var secondsEl = document.getElementById( 'ucmm-cd-seconds' );

			if ( distance < 0 ) {
				window.ucmmInitScheduleCountdown( config );
				return;
			}

			var days = Math.floor( distance / ( 1000 * 60 * 60 * 24 ) );
			var hours = Math.floor( ( distance % ( 1000 * 60 * 60 * 24 ) ) / ( 1000 * 60 * 60 ) );
			var minutes = Math.floor( ( distance % ( 1000 * 60 * 60 ) ) / ( 1000 * 60 ) );
			var seconds = Math.floor( ( distance % ( 1000 * 60 ) ) / 1000 );

			if ( daysEl ) {
				daysEl.textContent = String( days );
			}
			if ( hoursEl ) {
				hoursEl.textContent = ucmmPad2( hours );
			}
			if ( minutesEl ) {
				minutesEl.textContent = ucmmPad2( minutes );
			}
			if ( secondsEl ) {
				secondsEl.textContent = ucmmPad2( seconds );
			}
		}

		ucmmTickCountdown();
		window.ucmmCountdownInterval = setInterval( ucmmTickCountdown, 1000 );
	};

	var ucmmScheduleBoot = <?php
		echo wp_json_encode(
			array(
				'start'        => is_string( $ucmm_schedule_start_time ) ? $ucmm_schedule_start_time : '',
				'end'          => is_string( $ucmm_schedule_end_time ) ? $ucmm_schedule_end_time : '',
				'startMs'      => false !== $ucmm_schedule_start_ts ? (int) ( $ucmm_schedule_start_ts * 1000 ) : null,
				'endMs'        => false !== $ucmm_schedule_end_ts ? (int) ( $ucmm_schedule_end_ts * 1000 ) : null,
				'isCustomizer' => (bool) is_customize_preview(),
				'initialState' => $ucmm_schedule_countdown_state,
			)
		);
	?>;

	if ( 'countdown' === ucmmScheduleBoot.initialState || ucmmScheduleBoot.isCustomizer ) {
		window.ucmmInitScheduleCountdown( ucmmScheduleBoot );
	} else if ( 'expired' === ucmmScheduleBoot.initialState && ! ucmmScheduleBoot.isCustomizer ) {
		window.ucmmReloadScheduled = true;
		setTimeout( function () {
			location.reload( true );
		}, 3000 );
	}
}());
</script>
<?php endif; // end if to show counter add counter script ?>
<?php ucmm_wpbrigade_customizer_social_links_script(); ?>
<?php if ( is_user_logged_in() && ! is_customize_preview() ) : ?>
<?php wp_footer(); ?>
<?php endif; ?>
</body>
</html>
