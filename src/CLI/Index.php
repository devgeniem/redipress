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
    public function run( array $args = [], array $assoc_args = [] ) : bool {
        if ( count( $args ) === 0 ) {
            return $this->index_posts( $assoc_args );
        }
        else {
            switch ( $args[0] ) {
                case 'posts':
                    if ( count( $args ) === 1 ) {
                        return $this->index_posts( $assoc_args );
                    }
                    elseif ( count( $args ) === 2 ) {
                        if ( is_numeric( $args[1] ) ) {
                            return $this->index_single( $args[1] );
                        }
                        elseif ( $args[1] === 'missing' ) {
                            return $this->index_missing( $assoc_args );
                        }
                        else {
                            WP_CLI::error( 'RediPress: "index" does not accept second parameter "' . $args[1] . '"' );
                            return false;
                        }
                    }
                    break;
                case 'users':
                    if ( count( $args ) === 1 ) {
                        return $this->index_users();
                    }
                    elseif ( count( $args ) === 2 ) {
                        if ( is_numeric( $args[1] ) ) {
                            return $this->index_single_user( $args[1] );
                        }
                        else {
                            WP_CLI::error( 'RediPress: "index" does not accept second parameter "' . $args[1] . '"' );
                            return false;
                        }
                    }
                    break;
            }
        }
    }

    /**
     * Index all posts
     *
     * @return bool
     */
    public function index_posts( array $assoc_args = [] ) {
        $result = apply_filters( 'redipress/cli/index_all', null, $assoc_args );

        WP_CLI::success( 'All ' . $result . ' posts indexed successfully!' );
        return true;
    }

    /**
     * Index single post
     *
     * @param integer $id The ID to index.
     * @return bool
     */
    public function index_single( int $id ) {
        do_action( 'redipress/cli/index_single', $id );

        WP_CLI::success( 'Post by ID ' . $id . ' indexed successfully!' );
        return true;
    }

    /**
     * Index posts that are missing from the index
     *
     * @return bool
     */
    public function index_missing() {
        $result = apply_filters( 'redipress/cli/index_missing', 0 );

        WP_CLI::success( $result . ' posts indexed successfully!' );
        return true;
    }

    /**
     * Index all users
     *
     * @return bool
     */
    public function index_users() {
        $result = apply_filters( 'redipress/cli/index_all_users', 0 );

        WP_CLI::success( 'All ' . $result . ' users indexed successfully!' );
        return true;
    }

    /**
     * Index single user
     *
     * @param integer $id The ID to index.
     * @return bool
     */
    public function index_single_user( int $id ) {
        do_action( 'redipress/cli/index_single_user', $id );

        WP_CLI::success( 'User by ID ' . $id . ' indexed successfully!' );
        return true;
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
        return 2;
    }
}
