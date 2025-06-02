<?php
/**
 * RediPress main class file
 */

namespace Geniem\RediPress;

use Geniem\RediPressPlugin;
use Geniem\RediPress\Settings;
use Geniem\RediPress\Index\PostIndex;
use Geniem\RediPress\Index\UserIndex;
use Geniem\RediPress\Redis\Client;
use Geniem\RediPress\Utility;
use Geniem\RediPress\Rest;

// Require the external API functions
require_once( __DIR__ . '/API.php' );

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
     * The indexes
     *
     * @var array
     */
    protected $indexes = [];

    /**
     * Store the plugin core instance and initialize rest of the functionalities.
     *
     * @param RediPressPlugin $plugin The plugin instance to get access to basic settings.
     */
    public function __construct( RediPressPlugin $plugin ) {
        // Store the plugin instance.
        $this->plugin = $plugin;

        // Initialize plugin functionalities in proper hook
        \add_action( 'init', [ $this, 'init' ], 2 );

        \add_action( 'admin_menu', [ $this, 'add_settings_page' ] );

        \add_action( 'rest_api_init', [ Rest::class, 'rest_api_init' ] );

        // Register the CLI commands if WP CLI is available
        if ( defined( 'WP_CLI' ) ) {
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
            'connect'                => '',
            'check_redisearch'       => '',
            'check_indexes'          => '',
            'check_schema_integrity' => 'no_cli',
        ];

        // Run through various checks and quit the run if any of them fails.
        foreach ( $checks as $check => $cli ) {
            if ( $cli === '' || ( ! defined( 'WP_CLI' ) && $cli === 'no_cli' ) ) {
                if ( ! $this->{ $check }() ) {
                    return;
                }
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
    protected function connect(): bool {
        $client = new Client();

        try {
            $this->connection = $client->connect(
                Settings::get( 'hostname' ) ?? '127.0.0.1',
                intval( Settings::get( 'port' ) ) ?? 6379,
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
    protected function check_redisearch(): bool {
        $modules = $this->connection->raw_command( 'MODULE', [ 'LIST' ] );

        $redisearch = array_reduce( $modules, function ( $carry, $item = null ) {
            if ( $carry === true || ( ! empty( $item[1] ) && $item[1] === 'search' ) ) {
                return true;
            }

            return false;
        });

        if ( ! $redisearch ) {
            $this->plugin->show_admin_error( __( 'The Redisearch module is not loaded.', 'redipress' ) );

            return false;
        }

        // Initialize indexes.
        // Run initialization inside 'init' hook only if in WP CLI to avoid code execution order errors.
        defined( 'WP_CLI' ) ? \add_action( 'init', fn() => $this->init_indexes(), 1000 ): $this->init_indexes();

        return true;
    }

    /**
     * Initialize indexes.
     *
     * @return void
     */
    protected function init_indexes () {
        $this->indexes['posts'] = new PostIndex( $this->connection );

        if ( Settings::get( 'use_user_query' ) ) {
            $this->indexes['users'] = new UserIndex( $this->connection );
        }
    }

    /**
     * Check if the index exists in Redisearch.
     *
     * @return boolean Whether the Redisearch index exists or not.
     */
    protected function check_indexes(): bool {
        foreach ( $this->indexes as $type => $info ) {
            $index = $this->indexes[ $type ];

            $name = Settings::get( "{$type}_index" );

            $raw_info = $this->connection->raw_command( 'FT.INFO', [ $name ] );

            // Create the index if it doesn't already exist
            if ( $raw_info === 'Unknown Index name' ) {
                $this->plugin->show_admin_error( sprintf( __( 'RediPress: Index "%s" does not exist.', 'redipress' ), $type ) );

                return false;
            }

            $info = Utility::format( $raw_info );

            if ( (int) $info['num_docs'] === 0 ) {
                $this->plugin->show_admin_error( sprintf( __( 'RediPress: Index "%s" is empty, consider running the indexing function.', 'redipress' ), $type ) );

                return false;
            }
            else {
                // Store the index information
                $index->set_info( $info );

                // Initialize the query class, we have everything we need to have here.
                $class_name = $index::INDEX_QUERY_CLASS;
                new $class_name( $this->connection, $info );
            }
        }

        return true;
    }

    /**
     * Check if the schema has changed after last update.
     *
     * @return bool
     */
    protected function check_schema_integrity(): bool {
        \add_action( 'wp_loaded',  function () {
            foreach ( $this->indexes as $index_type => $info ) {
                $index_name = Settings::get( "{$index_type}_index" );

                $raw_info = $this->connection->raw_command( 'FT.INFO', [ $index_name ] );

                $info = Utility::format( $raw_info );

                $index = \apply_filters( "redipress/{$index_type}_index_instance", null );

                [ $options, $schema_fields, $raw_schema ] = $index->get_schema_fields();

                $fields = array_map( function ( $field ) {
                    // Remove some fields so that we can compare the output to our own schema
                    // definitions.
                    unset( $field[0] );
                    unset( $field[1] );
                    unset( $field[2] );
                    unset( $field[4] );

                    return array_values( $field );
                }, $info['attributes'] );

                // Sort alphabetically by field name.
                usort( $fields, fn( $a, $b ) => $a[0] <=> $b[0] );

                // Convert everything to strings.
                $schema = array_map( function ( $field ) {
                    return array_map( 'strval', $field->get() );
                }, $schema_fields );

                // Sort alphabetically by field name.
                usort( $schema, fn( $a, $b ) => $a[0] <=> $b[0] );

                $diff = array_diff(
                    array_map( 'json_encode', $schema ),
                    array_map( 'json_encode', $fields ),
                );

                if ( count( $diff ) > 0 ) {
                    array_map( function ( $json ) use ( $index_type ) {
                        $field = json_decode( $json );

                        $this->plugin->show_admin_error(
                            sprintf(
                                // translators: %s is the field name.
                                \__(
                                    'RediSearch %1$s schema does not contain field %2$s which has been defined in the theme, or its definition has changed. Consider recreating the schema.',
                                    'redipress'
                                ),
                                $index_type,
                                $field[0],
                            )
                        );
                    }, $diff );
                }
            }
        }, 1000 );

        return true;
    }

    /**
     * Add settings page
     *
     * @return void
     */
    public function add_settings_page() {
        $settings = new Settings();

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
            new \Geniem\RediPress\External\DustPressDebugger();
        }
    }

    /**
     * Get the plugin instance.
     *
     * @return RediPressPlugin
     */
    public function get_plugin(): RediPressPlugin {
        return $this->plugin;
    }
}
