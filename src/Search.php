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
     * QueryBuilder instance
     *
     * @var Search\QueryBuilder
     */
    protected $query_builder = null;

    /**
     * List of fields to include in search queries by default
     *
     * @var array
     */
    protected $default_search_fields = [
        'post_title',
        'post_excerpt',
        'post_content',
        'search_index',
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
        $search_query = $this->query_builder->get_query();

        $search_query = apply_filters( 'redipress/search_query', $search_query, $query );

        $search_query_string = apply_filters( 'redipress/search_query_string', implode( ' ', $search_query ) );

        $infields = array_unique( apply_filters( 'redipress/search_fields', $this->default_search_fields, $query ) );

        $return = array_unique( apply_filters( 'redipress/return_fields', [ 'post_object' ], $query ) );

        $limit = $query->query_vars['posts_per_page'];

        if ( isset( $query->query_vars['paged'] ) && $query->query_vars['paged'] > 1 ) {
            $offset = $query->query_vars['posts_per_page'] * ( $query->query_vars['paged'] - 1 );
        }
        else {
            $offset = 0;
        }

        return $this->client->raw_command(
            'FT.SEARCH',
            array_merge(
                [ $this->index, $search_query_string, 'INFIELDS', count( $infields ) ],
                $infields,
                [ 'RETURN', count( $return ) ],
                $return,
                [ 'LIMIT', $offset, $limit ]
            )
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
        $this->query_builder = new Search\QueryBuilder( $query );

        // Only filter front-end search queries
        if ( $this->query_builder->enable() ) {
            $results = $this->search( $query );

            if ( $results === '0' ) {
                return $request;
            }

            $count = $results[0];

            $this->results = $this->format_results( $results );

            $query->found_posts = $count;
            $query->max_num_pages( ceil( $count / $query->query_vars['posts_per_page'] ) );

            $query->using_redisearch = true;

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
        if ( $this->query_builder->enable() ) {
            return $this->results;
        }
        else {
            return $posts;
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
