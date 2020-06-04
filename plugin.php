<?php
/**
 * Plugin Name: RediPress
 * Plugin URI:  https://github.com/devgeniem/redipress
 * Description: A WordPress plugin that provides a blazing fast search engine and WP Query performance enhancements.
 * Version:     1.5.1
 * Author:      Geniem
 * Author URI:  http://www.geniem.fi/
 * License:     GPL3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: redipress
 * Domain Path: /languages
 */

namespace Geniem;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RediPress plugin class
 */
class RediPressPlugin {

    /**
     * Plugin data
     *
     * @var array
     */
    public $plugin_data = [
        'Plugin Name',
        'Plugin URI',
        'Description',
        'Version',
        'Author',
        'Author URI',
        'Text Domain',
        'Domain Path',
    ];

    /**
     * Plugin path
     *
     * @var string
     */
    public $path = '';

    /**
     * Whether we are on debug mode or not.
     *
     * @var boolean
     */
    public $debug = false;

    /**
     * Run the basic plugin functionalities
     */
    public function __construct() {
        // If a custom autoloader exists, use it.
        if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
            require_once __DIR__ . '/vendor/autoload.php';
        }

        // Ensure that the get_plugin_data() function is available.
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Get the plugin data and set it to the property
        $plugin_data       = \get_plugin_data( __FILE__, false, false );
        $this->plugin_data = \wp_parse_args( $plugin_data, $this->plugin_data );
        $this->path        = __DIR__;
        $this->url         = plugin_dir_url( __FILE__ );

        add_action( 'admin_enqueue_scripts', function() {
            // Register admin JavaScripts
            wp_register_script( 'RediPress', $this->url . 'assets/dist/admin.js', [ 'wp-i18n' ] );

            wp_localize_script( 'RediPress', 'RediPress', [
                'homeUrl'      => \home_url(),
                'restUrl'      => \rest_url( RediPress\Rest::NAMESPACE ),
                'restApiNonce' => \wp_create_nonce( 'wp_rest' ),
            ]);

            wp_set_script_translations( 'RediPress', 'redipress' );

            wp_enqueue_script( 'RediPress' );
        });

        // Whether we are on debug mode or not
        $this->debug = true;

        // Load the plugin textdomain.
        load_plugin_textdomain( 'redipress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Initialize the plugin itself
        new RediPress\RediPress( $this );
    }

    /**
     * Show WordPress admin notice
     *
     * @param string  $message     The message to be shown.
     * @param string  $details     Possible detailed error message.
     * @param boolean $dismissible Is the message dismissible.
     * @return void
     */
    public function show_admin_error( string $message, string $details = '', bool $dismissible = true ) {
        add_action( 'admin_notices', function() use ( $message, $details, $dismissible ) {
            printf(
                '<div class="notice notice-error%s"><p><b>RediPress:</b> %s</p>%s</div>',
                esc_html( $dismissible ? ' is-dismissible' : '' ),
                esc_html( $message ),
                $this->debug && ! empty( $details ) ? '<p>' . esc_html( $details ) . '</p>' : ''
            );
        });
    }
}

new RediPressPlugin();
