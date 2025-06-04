<?php
/**
 * RediPress CLI create index command
 */

namespace Geniem\RediPress\CLI;

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
    public function run( array $args = [], array $assoc_args = [] ): bool {
        if ( count( $args ) === 0 ) {
            return $this->create_index( 'posts' )
                && $this->create_index( 'users' )
                && $this->create_index( 'analytics' );
        }
        elseif ( count( $args ) === 1 ) {
            return $this->create_index( $args[0] );
        }

        \WP_CLI::error( 'RediPress: "create" command doesn\'t accept more than one parameter.' );
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
        }

        switch ( $return ) {
            case true:
                \WP_CLI::success( 'Index created.' );
                return true;
            case 'Index already exists. Drop it first!':
                \WP_CLI::error( 'Index already exists.' );
            default:
                \WP_CLI::error( 'Unprecetended response: ' . $return );
        }
    }

    /**
     * Returns the minimum amount of parameters the command accepts.
     *
     * @return integer
     */
    public static function get_min_parameters(): int {
        return 0;
    }

    /**
     * Returns the maximum amount of parameters the command accepts.
     *
     * @return integer
     */
    public static function get_max_parameters(): int {
        return 1;
    }
}
