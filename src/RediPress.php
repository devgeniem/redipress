<?php
/**
 * RediPress main class file
 */

namespace Geniem\RediPress;

use Geniem\RediPressPlugin,
    Geniem\RediPress\Admin,
    Geniem\RediPress\Index\Index,
    Geniem\RediPress\Redis\Client;

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
     * Store the plugin core instance and initialize rest of the functionalities.
     *
     * @param RediPressPlugin $plugin The plugin instance to get access to basic settings.
     */
    public function __construct( RediPressPlugin $plugin ) {
        // Store the plugin instance.
        $this->plugin = $plugin;

        // Initialize plugin functionalities in proper hook
        add_action( 'plugins_loaded', [ $this, 'init' ] );

        // Add DustPress partials directory
        add_filter( 'dustpress/partials', function( $partials ) {
            $partials[] = $this->plugin->path . '/partials';

            return $partials;
        });

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
            'check_acf',
            'connect',
            'check_redisearch',
            'check_index',
        ];

        // Run through various checks and quit the run if anyone fails.
        foreach ( $checks as $check ) {
            if ( ! $this->{ $check }() ) {
                return;
            }
        }
    }

    /**
     * Check if ACF is active and create the options page if it is.
     *
     * @return boolean Whether ACF is active or not.
     */
    protected function check_acf() : bool {
        $acf = class_exists( 'acf' );

        if ( $acf ) {
            // Create the admin page
            new Admin();

            return true;
        }
        else {
            $this->plugin->show_admin_error( __( 'Advanced Custom Fields is not active. It is required for RediPress to run.', 'redipress' ) );
            return false;
        }
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
                Admin::get( 'hostname' ) ?? '127.0.0.1',
                Admin::get( 'port' ) ?? 6379,
                Admin::get( 'database' ) ?? 0,
                Admin::get( 'password' ) ?: null
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
            new Index( $this->connection );

            return true;
        }
    }

    /**
     * Check if the index exists in Redisearch.
     *
     * @return boolean Whether the Redisearch index exists or not.
     */
    protected function check_index() : bool {
        // TEMP:
        $index_name = 'redipress';

        $index = $this->connection->raw_command( 'FT.INFO', [ $index_name ] );

        if ( $index === 'Unknown Index name' ) {
            $this->plugin->show_admin_error( __( 'Redisearch index is not created', 'redipress' ) );
            return false;
        }
        else {
            // Initialize searching features, we have everything we need to have here.
            new Search( $this->connection );

            return true;
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
}
