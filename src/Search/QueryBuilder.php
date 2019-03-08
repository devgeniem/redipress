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
        'tax_query'        => null,
    ];

    /**
     * Query builder constructor
     *
     * @param WP_Query $wp_query WP Query object.
     */
    public function __construct( WP_Query $wp_query ) {
        $this->wp_query = $wp_query;

        $this->ignore_query_vars = apply_filters( 'redipress/ignore_query_vars', [] );
    }

    /**
     * Get the RediSearch query based on the WP_Query
     *
     * @return array
     */
    public function get_query() : array {
        $return = array_filter( array_map( function( $query_var ) : string {
            if ( in_array( $query_var, $this->ignore_query_vars, true ) ) {
                return false;
            }

            if ( ! empty( $this->query_vars[ $query_var ] ) ) {
                $this->add_search_field( $query_var );
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
     * Helper function to add a filter for the search fields.
     *
     * @param string $field The field to add.
     * @return void
     */
    private function add_search_field( string $field ) {
        add_filter( 'redipress/search_fields', function( $fields ) use ( $field ) {
            $fields[] = $this->query_vars[ $field ];

            return $fields;
        }, 9999, 1 );
    }

    /**
     * A method to determine whether we want to override the normal query or not.
     *
     * @return boolean
     */
    public function enable() : bool {
        $query_vars = $this->wp_query->query;

        if ( $this->wp_query->is_front_page ) {
            return false;
        }

        $allowed = array_merge( array_keys( $this->query_vars ), $this->ignore_query_vars );

        return array_reduce( array_keys( $query_vars ), function( bool $carry, string $item ) use ( $allowed ) {
            if ( ! $carry ) {
                return false;
            }
            elseif ( array_search( $item, $allowed, true ) !== false ) {
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

    /**
     * WP_Query tax_query parameter.
     *
     * @return string
     */
    protected function tax_query() : string {
        $query = $this->wp_query->query_vars['tax_query'];

        // Sanitize and validate the query through the WP_Tax_Query class
        $tax_query = new \WP_Tax_Query( $query );

        return $this->create_taxonomy_query( $tax_query->queries );
    }

    /**
     * Create a RediSearch taxonomy query from a single WP_Query tax_query block.
     *
     * @param array  $query    The block to create the block from.
     * @param string $operator Possible operator of the parent array.
     * @return string
     */
    private function create_taxonomy_query( array $query, string $operator = 'AND' ) : string {
        $relation = $query['relation'] ?? $operator;
        unset( $query['relation'] );

        // Determine the relation type
        if ( $relation === 'AND' ) {
            $queries = [];

            foreach ( $query as $clause ) {
                if ( ! empty( $clause['taxonomy'] ) ) {
                    switch ( $clause['field'] ) {
                        case 'name':
                            foreach ( $clause['terms'] as $term ) {
                                $queries[] = sprintf(
                                    '(@taxonomy_%s:{%s})',
                                    $clause['taxonomy'],
                                    $term
                                );

                                $this->add_search_field( 'taxonomy_' . $clause['taxonomy'] );
                            }
                            break;
                        case 'slug':
                            $clause['terms'] = array_map( function( $term ) {
                                $term_obj = get_term_by( 'slug', $term );

                                return $term_obj->term_id;
                            }, $clause['terms'] );

                            // The fallthrough is intentional: we only turn the slugs into ids.
                        case 'term_id':
                            foreach ( $clause['terms'] as $term ) {
                                $queries[] = sprintf(
                                    '(@taxonomy_id_%s:{%s})',
                                    $clause['taxonomy'],
                                    $term
                                );

                                $this->add_search_field( 'taxonomy_id_' . $clause['taxonomy'] );
                            }
                            break;
                        default:
                            return false;
                    }
                }
                else {
                    $queries[] = $this->create_taxonomy_query( $clause, 'AND' );
                }
            }

            return '(' . implode( ' ', $queries ) . ')';
        }
        elseif ( $relation === 'OR' ) {
            $queries = [];

            foreach ( $query as $clause ) {
                if ( ! empty( $clause['taxonomy'] ) ) {
                    switch ( $clause['field'] ) {
                        case 'name':
                            $queries[] = sprintf(
                                '(@taxonomy_%s:{%s})',
                                $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );

                            $this->add_search_field( 'taxonomy_' . $clause['taxonomy'] );
                            break;
                        case 'slug':
                            $clause['terms'] = array_map( function( $term ) {
                                $term_obj = get_term_by( 'slug', $term );

                                return $term_obj->term_id;
                            }, $clause['terms'] );

                            // The fallthrough is intentional: we only turn the slugs into ids.
                        case 'term_id':
                            $queries[] = sprintf(
                                '(@taxonomy_id_%s:{%s})',
                                $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );

                            $this->add_search_field( 'taxonomy_id_' . $clause['taxonomy'] );
                            break;
                        default:
                            return false;
                    }
                }
                else {
                    $queries[] = $this->create_taxonomy_query( $clause, 'OR' );
                }
            }

            return '(' . implode( '|', $queries ) . ')';
        }
    }
}
