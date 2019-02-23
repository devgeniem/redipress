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
     * List of supported query vars for WP_Query.
     *
     * @var array
     */
    protected $supported_query_vars = [
        's',
        'categories__in',
        'categories__and',
    ];

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
     * @return mixed Search results.
     */
    public function search( \WP_Query $query ) {
        $search_query = $this->build_query( $query );

        $search_query = apply_filters( 'redipress/search_query', $search_query, $query );

        $limit = $query->query_vars['posts_per_page'];

        if ( isset( $query->query_vars['paged'] ) && $query->query_vars['paged'] > 1 ) {
            $offset = $query->query_vars['posts_per_page'] * ( $query->query_vars['paged'] - 1 );
        }
        else {
            $offset = 0;
        }

        return $this->client->raw_command(
            'FT.SEARCH',
            [ $this->index, $search_query, 'RETURN', 1, 'post_object', 'LIMIT', $offset, $limit ]
        );
    }

    /**
     * Build the RediSearch search query
     *
     * @param \WP_Query $wp_query The WP_Query object.
     * @return string
     */
    public function build_query( \WP_Query $wp_query ) : string {
        $query_string = [];

        if ( ! empty( $wp_query->query_vars['s'] ) ) {
            $query[] = $wp_query->query_vars['s'];
        }

        if ( ! empty( $wp_query->query_vars['category__in'] ) ) {
            $cats = $wp_query->query_vars['category__in'];

            $cat = is_array( $cats ) ? $cats : [ $cats ];

            $query[] = '@category:{' . implode( '|', $cat ) . '}';
        }

        if ( ! empty( $wp_query->query_vars['category__and'] ) ) {
            $cats = $wp_query->query_vars['category__and'];

            $cat = is_array( $cats ) ? $cats : [ $cats ];

            $query[] = implode( ' ', array_map( function( $cat ) {
                return '@category:{' . $cat . '}';
            }, $cat ));
        }

        return implode( ' ', $query );
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
        if ( $this->enable() ) {
            $results = $this->search( $query );

            $count = $results[0];

            $this->results = $this->format_results( $results );

            $query->found_posts = $count;
            $query->max_num_pages( ceil( $count / $query->query_vars['posts_per_page'] ) );

            return 'SELECT * FROM $wpdb->posts WHERE 1=0';
        }
        else {
            return $request;
        }
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
        if ( $this->enable() ) {
            return $this->results;
        }
        else {
            return $posts;
        }
    }

    /**
     * A method to determine whether we want to override the normal query or not.
     *
     * @param \WP_Query $wp_query The WP Query object.
     * @return boolean
     */
    protected function enable( \WP_Query $wp_query ) : bool {
        $query_vars           = $wp_query->query_vars;
        $supported_query_vars = $this->supported_query_vars;

        if ( count( array_diff( $query_vars, $supported_query_vars ) ) > 0 ) {
            return false;
        }
        else {
            return true;
        }
    }

    /**
     * Format RediSearch output formatted results to an array of WordPress posts.
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
