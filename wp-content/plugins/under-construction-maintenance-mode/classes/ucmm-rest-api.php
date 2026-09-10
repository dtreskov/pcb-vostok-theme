<?php
/**
 * UCMM REST API.
 *
 * @since 3.0.0
 */

if ( !class_exists( 'UCMM_REST_API' ) ) :

class UCMM_REST_API {

    /**
     * The namespace for our REST API.
     *
     * @var string
     * @since 3.0.0
     */
    private $namespace = 'ucmm/v1';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'ucmm_register_routes' ) );
    }

    /**
     * Register REST API routes.
     * 
     * @since 3.0.0
     */
    public function ucmm_register_routes() {
        // Settings endpoint
        register_rest_route( $this->namespace, '/settings', array(
            'methods' => 'GET',
            'callback' => array( $this, 'ucmm_get_settings' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/settings', array(
            'methods' => 'POST',
            'callback' => array( $this, 'ucmm_update_settings' ),
            'permission_callback' => array( $this, 'check_permissions' ),
            'args' => array(
                'settings' => array(
                    'required' => true,
                    'type' => 'object',
                    'sanitize_callback' => array( $this, 'sanitize_settings' ),
                ),
            ),
        ) );

        // User roles endpoint
        register_rest_route( $this->namespace, '/user-roles', array(
            'methods' => 'GET',
            'callback' => array( $this, 'ucmm_get_user_roles' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        // Pages and posts endpoint
        register_rest_route( $this->namespace, '/pages-posts', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_pages_and_posts' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );
    }

    /**
     * Check if the current user has permission to access the API.
     *
     * @return bool
     * 
     * @since 3.0.0
     */
    public function check_permissions() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Build a successful REST response.
     *
     * @param string $message Success message.
     * @param mixed  $data    Response payload.
     * @return WP_REST_Response
     */
    private function ucmm_success_response( $message, $data ) {
        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => $message,
                'data'    => $data,
            ),
            200
        );
    }

    /**
     * Get UCMM settings.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * 
     * @since 3.0.0
     */
    public function ucmm_get_settings( $request ) {
        try {
            $raw_settings             = get_option( 'ucmm_wpbrigade_setting', array() );
            $settings                 = $raw_settings;
            $ucmm_customizer_settings = get_option( 'ucmm_wpbrigade_customization', array() );
            
            // Check if customizer scheduling is enabled (same logic as PHP)
            $ucmm_customizer_enable = isset( $ucmm_customizer_settings['ucmm_schedule_show_end_time'] ) ? $ucmm_customizer_settings['ucmm_schedule_show_end_time'] : false;
            
            // Ensure we have default values
            $default_settings = array(
                'ucmm-status' => false,
                'ucmm-enable-on' => 'whole-site',
                'ucmm-exclude-pages' => false,
                'ucmm-excluded-pages' => array(),
                'ucmm-specific-pages' => array(),
                'ucmm-enable' => array(),
                'ucmm-uninstall' => false
            );

            $settings = wp_parse_args( $settings, $default_settings );
            
            // Add customizer schedule status to settings
            $settings['ucmm-schedule-enabled'] = $ucmm_customizer_enable;

            // Convert 'on'/'off' to boolean for checkboxes
            $settings['ucmm-status'] = ( isset( $settings['ucmm-status'] ) && $settings['ucmm-status'] === 'on' );
            $settings['ucmm-exclude-pages'] = ( isset( $settings['ucmm-exclude-pages'] ) && $settings['ucmm-exclude-pages'] === 'on' );
            $settings['ucmm-uninstall'] = ( isset( $settings['ucmm-uninstall'] ) && $settings['ucmm-uninstall'] === 'on' );
            
            // Ensure arrays are properly set
            if ( ! is_array( $settings['ucmm-enable'] ) ) {
                $settings['ucmm-enable'] = array();
            }

            if ( ! is_array( $raw_settings ) || ! array_key_exists( 'ucmm-enable', $raw_settings ) ) {
                $settings['ucmm-enable'] = ucmm_wpbrigade_default_role_exemptions();
            } else {
                $settings['ucmm-enable'] = ucmm_wpbrigade_migrate_role_exemption_keys( $settings['ucmm-enable'] );
            }
            if ( !is_array( $settings['ucmm-excluded-pages'] ) ) {
                $settings['ucmm-excluded-pages'] = array();
            }
            if ( !is_array( $settings['ucmm-specific-pages'] ) ) {
                $settings['ucmm-specific-pages'] = array();
            }

            return new WP_REST_Response( array(
                'success' => true,
                'data' => $settings
            ), 200 );

        } catch ( Exception $e ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $e->getMessage()
            ), 500 );
        }
    }

    /**
     * Update UCMM settings.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * 
     * @since 3.0.0
     */
    public function ucmm_update_settings( $request ) {
        try {
            $new_settings = $request->get_param( 'settings' );
            
            if ( empty( $new_settings ) || !is_array( $new_settings ) ) {
                throw new Exception( __( 'Invalid settings data provided.', 'ucmm-wpbrigade' ) );
            }

            // Convert boolean values back to 'on'/'off' for WordPress option storage
            $settings_to_save = array();
            
            // Handle ucmm-status
            $settings_to_save['ucmm-status'] = ( isset( $new_settings['ucmm-status'] ) && $new_settings['ucmm-status'] ) ? 'on' : 'off';
            
            // Handle ucmm-enable-on
            $enable_on = sanitize_key( $new_settings['ucmm-enable-on'] ?? 'whole-site' );
            $settings_to_save['ucmm-enable-on'] = in_array( $enable_on, array( 'whole-site', 'specific-page' ), true )
                ? $enable_on
                : 'whole-site';
            
            // Handle ucmm-exclude-pages
            $settings_to_save['ucmm-exclude-pages'] = ( isset( $new_settings['ucmm-exclude-pages'] ) && $new_settings['ucmm-exclude-pages'] ) ? 'on' : 'off';
            
            // Handle ucmm-excluded-pages
            $settings_to_save['ucmm-excluded-pages'] = array();
            if ( isset( $new_settings['ucmm-excluded-pages'] ) && is_array( $new_settings['ucmm-excluded-pages'] ) ) {
                $settings_to_save['ucmm-excluded-pages'] = array_map( 'intval', $new_settings['ucmm-excluded-pages'] );
            }
            
            // Handle ucmm-specific-pages
            $settings_to_save['ucmm-specific-pages'] = array();
            if ( isset( $new_settings['ucmm-specific-pages'] ) && is_array( $new_settings['ucmm-specific-pages'] ) ) {
                $settings_to_save['ucmm-specific-pages'] = array_map( 'intval', $new_settings['ucmm-specific-pages'] );
            }
            
            // Handle ucmm-uninstall
            $settings_to_save['ucmm-uninstall'] = ( isset( $new_settings['ucmm-uninstall'] ) && $new_settings['ucmm-uninstall'] ) ? 'on' : 'off';
            
            // Handle ucmm-enable (multi-checkbox)
            $settings_to_save['ucmm-enable'] = array();
            if ( isset( $new_settings['ucmm-enable'] ) && is_array( $new_settings['ucmm-enable'] ) ) {
                $settings_to_save['ucmm-enable'] = $new_settings['ucmm-enable'];
            }

            // Save the settings
            $updated = update_option( 'ucmm_wpbrigade_setting', $settings_to_save );

            $message = $updated
                ? __( 'Settings updated successfully.', 'ucmm-wpbrigade' )
                : __( 'Settings saved.', 'ucmm-wpbrigade' );

            return $this->ucmm_success_response( $message, $settings_to_save );

        } catch ( Exception $e ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => sprintf( __( 'Error saving settings: %s', 'ucmm-wpbrigade' ), $e->getMessage() )
            ), 500 );
        }
    }

    /**
     * Get WordPress user roles.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * 
     * @since 3.0.0
     */
    public function ucmm_get_user_roles( $request ) {
        try {
            global $wp_roles;
            
            if ( ! isset( $wp_roles ) ) {
                $wp_roles = new WP_Roles();
            }

            $roles = array();
            foreach ( $wp_roles->roles as $role => $details ) {
                $roles[ $role ] = $details['name'];
            }

            return new WP_REST_Response( array(
                'success' => true,
                'data' => $roles
            ), 200 );

        } catch ( Exception $e ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $e->getMessage()
            ), 500 );
        }
    }

    /**
     * Sanitize settings data.
     *
     * @param array $settings
     * @return array
     * 
     * @since 3.0.0
     */
    public function sanitize_settings( $settings ) {
        if ( !is_array( $settings ) ) {
            return array();
        }

        $sanitized = array();

        // Sanitize ucmm-status
        if ( isset( $settings['ucmm-status'] ) ) {
            $sanitized['ucmm-status'] = (bool) $settings['ucmm-status'];
        }

        // Sanitize ucmm-enable-on
        if ( isset( $settings['ucmm-enable-on'] ) ) {
            $enable_on = sanitize_key( $settings['ucmm-enable-on'] );
            $sanitized['ucmm-enable-on'] = in_array( $enable_on, array( 'whole-site', 'specific-page' ), true )
                ? $enable_on
                : 'whole-site';
        }

        // Sanitize ucmm-exclude-pages
        if ( isset( $settings['ucmm-exclude-pages'] ) ) {
            $sanitized['ucmm-exclude-pages'] = (bool) $settings['ucmm-exclude-pages'];
        }

        // Sanitize ucmm-excluded-pages
        if ( isset( $settings['ucmm-excluded-pages'] ) && is_array( $settings['ucmm-excluded-pages'] ) ) {
            $sanitized['ucmm-excluded-pages'] = array_map( 'intval', $settings['ucmm-excluded-pages'] );
        }

        // Sanitize ucmm-specific-pages
        if ( isset( $settings['ucmm-specific-pages'] ) && is_array( $settings['ucmm-specific-pages'] ) ) {
            $sanitized['ucmm-specific-pages'] = array_map( 'intval', $settings['ucmm-specific-pages'] );
        }

        // Sanitize ucmm-uninstall
        if ( isset( $settings['ucmm-uninstall'] ) ) {
            $sanitized['ucmm-uninstall'] = (bool) $settings['ucmm-uninstall'];
        }

        // Sanitize ucmm-enable
        if ( isset( $settings['ucmm-enable'] ) && is_array( $settings['ucmm-enable'] ) ) {
            $sanitized['ucmm-enable'] = array();
            foreach ( $settings['ucmm-enable'] as $key => $value ) {
                $sanitized_key = sanitize_key( $key );
                $sanitized_value = sanitize_text_field( $value );
                if ( !empty( $sanitized_key ) && !empty( $sanitized_value ) ) {
                    $sanitized['ucmm-enable'][ $sanitized_key ] = $sanitized_value;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Get pages and posts for multi-select.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     * 
     * @since 3.0.0
     */
    public function get_pages_and_posts( $request ) {
        try {
            $pages_and_posts = array();
            
            // Get all published pages
            $pages = get_posts( array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            ) );
            
            // Get all published posts
            $posts = get_posts( array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            ) );
            
            // Format pages
            foreach ( $pages as $page ) {
                $pages_and_posts[] = array(
                    'id' => $page->ID,
                    'title' => $page->post_title,
                    'type' => 'page',
                    'url' => get_permalink( $page->ID )
                );
            }
            
            // Format posts
            foreach ( $posts as $post ) {
                $pages_and_posts[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => 'post',
                    'url' => get_permalink( $post->ID )
                );
            }

            return new WP_REST_Response( array(
                'success' => true,
                'data' => $pages_and_posts
            ), 200 );

        } catch ( Exception $e ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $e->getMessage()
            ), 500 );
        }
    }
}

endif;

// Initialize the REST API
new UCMM_REST_API();
