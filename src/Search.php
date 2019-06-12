<?php
/**
 * RediPress search class file
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Admin,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility;
use function GuzzleHttp\Promise\each;

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
     * Index name
     *
     * @var string
     */
    protected $index;

    /**
     * Index info
     *
     * @var array
     */
    protected $index_info;

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
     * @param Client $client     Client instance.
     * @param array  $index_info Index information.
     */
    public function __construct( Client $client, array $index_info ) {
        $this->client     = $client;
        $this->index_info = $index_info;

        // Get the index name from settings
        $this->index = Admin::get( 'index' );

        // Add search filters
        add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 10, 2 );
    }

    /**
     * Return the client instance.
     *
     * @return Client
     */
    public function get_client() : Client {
        return $this->client;
    }

    /**
     * The search function itself
     *
     * @param \WP_Query $query The WP_Query object for the search.
     * @return mixed Search results.
     */
    public function search( \WP_Query $query ) {
        // Create the search query
        $search_query = $this->query_builder->get_query();

        // Filter the search query as an array
        $search_query = apply_filters( 'redipress/search_query', $search_query, $query );

        // Filter the search query as a string
        $search_query_string = apply_filters( 'redipress/search_query_string', implode( ' ', $search_query ), $query );

        // Filter the query string for the count feature
        $count_search_query_string = apply_filters( 'redipress/count_search_query_string', $search_query_string );

        // Filter the list of fields from which the search is conducted.
        $infields = array_unique( apply_filters( 'redipress/search_fields', $this->default_search_fields, $query ) );

        // Filter the list of fields that will be returned with the query.
        $return = array_unique( apply_filters( 'redipress/return_fields', [ 'post_object', 'post_date', 'post_type', 'post_id', 'post_parent' ], $query ) );

        // Determine the limit and offset parameters.
        $limit = $query->query_vars['posts_per_page'];

        if ( isset( $query->query_vars['paged'] ) && $query->query_vars['paged'] > 1 ) {
            $offset = $query->query_vars['posts_per_page'] * ( $query->query_vars['paged'] - 1 );
        }
        elseif ( isset( $query->query_vars['offset'] ) ) {
            $offset = $query->query_vars['offset'];
        }
        else {
            $offset = 0;
        }

        // Get the sortby parameter
        $sortby = $this->query_builder->get_sortby() ?: [];

        if ( empty( $sortby ) && ! empty( $query->query_vars['s'] ) ) {
            // Form the search query
            $command = array_merge(
                [ $this->index, $search_query_string, 'INFIELDS', count( $infields ) ],
                $infields,
                [ 'RETURN', count( $return ) ],
                $return,
                $sortby,
                [ 'LIMIT', $offset, $limit ]
            );

            // Run the command itself. FT.SEARCH is used because it allows us to sort the query by relevance
            $results = $this->client->raw_command(
                'FT.SEARCH',
                $command
            );

            $index = 0;

            // Remove the intermediary docIds to make the format match the one from FT.AGGREGATE
            $results = array_filter( $results, function( $item ) use ( &$index ) {
                if ( $index++ > 0 && ! is_array( $item ) ) {
                    return false;
                }

                return true;
            });

            // Store the search query string so at in can be debugged easily via WP_Query.
            $query->redisearch_query = 'FT.SEARCH ' . implode( ' ', $command );
        }
        else {
            // Form the return field clause
            $return_fields = array_map( function( string $field ) : array {

                $return = [
                    'REDUCE',
                    'FIRST_VALUE',
                    1,
                    '@' . $field,
                    'AS',
                    $field,
                ];

                return $return;
            }, $return );

            // Form the final query
            $command = array_merge(
                [ $this->index, $search_query_string ],
                [ 'LOAD', 1, '@post_object' ],
                $this->query_builder->get_groupby(),
                array_reduce( $return_fields, 'array_merge', [] ),
                array_merge( $sortby ),
                [ 'LIMIT', $offset, $limit ]
            );

            // Run the command itself. FT.AGGREGATE is used to allow multiple sortby queries
            $results = $this->client->raw_command(
                'FT.AGGREGATE',
                $command
            );

            if ( ! is_array( $results ) ) {
                $results = [];
            }

            // Clean the aggregate output to match usual key-value pairs
            $results = array_map( function( $result ) {
                if ( is_array( $result ) ) {
                    return array_map( function( $item ) {
                        // If we are dealing with an array, just turn it into a string
                        if ( is_array( $item ) ) {
                            return implode( ' ', $item );
                        }
                        else {
                            return $item;
                        }
                    }, $result );
                }
                else {
                    return $result;
                }
            }, $results );

            // Store the search query string so at in can be debugged easily via WP_Query.
            $query->redisearch_query = 'FT.AGGREGATE ' . implode( ' ', $command );
        }

        // Run the count post types command
        $counts = $this->client->raw_command(
            'FT.AGGREGATE',
            [ $this->index, $count_search_query_string, 'GROUPBY', 1, '@post_type', 'REDUCE', 'COUNT', '0', 'AS', 'amount' ]
        );

        // Return the results through a filter
        return apply_filters( 'redipress/search_results', (object) [
            'results' => $results,
            'counts'  => $counts,
        ], $query );
    }

    /**
     * Filter WordPress posts requests
     *
     * @param array|null $posts An empty array of posts.
     * @param \WP_Query  $query The WP_Query object.
     * @return array Results or null if no results.
     */
    public function posts_pre_query( ?array $posts, \WP_Query $query ) : ?array {
        global $wpdb;

        // If the query is empty, we are probably dealing with the front page and we want to skip RediSearch with that.
        if ( empty( $query->query ) ) {
            $query->is_front_page = true;
            return null;
        }

        $this->query_builder = new Search\QueryBuilder( $query, $this->index_info );

        // If we don't have explicitly defined post type query, use the public ones
        if ( empty( $query->query['post_type'] ) ) {
            $post_types = get_post_types([
                'public'              => true,
                'publicly_queryable'  => true,
                'exclude_from_search' => false,
            ], 'names' );

            $post_types = apply_filters( 'redipress/search_post_types', $post_types );

            $query->query['post_type']      = $post_types;
            $query->query_vars['post_type'] = $post_types;
        }

        // If we don't have explicitly defined post status, just use publish
        if ( empty( $query->query['post_status'] ) ) {
            $query->query['post_status']      = 'publish';
            $query->query_vars['post_status'] = 'publish';
        }

        // Only filter front-end search queries
        if ( $this->query_builder->enable() ) {
            do_action( 'redipress/before_search', $this, $query );

            $raw_results = $this->search( $query );

            if ( empty( $raw_results->results ) || $raw_results->results[0] === 0 ) {
                $query->redipress_no_results = true;
                return null;
            }

            $count = $raw_results->results[0];

            $results = $this->format_results( $raw_results->results );

            $query->post_type_counts = [];

            if ( is_array( $raw_results->counts ) ) {
                unset( $raw_results->counts[0] );

                foreach ( $raw_results->counts as $post_type ) {
                    $formatted = Utility::format( $post_type );

                    $query->post_type_counts[ $formatted['post_type'] ] = $formatted['amount'];
                }
            }

            // Filter the search results after the search has been conducted.
            $results = apply_filters(
                'redipress/formatted_search_results',
                $results,
                Utility::format( $raw_results->results )
            );

            $query->found_posts = $count;
            $query->max_num_pages( ceil( $count / $query->query_vars['posts_per_page'] ) );

            $query->using_redisearch = true;

            return $results;
        }
        else {
            return null;
        }
    }

    /**
     * Format RediSearch output formatted results to an array of WordPress posts.
     *
     * @param array $results Original array to format.
     * @return array
     */
    public function format_results( array $results ) : array {
        $results = Utility::format( $results );

        return array_map( function( array $result ) : ?\WP_Post {
            $formatted = Utility::format( $result );

            return maybe_unserialize( $formatted['post_object'] );
        }, $results );
    }
}
