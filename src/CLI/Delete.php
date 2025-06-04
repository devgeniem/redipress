<?php
/**
 * RediPress CLI delete command.
 */

namespace Geniem\RediPress\CLI;

use Geniem\RediPress\Redis\Client;

/**
 * RediPress CLI delete command class.
 */
class Delete implements Command {

    /**
     * RediPress wrapper for the Predis client
     *
     * @var Client
     */
    protected $client;

    /**
     * Index name
     *
     * @var string
     */
    protected $index;

    /**
     * Class constructor.
     */
    public function __construct() {
        $this->client = \apply_filters( 'redipress/client', null );
    }

    /**
     * The command itself.
     *
     * @param array $args The command parameters.
     * @param array $assoc_args The optional command parameters.
     * @return boolean
     */
    public function run( array $args = [], array $assoc_args = [] ): bool {
        // Default limit to 100.
        $limit = $assoc_args['limit'] ?? '100';

        // Remove the optional limit parameter, that should not be passed to the Redisearch query.
        unset( $assoc_args['limit'] );

        if ( count( $args ) === 1 ) {
            $this->index = $args[0];
            return $this->delete_posts( $assoc_args, $limit );
        }
        elseif ( count( $args ) > 1 ) {
            \WP_CLI::error( 'RediPress: "delete" command does not accept more than one parameter.' );
        }

        return false;
    }

    /**
     * Delete posts from the index.
     *
     * @param array $args The query args.
     * @param int   $limit Query limit.
     * @return boolean
     */
    public function delete_posts( $args, $limit ) {
        $doc_ids = $this->get_doc_ids( $args, $limit );

        $removed_doc_ids = [];

        // Loop through doc_ids to be removed and
        // run the delete command in RediSearch index.
        if ( ! empty( $doc_ids ) && is_array( $doc_ids ) ) {
            foreach ( $doc_ids as $doc_id ) {
                $this->delete_from_index( $doc_id );
                $removed_doc_ids[] = $doc_id;
            }
        }
        // Fail.
        else {
            \WP_CLI::error( 'No posts found on the given criteria.' );
        }

        $doc_ids_as_string = implode( ', ', $removed_doc_ids );

        // Success message.
        \WP_CLI::success( 'Posts deleted successfully! Deleted doc_ids: ' . $doc_ids_as_string );

        return true;
    }

    /**
     * Get the doc ids.
     *
     * @param array $args The query args.
     * @param int   $limit Query limit.
     * @return array An array of doc ids.
     */
    protected function get_doc_ids( $args, $limit ) {

        // Get the posts.
        // Do the RediSearch query.
        $doc_ids = $this->client->raw_command(
            'FT.SEARCH',
            [
                $this->index,
                $this->build_where( $args ),
                'RETURN',
                1,
                'post_id',
                'LIMIT',
                '0', // Limit from
                $limit, // Limit max
                'NOCONTENT', // NOCONTENT return only doc ids.
            ],
        );

        // The first item is the count of the results.
        unset( $doc_ids[0] );

        return $doc_ids;
    }

    /**
     * Delete from the index.
     *
     * @param string $doc_id RediSearch doc_id.
     * @return void
     */
    protected function delete_from_index( $doc_id ) {
        if ( is_string( $doc_id ) ) {
            // Delete post from the index.
            $this->client->raw_command(
                'DEL',
                [
                    $this->index,
                    $doc_id,
                ]
            );
        }
    }

    /**
     * Build where clause for the query.
     *
     * @param array $query_vars Query variables.
     * @return string RediSearch where clause as a string.
     */
    public function build_where( $query_vars ) {

        // Init.
        $where = '';

        // Fail fast.
        if ( empty( $query_vars ) ) {
            return $where;
        }

        // Count not empty values.
        $last_idx = count( $query_vars ) - 1;
        $idx      = 0;

        // Loop through query_vars and
        // add valid query_vars to the where clause.
        if ( is_array( $query_vars ) ) {
            foreach ( $query_vars as $key => $var ) {
                if ( ! empty( $var ) ) {
                    // Add the clause.
                    $where = $where . '@' . $key . ':(' . $var . ')';

                    // Add space if not the last query parameter.
                    if ( $idx !== $last_idx ) {
                        $where = $where . ' ';
                    }

                    ++$idx;
                }
            }
        }

        return $where;
    }

    /**
     * Returns the minimum amount of parameters the command accepts.
     *
     * @return integer
     */
    public static function get_min_parameters(): int {
        return 1;
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
