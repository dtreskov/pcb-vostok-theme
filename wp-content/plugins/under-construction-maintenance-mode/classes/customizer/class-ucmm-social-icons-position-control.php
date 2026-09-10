<?php
/**
 * Social icons screen position control for the Customizer.
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
 * Visual "frame + inner box" picker for top / right / bottom / left placement.
 */
class UCMM_WPBrigade_Social_Icons_Position_Control extends WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'ucmm-social-icons-position';

	/**
	 * Render control markup.
	 *
	 * @return void
	 */
	public function render_content() {
		$current = $this->value();
		$current = is_string( $current ) ? sanitize_key( $current ) : '';
		$allowed = array( 'top', 'right', 'bottom', 'left' );
		if ( ! in_array( $current, $allowed, true ) ) {
			$current = 'bottom';
		}

		$positions = array(
			'top'    => __( 'Top', 'ucmm-wpbrigade' ),
			'right'  => __( 'Right', 'ucmm-wpbrigade' ),
			'bottom' => __( 'Bottom', 'ucmm-wpbrigade' ),
			'left'   => __( 'Left', 'ucmm-wpbrigade' ),
		);
		?>
		<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
		<?php if ( ! empty( $this->description ) ) : ?>
			<span class="description customize-control-description"><?php echo esc_html( $this->description ); ?></span>
		<?php endif; ?>

		<div class="ucmm-social-position" role="group" aria-label="<?php esc_attr_e( 'Social icons position', 'ucmm-wpbrigade' ); ?>">
			<div class="ucmm-social-position__frame">
				<div class="ucmm-social-position__inner" aria-hidden="true"></div>
				<?php foreach ( $positions as $slug => $label ) : ?>
					<?php
					$input_id = $this->id . '-' . $slug;
					?>
					<div class="ucmm-social-position__slot ucmm-social-position__slot--<?php echo esc_attr( $slug ); ?>">
						<input
							type="radio"
							class="ucmm-social-position__input"
							id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( '_ucmm-social-pos-' . $this->id ); ?>"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( $slug, $current ); ?>
							<?php $this->link(); ?>
						/>
						<label class="ucmm-social-position__label" for="<?php echo esc_attr( $input_id ); ?>">
							<span class="ucmm-social-position__dot" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
						</label>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}

/**
 * Inline styles for the social position control in the Customizer.
 *
 * @return void
 */
function ucmm_social_icons_position_control_css() {
	?>
	<style>
		.ucmm-social-position__frame {
			aspect-ratio: 1;
			background: #f6f7f7;
			border: 2px solid #8c8f94;
			border-radius: 4px;
			box-sizing: border-box;
			margin: 8px auto 4px;
			max-width: 200px;
			position: relative;
			width: 100%;
		}
		.ucmm-social-position__inner {
			background: #fff;
			border: 2px dashed #c3c4c7;
			border-radius: 2px;
			bottom: 24%;
			box-sizing: border-box;
			left: 24%;
			pointer-events: none;
			position: absolute;
			right: 24%;
			top: 24%;
		}
		.ucmm-social-position__slot {
			position: absolute;
		}
		.ucmm-social-position__slot--top {
			left: 50%;
			top: 6px;
			transform: translateX(-50%);
		}
		.ucmm-social-position__slot--bottom {
			bottom: 6px;
			left: 50%;
			transform: translateX(-50%);
		}
		.ucmm-social-position__slot--left {
			left: 6px;
			top: 50%;
			transform: translateY(-50%);
		}
		.ucmm-social-position__slot--right {
			right: 6px;
			top: 50%;
			transform: translateY(-50%);
		}
		.ucmm-social-position__input {
			margin: 0;
			opacity: 0;
			position: absolute;
		}
		.ucmm-social-position__label {
			cursor: pointer;
			display: block;
			padding: 6px;
		}
		.ucmm-social-position__dot {
			background: #fff;
			border: 2px solid #8c8f94;
			border-radius: 50%;
			box-sizing: border-box;
			display: block;
			height: 18px;
			transition: border-color 0.15s ease, box-shadow 0.15s ease;
			width: 18px;
		}
		.ucmm-social-position__input:focus + .ucmm-social-position__label .ucmm-social-position__dot {
			box-shadow: 0 0 0 2px #2271b1;
			outline: none;
		}
		.ucmm-social-position__input:checked + .ucmm-social-position__label .ucmm-social-position__dot {
			background: #2271b1;
			border-color: #2271b1;
			box-shadow: 0 0 0 2px #fff inset;
		}
		.ucmm-social-position__legend {
			color: #50575e;
			display: flex;
			flex-wrap: wrap;
			font-size: 11px;
			gap: 8px 12px;
			justify-content: center;
			list-style: none;
			margin: 6px 0 0;
			padding: 0;
		}
		.ucmm-social-position__legend li {
			margin: 0;
		}
	</style>
	<?php
}
add_action( 'customize_controls_print_styles', 'ucmm_social_icons_position_control_css' );
