<?php
/**
 * RediPress search query builder class file
 */

namespace Geniem\RediPress\Search;

use WP_Query;
use Geniem\RediPress\Utility;

/**
 * RediPress search query builder class
 */
class PostQueryBuilder extends QueryBuilder {

    /**
     * Group by
     * If $sortby is defined we need to groupby sortby tags.
     * We need to add @post_id by minimum to make sure that we have unique results.
     *
     * @var array
     */
    protected $groupby = [];

    /**
     * Return fields
     * The fields that we want the query to return from RediSearch.
     *
     * @var array
     */
    protected $return_fields = [ 'post_object', 'post_date', 'post_type', 'post_id', 'post_parent' ];

    /**
     * Reduce functions for return fields
     *
     * @var array
     */
    protected $reduce_functions = [];

    /**
     * Mapped query vars
     *
     * @var array
     */
    protected $query_vars = [
        'paged'               => null,
        's'                   => null,
        'blog'                => 'blog_id',
        'p'                   => 'post_id',
        'name'                => 'post_name',
        'pagename'            => 'post_name',
        'page'                => null,
        'post_type'           => 'post_type',
        'post_parent'         => 'post_parent',
        'post_parent__in'     => 'post_parent',
        'post_parent__not_in' => 'post_parent',
        'post_mime_type'      => 'post_mime_type',
        'post_status'         => 'post_status',
        'post__in'            => 'post_id',
        'post__not_in'        => 'post_id',
        'author'              => 'post_author_id',
        'author__in'          => 'post_author_id',
        'author__not_in'      => 'post_author_id',
        'category__in'        => 'taxonomy_id_category',
        'category__not_in'    => 'taxonomy_id_category',
        'category__and'       => 'taxonomy_id_category',
        'category_name'       => 'taxonomy_category',
        'meta_query'          => null,
        'tax_query'           => null,
        'date_query'          => null,
        'order'               => null,
        'orderby'             => null,
        'groupby'             => null,
        // phpcs:ignore
        'posts_per_page'      => null,
        'offset'              => null,
        'meta_key'            => null,
        'weight'              => null,
        'reduce_functions'    => null,
        'geolocation'         => null,
    ];

    /**
     * Possible applies for the query
     *
     * @var array
     */
    protected $applies = [];

    /**
     * Possible filters for the query
     *
     * @var array
     */
    protected $filters = [];

    /**
     * Whether the builder is for posts or for users
     */
    const TYPE = 'post';

    /**
     * Query builder constructor
     *
     * @param \WP_Query $query      WP Query object.
     * @param array     $index_info Index information.
     */
    public function __construct( \WP_Query $query, array $index_info ) {
        global $wp_rewrite;

        $this->query      = $query;
        $this->index_info = $index_info;

        $ignore_added_query_vars = array_map( function( $code ) {
            return preg_replace( '/^\%(.+)\%$/', '\1', $code );
        }, $wp_rewrite->rewritecode );

        $this->ignore_query_vars = apply_filters( 'redipress/ignore_query_vars', array_merge( [
            'order',
            'orderby',
            'paged',
            'posts_per_page',
            'offset',
            'meta_key',
            'meta_type',
            'update_post_meta_cache',
            'update_post_term_cache',
            'ignore_sticky_posts',
            'rest_route',
            'fields',
        ], $ignore_added_query_vars ) );

        $this->ignore_query_vars = apply_filters( 'redipress/ignore_query_vars/' . static::TYPE, $this->ignore_query_vars );

        // Allow adding support for query vars via a filter
        $this->query_vars = apply_filters( 'redipress/query_vars', $this->query_vars );
        $this->query_vars = apply_filters( 'redipress/query_vars/' . static::TYPE, $this->query_vars );

        $this->query_params = $this->query->query;
    }

    /**
     * Get the RediSearch query based on the original query
     *
     * @return array
     */
    public function get_query() : array {
        if ( empty( $this->query->query['tax_query'] ) ) {
            $this->query->query['tax_query'] = true;
        }

        return parent::get_query();
    }

    /**
     * WP_Query s parameter.
     *
     * @return string
     */
    protected function s() : string {
        return $this->conduct_search( 's' );
    }

    /**
     * WP_Query p parameter.
     *
     * @return string
     */
    protected function p() : string {

        if ( empty( $this->query->query_vars['p'] ) ) {
            return false;
        }

        return '@post_id:' . $this->query->query_vars['p'];
    }

    /**
     * WP_Query blog parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function blog() : string {

        if ( empty( $this->query->query_vars['blog'] ) ) {
            $blog_id = \get_current_blog_id();
        }
        else {
            $blog_id = $this->query->query_vars['blog'];
        }

        $clause = '';

        if ( $blog_id === 'all' ) {
            return '';
        }

        if ( ! is_array( $blog_id ) ) {
            $blog_id = [ $blog_id ];
        }

        $clause = '@blog_id:(' . implode( '|', $blog_id ) . ')';

        return $clause;
    }

    /**
     * WP_Query post__in parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function post__in() : string {

        if ( empty( $this->query->query_vars['post__in'] ) ) {
            return false;
        }

        $post__in = $this->query->query_vars['post__in'];
        $clause   = '';

        if ( ! empty( $post__in ) && is_array( $post__in ) ) {
            $clause = '@post_id:(' . implode( '|', $post__in ) . ')';
        }

        return $clause;
    }

    /**
     * WP_Query post__not_in parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function post__not_in() : string {

        if ( empty( $this->query->query_vars['post__not_in'] ) ) {
            return false;
        }

        $post__not_in = $this->query->query_vars['post__not_in'];
        $clause       = '';

        if ( ! empty( $post__not_in ) && is_array( $post__not_in ) ) {
            $clause = '-@post_id:(' . implode( '|', $post__not_in ) . ')';
        }

        return $clause;
    }

    /**
     * WP_Query post_type parameter.
     *
     * @return ?string
     */
    protected function post_type() : ?string {
        $post_type = $this->query->query_vars['post_type'];

        if ( $post_type === 'any' ) {
            $post_types = get_post_types( [ 'exclude_from_search' => false ] );

            if ( empty( $post_types ) ) {
                return false;
            }
        }
        elseif ( ! empty( $post_type ) && is_array( $post_type ) ) {
            $post_types = $post_type;
        }
        elseif ( ! empty( $post_type ) ) {
            $post_types = [ $post_type ];
        }
        elseif ( $this->query->is_attachment ) {
            $post_types = [ 'attachment' ];
        }
        elseif ( $this->query->is_page ) {
            $post_types = [ 'page' ];
        }
        else {
            $post_types = [ 'post' ];
        }

        $post_types = array_map( [ $this, 'escape_string' ], $post_types );

        return '@post_type:(' . implode( '|', $post_types ) . ')';
    }

    /**
     * WP_Query author parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function author() : string {
        if ( empty( $this->query->query_vars['author'] ) ) {
            return false;
        }

        $author = $this->query->query_vars['author'];
        $clause     = '';

        if ( ! empty( $author ) && is_string( $author ) ) {
            $clause = '@post_author_id:(' . $author . ')';
        }

        return $clause;
    }

    /**
     * WP_Query author__in parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function author__in() : string {
        if ( empty( $this->query->query_vars['author__in'] ) ) {
            return false;
        }

        $author__in = $this->query->query_vars['author__in'];
        $clause     = '';

        if ( ! empty( $author__in ) && is_array( $author__in ) ) {
            $clause = '@post_author_id:(' . implode( '|', $author__in ) . ')';
        }

        return $clause;
    }

    /**
     * WP_Query author__not_in parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function author__not_in() : string {
        if ( empty( $this->query->query_vars['author__not_in'] ) ) {
            return false;
        }

        $author__not_in = $this->query->query_vars['author__not_in'];
        $clause         = '';

        if ( ! empty( $author__not_in ) && is_array( $author__not_in ) ) {
            $clause = '-@post_author_id:(' . implode( '|', $author__not_in ) . ')';
        }

        return $clause;
    }

    /**
     * WP_Query name parameter.
     *
     * @return ?string
     */
    protected function name() : ?string {
        if ( empty( $this->query->query_vars['name'] ) ) {
            return false;
        }

        $name = $this->query->query_vars['name'];

        // Handle pages.
        if ( isset(
            $this->query->query_vars['pagename'],
            $this->query->query_vars['post_type'],
            $this->query->query['pagename']
        ) ) {

            $post = \get_page_by_path( $this->query->query['pagename'] );

            if ( $post ) {
                $this->add_search_field( 'post_id' );
                return '@post_id:' . $post->ID;
            }
        }

        // Handle CPT posts.
        if ( isset(
            $this->query->query_vars['name'],
            $this->query->query_vars['post_type']
        ) ) {

            $post = \get_page_by_path( $this->query->query['name'], OBJECT, $this->query->query_vars['post_type'] );

            if ( $post ) {
                $this->add_search_field( 'post_id' );
                return '@post_id:' . $post->ID;
            }
        }

        if ( $name !== 'any' ) {
            $names = is_array( $name ) ? $name : [ $name ];

            $names = array_map( [ $this, 'escape_string' ], $names );

            return '@post_name:(' . implode( '|', $names ) . ')';
        }
        else {
            return '';
        }
    }

    /**
     * WP_Query post_status parameter.
     *
     * @return ?string
     */
    protected function post_status() : ?string {

        if ( empty( $this->query->query_vars['post_status'] ) ) {
            return false;
        }

        $post_status = $this->query->query_vars['post_status'];

        if ( $post_status === 'any' ) {
            $post_statuses = get_post_stati( [ 'exclude_from_search' => false ] );
        }
        elseif ( empty( $post_status ) ) {
            $post_statuses = [ 'publish' ];
        }
        else {
            $post_statuses = is_array( $post_status ) ? $post_status : [ $post_status ];
        }

        return '@post_status:(' . implode( '|', $post_statuses ) . ')';
    }

    /**
     * WP_Query post_parent parameter.
     *
     * @return ?string
     */
    protected function post_parent() : ?string {

        $post_parent = $this->query->query_vars['post_parent'] ?? false;

        // If post_parent is null or empty string ignore post_parent.
        if ( $post_parent === false || $post_parent === '' ) {
            return false;
        }

        return '@post_parent:' . $post_parent;
    }

    /**
     * WP_Query post_parent__in parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function post_parent__in() : string {

        if ( empty( $this->query->query_vars['post_parent__in'] ) ) {
            return false;
        }

        $post_parent__in = $this->query->query_vars['post_parent__in'];
        $clause     = '';

        if ( ! empty( $post_parent__in ) && is_array( $post_parent__in ) ) {
            $clause = '@post_parent:(' . implode( '|', $post_parent__in ) . ')';
        }

        return $clause;
    }

    /**
     * WP_Query post_parent__not_in parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function post_parent__not_in() : string {

        if ( empty( $this->query->query_vars['post_parent__not_in'] ) ) {
            return false;
        }

        $post_parent__not_in = $this->query->query_vars['post_parent__not_in'];
        $clause         = '';

        if ( ! empty( $post_parent__not_in ) && is_array( $post_parent__not_in ) ) {
            $clause = '-@post_parent:(' . implode( '|', $post_parent__not_in ) . ')';
        }

        return $clause;
    }

    /**
     * WP_Query mime type parameter.
     *
     * @return ?string
     */
    protected function post_mime_type() : ?string {

        if ( empty( $this->query->query_vars['post_mime_type'] ) ) {
            return '';
        }

        $mime_type = $this->query->query_vars['post_mime_type'];

        $mime_types = is_array( $mime_type ) ? $mime_type : [ $mime_type ];

        $mime_types = array_map( [ $this, 'escape_string' ], $mime_types );

        return '@post_mime_type:(' . implode( '|', $mime_types ) . ')';
    }

    /**
     * WP_Query category__in parameter.
     *
     * @return string
     */
    protected function category__in() : string {

        if ( empty( $this->query->query_vars['category__in'] ) ) {
            return false;
        }

        $cats = $this->query->query_vars['category__in'];

        $cat = is_array( $cats ) ? $cats : [ $cats ];

        return '@taxonomy_id_category:{' . implode( '|', $cat ) . '}';
    }

    /**
     * WP_Query category__not_in parameter.
     *
     * @return string
     */
    protected function category__not_in() : string {

        if ( empty( $this->query->query_vars['category__not_in'] ) ) {
            return false;
        }

        $cats = $this->query->query_vars['category__not_in'];

        $cat = is_array( $cats ) ? $cats : [ $cats ];

        return '-@taxonomy_id_category:{' . implode( '|', $cat ) . '}';
    }

    /**
     * WP_Query category__and parameter.
     *
     * @return string
     */
    protected function category__and() : string {

        if ( empty( $this->query->query_vars['category__and'] ) ) {
            return false;
        }

        $cats = $this->query->query_vars['category__and'];

        $cat = is_array( $cats ) ? $cats : [ $cats ];

        return implode( ' ', array_map( function( $cat ) {
            return '@taxonomy_id_category:{' . $cat . '}';
        }, $cat ));
    }

    /**
     * WP_Query category_name parameter.
     *
     * @return string
     */
    protected function category_name() : string {

        if ( empty( $this->query->query_vars['category_name'] ) ) {
            return false;
        }

        $cat = $this->query->query_vars['category_name'];

        $all_cats = explode( '+', $cat );

        $return = [];

        foreach ( $all_cats as $all ) {
            $some_cats = explode( ',', $all );

            foreach ( $some_cats as $some ) {
                if ( is_array( $some ) ) {
                    $return[] = '@taxonomy_category:{' . implode( '|', $some ) . '}';
                }
            }
        }

        return implode( ' ', $return );
    }

    /**
     * WP_Query tax_query parameter.
     *
     * @return string
     */
    protected function tax_query() : string {
        if ( empty( $this->query->tax_query ) ) {
            return false;
        }

        return $this->create_taxonomy_query( $this->query->tax_query->queries );
    }

    /**
     * WP_Query date_query parameter.
     *
     * @return string
     */
    protected function date_query() : string {
        if ( empty( $this->query->date_query ) ) {
            return false;
        }

        $queries = $this->create_date_query( $this->query->date_query->queries );

        $this->applies = array_merge( $this->applies, array_map( function( $apply ) {
            return [
                'APPLY',
                $apply['function'] . '(@' . $apply['field'] . ')',
                'AS',
                'redipress_' . $apply['as'],
            ];
        }, $queries['applies'] ) );

        $this->filters = array_merge( $this->filters, $queries['filters'] );

        return '';
    }

    /**
     * Create a RediSearch date query from a single WP_Query date_query block.
     *
     * This function runs itself recursively if the query has multiple levels.
     *
     * @param array $query The block to create the block from.
     * @return array
     */
    public function create_date_query( array $query ) : array {

        // Determine the relation type
        $queries = [];

        if ( empty( $query ) ) {
            return '';
        }

        $unsupported_time_keys = [
            'after',
            'before',
            'second',
            'week',
            'w',
            'dayofweek_iso',
            'hour',
            'minute',
            'second',
        ];

        $mappings = [
            'year'      => 'year',
            'month'     => 'monthofyear',
            'monthnum'  => 'monthofyear',
            'dayofyear' => 'dayofyear',
            'day'       => 'dayofmonth',
            'dayofweek' => 'dayofweek',
        ];

        $unsupported_compares = [
            'IN',
            'NOT IN',
            'BETWEEN',
            'NOT BETWEEN',
        ];

        if ( empty( $query['compare'] ) ) {
            $query['compare'] = '=';
        }

        // Compare
        switch ( $query['compare'] ) {
            case '=':
                $compare = '==';
                break;
            case '!=':
            case '<':
            case '<=':
            case '>':
            case '>=':
                $compare = $query['compare'];
                break;
            default:
                return [];
        }
        unset( $query['compare'] );

        switch ( $query['relation'] ) {
            case 'OR':
                $relation = '||';
                break;
            case 'AND':
            default:
                $relation = '&&';
        };
        unset( $query['relation'] );

        $column = $query['column'] ?? 'post_date';
        unset( $query['column'] );

        $applies = [];
        $filters = [];

        foreach ( $query as $clause ) {
            foreach ( $clause as $key => $value ) {
                if ( in_array( $key, $unsupported_time_keys, true ) ) {
                    return false;
                }
            }

            if ( $column === 'post_date_gmt' ) {
                return false;
            }

            if ( empty( $clause['compare'] ) ) {
                $clause['compare'] = '=';
            }

            if ( in_array( $compare, $unsupported_compares, true ) ) {
                return false;
            }

            // Compare
            switch ( $clause['compare'] ) {
                case '=':
                    $compare = '==';
                    break;
                case '!=':
                case '<':
                case '<=':
                case '>':
                case '>=':
                    $compare = $clause['compare'];
                    break;
                default:
                    return [];
            }
            unset( $clause['compare'] );

            switch ( $clause['relation'] ) {
                case 'OR':
                    $inner_relation = '||';
                    break;
                case 'AND':
                default:
                    $inner_relation = '&&';
            };
            unset( $clause['relation'] );

            // Count the number of non-arrays in the clause. If it's greater than zero
            // we are dealing with an actual clause.
            $single = array_reduce( $clause, function( int $carry, $item ) {
                if ( ! is_array( $item ) ) {
                    return ++$carry;
                }
                else {
                    return $carry;
                }
            }, 0 );

            if ( $single ) {
                $res = [];

                foreach ( $mappings as $map_key => $map_value ) {
                    if ( ! empty( $clause[ $map_key ] ) ) {
                        $applies[] = [
                            'as'       => $map_key,
                            'function' => $map_value,
                            'field'    => $column,
                        ];

                        if ( $map_value === 'monthofyear' ) {
                            $clause[ $map_key ] -= 1;
                        }

                        $res[] = [
                            '@redipress_' . $map_key, // Parameter key prefixed
                            $clause[ $map_key ],     // Parameter value
                        ];
                    }
                }

                $filters[] = '(' . implode( ' ' . $inner_relation . ' ', array_map( function( $clause ) use ( $compare ) {
                    return implode( ' ' . $compare . ' ', $clause );
                }, $res ) ) . ')';
            }
            // If we have multiple clauses in the block, run the function recursively.
            else {
                $sub_queries = $this->create_date_query( $clause );

                $applies[] = $sub_queries['applies'];
                $filters[] = $sub_queries['filters'];
            }
        }

        return [
            'applies' => $applies,
            'filters' => '(' . implode( ' ' . $relation . ' ',  $filters ) . ')',
        ];
    }

    /**
     * Determine sortby query values.
     *
     * @return boolean Whether we have a qualified orderby or not.
     */
    protected function get_orderby() : bool {

        if ( ! empty( $this->sortby ) ) {
            return true;
        }

        $query = $this->query->query_vars;

        // Handle empty orderby
        if ( empty( $query['orderby'] ) ) {
            if ( empty( $query['s'] ) ) {
                $query['orderby'] = 'date';
            }
            else {
                return true;
            }
        }

        // If we have a simple string as the orderby parameter.
        if (
            ! empty( $query['orderby'] ) &&
            is_string( $query['orderby'] ) &&
            strpos( $query['orderby'], ' ' ) === false
        ) {

            $sortby = [
                [
                    'order'   => $query['order'] ?? 'DESC',
                    'orderby' => $query['orderby'],
                ],
            ];
        }
        // If we have an array with key-value pairs
        elseif (
            ! empty( $query['orderby'] ) &&
            is_array( $query['orderby'] )
        ) {
            $sortby = [];

            foreach ( $query['orderby'] as $key => $value ) {
                $sortby[] = [
                    'order'   => $value,
                    'orderby' => $key,
                ];
            }
        }
        elseif ( empty( $query['orderby'] ) ) {
            $this->sortby = [];
            return true;
        }
        // Anything else is a no-go.
        else {
            return false;
        }

        $sortby = array_map( function( array $clause ) use ( $query ) {

            // Create the mappings for orderby parameter
            switch ( $clause['orderby'] ) {
                case 'menu_order':
                case 'meta_value':
                case 'meta_value_num':
                    break;
                case 'none':
                case 'relevance':
                    return true;
                case 'ID':
                case 'author':
                case 'title':
                case 'name':
                case 'type':
                case 'date':
                case 'parent':
                    $clause['orderby'] = 'post_' . strtolower( $clause['orderby'] );
                    break;
                default:

                    // If we have a distance clause, just pass it on
                    if (
                        ! empty( $clause['order'] ) &&
                        is_array( $clause['order']['compare'] ) &&
                        ! empty( $clause['order']['compare']['lat'] ) &&
                        ! empty( $clause['order']['compare']['lng'] )
                    ) {
                        return $clause;
                    }
                    // The value can also be a named meta clause
                    elseif ( ! empty( $this->meta_clauses[ $clause['orderby'] ] ) ) {
                        $clause['orderby'] = $this->meta_clauses[ $clause['orderby'] ];
                    }
                    else {
                        return false;
                    }
            }

            // We may also have a meta value as the orderby.
            if ( in_array( $clause['orderby'], [ 'meta_value', 'meta_value_num' ], true ) ) {
                if ( is_string( $query['meta_key'] ) && ! empty( $query['meta_key'] ) ) {
                    $clause['orderby'] = $query['meta_key'];
                }
                else {
                    return false;
                }
            }

            // If we don't have the field in the schema, it's a no-go as well.
            $fields = array_column( $this->index_info['attributes'], 1 );

            if ( ! in_array( $clause['orderby'], $fields, true ) ) {
                return false;
            }

            return $clause;
        }, $sortby );

        // If we have a false value in the sortby array, just bail away.
        foreach ( $sortby as $clause ) {
            if ( ! $clause ) {
                return false;
            }
            elseif ( $clause === true ) {
                $this->sortby = [];
                return true;
            }
        }

        $this->sortby = array_merge(
            [ 'SORTBY', ( count( $sortby ) * 2 ) ],
            array_reduce( $sortby, function( $carry, $item ) {

                // Distance clauses need a special treatment
                if (
                    ! empty( $item['order']['compare'] ) &&
                    is_array( $item['order']['compare'] ) &&
                    ! empty( $item['order']['compare']['lat'] ) &&
                    ! empty( $item['order']['compare']['lng'] )
                ) {
                    $field = $item['orderby'];
                    $lat   = $item['order']['compare']['lat'];
                    $lng   = $item['order']['compare']['lng'];

                    $this->applies[] = [
                        'APPLY',
                        "geodistance(@$field, \"$lat,$lng\")",
                        'AS',
                        'redipress__distance_order',
                    ];

                    $item['orderby'] = 'redipress__distance_order';
                    $item['order']   = $item['order']['order'];

                    // Store to return fields array, these need to be in sync with sortby params.
                    $this->return_fields[] = $field;
                }

                return array_merge( $carry, [ '@' . $item['orderby'], $item['order'] ] );
            }, [] )
        );

        return true;
    }
}
