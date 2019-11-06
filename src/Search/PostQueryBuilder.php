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
    protected $groupby = [ '@post_id' ];

    /**
     * Mapped query vars
     *
     * @var array
     */
    protected $query_vars = [
        'paged'            => null,
        's'                => null,
        'blog_id'          => 'blog_id',
        'p'                => 'post_id',
        'name'             => 'post_name',
        'pagename'         => 'post_name',
        'post_type'        => 'post_type',
        'post_parent'      => 'post_parent',
        'post_status'      => 'post_status',
        'post__in'         => 'post_id',
        'post__not_in'     => 'post_id',
        'category__in'     => 'taxonomy_id_category',
        'category__not_in' => 'taxonomy_id_category',
        'category__and'    => 'taxonomy_id_category',
        'category_name'    => 'taxonomy_category',
        'meta_query'       => null,
        'tax_query'        => null,
        'order'            => null,
        'orderby'          => null,
        'posts_per_page'   => null,
        'offset'           => null,
        'meta_key'         => null,
        'weight'           => null,
    ];

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
            'update_post_meta_cache',
            'update_post_term_cache',
        ], $ignore_added_query_vars ) );

        $this->ignore_query_vars = apply_filters( 'redipress/ignore_query_vars/' . static::TYPE, $this->ignore_query_vars );

        // Allow adding support for query vars via a filter
        $this->query_vars = apply_filters( 'redipress/query_vars', $this->query_vars );
        $this->query_vars = apply_filters( 'redipress/query_vars/' . static::TYPE, $this->query_vars );
    }

    /**
     * WP_Query s parameter.
     *
     * @return string
     */
    protected function s() : string {
        $return = $this->conduct_search( 's' );
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
     * WP_Query blog_id parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function blog_id() : string {

        if ( empty( $this->query->query_vars['blog_id'] ) ) {
            $blog_id = \get_current_blog_id();
        }
        else {
            $blog_id = $this->query->query_vars['blog_id'];
        }

        $clause = '';

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

        if ( empty( $this->query->query_vars['post_type'] ) ) {
            return false;
        }

        $post_type = $this->query->query_vars['post_type'];

        if ( $post_type !== 'any' ) {
            $post_types = is_array( $post_type ) ? $post_type : [ $post_type ];

            return '@post_type:(' . implode( '|', $post_types ) . ')';
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

        if ( empty( $this->query->query_vars['tax_query'] ) ) {
            return false;
        }

        $query = $this->query->query_vars['tax_query'];

        // Sanitize and validate the query through the WP_Tax_Query class
        $tax_query = new \WP_Tax_Query( $query );
        return $this->create_taxonomy_query( $tax_query->queries );
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
                $order = 'date';
            }
            else {
                return true;
            }
        }

        $order = $query['order'] ?? 'DESC';

        $sortby = [
            [
                'order'   => $order,
                'orderby' => null,
            ],
        ];

        // If we have a simple string as the orderby parameter.
        if (
            ! empty( $query['orderby'] ) &&
            is_string( $query['orderby'] ) &&
            strpos( $query['orderby'], ' ' ) === false
        ) {
            $sortby[0]['orderby'] = $query['orderby'];
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
                    // The value can also be a named meta clause
                    if ( ! empty( $this->meta_clauses[ $clause['orderby'] ] ) ) {
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
            $fields = array_column( $this->index_info['fields'], 0 );

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
        }

        $this->sortby = array_merge(
            [ 'SORTBY', ( count( $sortby ) * 2 ) ],
            array_reduce( $sortby, function( $carry, $item ) {

                // Store groupby these need to be in sync with sortby params.
                $this->groupby[] = '@' . $item['orderby'];

                return array_merge( $carry, [ '@' . $item['orderby'], $item['order'] ] );
            }, [] )
        );

        return true;
    }
}
