<?php
/**
 * RediPress CLI create index command
 */

namespace Geniem\RediPress\CLI;

use WP_CLI;

/**
 * RediPress CLI create index command class.
 */
class Create implements Command {

    /**
     * The command itself.
     *
     * @param array $args The command parameters.
     * @param array $assoc_args The optional command parameters.
     * @return boolean
     */
    public function run( array $args = [], array $assoc_args = [] ) : bool {
        if ( count( $args ) === 0 ) {
            return [
                'posts'     => $this->create_index( 'posts' ),
                'users'     => $this->create_index( 'users' ),
                'analytics' => $this->create_index( 'analytics' ),
            ];
        }
        elseif ( count( $args ) === 1 ) {
            return $this->create_index( $args[0] );
        }
        elseif ( count( $args ) > 1 ) {
            WP_CLI::error( 'RediPress: "create" command doesn\'t accept more than one parameter.' );
            return false;
        }
    }

    /**
     * Create posts index
     *
     * @param string $index The index to create.
     * @throws \Exception When the index type is not supported.
     * @return bool
     */
    public function create_index( string $index ) {
        switch ( $index ) {
            case 'posts':
                $return = apply_filters( 'redipress/index/posts/create', null );
                break;
            case 'users':
                $return = apply_filters( 'redipress/index/users/create', null );
                break;
            case 'analytics':
                $return = apply_filters( 'redipress/index/analytics/create', null );
                break;
            default:
                throw new \Exception( 'Index type ' . $index . ' is not supported.' );
                break;
        }

        switch ( $return ) {
            case true:
                WP_CLI::success( 'Index created.' );
                return true;
            case 'Index already exists. Drop it first!':
                WP_CLI::error( 'Index already exists.' );
                return false;
            default:
                WP_CLI::error( 'Unprecetended response: ' . $return );
                return false;
        }
    }

    /**
     * Returns the minimum amount of parameters the command accepts.
     *
     * @return integer
     */
    public static function get_min_parameters() : int {
        return 0;
    }

    /**
     * Returns the maximum amount of parameters the command accepts.
     *
     * @return integer
     */
    public static function get_max_parameters() : int {
        return 1;
    }
}
