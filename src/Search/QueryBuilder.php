<?php
/**
 * RediPress search query builder class file
 */

namespace Geniem\RediPress\Search;

use WP_Query;

/**
 * RediPress search query builder class
 */
class QueryBuilder {

    /**
     * WP Query object.
     *
     * @var WP_Query
     */
    protected $wp_query = null;

    /**
     * Possible preferred modifiers
     *
     * @var array
     */
    protected $modifiers = [];

    /**
     * Mapped query vars
     *
     * @var array
     */
    protected $query_vars = [
        'paged'            => null,
        's'                => null,
        'p'                => 'post_id',
        'name'             => 'post_name',
        'pagename'         => 'post_name',
        'post_type'        => 'post_type',
        'post_parent'      => 'post_parent',
        'category__in'     => 'taxonomy_id_category',
        'category__not_in' => 'taxonomy_id_category',
        'category__and'    => 'taxonomy_id_category',
        'category_name'    => 'taxonomy_category',
    ];

    /**
     * Query builder constructor
     *
     * @param WP_Query $wp_query WP Query object.
     */
    public function __construct( WP_Query $wp_query ) {
        $this->wp_query = $wp_query;
    }

    /**
     * Get the RediSearch query based on the WP_Query
     *
     * @return array
     */
    public function get_query() : array {
        $return = array_filter( array_map( function( $query_var ) : string {
            if ( ! empty( $this->query_vars[ $query_var ] ) ) {
                add_filter( 'redipress/search_fields', function( $fields ) use ( $query_var ) {
                    $fields[] = $this->query_vars[ $query_var ];

                    return $fields;
                }, 9999, 1 );
            }

            if ( method_exists( $this, $query_var ) ) {
                return $this->{ $query_var }();
            }
            else {
                return '@' . $this->query_vars[ $query_var ] . ':' . $this->wp_query->query_vars[ $query_var ];
            }
        }, array_keys( $this->wp_query->query ) ) );

        return array_merge( $return, $this->modifiers );
    }

    /**
     * A method to determine whether we want to override the normal query or not.
     *
     * @return boolean
     */
    public function enable() : bool {
        $query_vars = $this->wp_query->query;

        return array_reduce( array_keys( $query_vars ), function( bool $carry, string $item ) {
            if ( ! $carry ) {
                return false;
            }
            elseif ( array_key_exists( $item, $this->query_vars ) ) {
                return true;
            }
            else {
                return false;
            }
        }, true );
    }

    /**
     * WP_Query paged parameter.
     *
     * @return string|null
     */
    protected function paged() : string {
        return '';
    }

    /**
     * WP_Query s parameter.
     *
     * @return string
     */
    protected function s() : string {
        $terms = apply_filters( 'redipress/search_terms', $this->wp_query->query_vars['s'] );

        $sort = explode( ' ', $terms );

        $tilde = array_filter( $sort, function( $word ) {
            return strpos( $word, '~' ) === 0;
        });

        $rest = array_diff( $sort, $tilde );

        $this->modifiers = $tilde;

        return implode( ' ', $rest );
    }

    /**
     * WP_Query p parameter.
     *
     * @return string
     */
    protected function p() : string {
        return '@post_id:[' . $this->wp_query->query_vars['p'] . ' ' . $this->wp_query->query_vars['p'] . ']';
    }

    /**
     * WP_Query post_type parameter.
     *
     * @return ?string
     */
    protected function post_type() : ?string {
        $post_type = $this->wp_query->query_vars['post_type'];

        if ( $post_type !== 'any' ) {
            $post_types = is_array( $post_type ) ? $post_type : [ $post_type ];

            return '@post_type:(' . implode( '|', $post_types ) . ')';
        }
    }

    /**
     * WP_Query category__in parameter.
     *
     * @return string
     */
    protected function category__in() : string {
        $cats = $this->wp_query->query_vars['category__in'];

        $cat = is_array( $cats ) ? $cats : [ $cats ];

        return '@taxonomy_id_category:{' . implode( '|', $cat ) . '}';
    }

    /**
     * WP_Query category__not_in parameter.
     *
     * @return string
     */
    protected function category__not_in() : string {
        $cats = $this->wp_query->query_vars['category__not_in'];

        $cat = is_array( $cats ) ? $cats : [ $cats ];

        return '-@taxonomy_id_category:{' . implode( '|', $cat ) . '}';
    }

    /**
     * WP_Query category__and parameter.
     *
     * @return string
     */
    protected function category__and() : string {
        $cats = $this->wp_query->query_vars['category__and'];

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
        $cat = $this->wp_query->query_vars['category_name'];

        $all_cats = explode( '+', $cat );

        $return = [];

        foreach ( $all_cats as $all ) {
            $some_cats = explode( ',', $all );

            foreach ( $some_cats as $some ) {
                $return[] = '@taxonomy_category:{' . implode( '|', $some ) . '}';
            }
        }

        return implode( ' ', $return );
    }
}
