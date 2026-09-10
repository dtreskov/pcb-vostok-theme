<?php
/**
 *
 */
class UCMM_WPBrigade_Entities {

	function __construct() {
		$this->_hooks();
	}

	public function _hooks() {

		add_action( 'customize_register', array( $this, 'customize_ucmm_wpbrigade' ) );
		add_action( 'customize_controls_print_styles', array( __CLASS__, 'ucmm_customizer_description_css' ) );
	}

	/**
	 * Register plugin settings Panel in WP Customizer.
	 *
	 * @param $wp_customize
	 * 
	 * @since   1.0.0
	 */
	public function customize_ucmm_wpbrigade( $wp_customize ) {

		require_once UCMM_WPBRIGADE_DIR_PATH . 'classes/customizer/class-ucmm-social-icons-order-control.php';
		require_once UCMM_WPBRIGADE_DIR_PATH . 'classes/customizer/class-ucmm-social-icons-position-control.php';
		require_once UCMM_WPBRIGADE_DIR_PATH . 'classes/customizer/class-ucmm-group-control.php';

		// =============================
		// = Panel for UCMM WPBrigade  =
		// =============================
		$wp_customize->add_panel(
			'ucmm_wpbrigade_panel',
			array(
				'title'       => __( 'Under Construction', 'ucmm-wpbrigade' ),
				'description' => __( 'Customize Your WordPress Under Construction Page :)', 'ucmm-wpbrigade' ),
				'priority'    => 30,
			)
		);

		// =============================
		// = Section for Logo            =
		// =============================
		$wp_customize->add_section(
			'ucmm_wpbrigade_logo_section',
			array(
				'title'       => __( 'Logo', 'ucmm-wpbrigade' ),
				'description' => __( 'Customize Your Logo', 'ucmm-wpbrigade' ),
				'priority'    => 5,
				'panel'       => 'ucmm_wpbrigade_panel',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_logo]',
			array(
				'default'           => UCMM_WPBRIGADE_DIR_URL . 'img/logo-img.png',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_file' ),
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Image_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_logo]',
				array(
					'label'    => __( 'Logo Image:', 'ucmm-wpbrigade' ),
					'section'  => 'ucmm_wpbrigade_logo_section',
					'priority' => 5,
					'settings' => 'ucmm_wpbrigade_customization[ucmm_logo]',
				)
			)
		);

		$logo_control = array( 'ucmm_logo_width', 'ucmm_logo_height', 'ucmm_logo_padding', 'ucmm_logo_hover', 'ucmm_logo_hover_title' );
		$logo_default = array( '', '', '200px', '', '' );
		$logo_label   = array(
			__( 'Logo Width:', 'ucmm-wpbrigade' ),
			__( 'Logo Height:', 'ucmm-wpbrigade' ),
			__( 'Padding Bottom:', 'ucmm-wpbrigade' ),
			__( 'Logo URL:', 'ucmm-wpbrigade' ),
			__( 'Logo Hover Title:', 'ucmm-wpbrigade' ),
		);
		$logo_placeholder = array(
			__( 'e.g. 320px or 40%', 'ucmm-wpbrigade' ),
			__( 'e.g. 120px or auto', 'ucmm-wpbrigade' ),
		);

		$logo = 0;
		while ( $logo < 2 ) :

			$wp_customize->add_setting(
				"ucmm_wpbrigade_customization[{$logo_control[$logo]}]",
				array(
					'default'           => $logo_default[ $logo ],
					'type'              => 'option',
					'capability'        => 'manage_options',
					'transport'         => 'postMessage',
					'sanitize_callback' => 'sanitize_text_field',

				)
			);

			$wp_customize->add_control(
				$logo_control[ $logo ],
				array(
					'label'       => $logo_label[ $logo ],
					'section'     => 'ucmm_wpbrigade_logo_section',
					'priority'    => 10,
					'settings'    => "ucmm_wpbrigade_customization[{$logo_control[$logo]}]",
					'input_attrs' => array(
						'placeholder' => $logo_placeholder[ $logo ],
					),
				)
			);

			++$logo;
		endwhile;

		// =============================
		// = Section for Background        =
		// =============================
		$wp_customize->add_section(
			'ucmm_wpbrigade_background_section',
			array(
				'title'       => __( 'Background', 'ucmm-wpbrigade' ),
				'description' => '',
				'priority'    => 11,
				'panel'       => 'ucmm_wpbrigade_panel',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_background_color]',
			array(
				'default'           => '',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_background_color' ),
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_background_color]',
				array(
					'label'       => __( 'Background Color:', 'ucmm-wpbrigade' ),
					'description' => __( 'Used when the background image is removed. The background image takes priority when both are set.', 'ucmm-wpbrigade' ),
					'section'     => 'ucmm_wpbrigade_background_section',
					'priority'    => 11,
					'settings'    => 'ucmm_wpbrigade_customization[ucmm_background_color]',
				)
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[setting_background]',
			array(
				'default'           => UCMM_WPBRIGADE_DIR_URL . 'img/coming-soon.png',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_file' ),
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Image_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[setting_background]',
				array(
					'label'    => __( 'Background Image:', 'ucmm-wpbrigade' ),
					'section'  => 'ucmm_wpbrigade_background_section',
					'priority' => 12,
					'settings' => 'ucmm_wpbrigade_customization[setting_background]',
				)
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_vector_image]',
			array(
				'default'           => UCMM_WPBRIGADE_DIR_URL . 'img/coming-soon-vector.png',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_file' ),
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Image_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_vector_image]',
				array(
					'label'    => __( 'Vector Image:', 'ucmm-wpbrigade' ),
					'section'  => 'ucmm_wpbrigade_background_section',
					'priority' => 9,
					'settings' => 'ucmm_wpbrigade_customization[ucmm_vector_image]',
				)
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_background_group]',
			array(
				'default'           => '',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		$wp_customize->add_control(
			new UCMM_WPBrigade_Group_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_background_group]',
				array(
					'label'     => __( 'Background', 'ucmm-wpbrigade' ),
					'info_text' => __( 'Customize the background.', 'ucmm-wpbrigade' ),
					'section'   => 'ucmm_wpbrigade_background_section',
					'priority'  => 10,
					'settings'  => 'ucmm_wpbrigade_customization[ucmm_background_group]',
				)
			)
		);

		// Setting for background Cover
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[background_cover]',
			array(
				'default'           => 'cover',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_select' ),
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[background_cover]',
			array(
				'settings' => 'ucmm_wpbrigade_customization[background_cover]',
				'label'    => __( 'Background Image Size:', 'ucmm-wpbrigade' ),
				'section'  => 'ucmm_wpbrigade_background_section',
				'priority' => 14,
				'type'     => 'select',
				'choices'  => array(
					'auto'    => 'auto',
					'cover'   => 'cover',
					'contain' => 'contain',
					'initial' => 'initial',
					'inherit' => 'inherit',
				),
			)
		);

		// settings for background Repeat
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[background_repeat]',
			array(
				'default'           => 'no-repeat',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_select' ),
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[background_repeat]',
			array(
				'settings' => 'ucmm_wpbrigade_customization[background_repeat]',
				'label'    => __( 'Background Repeat:', 'ucmm-wpbrigade' ),
				'section'  => 'ucmm_wpbrigade_background_section',
				'priority' => 15,
				'type'     => 'select',
				'choices'  => array(
					'repeat'    => 'repeat',
					'repeat-x'  => 'repeat-x',
					'repeat-y'  => 'repeat-y',
					'no-repeat' => 'no-repeat',
					'initial'   => 'initial',
					'inherit'   => 'inherit',
				),
			)
		);

		// Settings for Background Position
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[background_position]',
			array(
				// 'default'        => 'center center',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_select' ),
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[background_position]',
			array(
				'settings' => 'ucmm_wpbrigade_customization[background_position]',
				'label'    => __( 'Background Position:', 'ucmm-wpbrigade' ),
				'section'  => 'ucmm_wpbrigade_background_section',
				'priority' => 16,
				'type'     => 'select',
				'choices'  => array(
					'top left'    => 'top left',
					'top center'  => 'top center',
					'top right'  => 'top right',
					'center left'    => 'center left',
					'center center'  => 'center center',
					'center right'   => 'center right',
					'bottom left'    => 'bottom left',
					'bottom center'  => 'bottom center',
					'bottom right'   => 'bottom right',
				),
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[background_attachment]',
			array(
				'default'           => 'scroll',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_select' ),
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[background_attachment]',
			array(
				'settings' => 'ucmm_wpbrigade_customization[background_attachment]',
				'label'    => __( 'Background Attachment:', 'ucmm-wpbrigade' ),
				'section'  => 'ucmm_wpbrigade_background_section',
				'priority' => 17,
				'type'     => 'select',
				'choices'  => array(
					'scroll' => __( 'Scroll', 'ucmm-wpbrigade' ),
					'fixed'  => __( 'Fixed (stick on scroll)', 'ucmm-wpbrigade' ),
				),
			)
		);

		// =============================
		// = Section for Text          =
		// =============================
		$wp_customize->add_section(
			'ucmm_wpbrigade_text_section',
			array(
				'title'       => __( 'Text Section', 'ucmm-wpbrigade' ),
				'description' => '',
				'priority'    => 15,
				'panel'       => 'ucmm_wpbrigade_panel',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[header_text]',
			array(
				'default'           => __( 'COMING SOON', 'ucmm-wpbrigade' ),
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[header_text]',
			array(
				'label'    => __( 'Header Text', 'ucmm-wpbrigade' ),
				'section'  => 'ucmm_wpbrigade_text_section',
				'priority' => 5,
				'settings' => 'ucmm_wpbrigade_customization[header_text]',
			)
		);

		// = Header Text Color setting =

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_header_text_color]',
			array(
				'default'           => '#1F2557',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_hex_color', // validates 3 or 6 digit HTML hex color code.
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_header_text_color]',
				array(
					'label'    => __( 'Header Text Color:', 'ucmm_wpbrigade' ),
					'section'  => 'ucmm_wpbrigade_text_section',
					'priority' => 10,
					'settings' => 'ucmm_wpbrigade_customization[ucmm_header_text_color]',
				)
			)
		);
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[footer_text]',
			array(
				'default'           => __( "We're not quite ready yet, Something is coming very soon", 'ucmm-wpbrigade' ),
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'wp_kses_post',
			)
		);

		/**
		 * Add the subheading textarea.
		 *
		 * @since 1.0.0
		 * @version 1.4.1
		 */
		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[footer_text]',
			array(
				'label'       => __( 'Subheading Text', 'ucmm-wpbrigade' ),
				'description' => __( 'A new experience. You can use HTML tags here.', 'ucmm-wpbrigade' ),
				'type'        => 'textarea',
				'section'     => 'ucmm_wpbrigade_text_section',
				'priority'    => 10,
				'settings'    => 'ucmm_wpbrigade_customization[footer_text]',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_footer_text_color]',
			array(
				'default'           => '#1F2557',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_hex_color', // validates 3 or 6 digit HTML hex color code.
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_footer_text_color]',
				array(
					'label'    => __( 'Subheading Text Color:', 'ucmm_wpbrigade' ),
					'section'  => 'ucmm_wpbrigade_text_section',
					'priority' => 10,
					'settings' => 'ucmm_wpbrigade_customization[ucmm_footer_text_color]',
				)
			)
		);

		// =============================
		// = Section for Footer Love   =
		// =============================

		$wp_customize->add_section(
			'section_footer_love',
			array(
				'title'    => __( 'Show Some Love', 'ucmm-wpbrigade' ),
				// 'description'  => __( 'Show some love', 'ucmm-wpbrigade' ),
				'priority' => 20,
				'panel'    => 'ucmm_wpbrigade_panel',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_display_footer_text]',
			array(
				'default'           => true,
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_checkbox' ),
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[ucmm_display_footer_text]',
			array(
				'settings' => 'ucmm_wpbrigade_customization[ucmm_display_footer_text]',
				'label'    => __( 'Please help others learn about this free plugin by placing a small link in the footer. Thank you very much!', 'ucmm-wpbrigade' ),
				'section'  => 'section_footer_love',
				'priority' => 5,
				'type'     => 'checkbox',

			)
		);

		/**
		 * Add the Love Position.
		 *
		 * @version 1.5.0
		 */
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_display_footer_text_position]',
			array(
				'default'           => 'right',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_select' ),
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[ucmm_display_footer_text_position]',
			array(
				'settings' => 'ucmm_wpbrigade_customization[ucmm_display_footer_text_position]',
				'label'    => __( 'Position:', 'ucmm-wpbrigade' ),
				'section'  => 'section_footer_love',
				'priority' => 6,
				'type'     => 'select',
				'choices'  => array(
					'right'  => __( 'Right', 'ucmm-wpbrigade' ),
					'left'   => __( 'Left', 'ucmm-wpbrigade' ),
					'center' => __( 'Center', 'ucmm-wpbrigade' ),
				),
			)
		);

		/**
		 * Add the Love text color option.
		 *
		 * @since 1.5.1
		 */
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_love_text_color]',
			array(
				'default'           => '#1F2557',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_hex_color', // validates 3 or 6 digit HTML hex color code.
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_love_text_color]',
				array(
					'label'    => __( 'Text Color:', 'ucmm_wpbrigade' ),
					'section'  => 'section_footer_love',
					'priority' => 10,
					'settings' => 'ucmm_wpbrigade_customization[ucmm_love_text_color]',
				)
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_love_hover_color]',
			array(
				'default'           => '#3BB9FF',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_hex_color',
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_love_hover_color]',
				array(
					'label'       => __( 'Hover Color:', 'ucmm-wpbrigade' ),
					'description' => __( 'Link color when visitors hover over the WPBrigade credit.', 'ucmm-wpbrigade' ),
					'section'     => 'section_footer_love',
					'priority'    => 11,
					'settings'    => 'ucmm_wpbrigade_customization[ucmm_love_hover_color]',
				)
			)
		);

		// =============================
		// = Section for Social Links  =
		// =============================

		$social_control = array( 'ucmm_facebook', 'ucmm_twitter', 'ucmm_linkedin', 'ucmm_youtube', 'ucmm_instagram', 'ucmm_pinterest', 'ucmm_codepen' );
		$social_default = array(
			'ucmm_facebook'  => '',
			'ucmm_twitter'   => '',
			'ucmm_linkedin'  => '',
			'ucmm_youtube'   => '',
			'ucmm_instagram' => '',
			'ucmm_linkedin'  => '',
			'ucmm_codepen'   => '',
			'ucmm_pinterest' => '',
		);
		$social_label   = array(
			'ucmm_facebook'  => __( 'Facebook Link:', 'ucmm-wpbrigade' ),
			'ucmm_twitter'   => __( 'Twitter Link:', 'ucmm-wpbrigade' ),
			'ucmm_linkedin'  => __( 'Linkedin Link:', 'ucmm-wpbrigade' ),
			'ucmm_google'    => __( 'Google Plus Link:', 'ucmm-wpbrigade' ),
			'ucmm_youtube'   => __( 'YouTube Link:', 'ucmm-wpbrigade' ),
			'ucmm_instagram' => __( 'Instagram Link:', 'ucmm-wpbrigade' ),
			'ucmm_pinterest' => __( 'Pinterest Link:', 'ucmm-wpbrigade' ),
			'ucmm_codepen'   => __( 'Codepen Link:', 'ucmm-wpbrigade' ),
		);
		// $social_sanitizations = array( 'ucmm_facebook_sanitization', 'ucmm_twitter_sanitization', 'ucmm_linkedin_sanitization',
		// 'ucmm_youtube_sanitization', 'ucmm_instagram_sanitization', 'ucmm_pinterest_sanitization', 'ucmm_codepen_sanitization')

		$wp_customize->add_section(
			'ucmm_social_icon_section',
			array(
				'title'    => __( 'Add Social Accounts', 'ucmm-wpbrigade' ),
				'priority' => 25,
				'panel'    => 'ucmm_wpbrigade_panel',
			)
		);

		foreach ( $social_control as $key => $social ) :

				$wp_customize->add_setting(
					"ucmm_wpbrigade_customization[$social]",
					array(
						// 'default'                => isset( $social_default[$social] ) ? esc_url_raw( $social_default[$social] ) : '' ,
						'type'              => 'option',
						'capability'        => 'manage_options',
						'transport'         => 'postMessage',
						'sanitize_callback' => 'esc_url_raw',
					)
				);

				$wp_customize->add_control(
					$social,
					array(
						'label'       => $social_label[ $social ],
						'section'     => 'ucmm_social_icon_section',
						'priority'    => 10,
						'settings'    => "ucmm_wpbrigade_customization[{$social}]",
						'input_attrs' => array(
							'placeholder' => __( 'https://www.' . explode( 'ucmm_', $social )[1] . '.com/Link', 'ucmm-wpbrigade' ),
						),
					)
				);

	endforeach;

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_social_icons_style_group]',
			array(
				'default'           => '',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		$wp_customize->add_control(
			new UCMM_WPBrigade_Group_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_social_icons_style_group]',
				array(
					'label'     => __( 'Icon Styling', 'ucmm-wpbrigade' ),
					'info_text' => __( 'Customize the icon style.', 'ucmm-wpbrigade' ),
					'section'   => 'ucmm_social_icon_section',
					'priority'  => 34,
					'settings'  => 'ucmm_wpbrigade_customization[ucmm_social_icons_style_group]',
				)
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_social_icons_style]',
			array(
				'default'           => 'classic',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => function ( $input ) {
					$allowed = array( 'classic', 'new' );
					$input   = is_string( $input ) ? sanitize_key( $input ) : '';
					return in_array( $input, $allowed, true ) ? $input : 'classic';
				},
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization_ucmm_social_icons_style',
			array(
				'label'    => __( 'Icons Style', 'ucmm-wpbrigade' ),
				'section'  => 'ucmm_social_icon_section',
				'settings' => 'ucmm_wpbrigade_customization[ucmm_social_icons_style]',
				'type'     => 'radio',
				'priority' => 35,
				'choices'  => array(
					'classic' => __( 'Classic Icons', 'ucmm-wpbrigade' ),
					'new'     => __( 'New Icons', 'ucmm-wpbrigade' ),
				),
			)
		);

	/**
	 * Add the social icons order setting.
	 *
	 * @since 3.0.0
	 */
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_social_icons_order]',
			array(
				'default'           => implode( ',', self::ucmm_wpbrigade_social_order_default() ),
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_wpbrigade_sanitize_social_order' ),
			)
		);

		$social_order_labels = array();
		foreach ( self::ucmm_wpbrigade_social_order_default() as $soc_key ) {
			if ( isset( $social_label[ $soc_key ] ) ) {
				$social_order_labels[ $soc_key ] = $social_label[ $soc_key ];
			}
		}

		$wp_customize->add_control(
			new UCMM_WPBrigade_Social_Icons_Order_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization_ucmm_social_icons_order',
				array(
					'label'               => __( 'Social Icons Order', 'ucmm-wpbrigade' ),
					'description'         => __( 'Drag networks to change the order they appear on the page.', 'ucmm-wpbrigade' ),
					'section'             => 'ucmm_social_icon_section',
					'settings'            => 'ucmm_wpbrigade_customization[ucmm_social_icons_order]',
					'priority'            => 40,
					'ucmm_order_labels'   => $social_order_labels,
				)
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_social_icons_position]',
			array(
				'default'           => 'bottom',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => function ( $input ) {
					$allowed = array( 'top', 'right', 'bottom', 'left' );
					$input   = is_string( $input ) ? sanitize_key( $input ) : '';
					return in_array( $input, $allowed, true ) ? $input : 'bottom';
				},
			)
		);

		$wp_customize->add_control(
			new UCMM_WPBrigade_Social_Icons_Position_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization_ucmm_social_icons_position',
				array(
					'label'       => __( 'Social Icons Position', 'ucmm-wpbrigade' ),
					'description' => __( 'Place the icon strip at the top, sides, or bottom of the page. On small screens, side positions stack under the main content.', 'ucmm-wpbrigade' ),
					'section'     => 'ucmm_social_icon_section',
					'settings'    => 'ucmm_wpbrigade_customization[ucmm_social_icons_position]',
					'priority'    => 50,
				)
			)
		);

		// =============================
		// = Section for Custom CSS        =
		// =============================
		$wp_customize->add_section(
			'ucmm_section_css',
			array(
				'title'       => __( 'Custom CSS', 'ucmm-wpbrigade' ),
				'description' => '',
				'priority'    => 30,
				'panel'       => 'ucmm_wpbrigade_panel',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_custom_css]',
			array(
				// 'default'           => "/* You can add your custom CSS here. */",
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'wp_strip_all_tags',
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[ucmm_custom_css]',
			array(
				'label'       => __( 'Customize CSS', 'ucmm-wpbrigade' ),
				'type'        => 'textarea',
				'section'     => 'ucmm_section_css',
				'input_attrs' => array(
					'placeholder' => __( 'You can add your custom CSS here.', 'ucmm-wpbrigade' ),
				),
				'priority'    => 5,
				'settings'    => 'ucmm_wpbrigade_customization[ucmm_custom_css]',
			)
		);

		// =============================
		// = Section for SEO Configuration
		// =============================

		$seo_control  = array( 'ucmm_seo_title', 'ucmm_seo_description', 'ucmm_seo_url', 'ucmm_seo_sitename', 'ucmm_seo_admin', 'ucmm_seo_keywords' );
		$seo_defaults = self::ucmm_wpbrigade_seo_defaults();
		$seo_sanitize = array( 'sanitize_text_field', 'sanitize_text_field', 'esc_url_raw', 'sanitize_text_field', 'sanitize_text_field', 'sanitize_text_field' );
		$seo_label    = array(
			__( 'SEO Title:', 'ucmm-wpbrigade' ),
			__( 'SEO Description:', 'ucmm-wpbrigade' ),
			__( 'SEO URL:', 'ucmm-wpbrigade' ),
			__( 'SEO Site Name:', 'ucmm-wpbrigade' ),
			__( 'SEO Author Name:', 'ucmm-wpbrigade' ),
			__( 'SEO Keywords:', 'ucmm-wpbrigade' ),
		);

		$wp_customize->add_section(
			'ucmm_seo_section',
			array(
				'title'       => __( 'SEO Configuration', 'ucmm-wpbrigade' ),
				'description' => '',
				'priority'    => 35,
				'panel'       => 'ucmm_wpbrigade_panel',
			)
		);

		$seo = 0;
		while ( $seo < 6 ) :

			$wp_customize->add_setting(
				"ucmm_wpbrigade_customization[{$seo_control[$seo]}]",
				array(
					'default'           => $seo_defaults[ $seo_control[ $seo ] ],
					'type'              => 'option',
					'capability'        => 'manage_options',
					'transport'         => 'postMessage',
					'sanitize_callback' => $seo_sanitize[ $seo ],
				)
			);

			$wp_customize->add_control(
				$seo_control[ $seo ],
				array(
					'label'    => $seo_label[ $seo ],
					'section'  => 'ucmm_seo_section',
					'settings' => "ucmm_wpbrigade_customization[{$seo_control[$seo]}]",
				)
			);

			++$seo;
		endwhile;

		// =============================
		// = Section for Google Analytics
		// =============================
		$wp_customize->add_section(
			'ucmm_ga_tracking_section',
			array(
				'title'       => __( 'Google Analytics Tracking Code', 'ucmm-wpbrigade' ),
				'description' => '',
				'priority'    => 40,
				'panel'       => 'ucmm_wpbrigade_panel',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_ga_tracking_code]',
			array(
				// 'default'           => "/* Google Analytics Tracking Code here. */",
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_tracking_scripts' ),
			)
		);

		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[ucmm_ga_tracking_code]',
			array(
				'label'       => __( 'Google Analytics Tracking Code', 'ucmm-wpbrigade' ),
				'type'        => 'textarea',
				'section'     => 'ucmm_ga_tracking_section',
				'priority'    => 5,
				'input_attrs' => array(
					'placeholder' => __( 'Paste your Google Analytics tracking code here.', 'ucmm-wpbrigade' ),
				),
				'settings'    => 'ucmm_wpbrigade_customization[ucmm_ga_tracking_code]',
			)
		);

		// ===================================
		// = Section for Start and End time    =
		// ===================================
		$isset_time_zone = get_option( 'timezone_string' );
		$time_warn       = __( '', 'ucmm-wpbrigade' );
		if ( $isset_time_zone == '' ) {
			$time_warn = sprintf(
				/* translators: 1: opening anchor tag, 2: closing anchor tag */
				__( 'Please set your WordPress %1$stime zone%2$s before creating a maintenance schedule.', 'ucmm-wpbrigade' ),
				'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">',
				'</a>'
			);
		}
		$wp_customize->add_section(
			'ucmm_schedule_section',
			array(
				'title'    => __( 'Schedule Maintenance', 'ucmm-wpbrigade' ),
				'priority' => 54,
				'panel'    => 'ucmm_wpbrigade_panel',
			)
		);
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_schedule_show_end_time]',
			array(
				// 'default'          => " default value",
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_sanitize_checkbox' ),

			)
		);
		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[ucmm_schedule_show_end_time]',
			array(
				'label'       => __( 'Show Maintenance Schedule', 'ucmm-wpbrigade' ),
				'description' => $time_warn,
				'type'        => 'checkbox',
				'section'     => 'ucmm_schedule_section',
				'priority'    => 5,
				'settings'    => 'ucmm_wpbrigade_customization[ucmm_schedule_show_end_time]',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_schedule_start]',
			array(
				// 'default'         => " default value",
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[ucmm_schedule_start]',
			array(
				'label'       => __( 'Start Maintenance Mode From', 'ucmm-wpbrigade' ),
				'description' => sprintf(
					/* translators: %s: WordPress site timezone. */
					__( 'WordPress time zone: %s', 'ucmm-wpbrigade' ),
					ucmm_wpbrigade_get_timezone_label()
				),
				'type'        => 'datetime-local',
				'section'     => 'ucmm_schedule_section',
				'priority'    => 5,
				'settings'    => 'ucmm_wpbrigade_customization[ucmm_schedule_start]',
			)
		);

		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_schedule_end]',
			array(
				// 'default'         => " default value",
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( 'UCMM_WPBrigade_Entities', 'ucmm_validate_schedule_end' ),
			)
		);
		$wp_customize->add_control(
			'ucmm_wpbrigade_customization[ucmm_schedule_end]',
			array(
				'label'       => __( 'End Maintenance Mode At', 'ucmm-wpbrigade' ),
				'description' => sprintf(
					/* translators: %s: WordPress site timezone. */
					__( 'WordPress time zone: %s', 'ucmm-wpbrigade' ),
					ucmm_wpbrigade_get_timezone_label()
				),
				'type'        => 'datetime-local',
				'section'     => 'ucmm_schedule_section',
				'priority'    => 6,
				'settings'    => 'ucmm_wpbrigade_customization[ucmm_schedule_end]',
			)
		);
		$wp_customize->add_setting(
			'ucmm_wpbrigade_customization[ucmm_schedule_text_color]',
			array(
				'default'           => '#000000',
				'type'              => 'option',
				'capability'        => 'manage_options',
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_hex_color', // validates 3 or 6 digit HTML hex color code.

			)
		);
		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				'ucmm_wpbrigade_customization[ucmm_schedule_text_color]',
				array(
					'label'       => __( 'Color', 'ucmm-wpbrigade' ),
					'description' => __( 'Color used for the maintenance countdown timer.', 'ucmm-wpbrigade' ),
					'section'     => 'ucmm_schedule_section',
					'priority'    => 7,
					'settings'    => 'ucmm_wpbrigade_customization[ucmm_schedule_text_color]',
				)
			)
		);
	}

	/**
	 * Shared description styling for UCMM customizer controls.
	 *
	 * @return void
	 */
	public static function ucmm_customizer_description_css() {
		?>
		<style>
			.customize-control[id*="ucmm_wpbrigade_customization"] .customize-control-description {
				color: #646970;
				display: block;
				font-size: 13px;
				font-style: normal;
				font-weight: 400;
				line-height: 1.5;
				margin-bottom: 8px;
				margin-top: 4px;
			}

			.customize-control[id*="ucmm_wpbrigade_customization"] .customize-control-description a {
				color: #2271b1;
			}

			.notice-dismiss {
				top: 7px !important;
			}
		</style>
		<?php
	}

	/**
	 * Default SEO field values (Customizer + frontend).
	 *
	 * @return array<string, string>
	 *
	 * @since 3.0.0
	 */
	public static function ucmm_wpbrigade_seo_defaults() {
		return array(
			'ucmm_seo_title'       => '',
			'ucmm_seo_description' => '',
			'ucmm_seo_url'         => get_bloginfo( 'url' ),
			'ucmm_seo_sitename'    => get_bloginfo( 'name' ),
			'ucmm_seo_admin'       => '',
			'ucmm_seo_keywords'    => '',
		);
	}

	/**
	 * Canonical social network option keys in default display order.
	 *
	 * @return string[]
	 *
	 * @since 3.0.0
	 */
	public static function ucmm_wpbrigade_social_order_default() {
		return array(
			'ucmm_facebook',
			'ucmm_twitter',
			'ucmm_linkedin',
			'ucmm_youtube',
			'ucmm_instagram',
			'ucmm_pinterest',
			'ucmm_codepen',
		);
	}

	/**
	 * Sanitize customizer select control values.
	 *
	 * @param mixed              $input   Setting value.
	 * @param WP_Customize_Setting $setting Setting instance.
	 * @return string
	 */
	public static function ucmm_sanitize_select( $input, $setting ) {
		$input = sanitize_key( $input );
		$choices = $setting->manager->get_control( $setting->id )->choices;

		return ( array_key_exists( $input, $choices ) ? $input : $setting->default );
	}

	/**
	 * Sanitize customizer image file URLs.
	 *
	 * @param string             $file    File URL.
	 * @param WP_Customize_Setting $setting Setting instance.
	 * @return string
	 */
	public static function ucmm_sanitize_file( $file, $setting ) {
		if ( null === $file || false === $file || '' === $file ) {
			return '';
		}

		$mimes = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'gif'          => 'image/gif',
			'png'          => 'image/png',
		);

		$file_ext = wp_check_filetype( $file, $mimes );

		return ( $file_ext['ext'] ? $file : $setting->default );
	}

	/**
	 * Sanitize background color; persist empty when cleared.
	 *
	 * @param mixed $color Setting value.
	 * @return string
	 */
	public static function ucmm_sanitize_background_color( $color ) {
		if ( null === $color || false === $color || '' === $color ) {
			return '';
		}

		$sanitized = sanitize_hex_color( $color );

		return $sanitized ? $sanitized : '';
	}

	/**
	 * Sanitize customizer checkbox values.
	 *
	 * @param mixed $input Setting value.
	 * @return bool
	 */
	public static function ucmm_sanitize_checkbox( $input ) {
		return ( isset( $input ) && ( true === $input || 1 === $input || '1' === $input ) );
	}

	/**
	 * Validate schedule end is later than start.
	 *
	 * @param bool|WP_Error        $valid   Current validity.
	 * @param string               $value   End datetime-local value.
	 * @param WP_Customize_Setting $setting Setting instance.
	 * @return bool|WP_Error
	 */
	public static function ucmm_validate_schedule_end( $valid, $value, $setting ) {
		if ( true !== $valid ) {
			return $valid;
		}

		return self::ucmm_validate_schedule_range( $setting->manager, null, $value );
	}

	/**
	 * Ensure maintenance end time is after start time.
	 *
	 * @param WP_Customize_Manager $wp_customize Customizer instance.
	 * @param string|null          $start_value  Optional start override.
	 * @param string|null          $end_value    Optional end override.
	 * @return bool|WP_Error
	 */
	private static function ucmm_validate_schedule_range( $wp_customize, $start_value = null, $end_value = null ) {
		$start_setting = $wp_customize->get_setting( 'ucmm_wpbrigade_customization[ucmm_schedule_start]' );
		$end_setting   = $wp_customize->get_setting( 'ucmm_wpbrigade_customization[ucmm_schedule_end]' );

		$start_raw = null !== $start_value ? $start_value : ( $start_setting ? $start_setting->value() : '' );
		$end_raw   = null !== $end_value ? $end_value : ( $end_setting ? $end_setting->value() : '' );

		if ( ! is_string( $start_raw ) || '' === trim( $start_raw ) || ! is_string( $end_raw ) || '' === trim( $end_raw ) ) {
			return true;
		}

		$start_ts = ucmm_wpbrigade_schedule_to_timestamp( $start_raw );
		$end_ts   = ucmm_wpbrigade_schedule_to_timestamp( $end_raw );

		if ( false === $start_ts || false === $end_ts ) {
			return true;
		}

		if ( $end_ts <= $start_ts ) {
			return new WP_Error(
				'ucmm_invalid_schedule_range',
				__( 'End time must be later than the start time.', 'ucmm-wpbrigade' )
			);
		}

		return true;
	}

	/**
	 * Sanitize comma-separated social icon order (exact permutation of known keys).
	 *
	 * @param mixed $value Raw value.
	 * @return string CSV of keys or default CSV.
	 * 
	 * @since 3.0.0
	 */
	public static function ucmm_wpbrigade_sanitize_social_order( $value ) {
		$default = self::ucmm_wpbrigade_social_order_default();
		$allowed = array_flip( $default );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return implode( ',', $default );
		}

		$parts = array_map( 'trim', explode( ',', $value ) );
		$seen  = array();
		$out   = array();

		foreach ( $parts as $part ) {
			$key = sanitize_key( $part );
			if ( '' === $key || ! isset( $allowed[ $key ] ) || isset( $seen[ $key ] ) ) {
				return implode( ',', $default );
			}
			$seen[ $key ] = true;
			$out[]        = $key;
		}

		if ( count( $out ) !== count( $default ) ) {
			return implode( ',', $default );
		}

		return implode( ',', $out );
	}

	/**
	 * Parse stored order into an array of keys (default if invalid).
	 *
	 * @param mixed $raw Raw option fragment.
	 * @return string[]
	 * 
	 * @since 3.0.0
	 */
	public static function ucmm_wpbrigade_parse_social_order( $raw ) {
		$csv = self::ucmm_wpbrigade_sanitize_social_order( is_string( $raw ) ? $raw : '' );
		return explode( ',', $csv );
	}

	/**
	 * Sanitize JavaScript tracking snippets for save and output.
	 *
	 * Accepts inline JS, external script tags, GTM/GA snippets, etc.
	 * Strips dangerous HTML/JS patterns while preserving valid tracking code.
	 *
	 * @param mixed $input Raw tracking code.
	 * @return string Sanitized code or empty string.
	 *
	 * @since 3.0.0
	 */
	public static function ucmm_sanitize_tracking_scripts( $input ) {
		if ( ! is_string( $input ) ) {
			return '';
		}

		$code = trim( wp_unslash( $input ) );

		if ( '' === $code ) {
			return '';
		}

		$code = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $code );
		$code = self::ucmm_strip_dangerous_tracking_html( $code );
		$code = self::ucmm_sanitize_tracking_attributes( $code );

		$code = preg_replace_callback(
			'#(<script\b[^>]*>)([\s\S]*?)(</script>)#i',
			function ( $matches ) {
				return $matches[1] . self::ucmm_sanitize_inline_js( $matches[2] ) . $matches[3];
			},
			$code
		);

		if ( ! preg_match( '#<script\b#i', $code ) ) {
			$code = '<script>' . "\n" . self::ucmm_sanitize_inline_js( $code ) . "\n" . '</script>';
		}

		return trim( $code );
	}

	/**
	 * Remove HTML tags that are not used for JS tracking snippets.
	 *
	 * @param string $code Raw input.
	 * @return string
	 */
	private static function ucmm_strip_dangerous_tracking_html( $code ) {
		$blocked = array(
			'object',
			'embed',
			'applet',
			'form',
			'input',
			'button',
			'select',
			'textarea',
			'style',
			'base',
			'frame',
			'frameset',
			'body',
			'html',
			'head',
			'title',
			'meta',
			'link',
			'img',
			'svg',
			'video',
			'audio',
			'canvas',
		);

		$pattern = '#</?\s*(?:' . implode( '|', $blocked ) . ')\b[^>]*>#i';

		return preg_replace( $pattern, '', $code );
	}

	/**
	 * Sanitize attributes on any remaining HTML (noscript, iframe, script tags).
	 *
	 * @param string $code Markup and JS.
	 * @return string
	 */
	private static function ucmm_sanitize_tracking_attributes( $code ) {
		$code = preg_replace( '/<\?(?:php)?/i', '', $code );
		$code = preg_replace( '/\s+on\w+\s*=\s*("|\')(?:\\\\.|[^"\'])*\1/i', '', $code );
		$code = preg_replace( '/\s+on\w+\s*=\s*[^\s>]+/i', '', $code );
		$code = preg_replace(
			'#(\b(?:src|href)\s*=\s*)(["\']?)\s*(?:javascript|data|vbscript):[^"\'\s>]*#i',
			'$1#',
			$code
		);

		return $code;
	}

	/**
	 * Sanitize inline JavaScript body.
	 *
	 * @param string $js Inline JS.
	 * @return string
	 */
	private static function ucmm_sanitize_inline_js( $js ) {
		$js = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $js );
		$js = preg_replace( '/<\?(?:php)?/i', '', $js );
		$js = preg_replace( '#javascript\s*:#i', '', $js );
		$js = preg_replace( '#vbscript\s*:#i', '', $js );

		return $js;
	}
}
