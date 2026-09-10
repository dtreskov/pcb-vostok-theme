<?php
/**
 * Settings page header banner (PHP).
 *
 * @package Under_Construction_Maintenance_Mode
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ucmm_logo_url = esc_url( UCMM_WPBRIGADE_DIR_URL . 'img/logo.png' );
?>
<div class="ucmm-banner">
	<div class="ucmm-banner-inner">
		<div class="ucmm-banner-left">
			<img
				src="<?php echo $ucmm_logo_url; ?>"
				alt="<?php esc_attr_e( 'Under Construction Logo', 'ucmm-wpbrigade' ); ?>"
				class="ucmm-banner-logo"
			/>
		</div>
		<div class="ucmm-banner-right">
			<a
				href="<?php echo esc_url( 'https://wordpress.org/support/plugin/under-construction-maintenance-mode/' ); ?>"
				target="_blank"
				rel="noopener noreferrer"
				class="ucmm-support-btn"
			>
				<svg width="15" height="18" viewBox="0 0 15 18" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
					<path d="M7.5 0C5.0187 0 3 2.0187 3 4.5C3 6.9813 5.0187 9 7.5 9C9.9813 9 12 6.9813 12 4.5C12 2.0187 9.9813 0 7.5 0ZM13.0989 11.9398C11.8669 10.6889 10.2336 10 8.5 10H6.5C4.7664 10 3.13313 10.6889 1.90113 11.9398C0.675167 13.1846 0 14.8278 0 16.5667C0 16.8428 0.223867 17.0667 0.5 17.0667H14.5C14.7761 17.0667 15 16.8428 15 16.5667C15 14.8278 14.3248 13.1846 13.0989 11.9398Z" fill="currentColor"/>
				</svg>
				<?php esc_html_e( 'Support', 'ucmm-wpbrigade' ); ?>
			</a>
			<a
				href="<?php echo esc_url( 'https://wpbrigade.com/documents/ucmm/' ); ?>"
				target="_blank"
				rel="noopener noreferrer"
				class="ucmm-documentation-btn"
			>
				<svg width="16" height="20" viewBox="0 0 16 20" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
					<path fill-rule="evenodd" clip-rule="evenodd" d="M16 19.1719H0V0H12.1166L16 4.12365V19.1719ZM13.8781 15.7223H2.43862V14.8517H13.8797V15.7223H13.8781ZM10.054 4.93496H2.43862V4.06904H10.0556V4.93496H10.054ZM13.6254 7.38761H2.43862V6.51702H13.6254V7.38761ZM7.37981 10.2054H2.43862V9.33944H7.37981V10.2054ZM13.8781 12.8999H2.43862V12.0339H13.8797V12.8999H13.8781Z" fill="currentColor"/>
				</svg>
				<?php esc_html_e( 'Documentation', 'ucmm-wpbrigade' ); ?>
			</a>
		</div>
	</div>
</div>
