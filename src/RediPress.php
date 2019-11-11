<?php
/**
 * RediPress main class file
 */

namespace Geniem\RediPress;

use Geniem\RediPressPlugin,
    Geniem\RediPress\Settings,
    Geniem\RediPress\Index\Index,
    Geniem\RediPress\Index\UserIndex,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility,
    Geniem\RediPress\Rest;

/**
 * RediPress main class
 */
class RediPress {

    /**
     * The plugin instance.
     *
     * @var RediPressPlugin
     */
    protected $plugin;

    /**
     * The Redis connection.
     *
     * @var Client
     */
    protected $connection;

    /**
     * The index information
     *
     * @var array
     */
    protected $index_info = null;

    /**
     * Store the plugin core instance and initialize rest of the functionalities.
     *
     * @param RediPressPlugin $plugin The plugin instance to get access to basic settings.
     */
    public function __construct( RediPressPlugin $plugin ) {
        // Store the plugin instance.
        $this->plugin = $plugin;

        // Initialize plugin functionalities in proper hook
        add_action( 'init', [ $this, 'init' ], 1 );

        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );

        add_action( 'rest_api_init', [ Rest::class, 'rest_api_init' ] );

        // Register the CLI commands if WP CLI is available
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'redipress', __NAMESPACE__ . '\\CLI' );
        }
    }

    /**
     * Initialize plugin functionalities
     *
     * @return void
     */
    public function init() {
        // List of check methods.
        $checks = [
            'connect',
            'check_redisearch',
            'check_index',
        ];

        if ( Settings::get( 'use_user_query' ) ) {
            $checks[] = 'check_user_index';
        }

        // Run through various checks and quit the run if anyone fails.
        foreach ( $checks as $check ) {
            if ( ! $this->{ $check }() ) {
                return;
            }
        }

        // Register external functionalities
        $this->register_external_functionalities();
    }

    /**
     * Connect to Redis.
     *
     * @return boolean Whether the connection succeeded or not.
     */
    protected function connect() : bool {
        $client = new Client();

        try {
            $this->connection = $client->connect(
                Settings::get( 'hostname' ) ?? '127.0.0.1',
                Settings::get( 'port' ) ?? 6379,
                0,
                Settings::get( 'password' ) ?: null
            );

            return true;
        }
        catch ( \Exception $e ) {
            $this->plugin->show_admin_error( __( 'Connection to Redis server did not succeed.', 'redipress' ), $e->getMessage() );
            return false;
        }
    }

    /**
     * Ensure that the connected Redis server has Redisearch module installed.
     *
     * @return boolean Whether the Redisearch module is installed or not.
     */
    protected function check_redisearch() : bool {
        $modules = $this->connection->raw_command( 'MODULE', [ 'LIST' ] );

        $redisearch = array_reduce( $modules, function( $carry, $item = null ) {
            if ( $carry === true || ( ! empty( $item[1] ) && $item[1] === 'ft' ) ) {
                return true;
            }
            else {
                return false;
            }
        });

        if ( ! $redisearch ) {
            $this->plugin->show_admin_error( __( 'The Redisearch module is not loaded.', 'redipress' ) );
            return false;
        }
        else {
            // Initialize indexing features, we have everything we need to have here.
            add_action( 'init', function() {
                new Index( $this->connection );
                new UserIndex( $this->connection );
            }, 1000 );

            return true;
        }
    }

    /**
     * Check if the index exists in Redisearch.
     *
     * @return boolean Whether the Redisearch index exists or not.
     */
    protected function check_index() : bool {
        $index_name = Settings::get( 'index' );

        $index = $this->connection->raw_command( 'FT.INFO', [ $index_name ] );

        if ( $index === 'Unknown Index name' ) {
            $this->plugin->show_admin_error( __( 'Redisearch index is not created.', 'redipress' ) );
            return false;
        }
        else {
            $info = Utility::format( $index );

            if ( (int) $info['num_docs'] === 0 ) {
                $this->plugin->show_admin_error( __( 'Redisearch index is empty.', 'redipress' ) );
                return false;
            }
            else {
                // Store the index information
                $this->index_info = $info;

                // Initialize searching features, we have everything we need to have here.
                new Search( $this->connection, $this->index_info );

                // Also require the external API functions
                require_once( __DIR__ . '/API.php' );

                return true;
            }
        }
    }

    /**
     * Check if the user index exists in Redisearch.
     *
     * @return boolean Whether the Redisearch user index exists or not.
     */
    protected function check_user_index() : bool {
        $index_name = Settings::get( 'user_index' );

        $index = $this->connection->raw_command( 'FT.INFO', [ $index_name ] );

        if ( $index === 'Unknown Index name' ) {
            $this->plugin->show_admin_error( __( 'Redisearch user index is not created.', 'redipress' ) );
            return false;
        }
        else {
            $info = Utility::format( $index );

            if ( (int) $info['num_docs'] === 0 ) {
                $this->plugin->show_admin_error( __( 'Redisearch user index is empty.', 'redipress' ) );
                return false;
            }
            else {
                // Store the index information
                $this->user_index_info = $info;

                // Initialize searching features, we have everything we need to have here.
                new UserQuery( $this->connection, $this->user_index_info );

                return true;
            }
        }
    }

    /**
     * Add settings page
     *
     * @return void
     */
    public function add_settings_page() {
        $settings = new Settings( $this->get_index_info() );

        \add_submenu_page(
            $settings->get_parent_slug(),
            $settings->get_page_title(),
            $settings->get_menu_title(),
            $settings->get_capability(),
            $settings->get_slug(),
            [ $settings, 'render_page' ]
        );
    }

    /**
     * Register external plugin functionalities
     *
     * @return void
     */
    protected function register_external_functionalities() {

        // Polylang
        if ( function_exists( 'pll_languages_list' ) ) {
            new \Geniem\RediPress\External\Polylang();
        }

        // DustPress Debugger
        if ( class_exists( '\DustPress\Debugger' ) && \DustPress\Debugger::use_debugger() ) {
            new \Geniem\RediPress\External\DustpressDebugger();
        }
    }

    /**
     * Get the plugin instance.
     *
     * @return RediPressPlugin
     */
    public function get_plugin() : RediPressPlugin {
        return $this->plugin;
    }

    /**
     * Get the index information
     *
     * @return array|null
     */
    public function get_index_info() : ?array {
        return $this->index_info;
    }
}
