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
     * @return boolean
     */
    public function run( array $args = [] ) : bool {
        if ( count( $args ) === 0 ) {
            $return = apply_filters( 'redipress/create_index', null );

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
        elseif ( count( $args ) === 1 ) {
            WP_CLI::error( 'RediPress: "create" command does not take any additional parameters.' );
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
        return 0;
    }
}
