<?php
/**
 * RediPress CLI delete command.
 */

namespace Geniem\RediPress\CLI;

use WP_CLI;

/**
 * RediPress CLI delete command class.
 */
class Delete implements Command {

    /**
     * Class constructor.
     */
    public function __construct() {

        // Get RediPress settings.
        $this->client = apply_filters( 'redipress/client', null );
        $this->index  = \Geniem\RediPress\Settings::get( 'index' );
    }

    /**
     * The command itself.
     *
     * @param array $args The command parameters.
     * @param array $assoc_args The optional command parameters.
     * @return boolean
     */
    public function run( array $args = [], array $assoc_args = [] ) : bool {

        // Safely get all the parameters.
        $args = [
            'blog_id'   => $assoc_args['blog_id'] ?? '',
            'post_type' => $assoc_args['post_type'] ?? '',
            'limit'     => $assoc_args['limit'] ?? '50',
        ];

        // Blog_id and post_type.
        if ( ! empty( $args['blog_id'] ) || ! empty( $args['post_type'] ) || ! empty( $args['limit'] ) ) {

            return $this->delete_posts( $args );
        }

        WP_CLI::error( 'Delete didn\'t execute' );

        return false;
    }

    /**
     * Index single post
     *
     * @param int $args The query args.
     * @return bool
     */
    public function delete_posts( $args ) {

        // Get the posts.
        // Do the RediSearch query.
        $posts = $this->client->raw_command(
            'FT.SEARCH',
            [
                $this->index,
                $this->build_where( $args ),
                'RETURN',
                1,
                'post_id',
                'LIMIT',
                '0', // Limit from
                $args['limit'], // Limit max
            ],
        );

        // We need to get indexes of the doc_ids to be removed
        $doc_id_indexes = $this->get_valid_doc_id_indexes( $posts );

        $removed_doc_ids = [];

        // Loop through doc_ids to be removed and
        // do the delete from redisearch index.
        if ( ! empty( $doc_id_indexes ) && is_array( $doc_id_indexes ) ) {
            foreach ( $doc_id_indexes as $doc_id_index ) {

                $doc_id = $posts[ $doc_id_index ];

                $this->delete_index( $doc_id );
                $removed_doc_ids[] = $doc_id;
            }
        }
        // Fail.
        else {
            WP_CLI::error( 'There wasn\'t anything to delete' );
        }

        // Success message.
        if ( ! empty( $removed_doc_ids ) && is_array( $removed_doc_ids ) ) {
            $doc_ids_as_string = implode( ', ', $removed_doc_ids );
        }

        WP_CLI::success( 'Posts deleted successfully! Deleted doc_ids: ' . $doc_ids_as_string );

        return true;
    }

    /**
     * Get valid list of indexes.
     * $post[0] = doc_id
     * $post[1] = post_data with blog_id and post_id.
     *
     * @param array $posts An array of posts with doc_id[0], 
     * @return array Return an array of posts.
     */
    public function get_valid_doc_id_indexes( $posts ) {

        $doc_id_indexes = [];

        // RediPress returns at first doc_id and after that the data.
        if ( ! empty( $posts ) && is_array( $posts ) ) {
            foreach ( $posts as $key => $post ) {

                // Check if valid post and add doc_id index to the deleted $doc_id_indexes.
                if ( ! empty( $post ) && is_array( $post ) ) {
                    $doc_id_indexes[] = (int) $key - 1;
                }
            }
        }

        return $doc_id_indexes;
    }

    /**
     * Delete index.
     *
     * @param [type] $doc_id
     * @return void
     */
    protected function delete_index( $doc_id ) {

        // Do the delete.
        if ( is_string( $doc_id ) ) {

            // Do the delete from index.
            $this->client->raw_command(
                'FT.DEL',
                [
                    $this->index,
                    $doc_id,
                    'DD',
                ]
            );
        }

        return true;
    }

    /**
     * Build where clause for the query.
     *
     * @param string $blog_id Blog id. 
     * @param string $post_type Post type as a string.
     * @param string $limit Limit.
     * @return string Where clause as a string.
     */
    public function build_where( $args ) {

        $where = '';

        if ( ! empty( $args['post_type'] ) ) {
            $where = '@post_type:' . $args['post_type'];
        }

        if ( ! empty( $args['blog_id'] ) ) {
            $where = $where . ' @blog_id:(' . $args['blog_id'] . ')';
        }

        return $where;
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
        return 3;
    }
}