<?php
/**
 * Sortable social icons order control for the Customizer.
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
 * Drag-and-drop list for social network display order (stored as comma-separated keys).
 */
class UCMM_WPBrigade_Social_Icons_Order_Control extends WP_Customize_Control {

	/**
	 * Control type.
	 *
	 * @var string
	 */
	public $type = 'ucmm-social-icons-order';

	/**
	 * Human labels keyed by option key (ucmm_facebook, ...).
	 *
	 * @var array<string, string>
	 */
	public $ucmm_order_labels = array();

	/**
	 * Render control markup.
	 *
	 * @return void
	 */
	public function render_content() {
		$csv     = UCMM_WPBrigade_Entities::ucmm_wpbrigade_sanitize_social_order( $this->value() );
		$order   = explode( ',', $csv );
		$list_id = 'ucmm-social-order-list-' . $this->id;
		$input_id = 'ucmm-social-order-input-' . $this->id;
		?>
		<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
		<?php if ( ! empty( $this->description ) ) : ?>
			<span class="description customize-control-description"><?php echo esc_html( $this->description ); ?></span>
		<?php endif; ?>

		<input
			type="hidden"
			class="ucmm-social-order-input"
			id="<?php echo esc_attr( $input_id ); ?>"
			value="<?php echo esc_attr( $csv ); ?>"
			<?php $this->link(); ?>
		/>

		<ul class="ucmm-social-order-list" id="<?php echo esc_attr( $list_id ); ?>" aria-label="<?php esc_attr_e( 'Social icons order', 'ucmm-wpbrigade' ); ?>">
			<?php foreach ( $order as $network_key ) : ?>
				<?php
				$label = isset( $this->ucmm_order_labels[ $network_key ] )
					? $this->ucmm_order_labels[ $network_key ]
					: $network_key;
				?>
				<li class="ucmm-social-order-item" data-network="<?php echo esc_attr( $network_key ); ?>">
					<span class="ucmm-social-order-handle dashicons dashicons-menu" aria-hidden="true"></span>
					<span class="ucmm-social-order-label"><?php echo esc_html( $label ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}

/**
 * Inline styles for the social order control in the Customizer.
 *
 * @return void
 */
function ucmm_wpbrigade_social_order_css() {
	?>
	<style>
		.ucmm-social-order-list {
			list-style: none;
			margin: 8px 0 0;
			max-width: 100%;
			padding: 0;
		}
		.ucmm-social-order-item {
			align-items: center;
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			box-sizing: border-box;
			cursor: grab;
			display: flex;
			gap: 8px;
			margin-bottom: 6px;
			padding: 8px 10px;
		}
		.ucmm-social-order-item:last-child {
			margin-bottom: 0;
		}
		.ucmm-social-order-item.ui-sortable-helper {
			cursor: grabbing;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
		}
		.ucmm-social-order-handle {
			color: #787c82;
			flex-shrink: 0;
			font-size: 18px;
			line-height: 1;
			width: 18px;
			height: 18px;
		}
		.ucmm-social-order-label {
			flex: 1;
			font-size: 13px;
			line-height: 1.4;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	</style>
	<?php
}
add_action( 'customize_controls_print_styles', 'ucmm_wpbrigade_social_order_css' );
