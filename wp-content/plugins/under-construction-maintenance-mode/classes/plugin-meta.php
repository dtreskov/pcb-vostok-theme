<?php 
/**
 * WordPress UCMM plugin meta
 * @since 1.1.0
 */

if ( ! class_exists( 'UCMM_Plugin_Meta' ) ) :
	
	class UCMM_Plugin_Meta {

		function __construct() {
			
      add_filter( 'plugin_row_meta', array( $this, '_row_meta'), 10, 2 );
      add_action( 'plugin_action_links', array( $this, '_action_links' ), 10, 2 );
			// add_action( 'admin_footer', array( $this, 'ucmm_add_deactive_modal' ) );
			add_action( 'admin_init', array( $this, 'ucmm_review_notice' ) );
		}

		/**
     * Add rating icon on plugins page.
     *
     * @since 1.1.0
     */

    public function _row_meta( $meta_fields, $file ) {

      if ( strpos( $file, UCMM_WPBRIGADE_MAIN_FILE ) == false ) {

        return $meta_fields;
      }

      echo "<style>.ucmm-rate-stars { display: inline-block; color: #ffb900; position: relative; top: 3px; }.ucmm-rate-stars svg{ fill:#ffb900; } .ucmm-rate-stars svg:hover{ fill:#ffb900 } .ucmm-rate-stars svg:hover ~ svg{ fill:none; } </style>";

      $plugin_rate   = "https://wordpress.org/support/plugin/under-construction-maintenance-mode/reviews/?rate=5#new-post";
      $plugin_filter = "https://wordpress.org/support/plugin/under-construction-maintenance-mode/reviews/?filter=5";
      $svg_xmlns     = "https://www.w3.org/2000/svg";
      $svg_icon      = '';

      for ( $i = 0; $i < 5; $i++ ) {
        $svg_icon .= "<svg xmlns='" . esc_url( $svg_xmlns ) . "' width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-star'><polygon points='12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2'/></svg>";
      }

      // Set icon for thumbsup.
      $meta_fields[] = '<a href="' . esc_url( $plugin_filter ) . '" target="_blank"><span class="dashicons dashicons-thumbs-up"></span>' . __( 'Vote!', 'ucmm-wpbrigade' ) . '</a>';

      // Set icon for 5-star reviews. v1.1.22
      $meta_fields[] = "<a href='" . esc_url( $plugin_rate ) . "' target='_blank' title='" . esc_html__( 'Rate', 'ucmm-wpbrigade' ) . "'><i class='ucmm-rate-stars'>" . $svg_icon . "</i></a>";

      return $meta_fields;
		}
		

		/**
		 * Add a link to the settings page to the plugins list
		 *
		 * @since  1.1.0
		 */
		public function _action_links( $links, $file ) {

			static $this_plugin;

			if ( empty( $this_plugin ) ) {

				$this_plugin = 'under-construction-maintenance-mode/under-construction-maintenance-mode.php';
			}

			if ( $file == $this_plugin ) {

				$settings_link = sprintf( esc_html__( '%1$s Settings %2$s | %3$s Customize %4$s', 'ucmm-wpbrigade' ), '<a href="' . admin_url( 'admin.php?page=ucmm_settings' ) . '">', '</a>', '<a href="' . admin_url( 'admin.php?page=under-construction-maintenance-mode' ) . '">', '</a>' );

				array_unshift( $links, $settings_link );

			}

			return $links;
		}

		/**
		 * Set time to current so review notice will popup after 14 days
		 *
		 * @since 1.0.1
		 */
		function ucmm_review_pending() {

			if ( ! is_admin() ||
			! current_user_can( 'manage_options' ) ||
			! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ucmm-review-nonce' ) ||
			! isset( $_GET['ucmm_review_later'] ) ) :

				return;
		  endif;

			// Reset Time to current time.
			update_site_option( 'ucmm_active_time', time() );
		}

		/**
		 * Ask users to review our plugin on WordPress.org
		 *
		 * @since 1.0.1
		 * @return boolean false
		 */
		public function ucmm_review_notice() {

			$this->ucmm_review_dismissal();
			$this->ucmm_review_pending();

			$activation_time  = get_site_option( 'ucmm_active_time' );
			$review_dismissal = get_site_option( 'ucmm_review_dismiss' );

			if ( 'yes' == $review_dismissal ) {
				return;
			}

			if ( ! $activation_time ) :

				$activation_time = time();
				add_site_option( 'ucmm_active_time', $activation_time );
			endif;

			// 1296000 = 15 Days in seconds.
			if ( time() - $activation_time > 1296000 ) :
				add_action( 'admin_notices', array( $this, 'ucmm_review_notice_message' ) );
			endif;
		}

		/**
		 * Whether the current screen is the UCMM settings page.
		 *
		 * @since 3.0.0
		 * @return bool
		 */
		private function ucmm_is_settings_screen() {

			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();

				if ( $screen && 'toplevel_page_ucmm_settings' === $screen->id ) {
					return true;
				}
			}

			return isset( $_GET['page'] ) && 'ucmm_settings' === sanitize_key( wp_unslash( $_GET['page'] ) );
		}

		/**
		 * Review notice message
		 *
		 * @since  1.0.1
		 */
		public function ucmm_review_notice_message() {

			$scheme      = ( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY ) ) ? '&' : '?';
			$url         = $_SERVER['REQUEST_URI'] . $scheme . 'ucmm_review_dismiss=yes';
			$dismiss_url = wp_nonce_url( $url, 'ucmm-review-nonce' );

			$_later_link = $_SERVER['REQUEST_URI'] . $scheme . 'ucmm_review_later=yes';
			$later_url   = wp_nonce_url( $_later_link, 'ucmm-review-nonce' );

			?>
		  <div class="ucmm-review-notice">
			  <div class="ucmm-review-thumbnail">
				  <img src="<?php echo plugins_url( '../img/review-icon.png', __FILE__ ); ?>" alt="">
			  </div>
			  <div class="ucmm-review-text">
				  <h3><?php _e( 'Leave A Review?', 'ucmm-wpbrigade' ); ?></h3>
				  <p><?php _e( 'We hope you\'ve enjoyed using Under Construction & Maintenance Mode! Would you consider leaving us a review on WordPress.org?', 'ucmm-wpbrigade' ); ?></p>
				  <ul class="ucmm-review-ul">
			<li><a href="https://wordpress.org/support/view/plugin-reviews/under-construction-maintenance-mode?rate=5#postform" target="_blank"><span class="dashicons dashicons-external"></span><?php _e( 'Sure! I\'d love to!', 'ucmm-wpbrigade' ); ?></a></li>
			<li><a href="<?php echo esc_url( $dismiss_url ); ?>"><span class="dashicons dashicons-smiley"></span><?php _e( 'I\'ve already left a review', 'ucmm-wpbrigade' ); ?></a></li>
			<li><a href="<?php echo esc_url( $later_url ); ?>"><span class="dashicons dashicons-calendar-alt"></span><?php _e( 'Maybe Later', 'ucmm-wpbrigade' ); ?></a></li>
			<li><a href="<?php echo esc_url( $dismiss_url ); ?>"><span class="dashicons dashicons-dismiss"></span><?php _e( 'Never show again', 'ucmm-wpbrigade' ); ?></a></li></ul>
			  </div>
		  </div>
			<?php
		}

		/**
		 *  Check and Dismiss review message.
		 *
		 *  @since 1.0.1
		 */
		private function ucmm_review_dismissal() {

			if ( ! is_admin() ||
			! current_user_can( 'manage_options' ) ||
			! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ucmm-review-nonce' ) ||
			! isset( $_GET['ucmm_review_dismiss'] ) ) :

				return;
			endif;

			add_site_option( 'ucmm_review_dismiss', 'yes' );
		}
	}
endif;

new UCMM_Plugin_Meta();