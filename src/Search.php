<?php
/**
 * RediPress search class file
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Admin,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Entity\NumericField,
    Geniem\RediPress\Entity\TagField,
    Geniem\RediPress\Entity\TextField,
    Geniem\RediPress\Redis\Client;

/**
 * RediPress search class
 */
class Search {

    /**
     * RediPress wrapper for the Predis client
     *
     * @var Client
     */
    protected $client;

    /**
     * Index
     *
     * @var string
     */
    protected $index;

    /**
     * Stored search results
     *
     * @var array
     */
    protected $results = [];

    /**
     * Construct the index object
     *
     * @param Client $client Client instance.
     */
    public function __construct( Client $client ) {
        $this->client = $client;

        // Get the index name from settings
        $this->index = Admin::get( 'index' );

        // Add search filters
        add_filter( 'posts_request', [ $this, 'posts_request' ], 10, 2 );
        add_filter( 'the_posts', [ $this, 'the_posts' ], 10, 2 );
    }

    /**
     * The search function itself
     *
     * @param \WP_Query $query The WP_Query object for the search.
     * @return array Search results.
     */
    public function search( \WP_Query $query ) : array {
        $search_term = $query->query_vars['s'];

        $search_term = apply_filters( 'redipress/search_term', $search_term, $query );

        $limit = $query->query_vars['posts_per_page'];

        if ( isset( $query->query_vars['paged'] ) && $query->query_vars['paged'] > 1 ) {
            $offset = $query->query_vars['posts_per_page'] * ( $query->query_vars['paged'] - 1 );
        }
        else {
            $offset = 0;
        }

        return $this->client->raw_command(
            'FT.SEARCH',
            [ $this->index, $search_term, 'RETURN', 1, 'post_object', 'LIMIT', $offset, $limit ]
        );
    }

    /**
     * Filter WordPress posts requests
     *
     * @param string    $request The original MySQL query.
     * @param \WP_Query $query   The WP_Query object.
     * @return string The resulting query.
     */
    public function posts_request( string $request, \WP_Query $query ) : string {
        // Only filter front-end search queries
        if (
            is_admin() ||
            ! $query->is_main_query() ||
            ( method_exists( $query, 'is_search' ) && ! $query->is_search() ) ||
            empty( $query->query_vars['s'] )
        ) {
            return $request;
        }

        $results = $this->search( $query );

        $count = $results[0];

        $this->results = $this->format_results( $results );

        $query->found_posts = $count;
        $query->max_num_pages( ceil( $count / $query->query_vars['posts_per_page'] ) );

        return 'SELECT * FROM $wpdb->posts WHERE 1=0';
    }

    /**
     * Return stored search results in the the_posts filter.
     *
     * @param array     $posts The original posts array.
     * @param \WP_Query $query The WP Query object.
     * @return array
     */
    public function the_posts( array $posts, \WP_Query $query ) : array {
        // Only filter front-end search queries
        if (
            is_admin() ||
            ! $query->is_main_query() ||
            ( method_exists( $query, 'is_search' ) && ! $query->is_search() ) ||
            empty( $query->query_vars['s'] )
        ) {
            return $posts;
        }

        return $this->results;
    }

    /**
     * Format RediSearch out formatted results to an array of WordPress posts.
     *
     * @param array $results Original array to format.
     * @return array
     */
    public function format_results( array $results ) : array {
        unset( $results[0] );

        $output = [];

        foreach ( $results as $result ) {
            if ( is_array( $result ) && count( $result ) > 1 ) {
                $output[] = maybe_unserialize( $result[1] );
            }
        }

        return $output;
    }
}
