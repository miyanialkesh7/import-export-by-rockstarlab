<?php
/**
 * Main App Class
 *
 * Singleton class for plugin initialization and global access.
 *
 * @package RockStarLab\ImportExport
 */

namespace RockStarLab\ImportExport;

defined( 'ABSPATH' ) or exit;

/**
 * Singleton that boots the plugin and gives access to its models and controllers.
 */
class App {

	/**
	 * The single App instance.
	 *
	 * @var static|null
	 */
	private static $instance = null;

	/**
	 * Plugin configuration.
	 *
	 * @var array
	 */
	public $Config;

	/**
	 * View loader.
	 *
	 * @var \RockStarLab\ImportExport\View\View
	 */
	public $View;

	/**
	 * Container holding the loaded controller instances.
	 *
	 * @var \stdClass
	 */
	public $Controller;

	/**
	 * Model registry.
	 *
	 * @var \RockStarLab\ImportExport\Model\Model_Registry
	 */
	public $Model;

	/**
	 * Get the single App instance, creating it on first use.
	 *
	 * @return static
	 */
	public static function getInstance() {
		if ( is_null( static::$instance ) ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Create the model registry. Private: use getInstance().
	 */
	private function __construct() {
		// Initialize model registry.
		$this->Model = new \RockStarLab\ImportExport\Model\Model_Registry();
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {
	}

	/**
	 * Run the core
	 **/
	public function run() {
		// Check if database tables exist and create if needed.
		if ( ! \RockStarLab\ImportExport\Helper\Database_Migration::tables_exist() ) {
			\RockStarLab\ImportExport\Helper\Database_Migration::create_tables();
		} else {
			// Check if database needs migration (version changed).
			$current_version = \RockStarLab\ImportExport\Helper\Database_Migration::get_version();
			$latest_version  = \RockStarLab\ImportExport\Helper\Database_Migration::DB_VERSION;

			if ( version_compare( $current_version, $latest_version, '<' ) ) {
				\RockStarLab\ImportExport\Helper\Database_Migration::create_tables();
			}
		}

		// Deleting a network site dropped only the WordPress tables: the plugin's tables stayed
		// and a new site with the same ID inherited its jobs, connections and keys.
		add_filter( 'wpmu_drop_tables', array( \RockStarLab\ImportExport\Helper\Database_Migration::class, 'add_site_tables_to_drop' ), 10, 2 );

		// Initialize Media Hash helper to add MD5 hashes to all uploads.
		\RockStarLab\ImportExport\Helper\Media_Hash::init();

		// Initialize Chunk Upload handler for large file uploads.
		new \RockStarLab\ImportExport\Helper\Chunk_Upload();
		\RockStarLab\ImportExport\Service\Job_Runner::register();

		// Load core classes.
		$this->_dispatch();
	}

	/**
	 * Load and instantiate all application
	 * classes neccessary for this theme
	 **/
	private function _dispatch() {

		$this->Config = require_once RSL_IE_PATH . '/app/config.php';

		$this->Controller = new \stdClass();
		$this->View       = new \RockStarLab\ImportExport\View\View();

		// Load controllers manually.
		$controllers = array(
			'Abilities_Controller',
			'Agent_Controller',
			'Files_Controller',
			'Init',
		);

		$this->_load_controllers( $controllers );
	}

	/**
	 * Autoload core modules in a specific directory.
	 *
	 * @param string $layer Layer name, which is also the app/ sub-directory (for example Controller).
	 * @param string $dir   Sub-directory inside the layer, with a trailing slash.
	 * @return false|void False when the directory is empty.
	 */
	private function _load_modules( $layer, $dir = '/' ) {

		$directory = RSL_IE_PATH . '/app/' . $layer . $dir;
		$handle    = opendir( $directory );

		if ( 0 === count( glob( "$directory/*" ) ) ) {
			return false;
		}

		while ( false !== ( $file = readdir( $handle ) ) ) {

			if ( is_file( $directory . $file ) ) {

				// Figure out class name from file name.
				$class = str_replace( '.php', '', $file );

				// Avoid recursion.
				if ( $class !== get_class( $this ) ) {
					$classPath            = "\\RockStarLab\ImportExport\\{$layer}\\{$class}";
					$this->$layer->$class = new $classPath();
				}
			}
		}
	}

	/**
	 * Autoload controllers in specific order.
	 *
	 * @param string[] $list Controller class names without the .php extension, in load order.
	 */
	private function _load_controllers( $list ) {

		$directory = RSL_IE_PATH . '/app/Controller/';

		foreach ( $list as $controller_name ) {

			if ( is_file( $directory . $controller_name . '.php' ) ) {
				// Convert controller name to class name (first letter uppercase).
				$class = ucfirst( $controller_name );

				// Avoid recursion.
				if ( $class !== get_class( $this ) ) {
					$classPath                          = "\\RockStarLab\ImportExport\\Controller\\{$class}";
					$this->Controller->$controller_name = new $classPath();
				}
			}
		}
	}

	/**
	 * Deactivate plugin hook
	 */
	public static function deactivate_cleanup() {
	}
}
