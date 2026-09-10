<?php
/**
 * Group heading control for the Customizer.
 *
 * @package UCMM_WPBrigade
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Customize_Control' ) ) {
	return;
}

/**
 * Section divider with heading and collapsible info text.
 */
class UCMM_WPBrigade_Group_Control extends WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'ucmm-group';

	/**
	 * Info text shown below the heading.
	 *
	 * @var string
	 */
	public $info_text = '';

	/**
	 * Enqueue control styles.
	 *
	 * @return void
	 */
	public function enqueue() {
		wp_enqueue_style(
			'ucmm-group-control-css',
			UCMM_WPBRIGADE_DIR_URL . 'classes/customizer/css/ucmm-group-control.css',
			array(),
			UCMM_WPBRIGADE_VERSION
		);
	}

	/**
	 * Render control markup.
	 *
	 * @return void
	 */
	public function render_content() {
		?>
		<div id="input_<?php echo esc_attr( $this->id ); ?>" class="ucmm-group-wrapper">
			<h3 class="ucmm-group-heading"><?php echo esc_html( $this->label ); ?></h3>
			<?php if ( $this->info_text ) : ?>
			<div class="ucmm-group-info">
				<p>
					<span class="ucmm-group-badge badges"><?php esc_html_e( 'Info:', 'ucmm-wpbrigade' ); ?></span><?php echo esc_html( $this->info_text ); ?>
				</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
