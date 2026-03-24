<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Download_Attachments_Settings class.
 *
 * @class Download_Attachments_Settings
 */
class Download_Attachments_Settings {

	private $settings_api;
	private $attachment_links;
	private $download_box_displays;
	private $contents;
	private $download_methods;
	private $redirect_targets;
	private $libraries;
	private $choices;
	private $captured_settings_errors = [];
	private $settings_errors_snapshot = [];
	public $post_types;

	/**
	 * Constructor class.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->settings_api = new Download_Attachments_Settings_API(
			[
				'prefix' => 'da',
				'domain' => 'download-attachments',
				'slug' => 'download-attachments',
				'plugin' => 'Download Attachments',
				'plugin_url' => DOWNLOAD_ATTACHMENTS_URL,
				'object' => $this,
			]
		);

		add_action( 'init', [ $this, 'load_defaults' ], 20 );
		add_action( 'admin_menu', [ $this, 'bootstrap_settings_api' ], 10 );
		add_action( 'admin_init', [ $this, 'bootstrap_settings_api' ], 10 );
		add_action( 'all_admin_notices', [ $this, 'capture_settings_errors_for_custom_area' ], 0 );
		add_action( 'da_before_render_settings_errors', [ $this, 'restore_captured_settings_errors' ], 10, 3 );
		add_action( 'da_after_render_settings_errors', [ $this, 'cleanup_restored_settings_errors' ], 10, 3 );
		add_action( 'wp_loaded', [ $this, 'load_post_types' ] );
		add_filter( 'wp_redirect', [ $this, 'preserve_tab_on_redirect' ], 10, 2 );
	}

	/**
	 * Load defaults.
	 *
	 * @return void
	 */
	public function load_defaults() {
		$this->choices = [
			'yes' => __( 'Enable', 'download-attachments' ),
			'no' => __( 'Disable', 'download-attachments' ),
		];

		$this->libraries = [
			'all' => __( 'All files', 'download-attachments' ),
			'post' => __( 'Attached to a post only', 'download-attachments' ),
		];

		$this->attachment_links = [
			'media_library' => __( 'Media Library', 'download-attachments' ),
			'modal' => __( 'Modal', 'download-attachments' ),
		];

		$this->download_box_displays = [
			'before_content' => __( 'Before the content', 'download-attachments' ),
			'after_content' => __( 'After the content', 'download-attachments' ),
			'manually' => __( 'Show manually', 'download-attachments' ),
		];

		$this->download_methods = [
			'force' => __( 'Force download', 'download-attachments' ),
			'redirect' => __( 'Redirect to file', 'download-attachments' ),
		];

		$this->contents = [
			'caption' => __( 'Caption', 'download-attachments' ),
			'description' => __( 'Description', 'download-attachments' ),
		];

		$this->redirect_targets = [
			'_blank' => __( 'Open in new tab', 'download-attachments' ),
			'_self' => __( 'Same tab', 'download-attachments' ),
		];
	}

	/**
	 * Get the current non-admin roles that have the plugin capability.
	 *
	 * @return array
	 */
	private function get_effective_user_roles() {
		global $wp_roles;

		$roles = [];
		$capability = Download_Attachments()->get_capability();

		if ( empty( $wp_roles ) || empty( $wp_roles->roles ) ) {
			return $roles;
		}

		foreach ( $wp_roles->roles as $role_name => $role_data ) {
			$role = $wp_roles->get_role( $role_name );

			if ( ! $role || $role->has_cap( 'manage_options' ) ) {
				continue;
			}

			if ( $role && $role->has_cap( $capability ) ) {
				$roles[] = $role_name;
			}
		}

		return $roles;
	}

	/**
	 * Sync role capabilities to match the selected or default state.
	 *
	 * @param array $selected_roles
	 * @param bool  $reset_to_defaults
	 * @return array
	 */
	private function sync_user_roles_capabilities( $selected_roles = [], $reset_to_defaults = false ) {
		global $wp_roles;

		$capability = Download_Attachments()->get_capability();
		$normalized_roles = [];

		foreach ( $wp_roles->roles as $role_name => $role_data ) {
			$role = $wp_roles->get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			if ( $role->has_cap( 'manage_options' ) ) {
				$role->add_cap( $capability );
				continue;
			}

			if ( $reset_to_defaults ) {
				if ( $role->has_cap( 'upload_files' ) ) {
					$role->add_cap( $capability );
					$normalized_roles[] = $role_name;
				} else {
					$role->remove_cap( $capability );
				}

				continue;
			}

			if ( in_array( $role_name, $selected_roles, true ) ) {
				$role->add_cap( $capability );
				$normalized_roles[] = $role_name;
			} else {
				$role->remove_cap( $capability );
			}
		}

		return $normalized_roles;
	}

	/**
	 * Normalize the stored user role list to the current capability state.
	 *
	 * @return void
	 */
	private function normalize_user_roles_option() {
		global $wp_roles;

		if ( empty( $wp_roles ) || empty( $wp_roles->roles ) ) {
			return;
		}

		$options = Download_Attachments()->options;
		$normalized_roles = $this->get_effective_user_roles();

		if ( isset( $options['user_roles'] ) && is_array( $options['user_roles'] ) && $options['user_roles'] === $normalized_roles ) {
			return;
		}

		$options['user_roles'] = $normalized_roles;
		Download_Attachments()->options = $options;
		update_option( 'download_attachments_general', $options );
	}

	/**
	 * Bootstrap the internal settings API.
	 *
	 * @return void
	 */
	public function bootstrap_settings_api() {
		if ( empty( $this->settings_api ) ) {
			return;
		}

		$this->settings_api->set_pages( $this->get_settings_pages() );
		$this->normalize_user_roles_option();
	}

	/**
	 * Capture settings errors before WordPress renders them above the page header.
	 *
	 * @return void
	 */
	public function capture_settings_errors_for_custom_area() {
		global $wp_settings_errors;

		if ( ! $this->is_settings_screen() ) {
			return;
		}

		$settings_errors = get_settings_errors();

		if ( empty( $settings_errors ) ) {
			return;
		}

		$this->captured_settings_errors = $settings_errors;
		$wp_settings_errors = [];
	}

	/**
	 * Restore captured settings errors before rendering the plugin notice area.
	 *
	 * @param string $tab_key
	 * @param string $setting
	 * @param array  $page
	 * @return void
	 */
	public function restore_captured_settings_errors( $tab_key, $setting, $page ) {
		global $wp_settings_errors;

		if ( ! $this->is_settings_screen() || empty( $this->captured_settings_errors ) ) {
			return;
		}

		$this->settings_errors_snapshot = (array) $wp_settings_errors;
		$wp_settings_errors = array_merge( (array) $wp_settings_errors, $this->captured_settings_errors );
	}

	/**
	 * Restore the original settings errors state after the custom notice area renders.
	 *
	 * @param string $tab_key
	 * @param string $setting
	 * @param array  $page
	 * @return void
	 */
	public function cleanup_restored_settings_errors( $tab_key, $setting, $page ) {
		global $wp_settings_errors;

		if ( ! $this->is_settings_screen() || empty( $this->captured_settings_errors ) ) {
			return;
		}

		$wp_settings_errors = $this->settings_errors_snapshot;
		$this->settings_errors_snapshot = [];
		$this->captured_settings_errors = [];
	}

	/**
	 * Check whether the current request is for the plugin settings screen.
	 *
	 * @return bool
	 */
	private function is_settings_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();

			if ( $screen && $screen->id === 'settings_page_download-attachments' ) {
				return true;
			}
		}

		global $pagenow;

		return $pagenow === 'options-general.php'
			&& isset( $_GET['page'] )
			&& sanitize_key( wp_unslash( $_GET['page'] ) ) === 'download-attachments';
	}

	/**
	 * Load post types.
	 *
	 * @return void
	 */
	public function load_post_types() {
		$this->post_types = apply_filters( 'da_post_types', array_merge( [ 'post', 'page' ], get_post_types( [ '_builtin' => false, 'public' => true ], 'names' ) ) );
		sort( $this->post_types, SORT_STRING );
	}

	/**
	 * Build settings page schema.
	 *
	 * @return array
	 */
	private function get_settings_pages() {
		return [
			'download-attachments' => [
				'type' => 'settings_page',
				'menu_slug' => 'download-attachments',
				'page_title' => __( 'Download Attachments', 'download-attachments' ),
				'menu_title' => __( 'Attachments', 'download-attachments' ),
				'capability' => 'manage_options',
				'validate' => [ $this, 'validate_general' ],
				'tabs' => $this->get_settings_tabs(),
				'form_callback' => [ $this, 'render_settings_form' ],
				'sidebar_callback' => [ $this, 'render_settings_sidebar' ],
			],
		];
	}

	/**
	 * Build settings tabs.
	 *
	 * @return array
	 */
	private function get_settings_tabs() {
		return [
			'general' => [
				'label' => __( 'General', 'download-attachments' ),
				'key' => 'download_attachments_general',
				'option_name' => 'download_attachments_general',
				'submit' => 'save_da_general',
				'reset' => 'reset_da_general',
				'heading' => __( 'Download Attachments', 'download-attachments' ),
				'sections' => [
					'download_attachments_general' => [
						'title' => __( 'General Settings', 'download-attachments' ),
						'fields' => $this->get_general_fields(),
					],
				],
			],
			'display' => [
				'label' => __( 'Display', 'download-attachments' ),
				'key' => 'download_attachments_display',
				'option_name' => 'download_attachments_general',
				'submit' => 'save_da_display',
				'reset' => 'reset_da_display',
				'heading' => __( 'Download Attachments', 'download-attachments' ),
				'sections' => [
					'download_attachments_display' => [
						'title' => __( 'Display Settings', 'download-attachments' ),
						'fields' => $this->get_display_fields(),
					],
				],
			],
			'admin' => [
				'label' => __( 'Admin', 'download-attachments' ),
				'key' => 'download_attachments_admin',
				'option_name' => 'download_attachments_general',
				'submit' => 'save_da_admin',
				'reset' => 'reset_da_admin',
				'heading' => __( 'Download Attachments', 'download-attachments' ),
				'sections' => [
					'download_attachments_admin' => [
						'title' => __( 'Admin Settings', 'download-attachments' ),
						'fields' => $this->get_admin_fields(),
					],
				],
			],
		];
	}

	/**
	 * Build general tab fields.
	 *
	 * @return array
	 */
	private function get_general_fields() {
		$option = Download_Attachments()->options;
		$user_roles = $this->get_effective_user_roles();
		$download_method = isset( $option['download_method'] ) ? $option['download_method'] : Download_Attachments()->defaults['general']['download_method'];
		$pretty_urls = ! empty( $option['pretty_urls'] );
		$download_link = isset( $option['download_link'] ) ? $option['download_link'] : Download_Attachments()->defaults['general']['download_link'];

		return [
			'label' => [
				'title' => __( 'Label', 'download-attachments' ),
				'type' => 'text',
				'class' => 'regular-text',
				'value' => isset( $option['label'] ) ? $option['label'] : Download_Attachments()->defaults['general']['label'],
				'description' => __( 'Enter the label for the attachments list.', 'download-attachments' ),
			],
			'user_roles' => [
				'title' => __( 'User Roles', 'download-attachments' ),
				'type' => 'checkbox',
				'options' => $this->get_user_role_options(),
				'value' => $user_roles,
				'description' => __( 'Select user roles allowed to add, remove and manage attachments.', 'download-attachments' ),
			],
			'post_types' => [
				'title' => __( 'Supported Post Types', 'download-attachments' ),
				'type' => 'checkbox',
				'options' => $this->get_post_type_options(),
				'value' => $this->get_selected_keys_from_map( isset( $option['post_types'] ) && is_array( $option['post_types'] ) ? $option['post_types'] : Download_Attachments()->defaults['general']['post_types'] ),
				'description' => __( 'Select which post types you want to enable downloads for.', 'download-attachments' ),
			],
			'download_method' => [
				'title' => __( 'Download Method', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->download_methods,
				'value' => $download_method,
				'description' => __( 'Select download method.', 'download-attachments' ),
			],
			'link_target' => [
				'title' => __( 'Redirect Target', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->redirect_targets,
				'value' => isset( $option['link_target'] ) ? $option['link_target'] : Download_Attachments()->defaults['general']['link_target'],
				'condition' => [
					'field' => 'download_method',
					'operator' => 'is',
					'value' => 'redirect',
				],
				'animation' => 'slide',
				'description' => __( 'Select the target for redirect-to-file links.', 'download-attachments' ),
			],
			'pretty_urls' => [
				'title' => __( 'Pretty URLs', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->choices,
				'value' => $pretty_urls ? 'yes' : 'no',
				'description' => __( 'Enable if you want to use pretty URLs.', 'download-attachments' ),
			],
			'download_link' => [
				'title' => __( 'Slug', 'download-attachments' ),
				'type' => 'text',
				'class' => 'regular-text',
				'condition' => [
					'field' => 'pretty_urls',
					'operator' => 'is',
					'value' => 'yes',
				],
				'animation' => 'slide',
				'input_attributes' => [
					'data-da-download-link-input' => 'true',
				],
				'after_field' => $this->get_download_link_preview_markup( $download_link ),
				'description' => __( 'Download link slug.', 'download-attachments' ),
			],
			'encrypt_urls' => [
				'title' => __( 'Encrypt URLs', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->choices,
				'value' => ! empty( $option['encrypt_urls'] ) ? 'yes' : 'no',
				'description' => __( 'Enable if you want to encrypt attachment IDs in generated URLs.', 'download-attachments' ),
			],
			'reset_downloads' => [
				'title' => __( 'Reset Count', 'download-attachments' ),
				'type' => 'custom',
				'callback' => [ $this, 'render_reset_downloads_field' ],
			],
			'deactivation_delete' => [
				'title' => __( 'Deactivation', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->choices,
				'value' => ! empty( $option['deactivation_delete'] ) ? 'yes' : 'no',
				'description' => __( 'Enable if you want all plugin data to be deleted on deactivation.', 'download-attachments' ),
			],
		];
	}

	/**
	 * Build display tab fields.
	 *
	 * @return array
	 */
	private function get_display_fields() {
		$option = Download_Attachments()->options;
		$frontend_columns = isset( $option['frontend_columns'] ) && is_array( $option['frontend_columns'] ) ? $option['frontend_columns'] : Download_Attachments()->defaults['general']['frontend_columns'];
		$frontend_content = isset( $option['frontend_content'] ) && is_array( $option['frontend_content'] ) ? $option['frontend_content'] : Download_Attachments()->defaults['general']['frontend_content'];

		return [
			'frontend_columns' => [
				'title' => __( 'Fields Display', 'download-attachments' ),
				'type' => 'checkbox',
				'options' => $this->get_frontend_column_options(),
				'value' => $this->get_selected_keys_from_map( $frontend_columns ),
				'description' => __( 'Select which columns would you like to enable on frontend for your downloads.', 'download-attachments' ),
			],
			'display_style' => [
				'title' => __( 'Display Style', 'download-attachments' ),
				'type' => 'radio',
				'options' => Download_Attachments()->display_styles,
				'value' => isset( $option['display_style'] ) ? $option['display_style'] : Download_Attachments()->defaults['general']['display_style'],
				'description' => __( 'Select display style for file attachments.', 'download-attachments' ),
			],
			'frontend_content' => [
				'title' => __( 'Downloads Description', 'download-attachments' ),
				'type' => 'checkbox',
				'options' => $this->contents,
				'value' => $this->get_selected_keys_from_map( $frontend_content ),
				'description' => __( 'Select what fields to use on frontend for download attachments description.', 'download-attachments' ),
			],
			'use_css_style' => [
				'title' => __( 'Use CSS Style', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->choices,
				'value' => ! empty( $option['use_css_style'] ) ? 'yes' : 'no',
				'description' => __( 'Select whether you\'d like to use the built-in CSS style.', 'download-attachments' ),
			],
			'download_box_display' => [
				'title' => __( 'Display Position', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->download_box_displays,
				'value' => isset( $option['download_box_display'] ) ? $option['download_box_display'] : Download_Attachments()->defaults['general']['download_box_display'],
				'description' => __( 'Select where you would like your download attachments to be displayed.', 'download-attachments' ),
			],
		];
	}

	/**
	 * Build admin tab fields.
	 *
	 * @return array
	 */
	private function get_admin_fields() {
		$option = Download_Attachments()->options;
		$backend_columns = isset( $option['backend_columns'] ) && is_array( $option['backend_columns'] ) ? $option['backend_columns'] : Download_Attachments()->defaults['general']['backend_columns'];
		$backend_content = isset( $option['backend_content'] ) && is_array( $option['backend_content'] ) ? $option['backend_content'] : Download_Attachments()->defaults['general']['backend_content'];

		return [
			'backend_columns' => [
				'title' => __( 'Fields Display', 'download-attachments' ),
				'type' => 'checkbox',
				'options' => $this->get_backend_column_options(),
				'value' => $this->get_selected_keys_from_map( $backend_columns ),
				'description' => __( 'Select which columns would you like to enable on backend for your downloads.', 'download-attachments' ),
			],
			'backend_content' => [
				'title' => __( 'Downloads Description', 'download-attachments' ),
				'type' => 'checkbox',
				'options' => $this->contents,
				'value' => $this->get_selected_keys_from_map( $backend_content ),
				'description' => __( 'Select what fields to use on backend for download attachments description.', 'download-attachments' ),
			],
			'restrict_edit_downloads' => [
				'title' => __( 'Restrict Edit', 'download-attachments' ),
				'type' => 'checkbox_single',
				'label' => __( 'Enable to restrict downloads count editing to admins only.', 'download-attachments' ),
				'value' => ! empty( $option['restrict_edit_downloads'] ),
			],
			'attachment_link' => [
				'title' => __( 'Edit Attachment Link', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->attachment_links,
				'value' => isset( $option['attachment_link'] ) ? $option['attachment_link'] : Download_Attachments()->defaults['general']['attachment_link'],
				'description' => __( 'Select where you want attachment editing to open.', 'download-attachments' ),
			],
			'library' => [
				'title' => __( 'Media Library', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->libraries,
				'value' => isset( $option['library'] ) ? $option['library'] : Download_Attachments()->defaults['general']['library'],
				'description' => __( 'Select which attachments should be visible in Media Library window.', 'download-attachments' ),
			],
			'downloads_in_media_library' => [
				'title' => __( 'Downloads Count', 'download-attachments' ),
				'type' => 'radio',
				'options' => $this->choices,
				'value' => ! empty( $option['downloads_in_media_library'] ) ? 'yes' : 'no',
				'description' => __( 'Enable if you want to display the downloads count in your Media Library columns.', 'download-attachments' ),
			],
		];
	}

	/**
	 * Get download link preview markup.
	 *
	 * @param string $download_link
	 * @return string
	 */
	private function get_download_link_preview_markup( $download_link ) {
		return '
			<p class="description da-download-link-preview"><code>' . esc_url( trailingslashit( home_url() ) ) . '<strong data-da-download-link-preview>' . esc_html( $download_link ) . '</strong>/123/</code></p>';
	}

	/**
	 * Get checkbox selection keys from an associative map.
	 *
	 * @param array $values
	 * @return array
	 */
	private function get_selected_keys_from_map( $values ) {
		if ( empty( $values ) || ! is_array( $values ) ) {
			return [];
		}

		$selected = [];

		foreach ( $values as $key => $value ) {
			if ( $value ) {
				$selected[] = $key;
			}
		}

		return $selected;
	}

	/**
	 * Get user role options.
	 *
	 * @return array
	 */
	private function get_user_role_options() {
		global $wp_roles;

		$editable_roles = get_editable_roles();
		$options = [];

		foreach ( $editable_roles as $role_key => $role_data ) {
			$role = $wp_roles->get_role( $role_key );

			if ( $role && $role->has_cap( 'manage_options' ) ) {
				continue;
			}

			$options[ $role_key ] = translate_user_role( $wp_roles->role_names[ $role_key ] );
		}

		return $options;
	}

	/**
	 * Get post type options.
	 *
	 * @return array
	 */
	private function get_post_type_options() {
		if ( empty( $this->post_types ) ) {
			$this->load_post_types();
		}

		$options = [];

		foreach ( $this->post_types as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );

			if ( $post_type_object && ! empty( $post_type_object->labels->singular_name ) ) {
				$options[ $post_type ] = $post_type_object->labels->singular_name;
			} elseif ( $post_type_object && ! empty( $post_type_object->label ) ) {
				$options[ $post_type ] = $post_type_object->label;
			} else {
				$options[ $post_type ] = $post_type;
			}
		}

		return $options;
	}

	/**
	 * Get frontend column options.
	 *
	 * @return array
	 */
	private function get_frontend_column_options() {
		$options = [];

		foreach ( Download_Attachments()->columns as $column => $label ) {
			if ( in_array( $column, [ 'id', 'type', 'title', 'exclude' ], true ) ) {
				continue;
			}

			$options[ $column ] = $label;
		}

		return $options;
	}

	/**
	 * Get backend column options.
	 *
	 * @return array
	 */
	private function get_backend_column_options() {
		$options = [];

		foreach ( Download_Attachments()->columns as $column => $label ) {
			if ( in_array( $column, [ 'index', 'icon', 'exclude' ], true ) ) {
				continue;
			}

			$options[ $column ] = $label;
		}

		return $options;
	}

	/**
	 * Render hidden inputs for settings form.
	 *
	 * @param string $setting
	 * @param string $page_type
	 * @param string $url_page
	 * @param string $tab_key
	 * @return void
	 */
	public function render_settings_form( $setting, $page_type, $url_page, $tab_key ) {
		echo '<input type="hidden" name="da_current_tab" value="' . esc_attr( $tab_key ) . '" />';
	}

	/**
	 * Render settings sidebar.
	 *
	 * @param string $setting
	 * @param string $page_type
	 * @param string $url_page
	 * @param string $tab_key
	 * @return void
	 */
	public function render_settings_sidebar( $setting, $page_type, $url_page, $tab_key ) {
		if ( $page_type !== 'settings_page' ) {
			return;
		}

		$version = isset( Download_Attachments()->defaults['version'] ) ? Download_Attachments()->defaults['version'] : '';
		$docs_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/docs/download-attachments/?utm_source=download-attachments-settings&utm_medium=link&utm_campaign=docs' ),
			esc_html__( 'Documentation', 'download-attachments' )
		);
		$support_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/support/?utm_source=download-attachments-settings&utm_medium=link&utm_campaign=support' ),
			esc_html__( 'Support forum', 'download-attachments' )
		);
		$rate_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'https://wordpress.org/support/plugin/download-attachments/reviews/?filter=5' ),
			esc_html__( 'Rate it 5 stars', 'download-attachments' )
		);
		$plugin_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/products/download-attachments/?utm_source=download-attachments-settings&utm_medium=link&utm_campaign=blog-about' ),
			esc_html__( 'plugin page', 'download-attachments' )
		);
		$other_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/products/?utm_source=download-attachments-settings&utm_medium=link&utm_campaign=other-plugins' ),
			esc_html__( 'WordPress plugins', 'download-attachments' )
		);

		?>
		<div class="df-credits">
			<h3 class="hndle"><?php echo esc_html__( 'Download Attachments', 'download-attachments' ) . ' ' . esc_html( $version ); ?></h3>
			<div class="inside">
				<h4 class="inner"><?php esc_html_e( 'Need support?', 'download-attachments' ); ?></h4>
				<p class="inner">
					<?php
					printf(
						wp_kses_post( __( 'If you are having problems with this plugin, please browse its %s or talk about them in the %s.', 'download-attachments' ) ),
						$docs_link,
						$support_link
					);
					?>
				</p>
				<hr />
				<h4 class="inner"><?php esc_html_e( 'Do you like this plugin?', 'download-attachments' ); ?></h4>
				<p class="inner">
					<?php
					printf(
						wp_kses_post( __( '%s on WordPress.org', 'download-attachments' ) ),
						$rate_link
					);
					echo '<br />';
					printf(
						wp_kses_post( __( 'Blog about it and link to the %s.', 'download-attachments' ) ),
						$plugin_link
					);
					echo '<br />';
					printf(
						wp_kses_post( __( 'Check out our other %s.', 'download-attachments' ) ),
						$other_link
					);
					?>
				</p>
				<hr />
				<p class="df-link inner">
					<a href="http://www.dfactory.co/?utm_source=download-attachments-settings&utm_medium=link&utm_campaign=created-by" target="_blank" title="<?php esc_attr_e( 'dFactory - Quality plugins for WordPress', 'download-attachments' ); ?>"><img src="<?php echo esc_url( DOWNLOAD_ATTACHMENTS_URL . '/images/df-black-sm.png' ); ?>" alt="<?php esc_attr_e( 'Digital Factory', 'download-attachments' ); ?>" /></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Preserve tab parameter in redirect after saving settings.
	 *
	 * @param string $location
	 * @param int    $status
	 * @return string
	 */
	public function preserve_tab_on_redirect( $location, $status ) {
		if ( strpos( $location, 'page=download-attachments' ) === false ) {
			return $location;
		}

		$tab = isset( $_POST['da_current_tab'] ) ? sanitize_key( wp_unslash( $_POST['da_current_tab'] ) ) : '';

		if ( empty( $tab ) ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		}

		return add_query_arg( 'tab', $tab, $location );
	}

	/**
	 * Validate general settings, reset general settings, reset download counts.
	 *
	 * @global object $wp_roles
	 * @global object $wpdb
	 *
	 * @param array $input
	 * @return array
	 */
	public function validate_general( $input ) {
		global $wpdb;

		if ( empty( $this->post_types ) ) {
			$this->load_post_types();
		}

		$old_input = Download_Attachments()->options;

		if ( $this->has_posted_action( [ 'save_da_general', 'save_download_attachments_general' ] ) ) {
			$new_input = $old_input;
			$new_input['label'] = isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : Download_Attachments()->defaults['general']['label'];

			$selected_roles = ! empty( $input['user_roles'] ) && is_array( $input['user_roles'] ) ? array_map( 'sanitize_key', $input['user_roles'] ) : [];
			$new_input['user_roles'] = $this->sync_user_roles_capabilities( $selected_roles );

			$post_types = ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_map( 'sanitize_key', $input['post_types'] ) : [];
			$mapped_post_types = [];

			foreach ( $this->post_types as $post_type ) {
				$mapped_post_types[ $post_type ] = in_array( $post_type, $post_types, true );
			}

			$new_input['post_types'] = $mapped_post_types;
			$new_input['download_method'] = isset( $input['download_method'], $this->download_methods[ $input['download_method'] ] ) ? $input['download_method'] : Download_Attachments()->defaults['general']['download_method'];
			$new_input['link_target'] = isset( $input['link_target'], $this->redirect_targets[ $input['link_target'] ] ) ? $input['link_target'] : Download_Attachments()->defaults['general']['link_target'];
			$new_input['encrypt_urls'] = isset( $input['encrypt_urls'], $this->choices[ $input['encrypt_urls'] ] ) ? ( $input['encrypt_urls'] === 'yes' ) : Download_Attachments()->defaults['general']['encrypt_urls'];
			$new_input['pretty_urls'] = isset( $input['pretty_urls'], $this->choices[ $input['pretty_urls'] ] ) ? ( $input['pretty_urls'] === 'yes' ) : Download_Attachments()->defaults['general']['pretty_urls'];

			if ( $new_input['pretty_urls'] ) {
				$new_input['download_link'] = sanitize_title( isset( $input['download_link'] ) ? $input['download_link'] : '', Download_Attachments()->defaults['general']['download_link'] );

				if ( $new_input['download_link'] === '' ) {
					$new_input['download_link'] = Download_Attachments()->defaults['general']['download_link'];
				}

				if ( $new_input['encrypt_urls'] ) {
					$rule = $new_input['download_link'] . '/([A-Za-z0-9_,-]+)/?';
				} else {
					$rule = $new_input['download_link'] . '/(\d+)/?';
				}

				add_rewrite_rule( $rule, 'index.php?' . $new_input['download_link'] . '=$matches[1]', 'top' );
			} else {
				$new_input['download_link'] = Download_Attachments()->defaults['general']['download_link'];
			}

			flush_rewrite_rules();
			$new_input['deactivation_delete'] = isset( $input['deactivation_delete'] ) && in_array( $input['deactivation_delete'], array_keys( $this->choices ), true ) ? ( $input['deactivation_delete'] === 'yes' ) : Download_Attachments()->defaults['general']['deactivation_delete'];
			add_settings_error( 'general', 'download_attachments_general_updated', esc_html__( 'General settings saved.', 'download-attachments' ), 'updated' );

			$input = $new_input;
		} elseif ( $this->has_posted_action( [ 'save_da_display', 'save_download_attachments_display' ] ) ) {
			$new_input = $old_input;

			$frontend_columns = ! empty( $input['frontend_columns'] ) && is_array( $input['frontend_columns'] ) ? array_map( 'sanitize_key', $input['frontend_columns'] ) : [];
			$columns = [];

			foreach ( Download_Attachments()->columns as $column => $text ) {
				if ( in_array( $column, [ 'id', 'type', 'exclude' ], true ) ) {
					continue;
				}

				if ( $column === 'title' ) {
					$columns[ $column ] = true;
				} else {
					$columns[ $column ] = in_array( $column, $frontend_columns, true );
				}
			}

			$new_input['frontend_columns'] = $columns;

			$frontend_content = ! empty( $input['frontend_content'] ) && is_array( $input['frontend_content'] ) ? array_map( 'sanitize_key', $input['frontend_content'] ) : [];
			$contents = [];

			foreach ( $this->contents as $content => $trans ) {
				$contents[ $content ] = in_array( $content, $frontend_content, true );
			}

			$new_input['frontend_content'] = $contents;
			$new_input['display_style'] = isset( $input['display_style'], Download_Attachments()->display_styles[ $input['display_style'] ] ) ? $input['display_style'] : Download_Attachments()->defaults['general']['display_style'];
			$new_input['use_css_style'] = isset( $input['use_css_style'] ) && in_array( $input['use_css_style'], array_keys( $this->choices ), true ) ? ( $input['use_css_style'] === 'yes' ) : Download_Attachments()->defaults['general']['use_css_style'];
			$new_input['download_box_display'] = isset( $input['download_box_display'] ) && in_array( $input['download_box_display'], array_keys( $this->download_box_displays ), true ) ? $input['download_box_display'] : Download_Attachments()->defaults['general']['download_box_display'];
			add_settings_error( 'display', 'download_attachments_display_updated', esc_html__( 'Display settings saved.', 'download-attachments' ), 'updated' );

			$input = $new_input;
		} elseif ( $this->has_posted_action( [ 'save_da_admin', 'save_download_attachments_admin' ] ) ) {
			$new_input = $old_input;

			$backend_columns = ! empty( $input['backend_columns'] ) && is_array( $input['backend_columns'] ) ? array_map( 'sanitize_key', $input['backend_columns'] ) : [];
			$columns = [];

			foreach ( Download_Attachments()->columns as $column => $text ) {
				if ( in_array( $column, [ 'index', 'icon', 'exclude' ], true ) ) {
					continue;
				}

				if ( $column === 'title' ) {
					$columns[ $column ] = true;
				} else {
					$columns[ $column ] = in_array( $column, $backend_columns, true );
				}
			}

			$new_input['backend_columns'] = $columns;
			$new_input['restrict_edit_downloads'] = array_key_exists( 'restrict_edit_downloads', $input );

			$backend_content = ! empty( $input['backend_content'] ) && is_array( $input['backend_content'] ) ? array_map( 'sanitize_key', $input['backend_content'] ) : [];
			$contents = [];

			foreach ( $this->contents as $content => $trans ) {
				$contents[ $content ] = in_array( $content, $backend_content, true );
			}

			$new_input['backend_content'] = $contents;
			$new_input['attachment_link'] = isset( $input['attachment_link'], $this->attachment_links[ $input['attachment_link'] ] ) ? $input['attachment_link'] : Download_Attachments()->defaults['general']['attachment_link'];
			$new_input['library'] = isset( $input['library'], $this->libraries[ $input['library'] ] ) ? $input['library'] : Download_Attachments()->defaults['general']['library'];
			$new_input['downloads_in_media_library'] = isset( $input['downloads_in_media_library'] ) && in_array( $input['downloads_in_media_library'], array_keys( $this->choices ), true ) ? ( $input['downloads_in_media_library'] === 'yes' ) : Download_Attachments()->defaults['general']['downloads_in_media_library'];
			add_settings_error( 'admin', 'download_attachments_admin_updated', esc_html__( 'Admin settings saved.', 'download-attachments' ), 'updated' );

			$input = $new_input;
		} elseif ( isset( $_POST['reset_da_general'] ) ) {
			$new_input = $old_input;
			$new_input['user_roles'] = $this->sync_user_roles_capabilities( [], true );

			$keys = [ 'label', 'link_target', 'download_method', 'post_types', 'pretty_urls', 'download_link', 'encrypt_urls', 'deactivation_delete' ];

			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, Download_Attachments()->defaults['general'] ) ) {
					$new_input[ $key ] = Download_Attachments()->defaults['general'][ $key ];
				}
			}

			flush_rewrite_rules();
			$input = $new_input;

			add_settings_error( 'general', 'download_attachments_general_reset', esc_html__( 'General settings restored to defaults.', 'download-attachments' ), 'updated' );
		} elseif ( isset( $_POST['reset_da_display'] ) ) {
			$new_input = $old_input;
			$keys = [ 'frontend_columns', 'display_style', 'frontend_content', 'use_css_style', 'download_box_display' ];

			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, Download_Attachments()->defaults['general'] ) ) {
					$new_input[ $key ] = Download_Attachments()->defaults['general'][ $key ];
				}
			}

			$input = $new_input;
			add_settings_error( 'display', 'download_attachments_display_reset', esc_html__( 'Display settings restored to defaults.', 'download-attachments' ), 'updated' );
		} elseif ( isset( $_POST['reset_da_admin'] ) ) {
			$new_input = $old_input;
			$keys = [ 'backend_columns', 'restrict_edit_downloads', 'backend_content', 'attachment_link', 'library', 'downloads_in_media_library' ];

			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, Download_Attachments()->defaults['general'] ) ) {
					$new_input[ $key ] = Download_Attachments()->defaults['general'][ $key ];
				}
			}

			$input = $new_input;
			add_settings_error( 'admin', 'download_attachments_admin_reset', esc_html__( 'Admin settings restored to defaults.', 'download-attachments' ), 'updated' );
		} elseif ( isset( $_POST['reset_da_downloads'] ) ) {
			$result = $wpdb->update( $wpdb->postmeta, [ 'meta_value' => 0 ], [ 'meta_key' => '_da_downloads' ], '%d', '%s' );
			$input = Download_Attachments()->options;

			if ( $result === false ) {
				add_settings_error( 'general', 'download_attachments_general_reset_downloads_error', esc_html__( 'Error occurred while resetting the downloads count.', 'download-attachments' ), 'error' );
			} else {
				add_settings_error( 'general', 'download_attachments_general_reset_downloads_updated', esc_html__( 'Attachments downloads count has been reset.', 'download-attachments' ), 'updated' );
			}
		}

		return $input;
	}

	/**
	 * Check whether a post action button was submitted.
	 *
	 * @param array $actions
	 * @return bool
	 */
	private function has_posted_action( $actions ) {
		foreach ( (array) $actions as $action ) {
			if ( isset( $_POST[ $action ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render reset downloads field.
	 *
	 * @param array $args
	 * @return void
	 */
	public function render_reset_downloads_field( $args ) {
		echo '<div id="da_general_reset_downloads">';
		echo '<fieldset>';
		submit_button( esc_html__( 'Reset downloads', 'download-attachments' ), 'button outline', 'reset_da_downloads', false );
		echo '<p class="description">' . esc_html__( 'Click to reset the downloads count for all the attachments.', 'download-attachments' ) . '</p>';
		echo '</fieldset>';
		echo '</div>';
	}
}

new Download_Attachments_Settings();
