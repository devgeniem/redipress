<?php
/**
 * RediPress Rest api functionalities
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Settings;

/**
 * Rest class
 */
class Rest {

    /**
     * The rest api namespace
     *
     * @var string
     */
    const NAMESPACE = 'redipress';

    /**
     * Rest route list
     *
     * @var array
     */
    protected static $rest_routes = [];

    /**
     * Register a singe api call path
     *
     * @param  string        $path                Api call path after namespace.
     * @param  callable      $callback            The function to call.
     * @param  string        $methods             What methods are allowed.
     * @param  array         $args                Rest api args.
     * @param  callable|null $permission_callback Permission callback or null if default.
     * @return array                              Current registered rest routes.
     */
    public static function register_api_call( string $path, callable $callback, string $methods = '', array $args = [], callable $permission_callback = null ) : array {
        static::$rest_routes[] = (object) [
            'namespace' => static::NAMESPACE,
            'path'      => $path,
            'args'      => [
                'methods'             => $methods,
                'callback'            => $callback,
                'permission_callback' => $permission_callback ?? [ __CLASS__, 'has_redipress_cap' ],
                'args'                => $args,
            ],
        ];

        return static::$rest_routes;
    }

    /**
     * Register the rest api routes
     * Should only be called via the rest_api_init hook
     */
    public static function rest_api_init() {
        foreach ( static::$rest_routes as $route ) {
            $route = \apply_filters( 'redipress/rest/route', $route );
            \register_rest_route( $route->namespace, $route->path, $route->args );
        }
    }

    /**
     * Check if current user has permissions for redipress
     *
     * @return boolean
     */
    public static function has_redipress_cap() : bool {
        $settings   = new Settings();
        $capability = $settings->get_capability();
        return \current_user_can( $capability );
    }
}
