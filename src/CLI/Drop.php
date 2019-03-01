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
     * @return boolean
     */
    public function run( array $args = [] ) : bool {
        if ( count( $args ) === 0 ) {
            $return = apply_filters( 'redipress/drop_index', null );

            switch ( $return ) {
                case true:
                    WP_CLI::success( 'Index deleted.' );
                    return true;
                case 'Unknown Index name':
                    WP_CLI::error( 'There were no index to delete or it was created under another name.' );
                    return false;
                default:
                    WP_CLI::error( 'Unprecetended response: ' . $return );
                    return false;
            }
        }
        elseif ( count( $args ) === 1 ) {
            WP_CLI::error( 'RediPress: "drop" command does not take any additional parameters.' );
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
