<?php
/**
 * RediPress CLI drop index command
 */

namespace Geniem\RediPress\CLI;

use WP_CLI;

/**
 * RediPress CLI drop index command class.
 */
class Drop implements Command {

    /**
     * The command itself.
     *
     * @param array $args The command parameters.
     * @param array $assoc_args The optional command parameters.
     * @return boolean
     */
    public function run( array $args = [], array $assoc_args = [] ) : bool {
        if ( count( $args ) === 1 ) {
            return $this->drop_index( $args[0], $assoc_args );
        }
        elseif ( count( $args ) > 1 ) {
            WP_CLI::error( 'RediPress: "drop" command does not accept more than two parameters.' );
            return false;
        }
    }

    /**
     * Drop the index
     *
     * @param string $index The index to delete.
     * @throws \Exception If index type is not supported.
     * @return bool
     */
    public function drop_index( string $index, array $assoc_args ) {
        switch ( $index ) {
            case 'posts':
                $return = apply_filters( 'redipress/drop_post_index', null, $assoc_args );
                break;
            case 'users':
                $return = apply_filters( 'redipress/drop_user_index', null, $assoc_args );
                break;
            default:
                throw new \Exception( 'Index type ' . $index . ' is not supported.' );
                break;
        }

        switch ( $return ) {
            case true:
                WP_CLI::success( 'Index deleted.' );
                return true;
            case 'Unknown Index name':
                WP_CLI::error( 'There was no index to delete or it was created under another name.' );
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
        return 1;
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
