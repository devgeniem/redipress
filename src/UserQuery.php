<?php
/**
 * RediPress UserQuery class file
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Settings;
use Geniem\RediPress\Redis\Client;
use Geniem\RediPress\Utility;

/**
 * RediPress UserQuery class
 */
class UserQuery {

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
     * UserQueryBuilder instance
     *
     * @var Search\UserQueryBuilder
     */
    protected $query_builder = null;

    /**
     * Default search fields
     *
     * @var array
     */
    protected $default_search_fields = [
        'user_id',
        'user_login',
        'user_url',
        'user_email',
        'user_nicename',
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
        $this->index = Settings::get( 'users_index' );

        // Add search filters
        \add_filter( 'users_pre_query', [ $this, 'users_pre_query' ], 10, 2 );
    }

    /**
     * Return the client instance.
     *
     * @return Client
     */
    public function get_client(): Client {
        return $this->client;
    }

    /**
     * The search function itself
     *
     * @param \WP_Query|\WP_User_Query $query The WP_Query or WP_User_query object for the search.
     * @return mixed Search results.
     */
    public function search( $query ) {
        // Create the search query
        $search_query = $this->query_builder->get_query();

        // Filter the search query as an array
        $search_query = \apply_filters( 'redipress/search_query', $search_query, $query );

        // Filter the search query as a string
        $search_query_string = \apply_filters( 'redipress/search_query_string', implode( ' ', $search_query ), $query );

        if ( ! $this->query_builder->use_only_defined_search_fields() ) {
            $search_fields = array_unique( array_merge( $this->query_builder->get_search_fields(), $this->default_search_fields ) );
        }
        else {
            $search_fields = $this->query_builder->get_must_use_search_fields();
        }

        // Filter the list of fields from which the search is conducted.
        $infields = array_unique( \apply_filters( 'redipress/search_fields', $search_fields, $query ) );

        // Filter the list of fields that will be returned with the query.
        $return = array_unique( \apply_filters( 'redipress/return_fields', $this->query_builder->get_return_fields(), $query ) );

        // Determine the limit and offset parameters.
        $limit = $query->query_vars['number'] ?: \get_option( 'posts_per_page' );

        if ( isset( $query->query_vars['paged'] ) && $query->query_vars['paged'] > 1 ) {
            $offset = $limit * ( $query->query_vars['paged'] - 1 );
        }
        elseif ( isset( $query->query_vars['offset'] ) ) {
            $offset = $query->query_vars['offset'];
        }
        else {
            $offset = 0;
        }

        // Get query parameters
        $sortby           = $this->query_builder->get_sortby() ?: [];
        $applies          = $this->query_builder->get_applies() ?: [];
        $filters          = $this->query_builder->get_filters() ?: [];
        $geofilter        = $this->query_builder->get_geofilter() ?: [];
        $reduce_functions = $this->query_builder->get_reduce_functions() ?: [];

        // Filters for query parts
        $sortby           = \apply_filters( 'redipress/sortby', $sortby );
        $applies          = \apply_filters( 'redipress/applies', $applies );
        $filters          = \apply_filters( 'redipress/filters', $filters );
        $geofilter        = \apply_filters( 'redipress/geofilter', $geofilter );
        $reduce_functions = \apply_filters( 'redipress/reduce_functions', $reduce_functions );

        if ( empty( $sortby ) && empty( $applies ) && empty( $filters ) && ! empty( $query->query_vars['search'] ) ) {
            // Form the search query
            $command = array_merge(
                [ $this->index, $search_query_string, 'INFIELDS', count( $infields ) ],
                $geofilter,
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

            if ( ! is_array( $results ) ) {
                $results = [];
            }

            $index = 0;

            // Remove the intermediary docIds to make the format match the one from FT.AGGREGATE
            $results = array_filter( $results, function ( $item ) use ( &$index ) {
                if ( $index++ > 0 && ! is_array( $item ) ) {
                    return false;
                }

                return true;
            });

            // Store the search query string so at in can be debugged easily via WP_Query.
            $query->request = 'FT.SEARCH ' . implode( ' ', $command );
        }
        else {
            $groupby = $this->query_builder->get_groupby();

            $groupby = \apply_filters( 'redipress/user_groupby', $groupby );

            $return_fields = array_map( function ( string $field ) use ( $reduce_functions ): array {
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
                [ 'LOAD', 1, '@user_object' ],
                array_merge( [ 'GROUPBY', count( $groupby ) ], array_map( function ( $g ) {
                    return '@' . $g;
                }, $groupby ) ),
                array_reduce( $return_fields, 'array_merge', [] ),
                array_merge( $applies ),
                array_merge( $filters ),
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
            $results = array_map( function ( $result ) {
                if ( is_array( $result ) ) {
                    return array_map( function ( $item ) {
                        // If we are dealing with an array, just turn it into a string
                        if ( is_array( $item ) ) {
                            return implode( ' ', $item );
                        }

                        return $item;
                    }, $result );
                }

                return $result;
            }, $results );

            // Store the search query string so that in can be debugged easily via WP_Query.
            $query->request = 'FT.AGGREGATE ' . implode( ' ', array_map( function ( $comm ) {
                if ( \strpos( $comm, ' ' ) !== false ) {
                    return '"' . $comm . '"';
                }

                return $comm;
            }, $command ) );
        }

        \do_action( 'redipress/debug_query', $query, $results, 'users' );

        // Return the results through a filter
        return \apply_filters( 'redipress/search_results/users', (object) [
            'results' => $results,
        ], $query );
    }

    /**
     * Filter WordPress users requests
     *
     * @param array|null     $users An empty array of posts.
     * @param \WP_User_Query $query The WP_User_Query object.
     * @return array Results or null if no results.
     */
    public function users_pre_query( ?array $users, \WP_User_Query $query ): ?array {
        // If we are on a multisite and have not explicitly defined that
        // we want to do stuff with other sites, use the current site
        if ( empty( $query->query['blog_id'] ) ) {
            $query_var            = $query->query;
            $query_var['blog_id'] = [ \get_current_blog_id() ];
        }

        $this->query_builder = new Search\UserQueryBuilder( $query, $this->index_info );

        // Only filter front-end search queries
        if ( $this->query_builder->enable() ) {
            \do_action( 'redipress/before_search', $this, $query );
            \do_action( 'redipress/before_user_search', $this, $query );

            $raw_results = $this->search( $query );

            if ( empty( $raw_results->results ) || $raw_results->results[0] === 0 ) {
                $query->redipress_no_results = true;
                $no_results                  = \apply_filters( 'redipress/no_results', null, $query );

                return \apply_filters( 'redipress/no_results/users', $no_results, $query );
            }

            $count = $raw_results->results[0];

            $results = $this->format_results( $raw_results->results );

            // Filter the search results after the search has been conducted.
            $results = \apply_filters(
                'redipress/formatted_search_results',
                $results,
                Utility::format( $raw_results->results )
            );

            $results = \apply_filters(
                'redipress/formatted_search_results/users',
                $results,
                Utility::format( $raw_results->results )
            );

            $number = $query->query_vars['number'] ?: \get_option( 'posts_per_page' );

            $query->total_users = $count;
            $query->max_num_pages( ceil( $count / $number ) );

            $query->using_redisearch = true;

            if ( ! is_array( $query->query_vars['fields'] ) ) {
				$results = array_column( $results, 'ID' );
			}

            return array_values( $results );
        }

        return null;
    }

    /**
     * Format RediSearch output formatted results to an array of WordPress posts.
     *
     * @param array $results Original array to format.
     * @return array
     */
    public function format_results( array $results ): array {
        $results = Utility::format( $results );

        return array_map( function ( array $result ): ?\WP_User {
            $formatted = Utility::format( $result );

            return \maybe_unserialize( $formatted['user_object'] );
        }, $results );
    }
}
