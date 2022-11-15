<?php
/**
 * RediPress PostQuery class file
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Settings,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility;

/**
 * RediPress PostQuery class
 */
class PostQuery {

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
        $this->index = Settings::get( 'posts_index' );

        // Add search filters
        add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 10, 2 );

        // Reverse filter for getting the Search instance.
        add_filter( 'redipress/search_instance', function( $value ) {
            return $this;
        }, 1, 1 );
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

        $infields = array_merge( $this->default_search_fields, $this->query_builder->get_search_fields() );

        // Filter the list of fields from which the search is conducted.
        $infields = array_unique( apply_filters( 'redipress/search_fields', $infields, $query ) );

        // Filter the list of fields that will be returned with the query.
        $return = array_unique( apply_filters( 'redipress/return_fields', $this->query_builder->get_return_fields(), $query ) );

        // If we are dealing with a singular view the limit and offset are clear.
        if ( $query->is_singular() ) {
            $limit  = 1;
            $offset = 0;
        }
        else {
            // Determine the limit and offset parameters.
            $limit = $query->query_vars['posts_per_page'];

            if ( isset( $query->query_vars['offset'] ) ) {
                $offset = $query->query_vars['offset'];
            }
            elseif ( isset( $query->query_vars['paged'] ) && $query->query_vars['paged'] > 1 ) {
                $offset = $query->query_vars['posts_per_page'] * ( $query->query_vars['paged'] - 1 );
            }
            else {
                $offset = 0;
            }
        }

        if ( $limit > 0 ) {
            $limits = [ 'LIMIT', $offset, $limit ];
        }
        else {
            $limits = [ 'LIMIT', 0, 100000 ];
        }

        // Get query parameters
        $sortby           = $this->query_builder->get_sortby() ?: [];
        $applies          = $this->query_builder->get_applies() ?: [];
        $filters          = $this->query_builder->get_filters() ?: [];
        $geofilter        = $this->query_builder->get_geofilter() ?: [];
        $reduce_functions = $this->query_builder->get_reduce_functions() ?: [];
        $groupby          = $this->query_builder->get_groupby() ?: [];

        // Filters for query parts
        $sortby           = apply_filters( 'redipress/sortby', $sortby );
        $applies          = apply_filters( 'redipress/applies', $applies );
        $filters          = apply_filters( 'redipress/filters', $filters );
        $geofilter        = apply_filters( 'redipress/geofilter', $geofilter );
        $groupby          = apply_filters( 'redipress/groupby', $groupby );
        $reduce_functions = apply_filters( 'redipress/reduce_functions', $reduce_functions );
        $load             = apply_filters( 'redipress/load', [ 'post_object' ] );

        if ( ! empty( $sortby ) || ! empty( $applies ) || ! empty( $filters ) || ! empty( $groupby ) ) {
            if ( empty( $groupby ) ) {
                $groupby = [ 'post_id' ];
            }

            // Form the return field clause
            $return_fields = array_map( function( string $field ) use ( $reduce_functions ) : array {
                $return = [
                    'REDUCE',
                    $reduce_functions[ $field ] ?? 'FIRST_VALUE',
                    1,
                    '@' . $field,
                    'AS',
                    $field,
                ];

                return $return;
            }, $return );

            // Form the final query
            $command = array_merge(
                [ $this->index, $search_query_string, 'INFIELDS', count( $infields ) ],
                $geofilter,
                $infields,
                array_merge( [ 'LOAD', count( $load ) ], array_map( function( $l ) {
                    return '@' . $l;
                }, $load ) ),
                array_merge( [ 'GROUPBY', count( $groupby ) ], array_map( function( $g ) {
                    return '@' . $g;
                }, $groupby ) ),
                array_reduce( $return_fields, 'array_merge', [] ),
                array_merge( $applies ),
                array_merge( $filters ),
                array_merge( $sortby ),
                $limits
            );

            // Run the command itself. FT.AGGREGATE is used to allow multiple sortby queries
            $results = $this->client->raw_command(
                'FT.AGGREGATE',
                $command
            );

            if ( ! is_array( $results ) ) {
                $results = [];
            }

            // If we have applies and filters, we need to calculate the count in a separate query
            if ( ! empty( $applies ) || ! empty( $filters ) ) {
                preg_match_all( '/@([^ ]+)/', implode( ' ', array_merge( $filters ) ), $matches );

                $filter_keys = $matches[1];

                $command = array_merge(
                    [ $this->index, $search_query_string, 'INFIELDS', count( $infields ) ],
                    $geofilter,
                    $infields,
                    array_merge( $applies ),
                    array_merge( $filters ),
                    array_merge( [ 'GROUPBY', count( $filter_keys ) ], array_map( function( $f ) {
                        return '@' . $f;
                    }, $filter_keys ) ),
                    [ 'REDUCE', 'COUNT', 0, 'AS', 'count' ],
                );

                // Run the command itself.
                $count_result = $this->client->raw_command(
                    'FT.AGGREGATE',
                    $command
                );

                $count_result = Utility::format( $count_result[1] ?? [] );

                // Use the count from our separate query if applicable
                $results[0] = $count_result['count'] ?? $results[0];
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

            // Store the search query string so that in can be debugged easily via WP_Query.
            $query->redisearch_query = 'FT.AGGREGATE ' . implode( ' ', array_map( function( $comm ) {
                if ( \strpos( $comm, ' ' ) !== false ) {
                    return '"' . $comm . '"';
                }
                else {
                    return $comm;
                }
            }, $command ) );
        }
        else {
            /**
             * Define the scorer
             *
             * @see https://oss.redislabs.com/redisearch/Scoring/
             */
            $scorer       = apply_filters( 'redipress/scorer', 'TFIDF', $query );
            $scorer_array = [];

            // The default scorer (TFIDF) doesn't require the argument.
            if ( ! empty( $scorer ) && $scorer !== 'TFIDF' ) {
                $scorer_array = [ 'SCORER', $scorer ];
            }

            // Form the final query
            $command = array_merge(
                [ $this->index, $search_query_string, 'INFIELDS', count( $infields ) ],
                $infields,
                $geofilter,
                [ 'RETURN', count( $return ) ],
                $return,
                $limits,
                $scorer_array,
            );

            // Run the command itself. FT.AGGREGATE is used to allow multiple sortby queries
            $results = $this->client->raw_command(
                'FT.SEARCH',
                $command
            );

            // Store the search query string so that in can be debugged easily via WP_Query.
            $query->redisearch_query = 'FT.SEARCH ' . implode( ' ', array_map( function( $comm ) {
                if ( \strpos( $comm, ' ' ) !== false ) {
                    return '"' . $comm . '"';
                }
                else {
                    return $comm;
                }
            }, $command ) );
        }

        // Run the count post types command
        $counts = $this->client->raw_command(
            'FT.AGGREGATE',
            array_merge(
                [ $this->index, $count_search_query_string, 'INFIELDS', count( $infields ) ],
                $infields,
                [ 'GROUPBY', 1, '@post_type', 'REDUCE', 'COUNT', '0', 'AS', 'amount' ]
            )
        );

        \do_action( 'redipress/debug_query', $query, $results, 'posts' );

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

        // We don't want to mess with the singular queries.
        if ( $query->is_singular() ) {
            add_filter( 'posts_results', [ $this, 'posts_results_single' ], 10, 2 );

            return null;
        }

        // If we are on a multisite and have not explicitly defined that
        // we want to do stuff with other sites, use the current site
        if ( is_multisite() ) {
            if ( empty( $query->query['blog'] ) ) {
                if ( ! empty( $query->query_vars['blog'] ) ) {
                    $query->query['blog'] = $query->query_vars['blog'];
                }
                else {
                    $query->query['blog']      = [ \get_current_blog_id() ];
                    $query->query_vars['blog'] = [ \get_current_blog_id() ];
                }
            }
            else {
                $query->query_vars['blog'] = $query->query['blog'];
            }
        }

        $this->query_builder = new Search\PostQueryBuilder( $query, $this->index_info );

        $post_status = apply_filters( 'redipress/post_status', $query->query['post_status'] ?? null );

        // If we don't have explicitly defined post status, just use publish
        if ( is_null( $post_status ) ) {
            $query->query['post_status']      = 'publish';
            $query->query_vars['post_status'] = 'publish';
        }
        else {
            $query->query['post_status']      = $post_status;
            $query->query_vars['post_status'] = $post_status;
        }

        // Only filter front-end search queries
        if ( $this->query_builder->enable() ) {
            do_action( 'redipress/before_search', $this, $query );

            $raw_results = $this->search( $query );

            if ( empty( $raw_results->results ) || $raw_results->results[0] === 0 ) {
                $query->redipress_no_results = true;

                if ( ! empty( $query->query_vars['post_type'] ) && is_array( $query->query_vars['post_type'] ) ) {
                    $query->query_vars['post_type'] = implode( ',', $query->query_vars['post_type'] );
                }

                if ( Settings::get( 'fallback' ) ) {
                    return apply_filters( 'redipress/no_results', null, $query );
                }
                else {
                    return apply_filters( 'redipress/no_results', [], $query );
                }
            }

            $count = $raw_results->results[0];

            $query->post_type_counts = [];

            if ( is_array( $raw_results->counts ) ) {
                unset( $raw_results->counts[0] );

                foreach ( $raw_results->counts as $post_type ) {
                    $formatted = Utility::format( $post_type );

                    $query->post_type_counts[ $formatted['post_type'] ] = $formatted['amount'];
                }
            }

            $results = $this->format_results( $raw_results->results );

            // Filter the search results after the search has been conducted.
            $results = apply_filters(
                'redipress/formatted_search_results',
                $results,
                Utility::format( $raw_results->results )
            );

            $query->found_posts   = $count;
            $query->max_num_pages = ceil( $count / $query->query_vars['posts_per_page'] );

            $query->using_redisearch = true;

            if ( isset( $query->query['attributes'] ) && $query->query['attributes'] === 'ids' ) {
                $results = \array_column( $results, 'ID' );
            }

            return array_values( $results );
        }
        else {
            return null;
        }
    }

    /**
     * Get the single post from RediSearch for customized features
     *
     * @param array     $posts The original posts array.
     * @param \WP_Query $query The WP_Query instance.
     * @return array
     */
    public function posts_results_single( array $posts, \WP_Query $query ) : array {
        remove_filter( 'posts_results', [ $this, 'posts_results_single' ], 10 );

        return array_map( '\\Geniem\\RediPress\\get_post', array_column( $posts, 'ID' ) );
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
            if ( empty( $result['post_object'] ) ) {
                $formatted = Utility::format( $result );
                $post_obj  = $formatted['post_object'] ?? null;
            }
            else {
                $post_obj = $result['post_object'];
            }

            return maybe_unserialize( $post_obj );
        }, $results );
    }
}
