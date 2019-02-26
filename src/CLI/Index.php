<?php
/**
 * RediPress CLI index command
 */

namespace Geniem\RediPress\CLI;

use WP_CLI;

/**
 * RediPress CLI index command class.
 */
class Index implements Command {

    /**
     * The command itself.
     *
     * @param array $args The command parameters.
     * @return boolean
     */
    public function run( array $args = [] ) : bool {
        if ( count( $args ) === 0 ) {
            $result = apply_filters( 'redipress/cli/index_all', 0 );

            WP_CLI::success( 'All ' . $result . ' posts indexed successfully!' );
            return true;
        }
        elseif ( count( $args ) === 1 ) {
            if ( ! is_numeric( $args[0] ) ) {
                WP_CLI::error( 'RediPress: second parameter of index must be an integer (post ID).' );
                return false;
            }
            else {
                do_action( 'redipress/cli/index_single', $args[0] );

                WP_CLI::success( 'Post by ID ' . $args[0] . ' indexed successfully!' );
                return true;
            }
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
