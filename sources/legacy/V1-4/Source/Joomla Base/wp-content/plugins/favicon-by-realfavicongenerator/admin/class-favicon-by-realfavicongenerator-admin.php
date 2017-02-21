<?php
// Copyright 2014 RealFaviconGenerator

require_once plugin_dir_path( __FILE__ ) . '../public/class-favicon-by-realfavicongenerator-common.php';
require_once plugin_dir_path( __FILE__ ) . 'class-favicon-by-realfavicongenerator-api-response.php';

class Favicon_By_RealFaviconGenerator_Admin extends Favicon_By_RealFaviconGenerator_Common {

	const DISMISS_UPDATE_NOTIICATION = 'fbrfg_dismiss_update_notification';
	const DISMISS_UPDATE_ALL_UPDATE_NOTIICATIONS = 'fbrfg_dismiss_all_update_notifications';
	const SETTINGS_FORM = 'fbrfg_settings_form';

	protected static $instance = null;

	private function __construct() {
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		add_action( 'admin_head', array( $this, 'add_favicon_markups' ) );

		// Deactivate Genesis default favicon
		add_filter( 'genesis_pre_load_favicon', array( $this, 'return_empty_favicon_for_genesis' ) );

		// See
		// - https://wordpress.org/support/topic/wp_debug-notice-for-bp_setup_current_user
		// - https://buddypress.org/support/topic/wp_debug-notice-for-bp_setup_current_user
		// The idea: is_super_admin must not be called too soon.
		add_action( 'init', array( $this, 'register_admin_actions' ) );
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     1.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function register_admin_actions() {
		// Except for the headers, everything is accessible only to the admin
		if ( ! is_super_admin() ) {
			return;
		}

		add_action( 'admin_menu',
			array( $this, 'create_favicon_settings_menu' ) );

		add_action('wp_ajax_' . Favicon_By_RealFaviconGenerator_Common::PLUGIN_PREFIX . '_install_new_favicon',
			array( $this, 'install_new_favicon' ) );
		add_action('wp_ajax_nopriv_' . Favicon_By_RealFaviconGenerator_Common::PLUGIN_PREFIX . '_install_new_favicon',
			array( $this, 'install_new_favicon' ) );

		// Update notice
		add_action('admin_notices', array( $this, 'display_update_notice' ) );
		add_action('admin_init',    array( $this, 'process_ignored_notice' ) );

		// Schedule update check
		if ( ! wp_next_scheduled( Favicon_By_RealFaviconGenerator_Common::ACTION_CHECK_FOR_UPDATE ) ) {
			wp_schedule_event( time(), 'daily', Favicon_By_RealFaviconGenerator_Common::ACTION_CHECK_FOR_UPDATE );
		}
	}

	public function create_favicon_settings_menu() {
		add_theme_page( __( 'Favicon', Favicon_By_RealFaviconGenerator_Common::PLUGIN_SLUG ), 
			__( 'Favicon', Favicon_By_RealFaviconGenerator_Common::PLUGIN_SLUG ), 'manage_options', __FILE__ . 'favicon_appearance_menu', 
			array( $this, 'create_favicon_appearance_page' ) );

		add_options_page( __( 'Favicon Settings', Favicon_By_RealFaviconGenerator_Common::PLUGIN_SLUG ), 
			__( 'Favicon', Favicon_By_RealFaviconGenerator_Common::PLUGIN_SLUG ), 'manage_options', __FILE__ . 'favicon_settings_menu', 
			array( $this, 'create_favicon_settings_page' ) );
	}

	public function create_favicon_settings_page() {
		global $current_user;

		$user_id = $current_user->ID;

		// Prepare variables
		$favicon_appearance_url = admin_url( 'themes.php?page=' . __FILE__ . 'favicon_appearance_menu' );
		$favicon_admin_url = admin_url( 'options-general.php?page=' . __FILE__ . 'favicon_settings_menu' );
		$display_update_notifications = ! $this->get_boolean_user_option(
			Favicon_By_RealFaviconGenerator_Common::META_NO_UPDATE_NOTICE );

		// Template time!
		include_once( plugin_dir_path(__FILE__) . 'views/settings.php' );		
	}

	public function create_favicon_appearance_page() {
		$result = NULL;

		// Prepare settings page

		// Option to allow user to not use the Rewrite API: display it only when the Rewrite API is available
		$can_rewrite = $this->can_access_pics_with_url_rewrite();
		$pic_path = $this->get_full_picture_path();

		$favicon_configured = $this->is_favicon_configured();
		$favicon_in_root = $this->is_favicon_in_root();

		$preview_url = $this->is_preview_available() ? $this->get_preview_url() : NULL;

		if ( isset( $_REQUEST['json_result_url'] ) ) {
			// New favicon to install:
			// Parameters will be processed with an Ajax call

			$new_favicon_params_url = 'http://realfavicongenerator.net' . $_REQUEST['json_result_url'];
			$ajax_url = admin_url( 'admin-ajax.php', isset( $_SERVER['HTTPS'] ) ? 'https://' : 'http://' );
		}
		else {
			// No new favicon, simply display the settings page
			$new_favicon_params_url = NULL;
		}

		// External files
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui' );
		wp_enqueue_media();
		wp_enqueue_style( 'fbrfg_admin_style', plugins_url( 'assets/css/admin.css', __FILE__ ) );

		// Template time!
		include_once( plugin_dir_path(__FILE__) . 'views/appearance.php' );
	}

	private function download_result_json( $url ) {
		$resp = wp_remote_get( $url );
		if ( is_wp_error( $resp )) {
			throw new InvalidArgumentException( "Cannot download JSON file at " . $url . ": " . $resp->get_error_message() );
		}

		$json = wp_remote_retrieve_body( $resp );
		if ( empty( $json ) ) {
			throw new InvalidArgumentException( "Empty JSON document at " . $url );
		}

		return $json;
	}

	public function install_new_favicon() {
		header("Content-type: application/json");

		try {
			// URL is explicitely decoded to compensate the extra encoding performed while generating the settings page
			$url = $_REQUEST['json_result_url'];

			$result = $this->download_result_json( $url );

			$response = new Favicon_By_RealFaviconGenerator_Api_Response( $result );

			$zip_path = Favicon_By_RealFaviconGenerator_Common::get_tmp_dir();
			if ( ! file_exists( $zip_path ) ) {
				mkdir( $zip_path, 0755, true );
			}
			$response->downloadAndUnpack( $zip_path );

			$this->store_pictures( $response );

			$this->store_preview( $response->getPreviewPath() );

			Favicon_By_RealFaviconGenerator_Common::remove_directory( $zip_path );

			update_option( Favicon_By_RealFaviconGenerator_Common::OPTION_HTML_CODE, $response->getHtmlCode() );
			
			$this->set_favicon_configured( true, $response->isFilesInRoot(), $response->getVersion() );
?>
{
	"status": "success",
	"preview_url": <?php echo json_encode( $this->get_preview_url() ) ?>,
	"favicon_in_root": <?php echo json_encode( $this->is_favicon_in_root() ) ?>
}
<?php
		}
		catch(Exception $e) {
?>
{
	"status": "error",
	"message": <?php echo json_encode( $e->getMessage() ) ?>
}
<?php
		}

		die();
	}

	public function get_picture_dir() {
		return Favicon_By_RealFaviconGenerator_Common::get_files_dir();
	}

	/**
	 * Returns http//somesite.com/blog/wp-content/upload/fbrfg/
	 */
	public function get_picture_url() {
		return Favicon_By_RealFaviconGenerator_Common::get_files_url();
	}

	/**
	 * Returns /blog/wp-content/upload/fbrfg/
	 */
	public function get_full_picture_path() {
		return parse_url( $this->get_picture_url(), PHP_URL_PATH );
	}

	/**
	 * Returns wp-content/upload/fbrfg/
	 */
	public function get_picture_path() {
		return substr( $this->get_picture_url(), strlen( home_url() ) );
	}

	public function get_preview_path( $preview_file_name = NULL ) {
		if ( ! $preview_file_name ) {
			$preview_file_name = $this->get_preview_file_name();
		}
		return $this->get_picture_dir() . 'preview/' . $preview_file_name;
	}

	public function get_preview_url( $preview_file_name = NULL ) {
		if ( ! $preview_file_name ) {
			$preview_file_name = $this->get_preview_file_name();
		}
		return $this->get_picture_url() . '/preview/' . $preview_file_name;
	}

	public function store_preview( $preview_path ) {
		// Remove previous preview, if any
		$previous_preview = $this->get_preview_file_name();
		if ( $previous_preview != NULL && ( file_exists( $this->get_preview_path( $previous_preview ) ) ) ) {
			unlink( $this->get_preview_path( $previous_preview ) );
		}

		if ( $preview_path == NULL ) {
			// "Unregister" previous preview, if any
			$this->set_preview_file_name( NULL );
			return NULL;
		}
		else {
			$preview_file_name = 'preview_' . md5( 'RFB stuff here ' . rand() . microtime() ) . '.png';
		}

		if ( ! file_exists( dirname( $this->get_preview_path( $preview_file_name ) ) ) ) {
			mkdir( dirname( $this->get_preview_path( $preview_file_name ) ), 0755 );
		}

		rename( $preview_path, $this->get_preview_path( $preview_file_name ) );

		$this->set_preview_file_name( $preview_file_name );
	}

	public function store_pictures( $rfg_response ) {
		$working_dir = $this->get_picture_dir();

		// Move pictures to production directory
		$files = glob( $working_dir . '*' );
		foreach( $files as $file ) {
			if ( is_file( $file ) ) {
			    unlink( $file );
			}
		}
		$files = glob( $rfg_response->getProductionPackagePath() . '/*' );
		foreach( $files as $file ) {
			if ( is_file( $file ) ) {
			    rename( $file, $working_dir . basename( $file ) );
			}
		}

		if ( $rfg_response->isFilesInRoot() ) {
			$this->rewrite_pictures_url( $working_dir );
			flush_rewrite_rules();
		}
	}

	public function rewrite_pictures_url( $pic_dir ) {
		foreach ( scandir($pic_dir) as $file ) {
			if ( ! is_dir( $pic_dir . '/' . $file ) ) {
				add_rewrite_rule( str_replace( '.', '\.', $file ), 
					trim( $this->get_picture_path(), '/') . '/' . $file );
			}
		}
	}

	/**
	 * Indicate if it is possible to create URLs such as /favicon.ico
	 */
	public function can_access_pics_with_url_rewrite() {
		global $wp_rewrite;

		// Due to too many problems with the rewrite API (for example, http://wordpress.org/support/topic/do-not-work-8?replies=3#post-),
		// it was deciced to turn the feature off once for all
		return false;

		// If blog is in root AND rewriting is available (http://wordpress.stackexchange.com/questions/142273/checking-that-the-rewrite-api-is-available),
		// we can produce URLs such as /favicon.ico
//		$rewrite = ( $this->wp_in_root() && $wp_rewrite->using_permalinks() );
//		if ( ! $rewrite ) {
//			return false;
//		}

		// See http://wordpress.org/support/topic/fbrfg-not-updating-htaccess-rewrite-rules
//		$htaccess = get_home_path() . '/.htaccess';
		// Two cases:
		//   - There is no .htaccess. Either we are not using Apache (so the Rewrite API is supposed to handle
		//     the rewriting differently) or there is a problem with Apache/WordPress config, but this is not our job.
		//   - .htaccess is present. If so, it should be writable.
//		return ( ( ! file_exists( $htaccess ) ) || is_writable( $htaccess ) );
	}

	/**
	 * Indicate if WP is installed in the root of the web site (eg. http://mysite.com) or not (eg. http://mysite.com/blog).
	 */
	public function wp_in_root() {
		$path = parse_url( home_url(), PHP_URL_PATH );
		return ( ($path == NULL) || (strlen( $path ) == 0) );
	}

	public function set_boolean_user_option( $option_name, $option_value ) {
		global $current_user;
		$user_id = $current_user->ID;

		update_user_option( $user_id, $option_name, $option_value );
	}

	public function get_boolean_user_option( $option_name ) {
		global $current_user;
		$user_id = $current_user->ID;

		return get_user_option( $option_name );
	}

	public function is_update_notice_to_be_displayed() {
		// No update? No notice
		if ( ! $this->is_update_available() ) {
			return false;
		}

		// Did the user prevent all notices?
		if ( $this->get_boolean_user_option( Favicon_By_RealFaviconGenerator_Common::META_NO_UPDATE_NOTICE_FOR_VERSION . $this->get_latest_version_available() ) ) {
			return false;
		}

		// Did the user prevent the notice for this particular version?
		if ( $this->get_boolean_user_option( Favicon_By_RealFaviconGenerator_Common::META_NO_UPDATE_NOTICE ) ) {
			return false;
		}

		return true;
	}

	public function display_update_notice() {
		if ( $this->is_update_notice_to_be_displayed() ) {
			echo '<div class="update-nag">';
			printf( __( '<a href="%s" target="_blank">An update is available</a> on RealFaviconGenerator. You might want to <a href="%s">generate your favicon again</a>.', FBRFG_PLUGIN_SLUG ),
					'http://realfavicongenerator.net/change_log?since='. $this->get_favicon_version(),
					admin_url( 'themes.php?page=' . __FILE__ . 'favicon_appearance_menu') );
			printf( __( ' | <a href="%s">Hide this notice</a>', FBRFG_PLUGIN_SLUG), 
				$this->add_parameter_to_current_url( Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_NOTIICATION . '=0' ) );
			printf( __( ' | <a href="%s">Do not warn me again in case of update</a>', FBRFG_PLUGIN_SLUG), 
				$this->add_parameter_to_current_url( Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_ALL_UPDATE_NOTIICATIONS . '=0' ) );
			echo '</div>';
		}
	}

	public function process_ignored_notice() {
	    global $current_user;
        $user_id = $current_user->ID;

        if ( isset( $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_NOTIICATION] ) && 
        		'0' == $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_NOTIICATION] ) {
             $this->set_boolean_user_option( Favicon_By_RealFaviconGenerator_Common::META_NO_UPDATE_NOTICE_FOR_VERSION . $this->get_latest_version_available(), true );
	    }

	    $no_notices = NULL;
        if ( ( isset( $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_ALL_UPDATE_NOTIICATIONS] ) && 
        		'0' == $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_ALL_UPDATE_NOTIICATIONS] ) ) {
        	// The "no more notifications" link was clicked in the notification itself
		    $no_notices = true;
        }
        if ( isset( $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::SETTINGS_FORM] ) && 
        		'1' == $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::SETTINGS_FORM] ) {
        	// The settings form was validated
        	$no_notices = ( ! isset( $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_ALL_UPDATE_NOTIICATIONS] ) || 
        		( '0' == $_REQUEST[Favicon_By_RealFaviconGenerator_Admin::DISMISS_UPDATE_ALL_UPDATE_NOTIICATIONS] ) );
	    }
		if ( $no_notices !== NULL ) {
			$this->set_boolean_user_option( Favicon_By_RealFaviconGenerator_Common::META_NO_UPDATE_NOTICE, $no_notices );
		}
	}
}
?>