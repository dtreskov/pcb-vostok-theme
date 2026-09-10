<?php

/**
 * UCMM WPBrigade Settings
 *
 * @since 1.0.0
 */
if ( !class_exists( 'UCMM_WPBrigade_Setting' ) ) :

class UCMM_WPBrigade_Setting {

    function __construct() {

      add_action( 'admin_menu', array( $this, 'ucmm_wpbrigade_setting_menu' ) );
      add_action( 'wp_ajax_ucmm_help', array( $this, 'download_help' ) );
    }

    /**
     * Registers the settings page and its sub-pages in the WordPress admin menu.
     *
     * @version 3.0.0
     * @return void
     */
    function ucmm_wpbrigade_setting_menu() {

      add_menu_page( __( 'Under Construction', 'ucmm-wpbrigade' ), __( 'Under Construction', 'ucmm-wpbrigade' ), 'manage_options', "ucmm_settings", '__return_null', '', 50 );

      $settings_page = add_submenu_page( 'ucmm_settings', __( 'Settings', 'ucmm-wpbrigade' ), __( 'Settings', 'ucmm-wpbrigade' ), 'manage_options', "ucmm_settings", array( $this, 'plugin_page' ) );

      add_submenu_page( 'ucmm_settings', __( 'Customizer', 'ucmm-wpbrigade' ), __( 'Customizer', 'ucmm-wpbrigade' ), 'manage_options', "under-construction-maintenance-mode", '__return_null' );
      add_submenu_page( 'ucmm_settings', __( 'Help', 'ucmm-wpbrigade' ), __( 'Help', 'ucmm-wpbrigade' ), 'manage_options', 'ucmm-help', array( $this, 'ucmm_help_page' ) );

      // Enqueue React assets only on our settings page
      add_action( 'load-' . $settings_page, array( $this, 'ucmm_enqueue_react_assets' ) );

    }



    /**
     * Displays the settings page. React root is displayed here.
     *
     * @version 3.0.0
     * @return void
     */
    function plugin_page() {

      echo '<div class="ucmm-settings-notices">';
      /**
       * Notices for the UCMM settings screen (after the banner).
       *
       * @since 3.0.0
       */
      do_action( 'ucmm_after_settings_banner' );
      echo '</div>';

      echo '<div id="ucmm-settings-root"></div>';

    }

    /**
     * Output the settings header banner before other admin notices.
     *
     * @return void
     */
    public function ucmm_render_settings_banner() {
      static $ucmm_banner_rendered = false;

      if ( $ucmm_banner_rendered ) {
        return;
      }

      $ucmm_banner_rendered = true;

      echo '<div class="ucmm-settings-banner-wrap">';
      include UCMM_WPBRIGADE_DIR_PATH . 'includes/settings-banner.php';
      echo '</div>';
    }

    /**
     * Enqueue React assets for the settings page
     *
     * @version 3.0.0
     * @return void
     */
    function ucmm_enqueue_react_assets() {

      add_action( 'admin_notices', array( $this, 'ucmm_render_settings_banner' ), 1 );

      $build_js = UCMM_WPBRIGADE_DIR_PATH . 'build/index.js';

      if ( ! file_exists( $build_js ) ) {
        add_action( 'ucmm_after_settings_banner', array( $this, 'ucmm_missing_build_notice' ) );
        return;
      }

      // Enqueue React build assets
      $asset_file = UCMM_WPBRIGADE_DIR_PATH . 'build/index.asset.php';
      $asset_data = file_exists( $asset_file ) ? require( $asset_file ) : array( 'dependencies' => array(), 'version' => UCMM_WPBRIGADE_VERSION );

      wp_enqueue_script(
        'ucmm-react-settings',
        UCMM_WPBRIGADE_DIR_URL . 'build/index.js',
        $asset_data['dependencies'],
        $asset_data['version'],
        true
      );

      wp_enqueue_style(
        'ucmm-inter-font',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap', // phpcs:ignore
        array(),
        null
      );

      wp_enqueue_style(
        'ucmm-react-settings',
        UCMM_WPBRIGADE_DIR_URL . 'build/index.css',
        array( 'ucmm-inter-font' ),
        $asset_data['version']
      );

      // Localize script with admin data
      wp_localize_script(
        'ucmm-react-settings',
        'ucmmAdmin',
        array(
          'apiUrl' => home_url( '/wp-json/' ),
          'nonce'  => wp_create_nonce( 'wp_rest' ),
          'customizerUrl' => ucmm_wpbrigade_get_customizer_url(),
          'currentUser' => wp_get_current_user(),
          'pluginUrl' => UCMM_WPBRIGADE_DIR_URL,
        )
      );

      wp_set_script_translations( 'ucmm-react-settings', 'ucmm-wpbrigade', UCMM_WPBRIGADE_DIR_PATH . 'languages' );
    }

    /**
     * Warn when the React settings build is missing.
     *
     * @return void
     */
    function ucmm_missing_build_notice() {
      echo '<div class="notice notice-error"><p>';
      echo esc_html__(
        'Under Construction settings UI could not load because build/index.js is missing.
        Run npm install && npm run build in the plugin directory.',
        'ucmm-wpbrigade'
      );
      echo '</p></div>';
    }




    /**
     * get info
     * @since 1.0.5
     */
    public function ucmm_help_page() {

		include UCMM_WPBRIGADE_DIR_PATH . 'classes/ucmm-logs.php';

		$support_text = sprintf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag */
			__( 'Free support is available on the %1$splugin support forums%2$s.', 'ucmm-wpbrigade' ),
			'<a href="https://wordpress.org/support/plugin/under-construction-maintenance-mode" target="_blank" rel="noopener noreferrer">',
			'</a>'
		);

		$bug_report_text = sprintf(
			/* translators: %s: link to contact page */
			__( 'Found a bug or have a feature request? Please submit an issue %s!', 'ucmm-wpbrigade' ),
			'<a href="https://wpbrigade.com/contact/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'here', 'ucmm-wpbrigade' ) . '</a>'
		);

		$description = wp_kses_post( $support_text . '<br>' . $bug_report_text );
		?>
		<div class="wrap ucmm-wpbrigade">
			<div class="wpbr-tabs-wrapper">
				<div id="ucmm_wpbrigade_setting" class="ucmm-wpbrigade-help-page">
					<div class="ucmm-settings-card">
						<div class="ucmm-settings-card-header">
							<div class="ucmm-settings-title-row">
								<h3><?php esc_html_e( 'Help & Troubleshooting', 'ucmm-wpbrigade' ); ?></h3>
							</div>
							<p class="ucmm-settings-card-description">
								<?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above. ?>
							</p>
						</div>
						<div class="ucmm-help-sysinfo">
							<div class="ucmm-help-sysinfo__box">
								<button
									type="button"
									class="ucmm-help-sysinfo-copy"
									aria-label="<?php esc_attr_e( 'Copy system info', 'ucmm-wpbrigade' ); ?>"
									aria-describedby="ucmm-help-sysinfo-copy-tooltip"
								>
									<span
										id="ucmm-help-sysinfo-copy-tooltip"
										class="ucmm-help-sysinfo-copy__tooltip"
										role="tooltip"
									><?php esc_html_e( 'Copy', 'ucmm-wpbrigade' ); ?></span>
									<span class="ucmm-help-sysinfo-copy__icon" aria-hidden="true">
										<svg width="20" height="20" viewBox="0 0 20 20" fill="none">
											<path fill="#646970" d="M12.668 10.667c0-.71 0-1.204-.031-1.588a2.4 2.4 0 0 0-.113-.615l-.055-.13a1.84 1.84 0 0 0-.676-.731l-.127-.072c-.158-.08-.37-.137-.745-.168-.384-.031-.877-.031-1.588-.031H6.5c-.711 0-1.204 0-1.588.031a2.4 2.4 0 0 0-.615.113l-.13.055a1.84 1.84 0 0 0-.731.676l-.07.127c-.081.158-.138.37-.169.745-.031.384-.032.877-.032 1.588V13.5c0 .711 0 1.204.032 1.588.031.376.088.587.168.745l.07.126c.177.288.43.522.732.676l.13.056c.144.052.333.089.615.112.384.031.877.032 1.588.032h2.833c.71 0 1.204 0 1.588-.032.376-.031.587-.088.745-.168l.127-.07c.287-.177.522-.43.676-.732l.055-.13c.052-.144.09-.333.113-.615.031-.384.031-.877.031-1.588zm1.33 1.998c.455-.002.803-.005 1.09-.028.376-.031.587-.088.745-.168l.126-.071c.288-.177.522-.43.676-.732l.056-.13a2.4 2.4 0 0 0 .112-.615c.031-.384.032-.877.032-1.588V6.5c0-.711 0-1.204-.032-1.588a2.4 2.4 0 0 0-.112-.615l-.056-.13a1.84 1.84 0 0 0-.676-.731l-.126-.07c-.158-.081-.37-.138-.745-.169-.384-.031-.877-.032-1.588-.032h-2.833c-.71 0-1.204.001-1.588.032-.282.023-.471.06-.615.112l-.13.056a1.84 1.84 0 0 0-.731.676l-.072.126c-.08.158-.137.37-.168.745-.023.287-.027.635-.029 1.09h1.999c.689 0 1.246 0 1.696.036.458.038.865.117 1.242.309l.217.122c.496.304.9.74 1.165 1.26l.067.143c.144.337.21.698.242 1.099.037.45.036 1.007.036 1.696zm4.167-3.332c0 .689 0 1.246-.036 1.696-.033.401-.098.762-.242 1.099l-.067.143c-.265.52-.67.956-1.165 1.26l-.219.122c-.376.192-.782.271-1.24.309-.337.027-.734.031-1.2.033-.003.467-.007.864-.034 1.201-.033.401-.098.762-.242 1.098l-.067.142c-.265.522-.669.958-1.165 1.262l-.217.122c-.377.192-.784.271-1.242.309-.45.037-1.007.036-1.696.036H6.5c-.69 0-1.246 0-1.696-.036-.4-.033-.762-.098-1.098-.242l-.143-.067a3.17 3.17 0 0 1-1.261-1.165l-.122-.219c-.192-.376-.271-.782-.309-1.24-.037-.45-.036-1.007-.036-1.696v-2.833c0-.689 0-1.246.036-1.696.038-.458.117-.865.309-1.242l.122-.217c.304-.496.74-.9 1.261-1.165l.143-.067c.336-.144.697-.21 1.098-.242.337-.027.733-.032 1.2-.034.002-.467.007-.863.034-1.2.037-.458.117-.864.309-1.24l.122-.22c.304-.495.74-.899 1.26-1.164l.143-.067c.337-.144.698-.21 1.099-.242.45-.037 1.007-.036 1.696-.036H13.5c.69 0 1.246 0 1.696.036.458.038.864.117 1.24.309l.22.122c.495.304.899.74 1.164 1.261l.067.143c.144.336.21.697.242 1.098.037.45.036 1.007.036 1.696z"/>
										</svg>
									</span>
								</button>
								<textarea class="ucmm-help-sysinfo__textarea" rows="25" readonly><?php echo esc_textarea( Uccm_Logs_Info::get_sysinfo() ); ?></textarea>
							</div>
						</div>
						<div class="ucmm-settings-actions">
							<input
								type="button"
								class="ucmm-button-primary ucmm-wpbrigade-log-file"
								value="<?php esc_attr_e( 'Download Log File', 'ucmm-wpbrigade' ); ?>"
							/>
							<span class="ucmm-log-file-sniper" aria-hidden="true">
								<img src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" />
							</span>
							<div
								class="ucmm-notice ucmm-notice--inline ucmm-notice-success ucmm-log-file-text"
								role="status"
								aria-hidden="true"
							>
								<p><?php esc_html_e( 'Under Construction Log File Downloaded Successfully!', 'ucmm-wpbrigade' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
    }
    /**
     * call back function of download help ajax
     * @since 1.0.5
     */
     public function download_help(){

       check_ajax_referer( 'ucmm_help_nonce', 'help_nonce' );

       if ( ! current_user_can( 'manage_options' ) ) {
         wp_die( 'error' );
       }

       include UCMM_WPBRIGADE_DIR_PATH . 'classes/ucmm-logs.php';
       echo Uccm_Logs_Info::get_sysinfo();
       wp_die();
     }
}



endif;
