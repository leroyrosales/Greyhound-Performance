<?php
/**
 * Plugin Name:       Greyhound Performance
 * Description:       Lean WordPress tuning from Greyhound Performance — fewer head tags, no emoji bloat, tighter XML-RPC/pingback surface (named for the track greyhound: built for speed).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Leroy Rosales
 * Author URI:        https://leroyrosales.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       greyhound-performance
 *
 * @package Greyhound_Performance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap hooks after plugins are loaded (translations, safe load order).
 */
add_action( 'plugins_loaded', array( 'Greyhound_Performance', 'init' ), 1 );

/**
 * Performance and hardening routines.
 */
final class Greyhound_Performance {
	private const OPTION_KEY          = 'greyhound_perf_settings';
	private const OPTION_ADVANCED_KEY = 'greyhound_perf_advanced';
	private const SETTINGS_GROUP      = 'greyhound_perf_settings_group';

	/** @var bool */
	private static $is_editor_screen = false;

	/**
	 * Settings map for labels/help text.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function settings_schema(): array {
		return array(
			'remove_head_noise'       => array(
				'label'       => __( 'Remove wp_head noise', 'greyhound-performance' ),
				'description' => __( 'Low risk. Removes generator, shortlink, WLW and other noisy head tags.', 'greyhound-performance' ),
				'impact'      => __( 'Safe default', 'greyhound-performance' ),
			),
			'remove_jqmigrate'        => array(
				'label'       => __( 'Remove jQuery Migrate (frontend)', 'greyhound-performance' ),
				'description' => __( 'Moderate risk. Some legacy themes/plugins require jquery-migrate.', 'greyhound-performance' ),
				'impact'      => __( 'Compatibility risk', 'greyhound-performance' ),
			),
			'remove_oembed'           => array(
				'label'       => __( 'Remove oEmbed head/route hooks', 'greyhound-performance' ),
				'description' => __( 'Moderate risk. Can affect some remote embed workflows.', 'greyhound-performance' ),
				'impact'      => __( 'Compatibility risk', 'greyhound-performance' ),
			),
			'disable_trackbacks'      => array(
				'label'       => __( 'Harden trackbacks and XML-RPC', 'greyhound-performance' ),
				'description' => __( 'Security-focused. Includes XML-RPC disable behavior.', 'greyhound-performance' ),
				'impact'      => __( 'May affect integrations', 'greyhound-performance' ),
			),
			'disable_emojis'          => array(
				'label'       => __( 'Disable emoji assets', 'greyhound-performance' ),
				'description' => __( 'Low risk. Removes emoji scripts/styles and related hints.', 'greyhound-performance' ),
				'impact'      => __( 'Safe default', 'greyhound-performance' ),
			),
			'remove_wp_embed'         => array(
				'label'       => __( 'Remove wp-embed script', 'greyhound-performance' ),
				'description' => __( 'Can reduce frontend JS for sites that do not use embeds.', 'greyhound-performance' ),
				'impact'      => __( 'Potential embed impact', 'greyhound-performance' ),
			),
			'remove_dashicons_guests' => array(
				'label'       => __( 'Remove Dashicons for guests', 'greyhound-performance' ),
				'description' => __( 'Unload dashicons on frontend for logged-out users.', 'greyhound-performance' ),
				'impact'      => __( 'Theme icon risk', 'greyhound-performance' ),
			),
			'remove_comment_reply'    => array(
				'label'       => __( 'Unload comment-reply when unnecessary', 'greyhound-performance' ),
				'description' => __( 'Keeps threading script off pages that do not need it.', 'greyhound-performance' ),
				'impact'      => __( 'Safe default', 'greyhound-performance' ),
			),
			'remove_asset_versions'   => array(
				'label'       => __( 'Remove asset version query strings', 'greyhound-performance' ),
				'description' => __( 'Removes ?ver= from script/style URLs on frontend.', 'greyhound-performance' ),
				'impact'      => __( 'Caching/plugin risk', 'greyhound-performance' ),
			),
			'cache_anonymous_html'    => array(
				'label'       => __( 'Cache anonymous HTML responses', 'greyhound-performance' ),
				'description' => __( 'Adds Cache-Control/Expires headers to frontend HTML for logged-out visitors.', 'greyhound-performance' ),
				'impact'      => __( 'Requires cache invalidation strategy', 'greyhound-performance' ),
			),
			'add_preconnect_hints'    => array(
				'label'       => __( 'Add preconnect resource hints', 'greyhound-performance' ),
				'description' => __( 'Adds preconnect hints for configured third-party origins.', 'greyhound-performance' ),
				'impact'      => __( 'Low risk', 'greyhound-performance' ),
			),
			'control_heartbeat_admin' => array(
				'label'       => __( 'Limit Heartbeat in wp-admin', 'greyhound-performance' ),
				'description' => __( 'Adjusts admin heartbeat interval.', 'greyhound-performance' ),
				'impact'      => __( 'May slow live updates', 'greyhound-performance' ),
			),
			'control_heartbeat_editor' => array(
				'label'       => __( 'Limit Heartbeat in post editor', 'greyhound-performance' ),
				'description' => __( 'Adjusts editor heartbeat interval independently.', 'greyhound-performance' ),
				'impact'      => __( 'May affect autosave cadence', 'greyhound-performance' ),
			),
			'disable_wp_cron_frontend' => array(
				'label'       => __( 'Disable frontend wp-cron trigger', 'greyhound-performance' ),
				'description' => __( 'Disables wp_cron() on frontend requests; use a server cron instead.', 'greyhound-performance' ),
				'impact'      => __( 'Needs server cron', 'greyhound-performance' ),
			),
			'rest_user_hardening'     => array(
				'label'       => __( 'Restrict REST user enumeration', 'greyhound-performance' ),
				'description' => __( 'Hides /wp/v2/users for unauthenticated users.', 'greyhound-performance' ),
				'impact'      => __( 'Security hardening', 'greyhound-performance' ),
			),
			'rest_require_auth'       => array(
				'label'       => __( 'Require auth for all REST requests', 'greyhound-performance' ),
				'description' => __( 'Aggressive mode; blocks unauthenticated REST access.', 'greyhound-performance' ),
				'impact'      => __( 'High breakage risk', 'greyhound-performance' ),
			),
			'security_headers_enabled' => array(
				'label'       => __( 'Add security headers', 'greyhound-performance' ),
				'description' => __( 'Sends configured response headers for hardening.', 'greyhound-performance' ),
				'impact'      => __( 'Policy tuning required', 'greyhound-performance' ),
			),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, bool>
	 */
	private static function default_settings(): array {
		$defaults = array();
		foreach ( array_keys( self::settings_schema() ) as $key ) {
			$defaults[ $key ] = true;
		}

		$defaults['rest_require_auth']        = false;
		$defaults['remove_asset_versions']    = false;
		$defaults['disable_wp_cron_frontend'] = false;
		$defaults['cache_anonymous_html']     = false;
		return $defaults;
	}

	/**
	 * @return array<string, string>
	 */
	private static function default_advanced(): array {
		return array(
			'preset'                    => 'balanced',
			'heartbeat_admin_interval'  => '60',
			'heartbeat_editor_interval' => '30',
			'html_cache_ttl'            => '300',
			'preconnect_origins'        => "https://fonts.googleapis.com\nhttps://fonts.gstatic.com\nhttps://secure.gravatar.com",
			'exclude_url_patterns'      => '',
			'exclude_post_types'        => '',
			'security_headers'          => "X-Content-Type-Options: nosniff\nReferrer-Policy: strict-origin-when-cross-origin\nX-Frame-Options: SAMEORIGIN",
			'import_json'               => '',
		);
	}

	/**
	 * @return array<string, bool>
	 */
	private static function get_settings(): array {
		$defaults = self::default_settings();
		$raw      = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		foreach ( $defaults as $key => $enabled ) {
			$defaults[ $key ] = isset( $raw[ $key ] ) && '1' === (string) $raw[ $key ];
		}

		return $defaults;
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_advanced_settings(): array {
		$defaults = self::default_advanced();
		$raw      = get_option( self::OPTION_ADVANCED_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		foreach ( $defaults as $key => $value ) {
			if ( isset( $raw[ $key ] ) ) {
				$defaults[ $key ] = (string) $raw[ $key ];
			}
		}

		return $defaults;
	}

	private static function is_enabled( string $key ): bool {
		$settings = self::get_settings();
		return ! empty( $settings[ $key ] );
	}

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		self::register_admin_settings();
		self::register_plugin_action_links();
		self::register_admin_tools();
		add_action( 'current_screen', array( self::class, 'detect_editor_screen' ) );

		if ( self::is_enabled( 'security_headers_enabled' ) ) {
			add_action( 'send_headers', array( self::class, 'send_security_headers' ) );
		}

		if ( self::is_enabled( 'rest_user_hardening' ) || self::is_enabled( 'rest_require_auth' ) ) {
			add_filter( 'rest_endpoints', array( self::class, 'filter_rest_endpoints' ) );
			add_filter( 'rest_authentication_errors', array( self::class, 'maybe_require_rest_auth' ) );
		}

		if ( self::is_enabled( 'disable_wp_cron_frontend' ) && ! is_admin() ) {
			remove_action( 'init', 'wp_cron' );
		}

		if ( self::is_enabled( 'control_heartbeat_admin' ) || self::is_enabled( 'control_heartbeat_editor' ) ) {
			add_filter( 'heartbeat_settings', array( self::class, 'filter_heartbeat_settings' ) );
		}
		if ( self::is_enabled( 'add_preconnect_hints' ) ) {
			add_filter( 'wp_resource_hints', array( self::class, 'add_preconnect_resource_hints' ), 20, 2 );
		}
		if ( self::is_enabled( 'cache_anonymous_html' ) ) {
			add_filter( 'wp_headers', array( self::class, 'add_anonymous_cache_headers' ) );
		}
		if ( self::should_skip_current_request() ) {
			return;
		}

		if ( self::is_enabled( 'remove_head_noise' ) ) {
			self::remove_head_noise();
		}
		if ( self::is_enabled( 'remove_jqmigrate' ) ) {
			self::register_jquery_migrate_removal();
		}
		if ( self::is_enabled( 'remove_oembed' ) ) {
			self::remove_oembed_head();
		}
		if ( self::is_enabled( 'disable_trackbacks' ) ) {
			self::disable_trackbacks_and_xmlrpc();
		}
		if ( self::is_enabled( 'disable_emojis' ) ) {
			self::disable_emojis();
		}
		if ( self::is_enabled( 'remove_wp_embed' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_wp_embed' ), 100 );
		}
		if ( self::is_enabled( 'remove_dashicons_guests' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_dashicons_for_guests' ), 100 );
		}
		if ( self::is_enabled( 'remove_comment_reply' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue_comment_reply_when_unneeded' ), 100 );
		}
		if ( self::is_enabled( 'remove_asset_versions' ) ) {
			add_filter( 'script_loader_src', array( self::class, 'strip_asset_version_query' ), 20 );
			add_filter( 'style_loader_src', array( self::class, 'strip_asset_version_query' ), 20 );
		}
	}

	public static function detect_editor_screen( $screen ): void {
		if ( ! is_object( $screen ) || empty( $screen->id ) ) {
			self::$is_editor_screen = false;
			return;
		}

		$id = (string) $screen->id;
		self::$is_editor_screen = false !== strpos( $id, 'post' ) || false !== strpos( $id, 'site-editor' );
	}

	private static function should_skip_current_request(): bool {
		if ( is_admin() ) {
			return false;
		}

		$advanced = self::get_advanced_settings();
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$patterns = self::split_lines( $advanced['exclude_url_patterns'] );
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $uri, $pattern ) ) {
				return true;
			}
		}

		if ( is_singular() ) {
			$exclude_post_types = self::split_lines( $advanced['exclude_post_types'] );
			$post_type          = (string) get_post_type();
			if ( in_array( $post_type, $exclude_post_types, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register plugin row action links.
	 */
	private static function register_plugin_action_links(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( self::class, 'add_settings_action_link' )
		);
	}

	/**
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function add_settings_action_link( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$url = admin_url( 'options-general.php?page=greyhound-performance' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'greyhound-performance' ) . '</a>'
		);
		return $links;
	}

	private static function register_admin_settings(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_menu', array( self::class, 'register_settings_page' ) );
	}

	private static function register_admin_tools(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_post_greyhound_perf_export', array( self::class, 'handle_export_settings' ) );
		add_action( 'admin_post_greyhound_perf_import', array( self::class, 'handle_import_settings' ) );
		add_action( 'admin_post_greyhound_perf_run_cleanup', array( self::class, 'handle_run_cleanup' ) );
		add_action( 'admin_post_greyhound_perf_generate_mu_loader', array( self::class, 'handle_generate_mu_loader' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_show_conflict_notice' ) );
	}

	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_ADVANCED_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_advanced_settings' ),
				'default'           => self::default_advanced(),
			)
		);

		add_settings_section(
			'greyhound_perf_main_section',
			__( 'Feature Toggles', 'greyhound-performance' ),
			array( self::class, 'render_settings_section' ),
			'greyhound-performance'
		);

		foreach ( self::settings_schema() as $key => $config ) {
			add_settings_field(
				$key,
				$config['label'],
				array( self::class, 'render_checkbox_field' ),
				'greyhound-performance',
				'greyhound_perf_main_section',
				array(
					'key'         => $key,
					'description' => $config['description'],
					'impact'      => $config['impact'],
				)
			);
		}

		add_settings_section(
			'greyhound_perf_advanced_section',
			__( 'Advanced Controls', 'greyhound-performance' ),
			array( self::class, 'render_advanced_section' ),
			'greyhound-performance'
		);

		add_settings_field(
			'preset',
			__( 'Optimization preset', 'greyhound-performance' ),
			array( self::class, 'render_preset_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section'
		);
		add_settings_field(
			'heartbeat_admin_interval',
			__( 'Admin Heartbeat interval (seconds)', 'greyhound-performance' ),
			array( self::class, 'render_number_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section',
			array( 'key' => 'heartbeat_admin_interval', 'min' => '15', 'max' => '120' )
		);
		add_settings_field(
			'heartbeat_editor_interval',
			__( 'Editor Heartbeat interval (seconds)', 'greyhound-performance' ),
			array( self::class, 'render_number_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section',
			array( 'key' => 'heartbeat_editor_interval', 'min' => '15', 'max' => '120' )
		);
		add_settings_field(
			'html_cache_ttl',
			__( 'Anonymous HTML cache TTL (seconds)', 'greyhound-performance' ),
			array( self::class, 'render_number_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section',
			array( 'key' => 'html_cache_ttl', 'min' => '60', 'max' => '86400' )
		);
		add_settings_field(
			'preconnect_origins',
			__( 'Preconnect origins', 'greyhound-performance' ),
			array( self::class, 'render_textarea_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section',
			array(
				'key'         => 'preconnect_origins',
				'description' => __( 'One origin per line, example: https://fonts.gstatic.com', 'greyhound-performance' ),
			)
		);
		add_settings_field(
			'exclude_url_patterns',
			__( 'URL exclusion patterns', 'greyhound-performance' ),
			array( self::class, 'render_textarea_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section',
			array(
				'key'         => 'exclude_url_patterns',
				'description' => __( 'One partial URL match per line. Matching requests skip frontend optimizations.', 'greyhound-performance' ),
			)
		);
		add_settings_field(
			'exclude_post_types',
			__( 'Post type exclusions', 'greyhound-performance' ),
			array( self::class, 'render_textarea_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section',
			array(
				'key'         => 'exclude_post_types',
				'description' => __( 'One post type slug per line (for singular requests).', 'greyhound-performance' ),
			)
		);
		add_settings_field(
			'security_headers',
			__( 'Security headers', 'greyhound-performance' ),
			array( self::class, 'render_textarea_field' ),
			'greyhound-performance',
			'greyhound_perf_advanced_section',
			array(
				'key'         => 'security_headers',
				'description' => __( 'One header per line, format: Header-Name: value', 'greyhound-performance' ),
			)
		);
	}

	/**
	 * @param mixed $input Raw setting payload.
	 * @return array<string, bool>
	 */
	public static function sanitize_settings( $input ): array {
		$defaults = self::default_settings();
		$input    = is_array( $input ) ? $input : array();

		foreach ( $defaults as $key => $enabled ) {
			$defaults[ $key ] = isset( $input[ $key ] ) && '1' === (string) $input[ $key ];
		}

		return self::apply_preset_to_settings( $defaults );
	}

	/**
	 * @param mixed $input Raw setting payload.
	 * @return array<string, string>
	 */
	public static function sanitize_advanced_settings( $input ): array {
		$defaults = self::default_advanced();
		$input    = is_array( $input ) ? $input : array();

		$preset = isset( $input['preset'] ) ? sanitize_key( (string) $input['preset'] ) : $defaults['preset'];
		if ( ! in_array( $preset, array( 'conservative', 'balanced', 'aggressive' ), true ) ) {
			$preset = 'balanced';
		}
		$defaults['preset'] = $preset;
		$defaults['heartbeat_admin_interval']  = (string) min( 120, max( 15, absint( $input['heartbeat_admin_interval'] ?? $defaults['heartbeat_admin_interval'] ) ) );
		$defaults['heartbeat_editor_interval'] = (string) min( 120, max( 15, absint( $input['heartbeat_editor_interval'] ?? $defaults['heartbeat_editor_interval'] ) ) );
		$defaults['html_cache_ttl']            = (string) min( 86400, max( 60, absint( $input['html_cache_ttl'] ?? $defaults['html_cache_ttl'] ) ) );
		$defaults['preconnect_origins']        = sanitize_textarea_field( (string) ( $input['preconnect_origins'] ?? '' ) );
		$defaults['exclude_url_patterns']      = sanitize_textarea_field( (string) ( $input['exclude_url_patterns'] ?? '' ) );
		$defaults['exclude_post_types']        = sanitize_textarea_field( (string) ( $input['exclude_post_types'] ?? '' ) );
		$defaults['security_headers']          = sanitize_textarea_field( (string) ( $input['security_headers'] ?? '' ) );
		$defaults['import_json']               = '';
		return $defaults;
	}

	private static function apply_preset_to_settings( array $settings ): array {
		$advanced = self::get_advanced_settings();
		$preset   = $advanced['preset'];

		if ( 'conservative' === $preset ) {
			$settings['remove_asset_versions'] = false;
			$settings['rest_require_auth']     = false;
			$settings['cache_anonymous_html']  = false;
			return $settings;
		}

		if ( 'aggressive' === $preset ) {
			$settings['remove_asset_versions']    = true;
			$settings['remove_wp_embed']          = true;
			$settings['remove_dashicons_guests']  = true;
			$settings['remove_comment_reply']     = true;
			$settings['rest_user_hardening']      = true;
			$settings['add_preconnect_hints']     = true;
			return $settings;
		}

		return $settings;
	}

	public static function render_settings_section(): void {
		echo '<p>' . esc_html__( 'Toggle each feature and review its impact badge before enabling on production.', 'greyhound-performance' ) . '</p>';
	}

	public static function render_advanced_section(): void {
		echo '<p>' . esc_html__( 'Advanced controls for heartbeat, exclusions, presets, and security headers.', 'greyhound-performance' ) . '</p>';
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function render_checkbox_field( array $args ): void {
		$key      = isset( $args['key'] ) ? (string) $args['key'] : '';
		$settings = self::get_settings();
		$checked  = ! empty( $settings[ $key ] );
		$id       = 'greyhound_perf_' . $key;

		echo '<label for="' . esc_attr( $id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="0" />';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="1" ' . checked( $checked, true, false ) . ' />';
		echo ' ' . esc_html__( 'Enabled', 'greyhound-performance' );
		echo '</label>';

		if ( ! empty( $args['impact'] ) ) {
			echo '<span style="margin-left:8px;padding:2px 6px;background:#f0f0f1;border-radius:3px;font-size:12px;">' . esc_html( $args['impact'] ) . '</span>';
		}
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	public static function render_preset_field(): void {
		$advanced = self::get_advanced_settings();
		$value    = $advanced['preset'];
		echo '<select name="' . esc_attr( self::OPTION_ADVANCED_KEY ) . '[preset]">';
		echo '<option value="conservative" ' . selected( $value, 'conservative', false ) . '>' . esc_html__( 'Conservative', 'greyhound-performance' ) . '</option>';
		echo '<option value="balanced" ' . selected( $value, 'balanced', false ) . '>' . esc_html__( 'Balanced', 'greyhound-performance' ) . '</option>';
		echo '<option value="aggressive" ' . selected( $value, 'aggressive', false ) . '>' . esc_html__( 'Aggressive', 'greyhound-performance' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Presets adjust baseline defaults for risk-sensitive toggles.', 'greyhound-performance' ) . '</p>';
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function render_number_field( array $args ): void {
		$key      = isset( $args['key'] ) ? (string) $args['key'] : '';
		$advanced = self::get_advanced_settings();
		$value    = isset( $advanced[ $key ] ) ? $advanced[ $key ] : '';
		$min      = isset( $args['min'] ) ? $args['min'] : '1';
		$max      = isset( $args['max'] ) ? $args['max'] : '999';
		echo '<input type="number" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" name="' . esc_attr( self::OPTION_ADVANCED_KEY ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" />';
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function render_textarea_field( array $args ): void {
		$key      = isset( $args['key'] ) ? (string) $args['key'] : '';
		$advanced = self::get_advanced_settings();
		$value    = isset( $advanced[ $key ] ) ? $advanced[ $key ] : '';
		echo '<textarea rows="5" cols="70" name="' . esc_attr( self::OPTION_ADVANCED_KEY ) . '[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	public static function register_settings_page(): void {
		add_options_page(
			__( 'Greyhound Performance', 'greyhound-performance' ),
			__( 'Greyhound Performance', 'greyhound-performance' ),
			'manage_options',
			'greyhound-performance',
			array( self::class, 'render_settings_page' )
		);
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		settings_errors( 'greyhound-performance' );
		$diagnostics = self::get_diagnostics();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Greyhound Performance', 'greyhound-performance' ); ?></h1>
			<p><?php echo esc_html__( 'Performance + hardening controls with diagnostics and maintenance utilities.', 'greyhound-performance' ); ?></p>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( 'greyhound-performance' );
				submit_button( __( 'Save settings', 'greyhound-performance' ) );
				?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Diagnostics', 'greyhound-performance' ); ?></h2>
			<ul>
				<li><?php echo esc_html( sprintf( 'Active toggles: %d', (int) $diagnostics['active_toggles'] ) ); ?></li>
				<li><?php echo esc_html( sprintf( 'Potential plugin conflicts: %d', (int) count( $diagnostics['conflicts'] ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( 'Due cron jobs: %d', (int) $diagnostics['due_cron_jobs'] ) ); ?></li>
				<li><?php echo esc_html( sprintf( 'Transients in options table: %d', (int) $diagnostics['transient_rows'] ) ); ?></li>
				<li><?php echo esc_html( sprintf( 'Post revisions: %d', (int) $diagnostics['revisions'] ) ); ?></li>
			</ul>
			<?php if ( ! empty( $diagnostics['conflicts'] ) ) : ?>
				<p><strong><?php echo esc_html__( 'Detected overlap:', 'greyhound-performance' ); ?></strong> <?php echo esc_html( implode( ', ', $diagnostics['conflicts'] ) ); ?></p>
			<?php endif; ?>

			<hr />
			<h2><?php echo esc_html__( 'Tools', 'greyhound-performance' ); ?></h2>
			<p><?php echo esc_html__( 'Server cron recommendation:', 'greyhound-performance' ); ?> <code>*/5 * * * * php /path/to/wp-cron.php</code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
				<?php wp_nonce_field( 'greyhound_perf_export' ); ?>
				<input type="hidden" name="action" value="greyhound_perf_export" />
				<?php submit_button( __( 'Export settings JSON', 'greyhound-performance' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
				<?php wp_nonce_field( 'greyhound_perf_import' ); ?>
				<input type="hidden" name="action" value="greyhound_perf_import" />
				<textarea rows="6" cols="70" name="import_json" placeholder="{...}"></textarea><br />
				<?php submit_button( __( 'Import settings JSON', 'greyhound-performance' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
				<?php wp_nonce_field( 'greyhound_perf_cleanup' ); ?>
				<input type="hidden" name="action" value="greyhound_perf_run_cleanup" />
				<?php submit_button( __( 'Run database cleanup now', 'greyhound-performance' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'greyhound_perf_mu_loader' ); ?>
				<input type="hidden" name="action" value="greyhound_perf_generate_mu_loader" />
				<?php submit_button( __( 'Generate MU-plugin loader', 'greyhound-performance' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_export_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'greyhound-performance' ) );
		}
		check_admin_referer( 'greyhound_perf_export' );

		$data = array(
			'settings' => self::get_settings(),
			'advanced' => self::get_advanced_settings(),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=greyhound-performance-settings.json' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT );
		exit;
	}

	public static function handle_import_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'greyhound-performance' ) );
		}
		check_admin_referer( 'greyhound_perf_import' );
		$json = isset( $_POST['import_json'] ) ? (string) wp_unslash( $_POST['import_json'] ) : '';
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['settings'] ) || empty( $data['advanced'] ) ) {
			add_settings_error( 'greyhound-performance', 'greyhound-import', __( 'Import failed: invalid JSON payload.', 'greyhound-performance' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'options-general.php?page=greyhound-performance' ) );
			exit;
		}

		update_option( self::OPTION_KEY, self::sanitize_settings( $data['settings'] ) );
		update_option( self::OPTION_ADVANCED_KEY, self::sanitize_advanced_settings( $data['advanced'] ) );
		add_settings_error( 'greyhound-performance', 'greyhound-import-ok', __( 'Settings imported.', 'greyhound-performance' ), 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'options-general.php?page=greyhound-performance' ) );
		exit;
	}

	public static function handle_run_cleanup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'greyhound-performance' ) );
		}
		check_admin_referer( 'greyhound_perf_cleanup' );
		$counts = self::run_cleanup();
		$msg    = sprintf(
			/* translators: 1: revisions, 2: transients */
			__( 'Cleanup complete. Removed revisions: %1$d, transients: %2$d.', 'greyhound-performance' ),
			(int) $counts['revisions'],
			(int) $counts['transients']
		);
		add_settings_error( 'greyhound-performance', 'greyhound-cleanup-ok', $msg, 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'options-general.php?page=greyhound-performance' ) );
		exit;
	}

	public static function handle_generate_mu_loader(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'greyhound-performance' ) );
		}
		check_admin_referer( 'greyhound_perf_mu_loader' );

		$dir = WPMU_PLUGIN_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$loader_file = trailingslashit( $dir ) . 'greyhound-performance-loader.php';
		$content     = "<?php\n/**\n * Greyhound MU loader.\n */\nif ( file_exists( WP_PLUGIN_DIR . '/greyhound-performance/greyhound-performance.php' ) ) {\n\trequire_once WP_PLUGIN_DIR . '/greyhound-performance/greyhound-performance.php';\n}\n";
		file_put_contents( $loader_file, $content );

		add_settings_error( 'greyhound-performance', 'greyhound-mu-ok', __( 'MU loader generated.', 'greyhound-performance' ), 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'options-general.php?page=greyhound-performance' ) );
		exit;
	}

	private static function get_diagnostics(): array {
		global $wpdb;
		$settings = self::get_settings();
		$active   = 0;
		foreach ( $settings as $enabled ) {
			if ( $enabled ) {
				++$active;
			}
		}

		$cron = _get_cron_array();
		$due  = 0;
		if ( is_array( $cron ) ) {
			$now = time();
			foreach ( array_keys( $cron ) as $timestamp ) {
				if ( (int) $timestamp <= $now ) {
					$due += count( $cron[ $timestamp ] );
				}
			}
		}

		$revisions      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$transient_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );

		return array(
			'active_toggles' => $active,
			'conflicts'      => self::detect_conflicts(),
			'due_cron_jobs'  => $due,
			'revisions'      => $revisions,
			'transient_rows' => $transient_rows,
		);
	}

	/**
	 * @return array<string, int>
	 */
	private static function run_cleanup(): array {
		global $wpdb;

		$revisions = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$transient = (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'" );
		return array(
			'revisions'  => max( 0, $revisions ),
			'transients' => max( 0, $transient ),
		);
	}

	public static function maybe_show_conflict_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || 'settings_page_greyhound-performance' !== $screen->id ) {
			return;
		}

		$conflicts = self::detect_conflicts();
		if ( empty( $conflicts ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Potential optimization overlap detected:', 'greyhound-performance' ) . '</strong> ' . esc_html( implode( ', ', $conflicts ) ) . '</p></div>';
	}

	/**
	 * @return string[]
	 */
	private static function detect_conflicts(): array {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$known          = array(
			'autoptimize/autoptimize.php'       => 'Autoptimize',
			'wp-rocket/wp-rocket.php'           => 'WP Rocket',
			'perfmatters/perfmatters.php'       => 'Perfmatters',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		);

		$found = array();
		foreach ( $known as $file => $label ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$found[] = $label;
			}
		}

		return $found;
	}

	/**
	 * Remove low-value or fingerprinting output from wp_head.
	 */
	private static function remove_head_noise(): void {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );

		if (
			apply_filters(
				'greyhound_perf_remove_feed_head_links',
				apply_filters( 'greyhound_perf_remove_feed_head_links_legacy', true )
			)
		) {
			remove_action( 'wp_head', 'feed_links', 2 );
		}

		remove_action( 'wp_head', 'start_post_rel_link' );
		remove_action( 'wp_head', 'index_rel_link' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
	}

	public static function dequeue_wp_embed(): void {
		wp_dequeue_script( 'wp-embed' );
	}

	public static function dequeue_dashicons_for_guests(): void {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	public static function dequeue_comment_reply_when_unneeded(): void {
		if ( is_admin() ) {
			return;
		}
		$should_keep = is_singular() && comments_open() && get_option( 'thread_comments' );
		if ( ! $should_keep ) {
			wp_dequeue_script( 'comment-reply' );
		}
	}

	public static function strip_asset_version_query( string $src ): string {
		if ( is_admin() ) {
			return $src;
		}
		return (string) remove_query_arg( 'ver', $src );
	}

	/**
	 * Drop jquery-migrate on the front end only (admin/editor may still need it).
	 */
	private static function register_jquery_migrate_removal(): void {
		add_action( 'wp_default_scripts', array( self::class, 'remove_jquery_migrate_frontend' ), 20 );
	}

	/**
	 * @param WP_Scripts $scripts WP_Scripts instance.
	 */
	public static function remove_jquery_migrate_frontend( $scripts ): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}

		$script = $scripts->registered['jquery'];
		if ( empty( $script->deps ) || ! is_array( $script->deps ) ) {
			return;
		}

		$script->deps = array_values(
			array_diff( $script->deps, array( 'jquery-migrate' ) )
		);
	}

	/**
	 * Remove oEmbed discovery and related hooks (saves requests; embed blocks still work for remote URLs in many cases).
	 */
	private static function remove_oembed_head(): void {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
	}

	/**
	 * Reduce pingback / XML-RPC attack surface.
	 */
	private static function disable_trackbacks_and_xmlrpc(): void {
		add_action( 'pre_ping', array( self::class, 'strip_internal_ping_links' ) );
		add_filter( 'wp_headers', array( self::class, 'remove_x_pingback_header' ) );
		add_filter( 'bloginfo_url', array( self::class, 'strip_pingback_url' ), 10, 2 );
		add_filter( 'bloginfo', array( self::class, 'strip_pingback_url' ), 10, 2 );

		if (
			apply_filters(
				'greyhound_perf_disable_xmlrpc',
				apply_filters( 'greyhound_perf_disable_xmlrpc_legacy', true )
			)
		) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}

		add_filter( 'xmlrpc_methods', array( self::class, 'remove_xmlrpc_pingback_method' ) );
	}

	public static function maybe_require_rest_auth( $result ) {
		if ( ! self::is_enabled( 'rest_require_auth' ) ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return $result;
		}

		return new WP_Error(
			'greyhound_rest_auth_required',
			__( 'Authentication required for REST API access.', 'greyhound-performance' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * @param array<string, mixed> $endpoints
	 * @return array<string, mixed>
	 */
	public static function filter_rest_endpoints( array $endpoints ): array {
		if ( ! self::is_enabled( 'rest_user_hardening' ) || is_user_logged_in() ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\\d]+)'] );
		return $endpoints;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function filter_heartbeat_settings( array $settings ): array {
		$advanced = self::get_advanced_settings();

		if ( self::$is_editor_screen && self::is_enabled( 'control_heartbeat_editor' ) ) {
			$settings['interval'] = (int) $advanced['heartbeat_editor_interval'];
			return $settings;
		}

		if ( self::is_enabled( 'control_heartbeat_admin' ) ) {
			$settings['interval'] = (int) $advanced['heartbeat_admin_interval'];
		}

		return $settings;
	}

	/**
	 * Add preconnect hints from configured origins.
	 *
	 * @param string[] $urls Existing hint URLs.
	 * @param string   $relation_type Hint relation type.
	 * @return string[]
	 */
	public static function add_preconnect_resource_hints( $urls, string $relation_type ): array {
		if ( 'preconnect' !== $relation_type || ! is_array( $urls ) ) {
			return is_array( $urls ) ? $urls : array();
		}

		$advanced = self::get_advanced_settings();
		$origins  = self::split_lines( $advanced['preconnect_origins'] );
		foreach ( $origins as $origin ) {
			$origin = trim( $origin );
			if ( '' === $origin ) {
				continue;
			}
			$urls[] = $origin;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Add cache headers for anonymous frontend HTML responses.
	 *
	 * @param array<string, string> $headers Existing headers.
	 * @return array<string, string>
	 */
	public static function add_anonymous_cache_headers( $headers ): array {
		if ( ! is_array( $headers ) ) {
			return array();
		}
		if ( is_admin() || is_user_logged_in() ) {
			return $headers;
		}
		if ( ! empty( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return $headers;
		}
		if ( is_feed() || is_search() || is_404() || is_preview() ) {
			return $headers;
		}

		$advanced = self::get_advanced_settings();
		$ttl      = max( 60, absint( $advanced['html_cache_ttl'] ) );
		$expires  = gmdate( 'D, d M Y H:i:s', time() + $ttl ) . ' GMT';

		$headers['Cache-Control'] = 'public, max-age=' . $ttl . ', s-maxage=' . $ttl;
		$headers['Expires']       = $expires;
		unset( $headers['Pragma'] );
		return $headers;
	}

	public static function send_security_headers(): void {
		$advanced = self::get_advanced_settings();
		$lines    = self::split_lines( $advanced['security_headers'] );

		foreach ( $lines as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' === $name || '' === $value ) {
				continue;
			}

			header( $name . ': ' . $value );
		}
	}

	/**
	 * @return string[]
	 */
	private static function split_lines( string $value ): array {
		$parts = preg_split( '/\r\n|\r|\n/', $value );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$clean = array();
		foreach ( $parts as $part ) {
			$line = trim( (string) $part );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}
		return $clean;
	}

	/**
	 * Remove self-references from URLs about to be pinged (avoids internal pingbacks).
	 *
	 * @param string[] $post_links URLs to ping (passed by reference; first arg of `pre_ping`).
	 */
	public static function strip_internal_ping_links( &$post_links ): void {
		if ( ! is_array( $post_links ) ) {
			return;
		}

		$home = (string) get_option( 'home', '' );
		if ( '' === $home ) {
			return;
		}

		foreach ( $post_links as $index => $link ) {
			if ( ! is_string( $link ) ) {
				continue;
			}
			if ( 0 === strpos( $link, $home ) ) {
				unset( $post_links[ $index ] );
			}
		}
		$post_links = array_values( $post_links );
	}

	/**
	 * @param string[] $headers Response headers.
	 * @return string[]
	 */
	public static function remove_x_pingback_header( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}
		unset( $headers['X-Pingback'], $headers['x-pingback'] );
		return $headers;
	}

	/**
	 * @param mixed  $output Filtered output.
	 * @param string $show   bloginfo key.
	 * @return mixed
	 */
	public static function strip_pingback_url( $output, string $show = '' ) {
		if ( 'pingback_url' === $show ) {
			return '';
		}
		return $output;
	}

	/**
	 * @param string[] $methods XML-RPC methods.
	 * @return string[]
	 */
	public static function remove_xmlrpc_pingback_method( $methods ): array {
		if ( ! is_array( $methods ) ) {
			return array();
		}
		unset( $methods['pingback.ping'] );
		return $methods;
	}

	/**
	 * Disable emoji scripts, styles, TinyMCE plugin, and DNS prefetch for emoji CDN.
	 */
	private static function disable_emojis(): void {
		add_action( 'init', array( self::class, 'remove_emoji_hooks' ), 1 );
		add_filter( 'tiny_mce_plugins', array( self::class, 'disable_emojis_tinymce' ) );
		add_filter( 'wp_resource_hints', array( self::class, 'disable_emojis_dns_prefetch' ), 10, 2 );
	}

	public static function remove_emoji_hooks(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}

	/**
	 * @param mixed $plugins TinyMCE plugin list.
	 * @return string[]
	 */
	public static function disable_emojis_tinymce( $plugins ): array {
		if ( ! is_array( $plugins ) ) {
			return array();
		}
		return array_values( array_diff( $plugins, array( 'wpemoji' ) ) );
	}

	/**
	 * @param string[]       $urls          URLs to hint.
	 * @param string         $relation_type Relation type.
	 * @return string[]|mixed
	 */
	public static function disable_emojis_dns_prefetch( $urls, string $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}

		/** This filter is documented in wp-includes/formatting.php */
		$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );

		return array_values( array_diff( $urls, array( $emoji_svg_url ) ) );
	}
}

/*
 * Optional: force the classic editor (uncomment in a child plugin or here if required).
 *
 * add_filter( 'use_block_editor_for_post', '__return_false' );
 */
