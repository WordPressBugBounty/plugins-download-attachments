<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Download_Attachments_Settings_API class.
 *
 * @class Download_Attachments_Settings_API
 */
class Download_Attachments_Settings_API {

	private $prefix = 'da';
	private $domain = 'download-attachments';
	private $slug = 'download-attachments';
	private $plugin = '';
	private $plugin_url = '';
	private $object;
	private $nested = false;
	private $pages = [];

	/**
	 * Class constructor.
	 *
	 * @param array $args
	 * @return void
	 */
	public function __construct( $args = [] ) {
		if ( ! empty( $args['prefix'] ) ) {
			$this->prefix = sanitize_key( $args['prefix'] );
		}

		if ( ! empty( $args['domain'] ) ) {
			$this->domain = sanitize_key( $args['domain'] );
		}

		if ( ! empty( $args['slug'] ) ) {
			$this->slug = sanitize_key( $args['slug'] );
		} else {
			$this->slug = $this->domain;
		}

		if ( ! empty( $args['plugin'] ) ) {
			$this->plugin = $args['plugin'];
		}

		if ( ! empty( $args['plugin_url'] ) ) {
			$this->plugin_url = rtrim( $args['plugin_url'], '/' );
		}

		if ( ! empty( $args['object'] ) ) {
			$this->object = $args['object'];
		}

		if ( ! empty( $args['nested'] ) ) {
			$this->nested = (bool) $args['nested'];
		}

		add_action( 'admin_menu', [ $this, 'admin_menu_options' ], 11 );
		add_action( 'admin_init', [ $this, 'register_settings' ], 11 );
	}

	/**
	 * Set pages.
	 *
	 * @param array $pages
	 * @return void
	 */
	public function set_pages( $pages ) {
		$this->pages = is_array( $pages ) ? $pages : [];
	}

	/**
	 * Get pages.
	 *
	 * @return array
	 */
	public function get_pages() {
		return $this->pages;
	}

	/**
	 * Get prefix.
	 *
	 * @return string
	 */
	public function get_prefix() {
		return $this->prefix;
	}

	/**
	 * Add menu pages.
	 *
	 * @return void
	 */
	public function admin_menu_options() {
		foreach ( $this->pages as $page ) {
			if ( empty( $page['type'] ) || $page['type'] !== 'settings_page' ) {
				continue;
			}

			add_options_page(
				! empty( $page['page_title'] ) ? $page['page_title'] : $this->plugin,
				! empty( $page['menu_title'] ) ? $page['menu_title'] : $this->plugin,
				! empty( $page['capability'] ) ? $page['capability'] : 'manage_options',
				! empty( $page['menu_slug'] ) ? $page['menu_slug'] : $this->slug,
				! empty( $page['callback'] ) ? $page['callback'] : [ $this, 'options_page' ]
			);
		}
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		foreach ( $this->pages as $page_key => $page ) {
			if ( empty( $page['tabs'] ) || ! is_array( $page['tabs'] ) ) {
				continue;
			}

			foreach ( $page['tabs'] as $tab_key => $tab ) {
				if ( empty( $tab['key'] ) || empty( $tab['option_name'] ) ) {
					continue;
				}

				register_setting(
					$tab['key'],
					$tab['option_name'],
					! empty( $page['validate'] ) ? $page['validate'] : null
				);

				if ( empty( $tab['sections'] ) || ! is_array( $tab['sections'] ) ) {
					continue;
				}

				foreach ( $tab['sections'] as $section_id => $section ) {
					$base_slug = sanitize_html_class( str_replace( '_', '-', $section_id ) );
					$section_prefix = sanitize_html_class( $this->prefix );
					$section_classes = $section_prefix . '-section ' . $section_prefix . '-section-' . $base_slug;
					$section_args = [
						'section_class' => $section_classes,
						'before_section' => '<section id="' . $section_prefix . '-section-' . $base_slug . '" class="%s">',
						'after_section' => '</section>'
					];

					add_settings_section(
						$section_id,
						! empty( $section['title'] ) ? $section['title'] : '',
						! empty( $section['callback'] ) ? $section['callback'] : null,
						$tab['key'],
						$section_args
					);

					if ( empty( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
						continue;
					}

					foreach ( $section['fields'] as $field_key => $field ) {
						if ( ! empty( $field['skip_rendering'] ) ) {
							continue;
						}

						$field_id = ! empty( $field['id'] ) ? $field['id'] : implode( '_', [ $this->prefix, $tab_key, $field_key ] );
						$args = $this->prepare_field_args( $field, $field_id, $field_key, $tab_key, $tab['option_name'] );
						$class = sanitize_html_class( str_replace( '_', '-', $field_id ) );
						$classes = [ $class ];

						if ( ! empty( $args['class'] ) ) {
							$extra_classes = preg_split( '/\s+/', trim( $args['class'] ) );
							$extra_classes = array_filter( $extra_classes );
							$extra_classes = array_map( 'sanitize_html_class', $extra_classes );
							$classes = array_merge( $classes, $extra_classes );
						}

						$classes = array_values( array_unique( array_filter( $classes ) ) );
						$field_class = implode( ' ', $classes );
						$args['field_class'] = $field_class;
						$args['css_id'] = $class;

						add_settings_field(
							$field_id,
							! empty( $field['title'] ) ? $field['title'] : '',
							[ $this, 'render_field' ],
							$tab['key'],
							$section_id,
							$args
						);
					}
				}
			}
		}
	}

	/**
	 * Prepare field arguments.
	 *
	 * @param array  $field
	 * @param string $field_id
	 * @param string $field_key
	 * @param string $tab_key
	 * @param string $option_name
	 * @return array
	 */
	public function prepare_field_args( $field, $field_id, $field_key, $tab_key, $option_name ) {
		$field_type = ! empty( $field['type'] ) ? $field['type'] : 'input';
		$html_id = ! empty( $field['html_id'] ) ? $field['html_id'] : sanitize_html_class( str_replace( '_', '-', $field_id ) );
		$name = ! empty( $field['name'] ) ? $field['name'] : $option_name . '[' . $field_key . ']';
		$value = array_key_exists( 'value', $field ) ? $field['value'] : null;
		$default = array_key_exists( 'default', $field ) ? $field['default'] : null;

		if ( $this->nested && ! empty( $field['parent'] ) ) {
			$name = $option_name . '[' . $field['parent'] . '][' . $field_key . ']';
		}

		return [
			'id' => $field_id,
			'html_id' => $html_id,
			'name' => $name,
			'class' => ! empty( $field['class'] ) ? $field['class'] : '',
			'field_class' => ! empty( $field['field_class'] ) ? $field['field_class'] : '',
			'wrapper_id' => ! empty( $field['wrapper_id'] ) ? $field['wrapper_id'] : '',
			'wrapper_class' => ! empty( $field['wrapper_class'] ) ? $field['wrapper_class'] : '',
			'wrapper_attributes' => ! empty( $field['wrapper_attributes'] ) ? $field['wrapper_attributes'] : [],
			'type' => $field_type,
			'label' => ! empty( $field['label'] ) ? $field['label'] : '',
			'description' => ! empty( $field['description'] ) ? $field['description'] : '',
			'text' => ! empty( $field['text'] ) ? $field['text'] : '',
			'min' => isset( $field['min'] ) ? (int) $field['min'] : 0,
			'max' => isset( $field['max'] ) ? (int) $field['max'] : 0,
			'step' => ! empty( $field['step'] ) ? $field['step'] : '',
			'options' => ! empty( $field['options'] ) ? $field['options'] : [],
			'callback' => ! empty( $field['callback'] ) ? $field['callback'] : null,
			'callback_args' => ! empty( $field['callback_args'] ) ? $field['callback_args'] : [],
			'validate' => ! empty( $field['validate'] ) ? $field['validate'] : null,
			'default' => $default,
			'value' => $value,
			'setting_id' => $tab_key,
			'disabled' => ! empty( $field['disabled'] ) ? $field['disabled'] : [],
			'display_type' => ! empty( $field['display_type'] ) ? $field['display_type'] : 'horizontal',
			'condition' => ! empty( $field['condition'] ) ? $field['condition'] : [],
			'condition_action' => ! empty( $field['condition_action'] ) ? $field['condition_action'] : '',
			'animation' => ! empty( $field['animation'] ) ? $field['animation'] : '',
			'attributes' => ! empty( $field['attributes'] ) ? $field['attributes'] : [],
			'input_attributes' => ! empty( $field['input_attributes'] ) ? $field['input_attributes'] : [],
			'before_field' => ! empty( $field['before_field'] ) ? $field['before_field'] : '',
			'after_field' => ! empty( $field['after_field'] ) ? $field['after_field'] : '',
			'prepend' => ! empty( $field['prepend'] ) ? $field['prepend'] : '',
			'append' => ! empty( $field['append'] ) ? $field['append'] : '',
			'subclass' => ! empty( $field['subclass'] ) ? $field['subclass'] : '',
			'checked_value' => array_key_exists( 'checked_value', $field ) ? $field['checked_value'] : '1',
			'unchecked_value' => array_key_exists( 'unchecked_value', $field ) ? $field['unchecked_value'] : '0',
			'field_key' => $field_key,
		];
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function options_page() {
		$page = $this->get_current_page();

		if ( empty( $page ) ) {
			return;
		}

		$tabs = ! empty( $page['tabs'] ) && is_array( $page['tabs'] ) ? $page['tabs'] : [];
		$tab_key = $this->get_current_tab_key( $tabs );
		$tab = ! empty( $tabs[ $tab_key ] ) ? $tabs[ $tab_key ] : [];
		$page_title = ! empty( $page['page_title'] ) ? $page['page_title'] : $this->plugin;
		$heading = ! empty( $tab['heading'] ) ? $tab['heading'] : ( ! empty( $tab['label'] ) ? $tab['label'] : $page_title );
		$setting = ! empty( $tab['option_name'] ) ? $tab['option_name'] : '';
		$page_slug = ! empty( $page['menu_slug'] ) ? $page['menu_slug'] : $this->slug;
		$form_allowed = ! empty( $page['form'] ) && array_key_exists( 'buttons', $page['form'] ) ? (bool) $page['form']['buttons'] : true;

		echo '
		<div class="wrap ' . esc_attr( $this->prefix ) . '-settings-wrapper download-attachments-settings" data-settings-prefix="' . esc_attr( $this->prefix ) . '" data-theme="light">
			<div class="' . esc_attr( $this->prefix ) . '-settings-header header-wrapper">
				<span class="header-title">' . esc_html( $heading ) . '</span>
			</div>';

		if ( ! empty( $tabs ) ) {
			echo '
			<nav class="nav-tab-wrapper">';

			foreach ( $tabs as $key => $tab_data ) {
				$tab_url = add_query_arg(
					[
						'page' => $page_slug,
						'tab' => $key,
					],
					admin_url( 'options-general.php' )
				);

				echo '
				<a class="nav-tab nav-tab-' . esc_attr( $key ) . ( $tab_key === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $tab_url ) . '">' . esc_html( ! empty( $tab_data['label'] ) ? $tab_data['label'] : $key ) . '</a>';
			}

			echo '
			</nav>';
		}

		echo '
			<div class="content-wrapper">';
		echo '
				<h1 class="screen-reader-text">' . esc_html( $heading ) . '</h1>';

		echo '
				<div class="' . esc_attr( $this->prefix ) . '-settings-notices">';
		do_action( $this->prefix . '_before_render_settings_errors', $tab_key, $setting, $page );
		settings_errors( $tab_key, false, false );
		do_action( $this->prefix . '_after_render_settings_errors', $tab_key, $setting, $page );
		echo '
				</div>';

		$settings_class = apply_filters( $this->prefix . '_settings_page_class', [ $this->slug . '-settings', $tab_key . '-settings', $this->prefix . '-settings' ] );
		$settings_class = array_unique( array_filter( array_map( 'sanitize_html_class', (array) $settings_class ) ) );

		$sidebar_html = $this->render_callback_output(
			! empty( $page['sidebar_callback'] ) ? $page['sidebar_callback'] : null,
			$setting,
			'settings_page',
			'options-general.php',
			$tab_key
		);

		if ( ! empty( $sidebar_html ) ) {
			$settings_class[] = 'has-sidebar';
		}

		echo '
				<div class="' . esc_attr( implode( ' ', $settings_class ) ) . '">';

		if ( $form_allowed ) {
			echo '
					<form action="options.php" method="post" novalidate class="' . esc_attr( $this->prefix ) . '-settings-form">';
		}

		if ( ! empty( $tab['key'] ) ) {
			settings_fields( $tab['key'] );
		}

		if ( $form_allowed ) {
			echo $this->render_callback_output(
				! empty( $page['form_callback'] ) ? $page['form_callback'] : null,
				$setting,
				'settings_page',
				'options-general.php',
				$tab_key
			);
		}

		if ( ! empty( $tab['key'] ) ) {
			do_settings_sections( $tab['key'] );
		}

		if ( $form_allowed ) {
			echo '
					<p class="submit">';

			submit_button( '', 'primary ' . esc_attr( ! empty( $tab['submit'] ) ? $tab['submit'] : 'save_' . $setting ), ! empty( $tab['submit'] ) ? $tab['submit'] : 'save_' . $setting, false );
			echo ' ';
			submit_button( __( 'Reset to defaults', $this->domain ), 'button outline ' . esc_attr( ! empty( $tab['reset'] ) ? $tab['reset'] : 'reset_' . $setting ), ! empty( $tab['reset'] ) ? $tab['reset'] : 'reset_' . $setting, false );

			echo '
					</p>
				</form>';
		}

		if ( ! empty( $sidebar_html ) ) {
			echo '
				<aside class="' . esc_attr( $this->prefix ) . '-sidebar download-attachments-sidebar">' . $sidebar_html . '</aside>';
		}

		echo '
				</div>
			</div>
			<div class="clear"></div>
		</div>';
	}

	/**
	 * Render callback output.
	 *
	 * @param callable|null $callback
	 * @param mixed         ...$args
	 * @return string
	 */
	private function render_callback_output( $callback = null, ...$args ) {
		if ( empty( $callback ) || ! is_callable( $callback ) ) {
			return '';
		}

		ob_start();
		call_user_func_array( $callback, $args );
		return trim( ob_get_clean() );
	}

	/**
	 * Get current page config.
	 *
	 * @return array
	 */
	private function get_current_page() {
		if ( empty( $this->pages ) ) {
			return [];
		}

		$page_key = '';

		if ( isset( $_GET['page'] ) ) {
			$page_key = sanitize_key( wp_unslash( $_GET['page'] ) );
		} else {
			$page_key = array_key_first( $this->pages );
		}

		if ( empty( $page_key ) || empty( $this->pages[ $page_key ] ) ) {
			$page_key = array_key_first( $this->pages );
		}

		return ! empty( $this->pages[ $page_key ] ) ? $this->pages[ $page_key ] : [];
	}

	/**
	 * Get current tab key.
	 *
	 * @param array $tabs
	 * @return string
	 */
	private function get_current_tab_key( $tabs ) {
		if ( empty( $tabs ) ) {
			return '';
		}

		reset( $tabs );
		$first_tab = key( $tabs );
		$tab_key = $first_tab;

		if ( isset( $_GET['tab'] ) ) {
			$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );

			if ( array_key_exists( $requested_tab, $tabs ) ) {
				$tab_key = $requested_tab;
			}
		}

		return $tab_key;
	}

	/**
	 * Render settings field.
	 *
	 * @param array $args
	 * @return void|string
	 */
	public function render_field( $args ) {
		if ( empty( $args ) || ! is_array( $args ) ) {
			return;
		}

		$div_classes = [];

		if ( ! empty( $args['field_class'] ) ) {
			$div_classes = preg_split( '/\s+/', trim( $args['field_class'] ) );
		}

		if ( ! empty( $args['wrapper_class'] ) ) {
			$wrapper_classes = preg_split( '/\s+/', trim( $args['wrapper_class'] ) );
			$div_classes = array_merge( (array) $div_classes, (array) $wrapper_classes );
		}

		$div_classes[] = esc_attr( $this->prefix ) . '-field';
		$div_classes[] = esc_attr( $this->prefix ) . '-field-type-' . sanitize_html_class( $args['type'] );
		$div_classes = array_values( array_unique( array_filter( $div_classes ) ) );

		$data_attrs = '';
		$conditions = [];
		$condition_action = '';

		if ( ! empty( $args['condition'] ) && is_array( $args['condition'] ) ) {
			if ( isset( $args['condition']['field'] ) ) {
				$conditions = [ $args['condition'] ];
			} else {
				$conditions = $args['condition'];
			}
		}

		if ( ! empty( $args['condition_action'] ) && in_array( $args['condition_action'], [ 'show', 'hide', 'enable', 'disable' ], true ) ) {
			$condition_action = $args['condition_action'];
		}

		if ( ! empty( $conditions ) ) {
			$data_attr_prefix = sanitize_html_class( $this->prefix );
			$normalized_conditions = [];

			foreach ( $conditions as $condition ) {
				if ( empty( $condition['field'] ) || empty( $condition['operator'] ) ) {
					continue;
				}

				$field = $condition['field'];

				if ( strpos( $field, '-' ) === false && ! empty( $args['setting_id'] ) ) {
					$field_id = implode( '_', [ $this->prefix, $args['setting_id'], $field ] );
					$field = sanitize_html_class( str_replace( '_', '-', $field_id ) );
				}

				$normalized_conditions[] = [
					'field' => $field,
					'operator' => sanitize_key( $condition['operator'] ),
					'value' => isset( $condition['value'] ) ? (string) $condition['value'] : '',
				];
			}

			if ( ! empty( $normalized_conditions ) && $data_attr_prefix !== '' ) {
				if ( $condition_action !== '' ) {
					$data_attrs .= ' data-' . $data_attr_prefix . '-conditional-action="' . esc_attr( $condition_action ) . '"';
				}

				if ( ! empty( $args['animation'] ) && in_array( $args['animation'], [ 'fade', 'slide' ], true ) ) {
					$data_attrs .= ' data-' . $data_attr_prefix . '-animation="' . esc_attr( $args['animation'] ) . '"';
				}

				$data_attrs .= ' data-' . $data_attr_prefix . '-conditional="' . esc_attr( wp_json_encode( $normalized_conditions ) ) . '"';
			}
		}

		$wrapper_id = ! empty( $args['wrapper_id'] ) ? $args['wrapper_id'] : $args['html_id'] . '-setting';
		$wrapper_id = sanitize_html_class( str_replace( '_', '-', $wrapper_id ) );

		if ( $wrapper_id === $args['html_id'] ) {
			$wrapper_id = sanitize_html_class( $args['html_id'] . '-setting' );
		}

		$wrapper_attributes = $this->render_attributes( ! empty( $args['wrapper_attributes'] ) ? $args['wrapper_attributes'] : [] );
		$html = '<div id="' . esc_attr( $wrapper_id ) . '"' . ( ! empty( $div_classes ) ? ' class="' . esc_attr( implode( ' ', $div_classes ) ) . '"' : '' ) . $wrapper_attributes . $data_attrs . '>';

		if ( ! empty( $args['before_field'] ) ) {
			$html .= $args['before_field'];
		}

		$attributes = $this->render_attributes( ! empty( $args['attributes'] ) ? $args['attributes'] : [] );
		$input_attributes = $this->render_attributes( ! empty( $args['input_attributes'] ) ? $args['input_attributes'] : [] );

		switch ( $args['type'] ) {
			case 'boolean':
				if ( empty( $args['disabled'] ) ) {
					$html .= '<input type="hidden" name="' . esc_attr( $args['name'] ) . '" value="false" />';
				}

				$html .= '<label><input id="' . esc_attr( $args['html_id'] ) . '" type="checkbox" role="switch" name="' . esc_attr( $args['name'] ) . '" value="true" ' . checked( (bool) $args['value'], true, false ) . ' ' . disabled( empty( $args['disabled'] ), false, false ) . $input_attributes . ' />' . esc_html( $args['label'] ) . '</label>';
				break;

			case 'checkbox_single':
				$html .= '<label><input id="' . esc_attr( $args['html_id'] ) . '" type="checkbox" name="' . esc_attr( $args['name'] ) . '" value="' . esc_attr( $args['checked_value'] ) . '" ' . checked( ! empty( $args['value'] ), true, false ) . ' ' . disabled( empty( $args['disabled'] ), false, false ) . $input_attributes . ' />' . esc_html( $args['label'] ) . '</label>';
				break;

			case 'radio':
				if ( empty( $args['options'] ) || ! is_array( $args['options'] ) ) {
					break;
				}

				if ( count( $args['options'] ) > 1 ) {
					$html .= '<div class="' . esc_attr( $this->prefix ) . '-field-group ' . esc_attr( $this->prefix ) . '-radio-group ' . esc_attr( $args['display_type'] ) . '">';
				}

				foreach ( $args['options'] as $key => $name ) {
					$option_id = esc_attr( $args['html_id'] . '-' . $key );
					$html .= '<label for="' . $option_id . '"><input id="' . $option_id . '" type="radio" name="' . esc_attr( $args['name'] ) . '" value="' . esc_attr( $key ) . '" ' . checked( $key, $args['value'], false ) . ' ' . disabled( ! empty( $args['disabled'] ) && in_array( $key, (array) $args['disabled'], true ), true, false ) . $input_attributes . ' />' . esc_html( $name ) . '</label>';
				}

				if ( count( $args['options'] ) > 1 ) {
					$html .= '</div>';
				}
				break;

			case 'checkbox':
				if ( $args['value'] === 'empty' ) {
					$args['value'] = [];
				}

				$html .= '<input type="hidden" name="' . esc_attr( $args['name'] ) . '" value="empty" />';

				if ( empty( $args['options'] ) || ! is_array( $args['options'] ) ) {
					break;
				}

				if ( count( $args['options'] ) > 1 ) {
					$html .= '<div class="' . esc_attr( $this->prefix ) . '-field-group ' . esc_attr( $this->prefix ) . '-checkbox-group ' . esc_attr( $args['display_type'] ) . '">';
				}

				foreach ( $args['options'] as $key => $name ) {
					$option_id = esc_attr( $args['html_id'] . '-' . $key );
					$html .= '<label for="' . $option_id . '"><input id="' . $option_id . '" type="checkbox" name="' . esc_attr( $args['name'] ) . '[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, (array) $args['value'], true ), true, false ) . ' ' . disabled( ! empty( $args['disabled'] ) && in_array( $key, (array) $args['disabled'], true ), true, false ) . $input_attributes . ' />' . esc_html( $name ) . '</label>';
				}

				if ( count( $args['options'] ) > 1 ) {
					$html .= '</div>';
				}
				break;

			case 'select':
				$html .= '<select id="' . esc_attr( $args['html_id'] ) . '" name="' . esc_attr( $args['name'] ) . '" ' . disabled( empty( $args['disabled'] ), false, false ) . $attributes . '>';

				foreach ( (array) $args['options'] as $key => $name ) {
					$html .= '<option value="' . esc_attr( $key ) . '" ' . selected( $args['value'], $key, false ) . '>' . esc_html( $name ) . '</option>';
				}

				$html .= '</select>';
				break;

			case 'custom':
				if ( ! empty( $args['callback'] ) && is_callable( $args['callback'] ) ) {
					ob_start();
					call_user_func( $args['callback'], $args );
					$html .= ob_get_clean();
				}
				break;

			case 'info':
				$html .= '<span' . ( ! empty( $args['subclass'] ) ? ' class="' . esc_attr( $args['subclass'] ) . '"' : '' ) . '>' . esc_html( $args['text'] ) . '</span>';
				break;

			case 'number':
				$min_attr = isset( $args['min'] ) ? ' min="' . esc_attr( $args['min'] ) . '"' : '';
				$max_attr = isset( $args['max'] ) ? ' max="' . esc_attr( $args['max'] ) . '"' : '';
				$step_attr = isset( $args['step'] ) && $args['step'] !== '' ? ' step="' . esc_attr( $args['step'] ) . '"' : '';
				$html .= ( ! empty( $args['prepend'] ) ? wp_kses_post( $args['prepend'] ) : '' );
				$html .= '<input id="' . esc_attr( $args['html_id'] ) . '" type="number" value="' . esc_attr( $args['value'] ) . '" name="' . esc_attr( $args['name'] ) . '"' . $min_attr . $max_attr . $step_attr . $input_attributes . ' />';
				$html .= ( ! empty( $args['append'] ) ? wp_kses_post( $args['append'] ) : '' );
				break;

			case 'textarea':
				$html .= '<textarea id="' . esc_attr( $args['html_id'] ) . '" name="' . esc_attr( $args['name'] ) . '"' . $input_attributes . '>' . esc_textarea( (string) $args['value'] ) . '</textarea>';
				break;

			case 'input':
			case 'text':
			default:
				$html .= ( ! empty( $args['prepend'] ) ? wp_kses_post( $args['prepend'] ) : '' );
				$html .= '<input id="' . esc_attr( $args['html_id'] ) . '" class="' . esc_attr( ! empty( $args['subclass'] ) ? $args['subclass'] : 'regular-text' ) . '" type="text" value="' . esc_attr( $args['value'] ) . '" name="' . esc_attr( $args['name'] ) . '" ' . disabled( empty( $args['disabled'] ), false, false ) . $attributes . $input_attributes . ' />';
				$html .= ( ! empty( $args['append'] ) ? wp_kses_post( $args['append'] ) : '' );
		}

		if ( ! empty( $args['after_field'] ) ) {
			$html .= $args['after_field'];
		}

		if ( ! empty( $args['description'] ) ) {
			$html .= '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}

		$html .= '</div>';

		if ( ! empty( $args['return'] ) ) {
			return $html;
		}

		echo $html;
	}

	/**
	 * Render attributes.
	 *
	 * @param array $attributes
	 * @return string
	 */
	private function render_attributes( $attributes ) {
		if ( empty( $attributes ) || ! is_array( $attributes ) ) {
			return '';
		}

		$markup = '';

		foreach ( $attributes as $attribute => $value ) {
			if ( $value === null || $value === false ) {
				continue;
			}

			if ( $value === true ) {
				$markup .= ' ' . sanitize_key( $attribute );
				continue;
			}

			$markup .= ' ' . sanitize_key( $attribute ) . '="' . esc_attr( $value ) . '"';
		}

		return $markup;
	}

	/**
	 * Validate settings field.
	 *
	 * @param mixed  $value
	 * @param string $type
	 * @param array  $args
	 * @return mixed
	 */
	public function validate_field( $value = null, $type = '', $args = [] ) {
		if ( is_null( $value ) ) {
			return null;
		}

		switch ( $type ) {
			case 'boolean':
			case 'checkbox_single':
				$value = $value === 'true' || $value === '1' || $value === 1 || $value === true;
				break;

			case 'radio':
				$value = is_array( $value ) ? ( ! empty( $args['default'] ) ? $args['default'] : '' ) : sanitize_key( $value );
				if ( ! empty( $args['disabled'] ) && in_array( $value, (array) $args['disabled'], true ) ) {
					$value = ! empty( $args['default'] ) ? $args['default'] : '';
				}
				break;

			case 'checkbox':
				if ( $value === 'empty' ) {
					$value = [];
				} elseif ( is_array( $value ) && ! empty( $value ) ) {
					$value = array_map( 'sanitize_key', $value );
					$values = [];

					foreach ( $value as $single_value ) {
						if ( array_key_exists( $single_value, (array) $args['options'] ) ) {
							$values[] = $single_value;
						}
					}

					$value = $values;
				} else {
					$value = [];
				}
				break;

			case 'number':
				$value = (int) $value;

				if ( isset( $args['min'] ) && $value < $args['min'] ) {
					$value = $args['min'];
				}

				if ( isset( $args['max'] ) && $value > $args['max'] ) {
					$value = $args['max'];
				}
				break;

			case 'textarea':
			case 'input':
			case 'text':
				$value = sanitize_text_field( $value );
				break;

			case 'select':
			case 'class':
				$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
				break;

			case 'custom':
			case 'info':
				break;

			default:
				$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
				break;
		}

		return stripslashes_deep( $value );
	}
}
