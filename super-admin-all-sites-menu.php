<?php
/**
 * Super Admin All Sites Menu
 *
 * @package     Soderlind\Multisite
 * @author      Per Soderlind
 * @copyright   2021 Per Soderlind
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: Super Admin All Sites Menu
 * Plugin URI: https://github.com/soderlind/super-admin-all-sites-menu
 * GitHub Plugin URI: https://github.com/soderlind/super-admin-all-sites-menu
 * Description: For the super admin, replace WP Admin Bar My Sites menu with an All Sites menu.
 * Version:     1.8.5
 * Author:      Per Soderlind
 * Network:     true
 * Author URI:  https://soderlind.no
 * Text Domain: super-admin-all-sites-menu
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

declare(strict_types=1);
namespace Soderlind\Multisite;

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

// Configuration constants
final class Config {
	public const LOAD_INCREMENTS  = 100;
	public const SEARCH_THRESHOLD = 20;
	public const CACHE_EXPIRATION = DAY_IN_SECONDS;
	public const ORDER_BY         = 'name';
	public const PLUGINS          = [ 'restricted-site-access/restricted_site_access.php' ];
	public const REST_NAMESPACE   = 'super-admin-all-sites-menu/v1';
	public const REST_BASE        = 'sites';
	public const REST_ENDPOINT    = self::REST_NAMESPACE . '/' . self::REST_BASE;
}

/**
 * Super Admin All Sites Menu main class
 */
final class SuperAdminAllSitesMenu {
	private int $number_of_sites = 0;

	public function __construct(
		private int $load_increments = Config::LOAD_INCREMENTS,
		private array $plugins = Config::PLUGINS,
		private string $order_by = Config::ORDER_BY,
		private int $search_threshold = Config::SEARCH_THRESHOLD,
		private int $cache_expiration = Config::CACHE_EXPIRATION
	) {}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {

		// Only for super admins and REST API requests.
		if ( ! is_super_admin() && ! wp_is_rest_endpoint() ) {
			return;
		}

		// Only for multisite.
		if ( ! is_multisite() ) {
			return;
		}

		// Only for REST API requests to the correct endpoint.
		if ( wp_is_rest_endpoint() && false === strpos( get_rest_url(), Config::REST_ENDPOINT ) ) {
			return;
		}

		$this->set_properties();
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'admin_bar_init', [ $this, 'action_admin_bar_init' ] );
		add_action( 'rest_api_init', [ $this, 'action_rest_api_init' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

		// Site changes
		add_action( 'wp_insert_site', [ $this, 'update_local_storage' ] );
		add_action( 'wp_update_site', [ $this, 'update_local_storage' ] );
		add_action( 'wp_delete_site', [ $this, 'update_local_storage' ] );
		add_action( 'update_option_blogname', [ $this, 'action_update_option_blogname' ], 10, 3 );

		// Plugin activation/deactivation
		add_action( 'activated_plugin', [ $this, 'plugin_update_local_storage' ] );
		add_action( 'deactivated_plugin', [ $this, 'plugin_update_local_storage' ] );
	}

	/**
	 * Init admin bar values.
	 *
	 * @return void
	 */
	public function action_admin_bar_init(): void {
		load_plugin_textdomain( 'super-admin-all-sites-menu', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		if ( \is_super_admin() ) {
			add_action( 'add_admin_bar_menus', [ $this, 'action_add_admin_bar_menus' ] );
			add_action( 'admin_bar_menu', [ $this, 'super_admin_all_sites_menu' ], 25 );

			add_action( 'admin_enqueue_scripts', [ $this, 'action_enqueue_scripts' ] );
			add_action( 'wp_enqueue_scripts', [ $this, 'action_enqueue_scripts' ] );
		}

		$this->number_of_sites = $this->get_number_of_sites();
	}

	/**
	 * Set properties.
	 *
	 * @return void
	 */
	public function set_properties(): void {
		$this->plugins = \apply_filters( 'all_sites_menu_plugin_trigger', Config::PLUGINS );
		if ( ! is_array( $this->plugins ) ) {
			$this->plugins = Config::PLUGINS;
		}

		$this->order_by = \apply_filters( 'all_sites_menu_order_by', Config::ORDER_BY );
		if ( ! in_array( $this->order_by, [ 'name', 'url', 'id' ], true ) ) {
			$this->order_by = Config::ORDER_BY;
		}

		$this->load_increments = \apply_filters( 'all_sites_menu_load_increments', Config::LOAD_INCREMENTS );
		if ( ! is_numeric( $this->load_increments ) || $this->load_increments < 1 ) {
			$this->load_increments = Config::LOAD_INCREMENTS;
		}

		$this->search_threshold = \apply_filters( 'all_sites_menu_search_threshold', Config::SEARCH_THRESHOLD );
		if ( ! is_numeric( $this->search_threshold ) || $this->search_threshold < 1 ) {
			$this->search_threshold = Config::SEARCH_THRESHOLD;
		}
		$this->cache_expiration = \apply_filters( 'all_sites_menu_force_refresh_expiration', Config::CACHE_EXPIRATION );
		if ( ! is_numeric( $this->search_threshold ) || $this->cache_expiration < 0 ) {
			$this->cache_expiration = Config::CACHE_EXPIRATION;
		}
	}

	/**
	 * Remove the default WP Admin Bar My Sites menu.
	 *
	 * @return void
	 */
	public function action_add_admin_bar_menus(): void {
		remove_action( 'admin_bar_menu', 'wp_admin_bar_my_sites_menu', 20 );
	}

	/**
	 * Add the "All Sites/[Site Name]" menu and all submenus, listing all subsites.
	 *
	 * Essentially the same as the WP native one but doesn't use switch_to_blog();
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar object.
	 * @return void
	 */
	public function super_admin_all_sites_menu( \WP_Admin_Bar $wp_admin_bar ): void {
		$my_sites_url = \admin_url( '/my-sites.php' );
		$wp_admin_bar->add_menu(
			[ 
				'id'    => 'my-sites',
				'title' => __( 'All Sites', 'super-admin-all-sites-menu' ),
				'href'  => $my_sites_url,
			]
		);

		$wp_admin_bar->add_group(
			[ 
				'parent' => 'my-sites',
				'id'     => 'my-sites-super-admin',
			]
		);

		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'my-sites-super-admin',
				'id'     => 'network-admin',
				'title'  => __( 'Network Admin', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url(),
			]
		);

		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'network-admin',
				'id'     => 'network-admin-d',
				'title'  => __( 'Dashboard', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url(),
			]
		);
		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'network-admin',
				'id'     => 'network-admin-s',
				'title'  => __( 'Sites', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url( '/sites.php' ),
			]
		);
		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'network-admin',
				'id'     => 'network-admin-n',
				'title'  => __( 'Add New Site', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url( '/site-new.php' ),
			]
		);
		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'network-admin',
				'id'     => 'network-admin-u',
				'title'  => __( 'Users', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url( '/users.php' ),
			]
		);
		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'network-admin',
				'id'     => 'network-admin-t',
				'title'  => __( 'Themes', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url( '/themes.php' ),
			]
		);
		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'network-admin',
				'id'     => 'network-admin-p',
				'title'  => __( 'Plugins', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url( '/plugins.php' ),
			]
		);
		$wp_admin_bar->add_menu(
			[ 
				'parent' => 'network-admin',
				'id'     => 'network-admin-o',
				'title'  => __( 'Settings', 'super-admin-all-sites-menu' ),
				'href'   => \network_admin_url( '/settings.php' ),
			]
		);

		// Add site links.
		$wp_admin_bar->add_group(
			[ 
				'parent' => 'my-sites',
				'id'     => 'my-sites-list',
				'meta'   => [ 
					'class' => 'ab-sub-secondary my-sites-container',
				],
			]
		);

		if ( $this->number_of_sites > $this->search_threshold ) {
			// Add search field.
			$wp_admin_bar->add_menu(
				[ 
					'parent' => 'my-sites-list',
					'id'     => 'all-sites-search',
					'title'  => sprintf(
						'<label for="all-sites-search-text">%s</label><input type="text" id="all-sites-search-text" placeholder="%s" />',
						esc_html__( 'Filter My Sites', 'super-admin-all-sites-menu' ),
						esc_attr__( 'Search Sites', 'super-admin-all-sites-menu' )
					),
					'meta'   => [ 
						'class' => 'hide-if-no-js',
					],
				]
			);
		}

		// Add an observable container, used by the IntersectionObserver in src/modules/observe.js.
		$timestamp = $this->get_timestamp();
		$wp_admin_bar->add_menu(
			[ 
				'id'     => 'load-more',
				'parent' => 'my-sites-list',
				'title'  => __( 'Loading..', 'super-admin-all-sites-menu' ),
				'meta'   => [ 
					'html'     => sprintf( '<span id="load-more-timestamp" data-timestamp="%s"></span>', $timestamp ),
					'class'    => 'load-more hide-if-no-js',
					'tabindex' => -1,
				],
			]
		);

	}

	/**
	 * Register REST route.
	 *
	 * @param \WP_REST_Server $wp_rest_server Server object.
	 */
	public function action_rest_api_init( \WP_REST_Server $wp_rest_server ): void {
		$is_route_created = register_rest_route(
			Config::REST_NAMESPACE,
			'/' . Config::REST_BASE,
			[ 
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'get_sites' ],
				'permission_callback' => [ $this, 'get_sites_permissions_check' ],
				'args'                => [ 
					'offset' => [ 
						'validate_callback' => function ($param, $request, $key) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

	}

	/**
	 * Do the permissions check.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool|\WP_Error
	 */
	public function get_sites_permissions_check( \WP_REST_Request $request ): bool {
		return current_user_can( 'manage_network' );
	}

	/**
	 * Get sites.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array
	 */
	public function get_sites( \WP_REST_Request $request ): array {

		$params = $request->get_params();
		header( 'Content-type: application/json' );
		$offset = ( isset( $params[ 'offset' ] ) ) ? filter_var( wp_unslash( $params[ 'offset' ] ), FILTER_VALIDATE_INT, [ 'default' => 0 ] ) : 0;

		$sites     = \get_sites(
			[ 
				'orderby'  => 'path',
				'number'   => $this->load_increments,
				'offset'   => $offset,
				'deleted'  => '0',
				'mature'   => '0',
				'archived' => '0',
				'spam'     => '0',
			]
		);
		$menu      = [];
		$timestamp = $this->get_timestamp();
		foreach ( (array) $sites as $site ) {

			$blogid   = $site->blog_id;
			$blogname = $site->__get( 'blogname' );
			$menu_id  = 'blog-' . $blogid;
			$blavatar = '<div class="blavatar"></div>';
			$siteurl  = $site->__get( 'siteurl' );
			$adminurl = $siteurl . '/wp-admin';

			if ( ! $blogname ) {
				$blogname = preg_replace( '#^(https?://)?(www.)?#', '', $siteurl );
			}

			// The $site->public value is set to 2, by the Restricted Site Access plugin, when a site has restricted access.
			if ( 2 === (int) $site->public ) {
				$blavatar = '<div class="blavatar" style="color:#f00;"></div>';
			}
			$menu[] = [ 
				'parent'    => 'my-sites-list',
				'id'        => $menu_id,
				'name'      => strtoupper( $blogname ), // Index in local storage.
				'title'     => $blavatar . $blogname,
				'admin'     => $adminurl,
				'url'       => $siteurl,
				'timestamp' => $timestamp,
			];
		}

		if ( [] !== $menu ) {
			$response[ 'response' ] = 'success';
			$response[ 'data' ]     = $menu;
		} else {
			$response[ 'response' ] = 'unobserve';
			$response[ 'data' ]     = '';
		}

		return $response;
	}

	/**
	 * Enqueue scripts for all admin pages.
	 *
	 * @param string $hook_suffix The current admin page.
	 * @return void
	 */
	public function action_enqueue_scripts( string $hook_suffix ): void {

		$deps_file = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

		$jsdeps  = [ 'admin-bar' ];
		$version = wp_rand();
		if ( file_exists( $deps_file ) ) {
			$file    = require $deps_file;
			$jsdeps  = array_merge( $jsdeps, $file[ 'dependencies' ] );
			$version = $file[ 'version' ];
		}
		wp_register_style( 'super-admin-all-sites-menu', plugin_dir_url( __FILE__ ) . 'css/all-sites-menu.css', [], $version );
		wp_enqueue_style( 'super-admin-all-sites-menu' );

		wp_register_script( 'super-admin-all-sites-menu', plugin_dir_url( __FILE__ ) . 'build/index.js', $jsdeps, $version, true );
		wp_enqueue_script( 'super-admin-all-sites-menu' );

		$data = wp_json_encode(
			[ 
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'restURL'        => rest_url() . Config::REST_ENDPOINT,
				'loadincrements' => $this->load_increments,
				'orderBy'        => $this->order_by,
				'displaySearch'  => ( $this->number_of_sites > $this->search_threshold ) ? true : false,
				'timestamp'      => $this->get_timestamp(),
			]
		);

		wp_add_inline_script( 'super-admin-all-sites-menu', "const pluginAllSitesMenu = {$data};", 'before' );
		wp_set_script_translations( 'super-admin-all-sites-menu', 'super-admin-all-sites-menu' );
	}

	/**
	 * Fires after a site has been added to or deleted from the database.
	 *
	 * @param \WP_Site $site Site object.
	 * @return void
	 */
	public function update_local_storage( \WP_Site $site ): void {
		$this->refresh_local_storage();
	}

	/**
	 * Fires after a plugin is activated/deactivated.
	 *
	 * @param string $plugin       Path to the plugin file relative to the plugins directory.
	 * @return void                           or just the current site. Multisite only. Default false.
	 */
	public function plugin_update_local_storage( string $plugin ): void {
		if ( in_array( $plugin, $this->plugins, true ) ) {
			$this->refresh_local_storage();
		}
	}

	/**
	 * Fires after a blog is renamed.
	 *
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value     The new option value.
	 * @param string $option    Option name.
	 * @return void
	 */
	public function action_update_option_blogname( $old_value, $value, string $option ): void {
		if ( $old_value !== $value ) {
			$this->refresh_local_storage();
		}
	}

	/**
	 * When options allsitemenurefresh is true, refresh the local storage.
	 *
	 * @return void
	 */
	public function refresh_local_storage(): void {
		$this->remove_timestamp();
	}

	/**
	 * Remove site option when plugin is deactivated.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->remove_timestamp();
	}

	/**
	 * Get number of sites.
	 *
	 * @return integer
	 */
	private function get_number_of_sites(): int {
		$network_id = get_current_network_id();

		$args = [ 
			'network_id'    => $network_id,
			'number'        => 1,
			'fields'        => 'ids',
			'no_found_rows' => false,
		];

		$q = new \WP_Site_Query( $args );
		return $q->found_sites;
	}

	/**
	 * Get the timestamp.
	 *
	 * @return string
	 */
	private function get_timestamp(): string {
		$timestamp = get_site_transient( 'allsitemenutimestamp' );
		if ( ! $timestamp ) {
			$timestamp = (string) time();
			set_site_transient( 'allsitemenutimestamp', $timestamp, $this->cache_expiration );
		}
		return $timestamp;
	}

	/**
	 * Force a refresh by deleting the timestamp. Also used when plugin is deactivated.
	 *
	 * @return void
	 */
	private function remove_timestamp(): void {
		delete_site_transient( 'allsitemenutimestamp' );
	}

}

add_action( 'plugins_loaded', function () {
	// Initialize plugin
	( new SuperAdminAllSitesMenu() )->init();
} );

