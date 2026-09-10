<?php
/**
 * Plugin Name: Under Construction & Maintenance Mode
 * Plugin URI: https://wpbrigade.com/wordpress/plugins/under-construction-maintenance-mode/?utm_source=ucmm-org&utm_medium=plugin-url-link
 * Description: This plugin will Display an Under Construction, Maintenance Mode or Coming Soon landing Page that takes 5 seconds to setup, while you're doing maintenance work on your site.
 * Version: 3.0.1
 * Author: WPBrigade
 * Author URI: https://www.WPBrigade.com/?utm_source=ucmm-org&utm_medium=author-url-link
 * Requires at least: 5.0
 * Text Domain: ucmm-wpbrigade
 * Domain Path: /languages
 * GitHub Plugin URI: https://github.com/WPBrigade/under-construction-maintenance-mode
 *
 * @package ucmm-wpbrigade
 * @category Core
 * @author WPBrigade
 */

/**
 *UnderConstruction main class.
 */

if ( ! function_exists( 'ucmm_wpb50659630' ) ) {
	/**
	 * Create a helper function for easy SDK access.
	 *
	 * @return mixed
	 */
	function ucmm_wpb50659630() {
		global $ucmm_wpb50659630;

		if ( ! isset( $ucmm_wpb50659630 ) || ! is_array( $ucmm_wpb50659630 ) ) {
			require_once __DIR__ . '/lib/wpb-sdk/start.php';

			/**
			 * Initialize WPB SDK.
			 *
			 * @phpstan-ignore-next-line
			 */
			$ucmm_wpb50659630 = wpb_sdk_dynamic_init(
				array(
					'id'              => '1',
					'slug'            => 'under-construction-maintenance-mode',
					'type'            => 'plugin',
					'plugin_file'     => __FILE__,
					'sdk_views_dir'   => __DIR__ . '/lib/wpb-sdk/views',
					'public_key'      => '1|4aOA8EuyIN4pi2miMvC23LLpnHbBZFNki9R9pVmwd673d3c8',
					'secret_key'      => 'sk_b36c525848fee035',
					'is_premium'      => false,
					'has_addons'      => false,
					'has_paid_plans'  => false,
					'optin_user_meta' => array(
						'token'          => '_ucmm_verification_token',
						'email_verified' => '_ucmm_email_verified',
					),
					'optin'           => array(
						'option_name'       => '_ucmm_optin',
						'settings_page'     => 'ucmm_settings',
						'optin_page'        => 'ucmm-optin',
						'verify_query_args' => array(
							'under-construction-maintenance-mode_optin_verify',
							'ucmm_optin_verify',
						),
						'ajax_prefix'       => 'ucmm',
						'product_name'      => 'Under Construction & Maintenance Mode',
					),
					'telemetry'       => array(
						'optout_submit_key' => 'ucmm-submit-optout',
					),
					'menu'            => array(
						'slug'    => 'under-construction-maintenance-mode',
						'account' => false,
						'support' => false,
					),
					'settings'        => array(
						'ucmm_wpbrigade_setting'                                      => '',
						'ucmm_wpbrigade_customization'                                => '',
						'_ucmm_optin'                                                 => '',
						'wpb_sdk_under-construction-maintenance-mode'                 => '',
						'wpb_sdk_under-construction-maintenance-mode_fallback_verify_token' => '',
						'wpb_sdk_under-construction-maintenance-mode_initial_log_sent' => '',
					),
				)
			);
		}

		return $ucmm_wpb50659630;
	}

	ucmm_wpb50659630();
	do_action( 'ucmm_wpb50659630_loaded' );
}


if ( ! class_exists( 'UCMM_WPBrigade' ) ) :

	class UCMM_WPBrigade {

		/**
		 * @var string
		 */
		public $version = '3.0.1';

		/**
		 * @var array
		 * @since 1.0.5
		 */
		public $ucmm_settings;

		/**
		 * @var array
		 * @since 1.0.6
		 */
		public $ucmm_customize_settings;

		function __construct() {

			$this->define_constants();
			$this->_hooks();
			$this->includes();
		}

		public function define_constants() {

			$this->define( 'UCMM_WPBRIGADE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
			$this->define( 'UCMM_WPBRIGADE_DIR_PATH', plugin_dir_path( __FILE__ ) );
			$this->define( 'UCMM_WPBRIGADE_DIR_URL', plugin_dir_url( __FILE__ ) );
			$this->define( 'UCMM_WPBRIGADE_ROOT_PATH', dirname( __FILE__ ) . '/' );
			$this->define( 'UCMM_WPBRIGADE_VERSION', $this->version );
			$this->define( 'UCMM_WPBRIGADE_FEEDBACK_SERVER', 'https://wpbrigade.com/' );
			$this->define( 'UCMM_WPBRIGADE_MAIN_FILE', 'under-construction-maintenance-mode.php' );
		}

		/**
		 * Define all the hooks.
		 *
		 * @since 1.0.0
		 * @version 1.4.0
		 */
		public function _hooks() {

			register_activation_hook( __FILE__, array( $this, 'ucmm_activation' ) );
			register_deactivation_hook( __FILE__, array( $this, 'ucmm_deactivation' ) );

			add_action( 'init', array( $this, 'ucmm_redirect_customizer' ) );
			add_action( 'init', array( $this, 'ucmm_set_setting' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'ucmm_admin_scripts' ) );
			add_action( 'wp', array( $this, 'ucmm_parse_request' ), 10, 1 );

			// add_action( 'wp_ajax_ucmm_deactivate', array( $this, 'ucmm_deactivate' ) );
			add_action( 'customize_controls_enqueue_scripts', array( $this, 'ucmm_customizer_js' ) );
			add_action( 'wp_ajax_ucmm_mc_api', array( $this, 'ucmm_mc_api_function' ) );
			add_action( 'wp_ajax_nopriv_ucmm_mc_api', array( $this, 'ucmm_mc_api_function' ) );
			add_action( 'admin_bar_menu', array( $this, 'ucmm_admin_top_menu' ), 100 );
			add_action( 'admin_footer', array( $this, 'ucmm_admin_css' ), 11 );

			add_action( 'admin_init', array( $this, 'redirect_optin' ) );
			// After UCMM_WPBrigade_Setting registers ucmm_settings (includes() runs after _hooks()).
			add_action( 'admin_menu', array( $this, 'register_ucmm_optin_page' ), 20 );
			add_action( 'wp_wpb_sdk_after_uninstall', array( $this, 'plugin_uninstallation' ) );

		}

		/**
		 * Summary of register_ucmm_optin_page
		 * @since 1.5.4
		 */
		function register_ucmm_optin_page() {
			add_submenu_page(
				'ucmm_settings',
				__( 'Activate', 'ucmm-wpbrigade' ),
				' ',
				'manage_options',
				'ucmm-optin',
				array( $this, 'ucmm_render_optin_page' )
			);
		}

		/**
		 * Summary of ucmm_render_optin_page
		 * @since 1.5.4
		 */
		function ucmm_render_optin_page() {
			if ( function_exists( 'wpb_sdk_render_optin_form' ) ) {
				wpb_sdk_render_optin_form( 'under-construction-maintenance-mode' );
			}
		}

        /**
         * This function is triggered when the plugin is uninstalled.
		 * 
         * @since 2.1.0
         */
		function plugin_uninstallation( $slug = '' ) {
			if ( 'under-construction-maintenance-mode' !== $slug ) {
				return;
			}
			include_once UCMM_WPBRIGADE_DIR_PATH . 'includes/uninstall.php';
		}

		/**
		 * Summary of redirect_optin
		 * @since 1.5.4
		 */
		function redirect_optin() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
			$decision = function_exists( 'wpb_sdk_get_optin_decision' )
				? wpb_sdk_get_optin_decision( 'under-construction-maintenance-mode' )
				: (string) get_option( '_ucmm_optin', '' );

			if (
				$page
				&& in_array( $page, array( 'ucmm_settings', 'under-construction-maintenance-mode' ), true )
				&& '' === $decision
			) {
				$page_redirect = 'ucmm_settings' === $page ? 'ucmm_settings' : 'under-construction-maintenance-mode';
				wp_safe_redirect(
					admin_url( 'admin.php?page=ucmm-optin&redirect-page=' . rawurlencode( $page_redirect ) )
				);
				exit;
			}

			if (
				(
					function_exists( 'wpb_sdk_should_redirect_from_optin_page' )
						? wpb_sdk_should_redirect_from_optin_page( 'under-construction-maintenance-mode' )
						: ( 'yes' === $decision )
				)
				&& 'ucmm-optin' === $page
			) {
				wp_safe_redirect( admin_url( 'admin.php?page=ucmm_settings' ) );
				exit;
			}
		}

		/**
		 * Includes all the necessary PHP files
		 *
		 * @version 3.0.0
		 */
		public function includes() {

			include_once UCMM_WPBRIGADE_DIR_PATH . 'classes/customizer.php';
			new UCMM_WPBrigade_Entities();
			include_once UCMM_WPBRIGADE_DIR_PATH . 'classes/ucmm-wpbrigade-setup.php';
			new UCMM_WPBrigade_Setting();
			include_once UCMM_WPBRIGADE_DIR_PATH . 'classes/plugin-meta.php';
			include_once UCMM_WPBRIGADE_DIR_PATH . 'classes/ucmm-rest-api.php';

		}

		public function ucmm_activation() {

			/*Activation Plugin*/
			$this::ucmm_remove_cache();

		}

		public function ucmm_deactivation() {

			/*Deactivation Plugin*/
			$this::ucmm_remove_cache();
		}

		public static function ucmm_remove_cache() {

			global $file_prefix;
			if ( function_exists( 'w3tc_pgcache_flush' ) ) {
				w3tc_pgcache_flush();
			}
			if ( function_exists( 'wp_cache_clean_cache' ) ) {
				wp_cache_clean_cache( $file_prefix, true );
			}
		}

		function ucmm_redirect_customizer() {

			if ( ! empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$page = sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( 'under-construction-maintenance-mode' === $page ) {
					wp_safe_redirect( ucmm_wpbrigade_get_customizer_url() );
					exit;
				}
			}

			$plugin_dir = dirname( plugin_basename( __FILE__ ) );
			load_plugin_textdomain( 'ucmm-wpbrigade', false, $plugin_dir . '/languages/' );
		}

		/**
		 * Time Remaining.
		 *
		 * @since 1.0.6
		 */
		function is_ucmm_time() {
			$ucmm_now            = time();
			$schedule_start      = isset( $this->ucmm_customize_settings['ucmm_schedule_start'] ) ? $this->ucmm_customize_settings['ucmm_schedule_start'] : null;
			$ucmm_schedule_start = ucmm_wpbrigade_schedule_to_timestamp( $schedule_start );
			$schedule_end        = isset( $this->ucmm_customize_settings['ucmm_schedule_end'] ) ? $this->ucmm_customize_settings['ucmm_schedule_end'] : null;
			$ucmm_schedule_end   = ucmm_wpbrigade_schedule_to_timestamp( $schedule_end );

			if ( false === $ucmm_schedule_start || false === $ucmm_schedule_end ) {
				return false;
			}

			if ( $ucmm_now > $ucmm_schedule_start && $ucmm_now <= $ucmm_schedule_end ) {
				return true;
			}

			return false;
		}

	/**
	 * Check manual function to check if user has manually turned on ucmm mode or not
	 * 
	 * @return bool
	 * @since 1.1.0
	 */
	function check_manual() { 
		$ucmm_settings = get_option( 'ucmm_wpbrigade_setting' );
		if( isset( $ucmm_settings['ucmm-status'] ) && 'on' == $ucmm_settings['ucmm-status'] ) { 
			return true; 
		} else { 
			return false; 
		}
	}

	/**
	* Check schedule function to check if user has schduled mode turned on or not
	*
	* @return bool
	* @since 1.1.0
	*/
	//schedule enabled and time set
	function check_schedule() {
		$ucmm_customizer_settings = get_option( 'ucmm_wpbrigade_customization' );
		$is_schedule_checked			= isset( $ucmm_customizer_settings['ucmm_schedule_show_end_time'] ) ? $ucmm_customizer_settings['ucmm_schedule_show_end_time'] : false;

		//if schedule is checked and time is remaining
		if( $is_schedule_checked ) {
			return true;
		} else {
			return false;
		}
	}

		/**
		 * parse_request Fires once all query variables for the current request have been parsed.
		 *
		 * @param $wp Current WordPress environment instance (passed by reference)
		 * @since 1.0.0
		 * @version 1.4.0
		 */

		function ucmm_parse_request( $wp ) {

			// check to disable the enable page option if schedule end-time is less than current time 
			global $wp_customize, $current_user, $user_login;
			$ucmm_settings     = $this->ucmm_settings;
			$current_user_role = current( $current_user->roles );
			$screen            = function_exists( 'get_current_screen' ) ? get_current_screen() : '';

			// Exclude UCMM on if edit page is being edited.
			if ( ! empty( $screen ) && $screen->id == 'edit-post' ) {
				return;
			}

			// Exclude UCMM page for the post/s and Pages.
			if ( $this->ucmm_exclude_post() && ! ( isset( $wp_customize ) && isset( $_GET['watch'] ) && $_GET['watch'] == 'ucmm-customizer' ) ) { 
				return; 
			}

			// Main condition and excluded role/s AND [schedule is disabled] or should be false and time is left
			if( $this->check_manual() && $this->check_schedule() && $this->is_ucmm_time() && !isset( $ucmm_settings['ucmm-enable'][ 'ucmm-wpbrigade_role_' . $current_user_role ] ) ) {

				$this->ucmm_render_maintenance_page();
			}

			// check_schedule, time is remaining, excluded role/s AND [schedule is disabled] or should be true
			if( $this->check_schedule() && !isset( $ucmm_settings['ucmm-enable'][ 'ucmm-wpbrigade_role_' . $current_user_role ] ) ) {

				if($this->is_ucmm_time()){
					$this->ucmm_render_maintenance_page();
				}
			}

			// check_manual, time is remaining and excluded role/s AND [check_schedule is disabled] or should be false
			if( $this->check_manual() && $this->check_schedule() && !isset( $ucmm_settings['ucmm-enable'][ 'ucmm-wpbrigade_role_' . $current_user_role ] ) ) {

				if( $this->is_ucmm_time() ){				
					$this->ucmm_render_maintenance_page();
				}
			}
			
			// check_manual, check_schedule unchecked and excluded role/s AND [check_schedule is disabled] or should be false
			if( $this->check_manual() && $this->check_schedule() == false && !isset( $ucmm_settings['ucmm-enable'][ 'ucmm-wpbrigade_role_' . $current_user_role ] ) ) {

				$this->ucmm_render_maintenance_page();
			}

			// For customizer preview.
			if( isset( $wp_customize ) && isset( $_GET['watch'] ) && $_GET['watch'] == 'ucmm-customizer' ) {

				$this->ucmm_render_maintenance_page();
			}

		}

		/**
		 * Bootstrap admin bar and render the maintenance template.
		 *
		 * Exits during the `wp` hook, before `template_redirect`, so core admin
		 * bar initialization must run manually for logged-in users.
		 *
		 * @since 3.0.0
		 * @return void
		 */
		private function ucmm_render_maintenance_page() {
			$is_ucmm_customizer_preview = is_customize_preview()
				|| ( isset( $_GET['watch'] ) && 'ucmm-customizer' === $_GET['watch'] );

			if ( $is_ucmm_customizer_preview ) {
				show_admin_bar( false );
			} elseif ( is_user_logged_in() ) {
				show_admin_bar( true );

				global $wp_admin_bar;

				if ( function_exists( '_wp_admin_bar_init' ) && ! is_object( $wp_admin_bar ) ) {
					_wp_admin_bar_init();
				}
			}

			include UCMM_WPBRIGADE_DIR_PATH . 'ucmm-customize.php';
			exit();
		}

		/**
		 * Check if current page should be excluded from maintenance mode based on new settings.
		 *
		 * @since 3.0.0
		 * @return bool true if maintenance should be disabled for current page
		 */
		public function ucmm_should_exclude_current_page() {
			
			$ucmm_settings = get_option( 'ucmm_wpbrigade_setting' );
			$enable_on = isset( $ucmm_settings['ucmm-enable-on'] ) ? $ucmm_settings['ucmm-enable-on'] : 'whole-site';

			$current_post_id = 0;
			
			// Get current post/page ID - more comprehensive detection
			if ( is_singular() ) {
				$current_post_id = get_queried_object_id();
			} elseif ( is_home() && get_option( 'page_for_posts' ) ) {
				$current_post_id = get_option( 'page_for_posts' );
			} elseif ( is_front_page() && get_option( 'page_on_front' ) ) {
				$current_post_id = get_option( 'page_on_front' );
			} elseif ( is_front_page() ) {
				// Default front page (latest posts)
				$current_post_id = 0; // Use 0 for default front page
			}
			
			if ( $enable_on === 'specific-page' ) {
				// Only show maintenance on specific pages
				$specific_pages = isset( $ucmm_settings['ucmm-specific-pages'] ) ? $ucmm_settings['ucmm-specific-pages'] : array();
				
				// Ensure it's an array and convert to integers
				if ( ! is_array( $specific_pages ) ) {
					$specific_pages = array();
				}
				$specific_pages = array_map( 'intval', $specific_pages );
				$specific_pages = array_filter( $specific_pages ); // Remove zeros
				
				// If no pages selected, exclude maintenance everywhere
				if ( empty( $specific_pages ) ) {
					return true; // Exclude maintenance mode
				}
				
				// If current page is NOT in the specific pages list, exclude maintenance mode
				if ( ! in_array( intval( $current_post_id ), $specific_pages ) ) {
					return true; // Exclude maintenance mode
				}

				return false; // Allow maintenance mode on this specific page
				
			} else {
				// Whole site mode - check exclusions
				$exclude_pages_enabled = isset( $ucmm_settings['ucmm-exclude-pages'] ) && $ucmm_settings['ucmm-exclude-pages'] === 'on';
				
				if ( $exclude_pages_enabled ) {
					$excluded_pages = isset( $ucmm_settings['ucmm-excluded-pages'] ) ? $ucmm_settings['ucmm-excluded-pages'] : array();
					
					// Ensure it's an array and convert to integers
					if ( ! is_array( $excluded_pages ) ) {
						$excluded_pages = array();
					}
					$excluded_pages = array_map( 'intval', $excluded_pages );
					$excluded_pages = array_filter( $excluded_pages ); // Remove zeros
					
					// If current page is in exclusion list, exclude maintenance mode
					if ( in_array( intval( $current_post_id ), $excluded_pages ) ) {
						return true; // Exclude maintenance mode
					}
				}
			}
			
			return false;
		}

		/**
		 * Disable the UCMM functionality for specific page/s or post/s.
		 *
		 * @since 3.0.0
		 * @return bool true if a pages/posts is excluded | false if pages/posts are not excluded.
		 */
		public function ucmm_exclude_post() {

			// Check new Enable On settings first
			if ( $this->ucmm_should_exclude_current_page() ) {
				return true;
			}

			/**
			 * Disable the UCMM functionality for specific page/s or post/s.
			 *
			 * @param array|string The post ID or Post Slug which you want to remove from UCMM functionality.
			 * @since 1.4.0
			 */
			$exclude_ids = apply_filters( 'ucmm_exclude_post', false );

			if ( ! $exclude_ids ) {
				return false;
			}

			global $wp_query;

			$post_obj  = $wp_query->get_queried_object();
			$post_id   = isset( $post_obj->ID ) ? $post_obj->ID : '';
			$post_slug = isset( $post_obj->post_name ) ? $post_obj->post_name : '';

			if ( $post_slug && false !== $exclude_ids ) {

				// if array is provided by user.
				if ( is_array( $exclude_ids ) ) {
					foreach ( $exclude_ids as $value ) {
						if ( $post_slug == $value || $post_id == $value ) {
							return true;
						}
					}
				} else {
					// if single value is provided by user.
					if ( $post_slug == $exclude_ids || $post_id == $exclude_ids ) {
						return true;
					}
				}
			}
		}

		/**
		 * Enqueue jQuery and use wp_localize_script.
		 *
		 * @since 1.0.4
		 * @version 3.0.0
		 */
		function ucmm_customizer_js() {

			wp_enqueue_script( 'jquery' );
			wp_enqueue_script(
				'ucmm-customize-control',
				plugins_url( 'assets/js/customize-controls.js', __FILE__ ),
				array( 'jquery', 'jquery-ui-sortable', 'customize-controls' ),
				UCMM_WPBRIGADE_VERSION,
				true
			);

			if ( isset( $_GET['autofocus'] ) && 'ucmm_wpbrigade_panel' === sanitize_text_field( wp_unslash( $_GET['autofocus'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$ucmm_auto_focus = true;
			} else {
				$ucmm_auto_focus = false;
			}

			wp_localize_script(
				'ucmm-customize-control',
				'UCMM',
				array(
					'url_path'            => plugin_dir_url( __FILE__ ),
					'autoFocusPanel'      => $ucmm_auto_focus,
					'header_text_default' => __( 'COMING SOON', 'ucmm-wpbrigade' ),
					'footer_text_default' => __( "We're not quite ready yet, Something is coming very soon", 'ucmm-wpbrigade' ),
					'customizer_strings'  => array(
						_x( 'Powered by: ', 'String for the "Show Some Love" footer text', 'ucmm-wpbrigade' ),
						_x( 'WPBrigade', 'String for the "Show Some Love" footer text', 'ucmm-wpbrigade' ),
					),
					'scheduleUtcOffset'           => (int) ucmm_wpbrigade_schedule_utc_offset(),
					'scheduleInvalidMessage'      => __( 'End time must be later than the start time.', 'ucmm-wpbrigade' ),
					'scheduleInvalidPreviewMessage' => __( 'End time must be later than the start time. This schedule cannot run until corrected.', 'ucmm-wpbrigade' ),
					'schedulePendingPreviewMessage' => __( 'Maintenance has not started yet. The countdown timer will begin at the scheduled start time.', 'ucmm-wpbrigade' ),
					'scheduleExpiredPreviewMessage' => __( 'Maintenance schedule has been expired. Please update the time or turn it off.', 'ucmm-wpbrigade' ),
					'scheduleExpiredLiveMessage'    => __( 'Website is now LIVE! will be redirected to homepage shortly. If not, please refresh the page.', 'ucmm-wpbrigade' ),
				)
			);
		}
		/**
		 * Define constant if not already set
		 *
		 * @param  string      $name
		 * @param  string|bool $value
		 * @since 1.0.0
		 */
		private function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		/**
		 * Admin styles/scripts for review notice (global) and Help page log download.
		 *
		 * @param string $hook_suffix Current admin screen hook.
		 * @return void
		 */
		public function ucmm_admin_scripts( $hook_suffix ) {
			wp_enqueue_style(
				'ucmm-review-notice',
				plugins_url( 'assets/css/review-notice.css', __FILE__ ),
				array(),
				UCMM_WPBRIGADE_VERSION
			);

			if ( false === strpos( $hook_suffix, 'ucmm-help' ) ) {
				return;
			}

			$asset_file = UCMM_WPBRIGADE_DIR_PATH . 'build/index.asset.php';
			$asset_data = file_exists( $asset_file ) ? require $asset_file : array(
				'version' => UCMM_WPBRIGADE_VERSION,
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

			wp_enqueue_style(
				'ucmm-help-page',
				plugins_url( 'assets/css/help-page.css', __FILE__ ),
				array( 'ucmm-react-settings' ),
				UCMM_WPBRIGADE_VERSION
			);

			wp_enqueue_script( 'ucmm-js', plugins_url( 'assets/js/main.js', __FILE__ ), array( 'jquery' ), UCMM_WPBRIGADE_VERSION, true );

			wp_localize_script(
				'ucmm-js',
				'mc_api',
				array(
					'ajaxurl'     => admin_url( 'admin-ajax.php' ),
					'loader'      => admin_url( '/images/spinner.gif' ),
					'help_nonce'  => wp_create_nonce( 'ucmm_help_nonce' ),
					'security'    => wp_create_nonce( 'security_under-construction-maintenance-mode' ),
					'copyLabel'     => __( 'Copy', 'ucmm-wpbrigade' ),
					'copiedLabel'   => __( 'Copied!', 'ucmm-wpbrigade' ),
					'copyAriaLabel' => __( 'Copy system info', 'ucmm-wpbrigade' ),
				)
			);
		}

		public function ucmm_mc_api_function() {

			include UCMM_WPBRIGADE_DIR_PATH . 'includes/mc-get_lists.php';
			wp_die();
		}

		/**
		 * @since 1.0.5
		 */
		public function ucmm_admin_top_menu() {
			global $wp_admin_bar;
			$value = $this->ucmm_get_options( 'ucmm-status', 'off' );
			if ( $value == 'on' ) {
				$argsParent = array(
					'id'    => 'ucmm_top_menu',
					'title' => __( 'Under Construction mode Enabled', 'ucmm-wpbrigade' ),
					'href'  => admin_url( '?page=ucmm_settings' ),
					'meta'  => array( 'class' => 'ucmm_top_menu' ),

				);
				$wp_admin_bar->add_menu( $argsParent );
			}

		}

		/**
		 * custom css for admin.
		 *
		 * @since 1.0.5
		 */
		public function ucmm_admin_css() {
			echo '<style>
     #wp-admin-bar-ucmm_top_menu a{
      background: #9522ce;
     }
      #wp-admin-bar-ucmm_top_menu a:hover{
      background: #731f9c !important;
      color:#fff!important;
     }

      </style>';

		}
		/**
		 * Get setting option uccm options
		 *
		 * @since 1.0.5
		 * @param string $ucmm_key option key in options.
		 * @param mixed  $default_value default value of the option.
		 *
		 * @return mixed  any type will me return.
		 */
		public function ucmm_get_options( $ucmm_key, $default_value = false ) {

			$ucmm_wpbrigade_array = $this->ucmm_settings;
			if ( array_key_exists( $ucmm_key, $ucmm_wpbrigade_array ) ) {
				return $ucmm_wpbrigade_array[ $ucmm_key ];
			} else {
				return $default_value;
			}
		}

		/**
		 * set setting of ucmm.
		 *
		 * @since 1.0.5
		 */
		public function ucmm_set_setting() {
			$this->ucmm_settings           = (array) get_option( 'ucmm_wpbrigade_setting' );
			$this->ucmm_customize_settings = (array) get_option( 'ucmm_wpbrigade_customization' );

			if ( ! array_key_exists( 'ucmm-enable', $this->ucmm_settings ) ) {
				$this->ucmm_settings['ucmm-enable'] = ucmm_wpbrigade_default_role_exemptions();
			} else {
				$this->ucmm_settings['ucmm-enable'] = ucmm_wpbrigade_migrate_role_exemption_keys(
					$this->ucmm_settings['ucmm-enable']
				);
			}
		}
	}

endif;

if ( ! function_exists( 'ucmm_wpbrigade_default_role_exemptions' ) ) {
	/**
	 * Roles exempt from maintenance when ucmm-enable has never been saved.
	 *
	 * @return array<string, string>
	 */
	function ucmm_wpbrigade_default_role_exemptions() {
		return array(
			'ucmm-wpbrigade_role_administrator' => 'ucmm-wpbrigade_role_administrator',
		);
	}

	/**
	 * Migrate legacy underscore role keys to hyphenated keys.
	 *
	 * @param array $exemptions Saved ucmm-enable value.
	 * @return array<string, string>
	 */
	function ucmm_wpbrigade_migrate_role_exemption_keys( $exemptions ) {
		if ( ! is_array( $exemptions ) ) {
			return array();
		}

		if ( isset( $exemptions['ucmm_wpbrigade_role_administrator'] ) ) {
			$exemptions['ucmm-wpbrigade_role_administrator'] = 'ucmm-wpbrigade_role_administrator';
			unset( $exemptions['ucmm_wpbrigade_role_administrator'] );
		}

		return $exemptions;
	}
}

if ( ! function_exists( 'ucmm_wpbrigade_get_timezone_label' ) ) {
	/**
	 * Human-readable WordPress site timezone (city or UTC offset).
	 *
	 * @return string
	 */
	function ucmm_wpbrigade_get_timezone_label() {
		if ( function_exists( 'wp_timezone_string' ) ) {
			$tz_string = wp_timezone_string();
			if ( is_string( $tz_string ) && '' !== $tz_string ) {
				return $tz_string;
			}
		}

		$offset = (float) get_option( 'gmt_offset', 0 );
		if ( 0.0 === $offset ) {
			return 'UTC';
		}

		$sign  = $offset >= 0 ? '+' : '-';
		$hours = (int) floor( abs( $offset ) );
		$mins  = (int) round( ( abs( $offset ) - $hours ) * 60 );

		if ( 0 === $mins ) {
			return 'UTC' . $sign . $hours;
		}

		return sprintf( 'UTC%s%d:%02d', $sign, $hours, $mins );
	}
}

if ( ! function_exists( 'ucmm_wpbrigade_schedule_to_timestamp' ) ) {
	/**
	 * Parse a datetime-local schedule value in the WordPress site timezone.
	 *
	 * @param string $datetime_local Value from datetime-local input (e.g. 2026-06-05T15:40).
	 * @return int|false Unix timestamp, or false on failure.
	 */
	function ucmm_wpbrigade_schedule_to_timestamp( $datetime_local ) {
		if ( ! is_string( $datetime_local ) || '' === trim( $datetime_local ) ) {
			return false;
		}

		$datetime_local = trim( $datetime_local );
		$timezone       = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$formats        = array( 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i' );

		foreach ( $formats as $format ) {
			$dt = DateTimeImmutable::createFromFormat( $format, $datetime_local, $timezone );
			if ( false !== $dt ) {
				return $dt->getTimestamp();
			}
		}

		try {
			$dt = new DateTimeImmutable( $datetime_local, $timezone );
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			return false;
		}
	}
}

if ( ! function_exists( 'ucmm_wpbrigade_schedule_utc_offset' ) ) {
	/**
	 * WordPress site timezone offset in seconds (for JS datetime-local parsing).
	 *
	 * @return int
	 */
	function ucmm_wpbrigade_schedule_utc_offset() {
		if ( ! function_exists( 'wp_timezone' ) ) {
			return 0;
		}

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );

		return (int) $timezone->getOffset( $now );
	}
}

if ( ! function_exists( 'ucmm_wpbrigade_get_schedule_countdown_state' ) ) {
	/**
	 * Countdown UI state for maintenance schedule times.
	 *
	 * @param string $start_raw datetime-local start value.
	 * @param string $end_raw   datetime-local end value.
	 * @return string invalid|expired|countdown
	 */
	function ucmm_wpbrigade_get_schedule_countdown_state( $start_raw, $end_raw ) {
		$start_ts = ucmm_wpbrigade_schedule_to_timestamp( $start_raw );
		$end_ts   = ucmm_wpbrigade_schedule_to_timestamp( $end_raw );

		if ( false === $start_ts || false === $end_ts || $end_ts <= $start_ts ) {
			return 'invalid';
		}

		if ( time() > $end_ts ) {
			return 'expired';
		}

		return 'countdown';
	}
}

if ( ! function_exists( 'ucmm_wpbrigade_get_customizer_url' ) ) {
	/**
	 * Admin URL to open the UCMM customizer panel.
	 *
	 * @return string
	 */
	function ucmm_wpbrigade_get_customizer_url() {
		$preview_url = add_query_arg(
			array(
				'watch'     => 'ucmm-customizer',
				'customize' => 'ucmm',
			),
			home_url( '/ucmm-customize.php/' )
		);

		return get_admin_url() . 'customize.php?url=' . rawurlencode( $preview_url ) . '&autofocus=ucmm_wpbrigade_panel';
	}
}

new UCMM_WPBrigade();
