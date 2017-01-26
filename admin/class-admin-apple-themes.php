<?php
/**
 * This class is in charge of handling the management of Apple News themes.
 */
class Admin_Apple_Themes extends Apple_News {

	/**
	 * Theme management page name.
	 *
	 * @var string
	 * @access private
	 */
	private $page_name;

	/**
	 * Key for the theme index.
	 *
	 * @var string
	 * @access private
	 */
	private $theme_index_key = 'apple_news_installed_themes';

	/**
	 * Key for the active theme.
	 *
	 * @var string
	 * @access private
	 */
	private $theme_active_key = 'apple_news_active_theme';

	/**
	 * Prefix for individual theme keys.
	 *
	 * @var string
	 * @access private
	 */
	private $theme_key_prefix = 'apple_news_theme_';

	/**
	 * Valid actions handled by this class and their callback functions.
	 *
	 * @var array
	 * @access private
	 */
	private $valid_actions;

	/**
	 * Constructor.
	 */
	function __construct() {
		$this->page_name = $this->plugin_domain . '-themes';

		$this->valid_actions = array(
			'apple_news_create_theme' => array( $this, 'create_theme' ),
			'apple_news_upload_theme' => array( $this, 'upload_theme' ),
			'apple_news_export_theme' => array( $this, 'export_theme' ),
			'apple_news_delete_theme' => array( $this, 'delete_theme' ),
			'apple_news_set_theme' => array( $this, 'set_theme' ),
		);

		add_action( 'admin_menu', array( $this, 'setup_theme_page' ), 99 );
		add_action( 'admin_init', array( $this, 'action_router' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Check for a valid theme setup on the site.
	 *
	 * @access private
	 */
	private function validate_themes() {
		$themes = self::list_themes();
		if ( empty( $themes ) ) {
			$this->create_themes( __( 'Default', 'apple-news' ) );
		}
	}

	/**
	 * Options page setup.
	 *
	 * @access public
	 */
	public function setup_theme_page() {
		$this->validate_themes();

		add_submenu_page(
			'apple_news_index',
			__( 'Apple News Themes', 'apple-news' ),
			__( 'Themes', 'apple-news' ),
			apply_filters( 'apple_news_settings_capability', 'manage_options' ),
			$this->page_name,
			array( $this, 'page_themes_render' )
		);
	}

	/**
	 * Options page render.
	 *
	 * @access public
	 */
	public function page_themes_render() {
		if ( ! current_user_can( apply_filters( 'apple_news_settings_capability', 'manage_options' ) ) ) {
			wp_die( __( 'You do not have permissions to access this page.', 'apple-news' ) );
		}

		include plugin_dir_path( __FILE__ ) . 'partials/page_themes.php';
	}

	/**
	 * Register assets for the options page.
	 *
	 * @param string $hook
	 * @access public
	 */
	public function register_assets( $hook ) {
		if ( 'apple-news_page_apple-news-themes' != $hook ) {
			return;
		}

		wp_enqueue_style( 'apple-news-themes-css', plugin_dir_url( __FILE__ ) .
			'../assets/css/themes.css', array() );

		wp_enqueue_script( 'apple-news-themes-js', plugin_dir_url( __FILE__ ) .
			'../assets/js/themes.js', array( 'jquery' )
		);

		wp_localize_script( 'apple-news-themes-js', 'appleNewsThemes', array(
			'deleteWarning' => __( 'Are you sure you want to delete this theme?', 'apple-news' ),
			'themeExistsWarning' => __( 'A theme by this name already exists. Are you sure you want to overwrite it?', 'apple-news' ),
			'noNameError' => __( 'Please enter a name for the new theme.', 'apple-news' ),
			'tooLongError' => __( 'Theme names must be 45 characters or less.', 'apple-news' ),
		) );
	}

	/**
	 * List all available themes
	 *
	 * @access public
	 * @static
	 */
	public static function list_themes() {
		return get_option( $this->theme_index_key, array() );
	}

	/**
	 * Get a specific theme
	 *
	 * @access public
	 * @static
	 */
	public static function get_theme( $key ) {
		return get_option( $key, array() );
	}

	/**
	 * Saves the theme JSON for the key provided.
	 *
	 * @param string $name
	 * @param string $json
	 * @access private
	 */
	private function save_theme( $name, $json ) {
		// Get the index
		$index = self::list_themes();
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$key = $this->theme_key_from_name( $name );

		// Attempt to save the JSON first just in case there is an issue
		$result = update_option( $key, $json );
		if ( false === $result ) {
			\Admin_Apple_Notice::error( sprintf(
				__( 'There was an error saving the theme %s', 'apple-news' ),
				$name
			) );
			return;
		}

		// Add the key to the index
		$index[] = $name;

		$result = update_option( $this->theme_index_key, $index );
		if ( false === $result ) {
			\Admin_Apple_Notice::error( sprintf(
				__( 'There was an error saving the theme index for %s', 'apple-news' ),
				$name
			) );

			// Avoid any unpleasant data reference issues
			delete_option( $key );
		}

		\Admin_Apple_Notice::success( sprintf(
			__( 'The theme %s was saved successfully', 'apple-news' ),
			$name
		) );
	}

	/**
	 * Route all possible theme actions to the right place.
	 *
	 * @param string $hook
	 * @access public
	 */
	public function action_router() {
		// Check for a valid action
		$action	= isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : null;
		if ( ( empty( $action ) && ! array_key_exists( $action, $this->valid_actions ) ) ) {
			return;
		}

		// Check the nonce
		check_admin_referer( 'apple_news_themes', 'apple_news_themes' );

		// Call the callback for the action for further processing
		call_user_func( $this->actions[ $action ] );
	}

	/**
	 * Handles creating a new theme from current settings.
	 *
	 * @param string $name
	 * @access private
	 */
	private function create_theme( $name ) {
		// Get all the current settings for the site and save them as a new theme
		$settings = new Admin_Apple_Settings();
		$this->save_theme( $name, true, $settings );
	}

	/**
	 * Handles setting the active theme.
	 *
	 * @param string $name
	 * @access private
	 */
	private function set_theme( $name ) {
		// Attempt to load the theme settings
		$key = $this->theme_key_from_name( $name );
		$new_settings = get_option( $key );
		if ( empty( $settings ) ) {
			\Admin_Apple_Notice::error( sprintf(
				__( 'There was an error loading settings for the theme %s', 'apple-news' ),
				$name
			) );
			return;
		}

		// Load the settings from the theme
		$settings = new \Admin_Apple_Settings();
		$settings->save_settings( $new_settings );

		// Set the theme active
		update_option( $this->theme_active_key, $name );

		// Indicate success
		\Admin_Apple_Notice::success( sprintf(
			__( 'Successfully switched to theme %s', 'apple-news' ),
			$name
		) );
	}

	/**
	 * Handles deleting a theme.
	 *
	 * @param string $name
	 * @access private
	 */
	private function delete_theme( $name ) {
		// Get the key
		$key = $this->theme_key_from_name( $name );

		// Make sure it exists
		$themes = self::list_themes();
		$index = array_search( $name, $themes );
		if ( false === $index ) {
			\Admin_Apple_Notice::error( sprintf(
				__( 'The theme %s to be deleted does not exist', 'apple-news' ),
				$name
			) );
			return;
		}

		// Remove from the index and delete settings
		unset( $themes[ $index ] );
		update_option( $this->theme_index_key, $themes );
		delete_option( $key );

		// Indicate success
		\Admin_Apple_Notice::success( sprintf(
			__( 'Successfully deleted theme %s', 'apple-news' ),
			$name
		) );
	}

	/**
	 * Handles uploading a new theme from a JSON file.
	 *
	 * @access private
	 */
	private function upload_theme() {
		$file = wp_import_handle_upload();

		if ( isset( $file['error'] ) ) {
			\Admin_Apple_Notice::error(
				__( 'There was an error uploading the theme file', 'apple-news' ),
			);
			return;
		}

		if ( ! isset( $file['file'], $file['id'] ) ) {
			\Admin_Apple_Notice::error(
				__( 'The file did not upload properly. Please try again.', 'apple-news' ),
			);
			return;
		}

		$this->file_id = intval( $file['id'] );

		if ( ! file_exists( $file['file'] ) ) {
			wp_import_cleanup( $this->file_id );
			\Admin_Apple_Notice::error( sprintf(
				__( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'wp-options-importer' ),
				esc_html( $file['file'] )
			) );
			return;
		}

		if ( ! is_file( $file['file'] ) ) {
			wp_import_cleanup( $this->file_id );
			\Admin_Apple_Notice::error(
				__( 'The path is not a file, please try again.', 'apple-news' )
			);
			return;
		}

		$file_contents = file_get_contents( $file['file'] );
		$import_data = json_decode( $file_contents, true );

		wp_import_cleanup( $this->file_id );

		$result = $this->validate_data( $import_data );
		if ( ! is_array( $result ) ) {
			\Admin_Apple_Notice::error( sprintf(
				__( 'The theme file was invalid and cannot be imported: %s', 'apple-news' ),
				$result
			 ) );
			return;
		} else {
			// Get the name from the data and unset it since it doesn't need to be stored
			$name = $result['theme_name'];
			unset( $result['theme_name'] );
			$this->save_theme( $name, $result );
		}

		// Indicate success
		\Admin_Apple_Notice::success( sprintf(
			__( 'Successfully uploaded theme %s', 'apple-news' ),
			$name
		) );
	}

	/**
	 * Handles exporting a new theme to a JSON file.
	 *
	 * @param string $name
	 * @access private
	 */
	private function export_theme( $name ) {
		$key = $this->theme_key_from_name( $name );
		$theme = get_option( $key );
		if ( empty( $theme ) ) {
			\Admin_Apple_Notice::error( sprintf(
				__( 'The theme $s could not be found', 'apple-news' ),
				$name
			) );
			return;
		}

		// Add the theme name
		$theme['theme_name'] = $name;

		// Generate the filename
		$filename = $key . '.json'

		// Start the download
		header( 'Content-Description: File Transfer' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ), true );

		$JSON_PRETTY_PRINT = defined( 'JSON_PRETTY_PRINT' ) ? JSON_PRETTY_PRINT : null;
		echo json_encode( $theme, $JSON_PRETTY_PRINT );

		exit;
	}

	/**
	 * Validate data for an import file upload.
	 *
	 * @param array $data
	 * @return array|boolean
	 * @access private
	 */
	private function validate_data( $data ) {
		$settings = new \Apple_Exporter\Settings();
		$valid_settings = array_keys( $settings->all() );
		$clean_settings = array();

		// Check for the theme name
		if ( ! isset( $data[ 'theme_name' ] ) ) {
			return __( 'The theme file did not include a name', 'apple-news' );
		}
		$clean_settings['theme_name'] = $data['theme_name'];
		unset( $data['theme_name'] );

		foreach ( $valid_settings as $setting ) {
			if ( ! isset( $data[ $setting ] ) ) {
				return sprintf(
					__( 'The theme was missing the required setting %s', 'apple-news' ),
					$setting
			}

			$clean_settings[ $setting ] = sanitize_text_field( $data[ $setting ] );
			unset( $data[ $setting ] );
		}

		// Check if invalid data was present
		if ( ! empty( $data ) ) {
			return __( 'The theme file contained invalid options', 'apple-news' );
		}

		return $clean_settings;
	}

	/**
	 * Generates a key for the theme from the provided name
	 *
	 * @param string $name
	 * @access private
	 */
	private function theme_key_from_name( $name ) {
		return $theme_key_prefix . sanitize_key( $name );
	}
}