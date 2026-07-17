<?php
/**
 * Plugin Name:       BE PlumX Metrics
 * Description:       Adds a PlumX widget to posts that have a DOI stored in post meta (default key: doi). Includes a shortcode, settings page, and an integrated script blocker (click-to-load) for cookie-free sites.
 * Version:           0.2.7
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Big Ears Webagentur
 * Author URI:        https://bigears.work
 * Text Domain:       be-plumx-metrics
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BE_PLUMX_VERSION', '0.2.7' );
define( 'BE_PLUMX_FILE', __FILE__ );
define( 'BE_PLUMX_DIR', plugin_dir_path( __FILE__ ) );
define( 'BE_PLUMX_URL', plugin_dir_url( __FILE__ ) );

function be_plumx_defaults() : array {
	return [
		// Where to load
		'enabled_post_types' => 'researcharticles', // comma-separated
		'doi_meta_key'       => 'doi',
		'use_acf_fallback'   => 1,
		'use_content_fallback' => 1,

		// Enqueue behavior
		'enqueue_mode'       => 'always', // always | shortcode (content)

		// PlumX script
		'script_choice'      => 'all', // all | popup

		// Widget defaults
		'default_widget'     => 'summary', // summary | print | details
		'theme_class'        => '',        // optional extra CSS class(es) added to the PlumX element

		'default_orientation' => 'horizontal', // summary
		'default_popup'      => 'right',       // print
		'default_size'       => 'medium',      // print
		'default_border'     => 0,             // details
		'default_width'      => '',            // details
		'hide_empty'         => 1,

		// UI wrapper
		'wrap_card'          => 1,
		'card_title'         => 'Metrics',
		'enqueue_css'        => 1,
		'hide_wrapper_when_empty' => 1,

		// Script blocker (Consent)
		'require_consent'    => 1,
		'remember_consent'   => 0, // localStorage (no cookies). Off by default.
		'consent_text'       => 'Metrics are provided by PlumX. Click “Load metrics” to load external content.',
		'consent_button_label' => 'Load metrics',
		'consent_button_class' => 'gbp-button--primary',
	];
}

function be_plumx_get_settings() : array {
	$defaults = be_plumx_defaults();
	$opt = get_option( 'be_plumx_settings', [] );
	if ( ! is_array( $opt ) ) $opt = [];
	return array_merge( $defaults, $opt );
}

register_activation_hook( __FILE__, function () {
	if ( get_option( 'be_plumx_settings', null ) === null ) {
		add_option( 'be_plumx_settings', be_plumx_defaults() );
	}
} );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'be-plumx-metrics', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Helpers
 */
function be_plumx_normalize_doi( $doi ) : string {
	$doi = trim( (string) $doi );
	$doi = preg_replace( '~^https?://(dx\.)?doi\.org/~i', '', $doi );
	$doi = preg_replace( '~^doi:\s*~i', '', $doi );
	return trim( $doi );
}

function be_plumx_find_doi_in_content( int $post_id ) : string {
	$content = (string) get_post_field( 'post_content', $post_id );
	if ( $content === '' ) return '';
	if ( preg_match( '~\b10\.\d{4,9}/[-._;()/:A-Z0-9]+\b~i', $content, $m ) ) {
		return be_plumx_normalize_doi( $m[0] );
	}
	return '';
}

function be_plumx_get_doi( int $post_id ) : string {
	$s = be_plumx_get_settings();
	$key = (string) $s['doi_meta_key'];

	$doi = get_post_meta( $post_id, $key, true );

	if ( ! $doi && ! empty( $s['use_acf_fallback'] ) && function_exists( 'get_field' ) ) {
		$doi = get_field( $key, $post_id );
	}

	$doi = be_plumx_normalize_doi( $doi );

	if ( ! $doi && ! empty( $s['use_content_fallback'] ) ) {
		$doi = be_plumx_find_doi_in_content( $post_id );
	}

	return $doi;
}

function be_plumx_enabled_post_types() : array {
	$s = be_plumx_get_settings();
	$raw = (string) $s['enabled_post_types'];
	$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	return array_values( array_unique( $parts ) );
}

function be_plumx_get_script_url() : string {
	$s = be_plumx_get_settings();
	$script = ( $s['script_choice'] === 'popup' ) ? 'https://cdn.plu.mx/widget-popup.js' : 'https://cdn.plu.mx/widget-all.js';
	if ( $s['script_choice'] === 'popup' && $s['default_widget'] !== 'print' ) {
		$script = 'https://cdn.plu.mx/widget-all.js';
	}
	return $script;
}

function be_plumx_sanitize_class_list( string $input ) : string {
	$input = trim( $input );
	if ( $input === '' ) return '';
	$parts = preg_split( '/\s+/', $input );
	$out = [];
	foreach ( $parts as $p ) {
		$p = trim( (string) $p );
		if ( $p === '' ) continue;
		$out[] = sanitize_html_class( $p );
	}
	return implode( ' ', array_unique( $out ) );
}

/**
 * Asset enqueue (can be called late from shortcode render)
 */
function be_plumx_enqueue_assets() : void {
	static $did = false;
	static $localized = false;

	if ( $did ) return;
	$did = true;

	if ( is_admin() ) return;

	$s = be_plumx_get_settings();

	if ( ! empty( $s['enqueue_css'] ) ) {
		wp_enqueue_style(
			'be-plumx',
			BE_PLUMX_URL . 'assets/be-plumx.css',
			[],
			BE_PLUMX_VERSION
		);
	}

	wp_enqueue_script(
		'be-plumx-frontend',
		BE_PLUMX_URL . 'assets/be-plumx-frontend.js',
		[],
		BE_PLUMX_VERSION,
		true
	);

	if ( ! $localized ) {
		$localized = true;
		wp_localize_script( 'be-plumx-frontend', 'BE_PLUMX', [
			'requireConsent' => ! empty( $s['require_consent'] ),
			'rememberConsent' => ! empty( $s['remember_consent'] ),
			'scriptUrl' => be_plumx_get_script_url(),
			'storageKey' => 'be_plumx_consent',
			'hideWrapperWhenEmpty' => ! empty( $s['hide_wrapper_when_empty'] ),
		] );
	}

	if ( empty( $s['require_consent'] ) ) {
		wp_enqueue_script(
			'plumx-widget',
			be_plumx_get_script_url(),
			[],
			null,
			true
		);
	}
}

function be_plumx_should_load_on_request() : bool {
	if ( ! is_singular() ) return false;

	$post_id = (int) get_queried_object_id();
	if ( ! $post_id ) return false;

	$post_type = get_post_type( $post_id );
	if ( ! $post_type ) return false;

	if ( ! in_array( $post_type, be_plumx_enabled_post_types(), true ) ) return false;

	$doi = be_plumx_get_doi( $post_id );
	if ( ! $doi ) return false;

	$s = be_plumx_get_settings();

	if ( $s['enqueue_mode'] === 'shortcode' ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( ! ( has_shortcode( $content, 'be_plumx' ) || has_shortcode( $content, 'plumx' ) ) ) {
			return false;
		}
	}

	return true;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( be_plumx_should_load_on_request() ) {
		be_plumx_enqueue_assets();
	}
} );

/**
 * Shortcode renderer
 */
function be_plumx_render_widget( array $atts = [], ?int $post_id = null ) : string {
	$s = be_plumx_get_settings();

	$post_id = $post_id ?: get_the_ID();
	if ( ! $post_id ) return '';

	$doi = be_plumx_get_doi( (int) $post_id );
	if ( ! $doi ) return '';

	// Ensure scripts/styles are present even if shortcode is rendered from templates.
	be_plumx_enqueue_assets();

	$atts = shortcode_atts( [
		'widget'      => $s['default_widget'],
		'popup'       => $s['default_popup'],
		'size'        => $s['default_size'],
		'orientation' => $s['default_orientation'],
		'border'      => $s['default_border'] ? 'true' : 'false',
		'width'       => $s['default_width'],
		'hide_empty'  => $s['hide_empty'] ? 'true' : 'false',

		'wrap'        => $s['wrap_card'] ? 'true' : 'false',
		'title'       => $s['card_title'],
	], $atts, 'be_plumx' );

	$href = 'https://plu.mx/plum/a/?doi=' . rawurlencode( $doi );

	$class = 'plumx-summary';
	if ( $atts['widget'] === 'print' )   $class = 'plumx-plum-print-popup';
	if ( $atts['widget'] === 'details' ) $class = 'plumx-details';

	$theme = be_plumx_sanitize_class_list( (string) $s['theme_class'] );

	$attr = [
		'href' => $href,
		'class' => trim( $class . ' be-plumx ' . $theme ),
		'data-hide-when-empty' => $atts['hide_empty'],
	];

	if ( $atts['widget'] === 'print' ) {
		$attr['data-popup'] = $atts['popup'];
		$attr['data-size']  = $atts['size'];
	}

	if ( $atts['widget'] === 'summary' ) {
		$attr['data-orientation'] = $atts['orientation'];
	}

	if ( $atts['widget'] === 'details' ) {
		$attr['data-border'] = $atts['border'];
		if ( ! empty( $atts['width'] ) ) $attr['data-width'] = $atts['width'];
	}

	$html_attr = '';
	foreach ( $attr as $k => $v ) {
		$html_attr .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( (string) $v ) );
	}

	$anchor = sprintf( '<a%s></a>', $html_attr );

	$wrap = filter_var( $atts['wrap'], FILTER_VALIDATE_BOOLEAN );
	if ( ! $wrap ) return $anchor;

	$blocker = '';
	$has_blocker = ! empty( $s['require_consent'] );
	if ( $has_blocker ) {
		$text = trim( (string) $s['consent_text'] );
		$label = trim( (string) $s['consent_button_label'] );
		$btn_class = trim( (string) $s['consent_button_class'] );
		if ( $btn_class === '' ) $btn_class = 'button';

		$blocker = '<div class="be-plumx-blocker" role="note" aria-label="PlumX consent">
			' . ( $text !== '' ? '<p class="be-plumx-blocker__text">' . esc_html( $text ) . '</p>' : '' ) . '
			<button type="button" class="' . esc_attr( $btn_class ) . ' be-plumx-blocker__btn" data-be-plumx-action="load">' . esc_html( $label !== '' ? $label : 'Load metrics' ) . '</button>
		</div>';
	}

	// If we have a blocker, keep the card visible immediately.
	// If there is no blocker, start hidden until PlumX actually renders content.
	$state_class = $has_blocker ? 'be-plumx-state--blocked' : 'be-plumx-state--pending';

	return '<section class="be-plumx-card ' . esc_attr( $state_class ) . '" data-be-plumx-state="' . esc_attr( $has_blocker ? 'blocked' : 'pending' ) . '" aria-label="PlumX Metrics">' .
		$blocker .
		'<div class="be-plumx-card__body">' . $anchor . '</div>' .
	'</section>';
}

add_shortcode( 'be_plumx', function ( $atts = [] ) {
	return be_plumx_render_widget( (array) $atts );
} );

add_shortcode( 'plumx', function ( $atts = [] ) {
	return be_plumx_render_widget( (array) $atts );
} );

/**
 * Admin settings page
 */
add_action( 'admin_menu', function () {
	add_options_page(
		__( 'BE PlumX Metrics', 'be-plumx-metrics' ),
		__( 'BE PlumX Metrics', 'be-plumx-metrics' ),
		'manage_options',
		'be-plumx-metrics',
		'be_plumx_render_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'be_plumx_settings_group', 'be_plumx_settings', [
		'type' => 'array',
		'sanitize_callback' => 'be_plumx_sanitize_settings',
		'default' => be_plumx_defaults(),
	] );

	add_settings_section(
		'be_plumx_main',
		__( 'Basic Settings', 'be-plumx-metrics' ),
		function () {
			echo '<p>' . esc_html__( 'Configure where PlumX loads and which DOI field to use.', 'be-plumx-metrics' ) . '</p>';
		},
		'be-plumx-metrics'
	);

	add_settings_field( 'enabled_post_types', __( 'Enabled post types', 'be-plumx-metrics' ), 'be_plumx_field_enabled_post_types', 'be-plumx-metrics', 'be_plumx_main' );
	add_settings_field( 'doi_meta_key', __( 'DOI meta key', 'be-plumx-metrics' ), 'be_plumx_field_doi_meta_key', 'be-plumx-metrics', 'be_plumx_main' );
	add_settings_field( 'enqueue_mode', __( 'Enqueue mode', 'be-plumx-metrics' ), 'be_plumx_field_enqueue_mode', 'be-plumx-metrics', 'be_plumx_main' );

	add_settings_section(
		'be_plumx_widget',
		__( 'Widget Defaults', 'be-plumx-metrics' ),
		function () {
			echo '<p>' . esc_html__( 'These defaults are used when the shortcode does not override them.', 'be-plumx-metrics' ) . '</p>';
		},
		'be-plumx-metrics'
	);

	add_settings_field( 'default_widget', __( 'Default widget', 'be-plumx-metrics' ), 'be_plumx_field_default_widget', 'be-plumx-metrics', 'be_plumx_widget' );
	add_settings_field( 'theme_class', __( 'Theme class (optional)', 'be-plumx-metrics' ), 'be_plumx_field_theme_class', 'be-plumx-metrics', 'be_plumx_widget' );
	add_settings_field( 'script_choice', __( 'Script', 'be-plumx-metrics' ), 'be_plumx_field_script_choice', 'be-plumx-metrics', 'be_plumx_widget' );
	add_settings_field( 'hide_empty', __( 'Hide when empty', 'be-plumx-metrics' ), 'be_plumx_field_hide_empty', 'be-plumx-metrics', 'be_plumx_widget' );

	add_settings_section(
		'be_plumx_ui',
		__( 'UI (Card)', 'be-plumx-metrics' ),
		function () {
			echo '<p>' . esc_html__( 'Optional wrapper card styling for a clean “own widget” feel.', 'be-plumx-metrics' ) . '</p>';
		},
		'be-plumx-metrics'
	);

	add_settings_field( 'wrap_card', __( 'Wrap in card', 'be-plumx-metrics' ), 'be_plumx_field_wrap_card', 'be-plumx-metrics', 'be_plumx_ui' );
	add_settings_field( 'card_title', __( 'Card title', 'be-plumx-metrics' ), 'be_plumx_field_card_title', 'be-plumx-metrics', 'be_plumx_ui' );
	add_settings_field( 'enqueue_css', __( 'Load default CSS', 'be-plumx-metrics' ), 'be_plumx_field_enqueue_css', 'be-plumx-metrics', 'be_plumx_ui' );
	add_settings_field( 'hide_wrapper_when_empty', __( 'Hide card if empty', 'be-plumx-metrics' ), 'be_plumx_field_hide_wrapper_when_empty', 'be-plumx-metrics', 'be_plumx_ui' );

	add_settings_section(
		'be_plumx_privacy',
		__( 'Script Blocker', 'be-plumx-metrics' ),
		function () {
			echo '<p>' . esc_html__( 'PlumX is loaded only after a user click. Cookie-free by default.', 'be-plumx-metrics' ) . '</p>';
		},
		'be-plumx-metrics'
	);

	add_settings_field( 'require_consent', __( 'Enable script blocker', 'be-plumx-metrics' ), 'be_plumx_field_require_consent', 'be-plumx-metrics', 'be_plumx_privacy' );
	add_settings_field( 'remember_consent', __( 'Remember consent (localStorage)', 'be-plumx-metrics' ), 'be_plumx_field_remember_consent', 'be-plumx-metrics', 'be_plumx_privacy' );
	add_settings_field( 'consent_text', __( 'Blocker text', 'be-plumx-metrics' ), 'be_plumx_field_consent_text', 'be-plumx-metrics', 'be_plumx_privacy' );
	add_settings_field( 'consent_button_label', __( 'Button label', 'be-plumx-metrics' ), 'be_plumx_field_consent_button_label', 'be-plumx-metrics', 'be_plumx_privacy' );
	add_settings_field( 'consent_button_class', __( 'Button CSS class', 'be-plumx-metrics' ), 'be_plumx_field_consent_button_class', 'be-plumx-metrics', 'be_plumx_privacy' );
} );

function be_plumx_sanitize_settings( $input ) : array {
	$defaults = be_plumx_defaults();
	if ( ! is_array( $input ) ) return $defaults;

	$out = $defaults;

	$out['enabled_post_types'] = sanitize_text_field( $input['enabled_post_types'] ?? $defaults['enabled_post_types'] );
	$out['doi_meta_key']       = sanitize_key( $input['doi_meta_key'] ?? $defaults['doi_meta_key'] );

	$out['use_acf_fallback']     = ! empty( $input['use_acf_fallback'] ) ? 1 : 0;
	$out['use_content_fallback'] = ! empty( $input['use_content_fallback'] ) ? 1 : 0;

	$out['enqueue_mode'] = in_array( (string) ( $input['enqueue_mode'] ?? $defaults['enqueue_mode'] ), [ 'always', 'shortcode' ], true )
		? (string) $input['enqueue_mode']
		: $defaults['enqueue_mode'];

	$out['script_choice'] = in_array( (string) ( $input['script_choice'] ?? $defaults['script_choice'] ), [ 'all', 'popup' ], true )
		? (string) $input['script_choice']
		: $defaults['script_choice'];

	$out['default_widget'] = in_array( (string) ( $input['default_widget'] ?? $defaults['default_widget'] ), [ 'summary', 'print', 'details' ], true )
		? (string) $input['default_widget']
		: $defaults['default_widget'];

	$out['theme_class'] = be_plumx_sanitize_class_list( sanitize_text_field( $input['theme_class'] ?? $defaults['theme_class'] ) );

	$out['hide_empty'] = ! empty( $input['hide_empty'] ) ? 1 : 0;

	$out['wrap_card']   = ! empty( $input['wrap_card'] ) ? 1 : 0;
	$out['card_title']  = sanitize_text_field( $input['card_title'] ?? $defaults['card_title'] );
	$out['enqueue_css'] = ! empty( $input['enqueue_css'] ) ? 1 : 0;
	$out['hide_wrapper_when_empty'] = ! empty( $input['hide_wrapper_when_empty'] ) ? 1 : 0;

	$out['require_consent'] = ! empty( $input['require_consent'] ) ? 1 : 0;
	$out['remember_consent'] = ! empty( $input['remember_consent'] ) ? 1 : 0;
	$out['consent_text'] = sanitize_text_field( $input['consent_text'] ?? $defaults['consent_text'] );
	$out['consent_button_label'] = sanitize_text_field( $input['consent_button_label'] ?? $defaults['consent_button_label'] );
	$out['consent_button_class'] = sanitize_text_field( $input['consent_button_class'] ?? $defaults['consent_button_class'] );

	return $out;
}

/**
 * Field callbacks
 */
function be_plumx_field_enabled_post_types() {
	$s = be_plumx_get_settings();
	printf('<input type="text" class="regular-text" name="be_plumx_settings[enabled_post_types]" value="%s" />', esc_attr( $s['enabled_post_types'] ));
	echo '<p class="description">' . esc_html__( 'Comma-separated list. Example: researcharticles, post', 'be-plumx-metrics' ) . '</p>';
}
function be_plumx_field_doi_meta_key() {
	$s = be_plumx_get_settings();
	printf('<input type="text" class="regular-text" name="be_plumx_settings[doi_meta_key]" value="%s" />', esc_attr( $s['doi_meta_key'] ));
	echo '<p class="description">' . esc_html__( 'Post meta key that stores the DOI. Default: doi', 'be-plumx-metrics' ) . '</p>';
}
function be_plumx_field_enqueue_mode() {
	$s = be_plumx_get_settings(); ?>
	<select name="be_plumx_settings[enqueue_mode]">
		<option value="always" <?php selected( $s['enqueue_mode'], 'always' ); ?>><?php echo esc_html__( 'Always on enabled post types when DOI exists', 'be-plumx-metrics' ); ?></option>
		<option value="shortcode" <?php selected( $s['enqueue_mode'], 'shortcode' ); ?>><?php echo esc_html__( 'Only if shortcode is present in post content', 'be-plumx-metrics' ); ?></option>
	</select>
	<p class="description"><?php echo esc_html__( 'If the shortcode is rendered from a template, assets are still loaded automatically.', 'be-plumx-metrics' ); ?></p>
<?php }
function be_plumx_field_default_widget() {
	$s = be_plumx_get_settings(); ?>
	<select name="be_plumx_settings[default_widget]">
		<option value="summary" <?php selected( $s['default_widget'], 'summary' ); ?>>summary</option>
		<option value="print" <?php selected( $s['default_widget'], 'print' ); ?>>print (popup)</option>
		<option value="details" <?php selected( $s['default_widget'], 'details' ); ?>>details</option>
	</select>
<?php }
function be_plumx_field_theme_class() {
	$s = be_plumx_get_settings();
	printf('<input type="text" class="regular-text" name="be_plumx_settings[theme_class]" value="%s" />', esc_attr( $s['theme_class'] ));
	echo '<p class="description">' . esc_html__( 'Optional CSS class(es) added to the PlumX element.', 'be-plumx-metrics' ) . '</p>';
}
function be_plumx_field_script_choice() {
	$s = be_plumx_get_settings(); ?>
	<select name="be_plumx_settings[script_choice]">
		<option value="all" <?php selected( $s['script_choice'], 'all' ); ?>>widget-all.js</option>
		<option value="popup" <?php selected( $s['script_choice'], 'popup' ); ?>>widget-popup.js (print only)</option>
	</select>
<?php }
function be_plumx_field_hide_empty() {
	$s = be_plumx_get_settings();
	printf('<label><input type="checkbox" name="be_plumx_settings[hide_empty]" value="1" %s /> %s</label>', checked( 1, (int) $s['hide_empty'], false ), esc_html__( 'Ask PlumX to hide the widget when no metrics are available', 'be-plumx-metrics' ));
}
function be_plumx_field_wrap_card() {
	$s = be_plumx_get_settings();
	printf('<label><input type="checkbox" name="be_plumx_settings[wrap_card]" value="1" %s /> %s</label>', checked( 1, (int) $s['wrap_card'], false ), esc_html__( 'Wrap output in a styled card', 'be-plumx-metrics' ));
}
function be_plumx_field_card_title() {
	$s = be_plumx_get_settings();
	printf('<input type="text" class="regular-text" name="be_plumx_settings[card_title]" value="%s" />', esc_attr( $s['card_title'] ));
}
function be_plumx_field_enqueue_css() {
	$s = be_plumx_get_settings();
	printf('<label><input type="checkbox" name="be_plumx_settings[enqueue_css]" value="1" %s /> %s</label>', checked( 1, (int) $s['enqueue_css'], false ), esc_html__( 'Load default card CSS', 'be-plumx-metrics' ));
}
function be_plumx_field_hide_wrapper_when_empty() {
	$s = be_plumx_get_settings();
	printf('<label><input type="checkbox" name="be_plumx_settings[hide_wrapper_when_empty]" value="1" %s /> %s</label>', checked( 1, (int) $s['hide_wrapper_when_empty'], false ), esc_html__( 'Hide the whole card (including the heading) if PlumX returns no data', 'be-plumx-metrics' ));
}
function be_plumx_field_require_consent() {
	$s = be_plumx_get_settings();
	printf('<label><input type="checkbox" name="be_plumx_settings[require_consent]" value="1" %s /> %s</label>', checked( 1, (int) $s['require_consent'], false ), esc_html__( 'Block PlumX script until the user clicks “Load metrics”', 'be-plumx-metrics' ));
}
function be_plumx_field_remember_consent() {
	$s = be_plumx_get_settings();
	printf('<label><input type="checkbox" name="be_plumx_settings[remember_consent]" value="1" %s /> %s</label>', checked( 1, (int) $s['remember_consent'], false ), esc_html__( 'Remember user choice in localStorage (still no cookies)', 'be-plumx-metrics' ));
}
function be_plumx_field_consent_text() {
	$s = be_plumx_get_settings();
	printf('<input type="text" class="large-text" name="be_plumx_settings[consent_text]" value="%s" />', esc_attr( $s['consent_text'] ));
}
function be_plumx_field_consent_button_label() {
	$s = be_plumx_get_settings();
	printf('<input type="text" class="regular-text" name="be_plumx_settings[consent_button_label]" value="%s" />', esc_attr( $s['consent_button_label'] ));
}
function be_plumx_field_consent_button_class() {
	$s = be_plumx_get_settings();
	printf('<input type="text" class="regular-text" name="be_plumx_settings[consent_button_class]" value="%s" />', esc_attr( $s['consent_button_class'] ));
	echo '<p class="description">' . esc_html__( 'Example for GenerateBlocks: gbp-button--primary', 'be-plumx-metrics' ) . '</p>';
}

function be_plumx_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) return; ?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'BE PlumX Metrics', 'be-plumx-metrics' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'be_plumx_settings_group' ); do_settings_sections( 'be-plumx-metrics' ); submit_button(); ?>
		</form>
		<hr />
		<h2><?php echo esc_html__( 'Usage', 'be-plumx-metrics' ); ?></h2>
		<p><?php echo esc_html__( 'Place this shortcode in your template or content:', 'be-plumx-metrics' ); ?></p>
		<code>[be_plumx]</code>
	</div>
<?php }
