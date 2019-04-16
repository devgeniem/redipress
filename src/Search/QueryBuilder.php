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
     * Possible sortby command
     *
     * @var array
     */
    protected $sortby = [];

    /**
     * Index info
     *
     * @var array
     */
    protected $index_info;

    /**
     * Container for stored named meta clauses
     *
     * @var array
     */
    protected $meta_clauses = [];

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
        'post_status'      => 'post_status',
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
    ];

    /**
     * Query builder constructor
     *
     * @param WP_Query $wp_query   WP Query object.
     * @param array    $index_info Index information.
     */
    public function __construct( WP_Query $wp_query, array $index_info ) {
        global $wp_rewrite;

        $this->wp_query   = $wp_query;
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
        ], $ignore_added_query_vars ) );
    }

    /**
     * Get the determined sortby command.
     *
     * @return array
     */
    public function get_sortby() : array {
        return $this->sortby;
    }

    /**
     * Get the RediSearch query based on the WP_Query
     *
     * @return array
     */
    public function get_query() : array {
        $return = array_filter( array_map( function( string $query_var ) : string {
            if ( in_array( $query_var, $this->ignore_query_vars, true ) ) {
                return false;
            }

            if ( ! empty( $this->query_vars[ $query_var ] ) ) {
                $this->add_search_field( $query_var );
            }

            $fields = Utility::format( $this->index_info['fields'] );

            // Find out the type of the field we are dealing with.
            $field_type = array_reduce( $fields, function( $carry = null, $item = null ) use ( $query_var ) {
                if ( ! empty( $carry ) ) {
                    return $carry;
                }

                $name = $item[0];

                if ( $name === $query_var ) {
                    return Utility::get_value( $item, 'type' );
                }

                return null;
            });

            // Use the designated method if it exists.
            if ( method_exists( $this, $query_var ) ) {
                return $this->{ $query_var }();
            }
            // Special treatment for numeric fields.
            elseif ( $field_type === 'NUMERIC' ) {

                if ( empty( $this->query_vars[ $query_var ] ) || empty( $this->wp_query->query_vars[ $query_var ] ) ) {
                    return false;
                }

                $return = '@' . $this->query_vars[ $query_var ] . ':[' . $this->wp_query->query_vars[ $query_var ] . ' ' . $this->wp_query->query_vars[ $query_var ] . ']';

                return $return;
            }
            // Otherwise we are dealing with an ordinary text fiel  d.
            else {

                if ( empty( $this->wp_query->query_vars[ $query_var ] ) || empty( $this->query_vars[ $query_var ] ) ) {
                    return false;
                }

                $return = '@' . $this->query_vars[ $query_var ] . ':' . $this->wp_query->query_vars[ $query_var ];

                return $return;
            }
        }, array_keys( $this->wp_query->query_vars ) ) );

        // All minuses to the end of the line.
        usort( $return, function( $a, $b ) {
            return ( substr( $a, 0, 1 ) === '-' );
        });

        return array_merge(
            $return,
            $this->modifiers
        );
    }

    /**
     * Helper function to add a filter for the search fields.
     *
     * @param string $field The field to add.
     * @return void
     */
    private function add_search_field( string $field ) {
        add_filter( 'redipress/search_fields', function( $fields ) use ( $field ) {
            $fields[] = $this->query_vars[ $field ] ?? $field;

            return $fields;
        }, 9999, 1 );
    }

    /**
     * A method to determine whether we want to override the normal query or not.
     *
     * @return boolean
     */
    public function enable() : bool {
        // Don't use RediPress in admin
        if ( is_admin() ) {
            return false;
        }

        $query_vars = $this->wp_query->query;

        if ( $this->wp_query->is_front_page ) {
            return false;
        }

        if ( ! empty( $query_vars['meta_query'] ) ) {
            if ( ! $this->meta_query() ) {
                return false;
            }
        }

        if ( ! $this->get_orderby() ) {
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

        if ( empty( $this->wp_query->query_vars['p'] ) ) {
            return false;
        }

        return '@post_id:' . $this->wp_query->query_vars['p'];
    }

    /**
     * WP_Query post__not_in parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function post__not_in() : string {

        if ( empty( $this->wp_query->query_vars['post__not_in'] ) ) {
            return false;
        }

        $post__not_in = $this->wp_query->query_vars['post__not_in'];
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

        if ( empty( $this->wp_query->query_vars['post_type'] ) ) {
            return false;
        }

        $post_type = $this->wp_query->query_vars['post_type'];

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

        if ( empty( $this->wp_query->query_vars['post_status'] ) ) {
            return false;
        }

        $post_status = $this->wp_query->query_vars['post_status'];

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
     * WP_Query category__in parameter.
     *
     * @return string
     */
    protected function category__in() : string {

        if ( empty( $this->wp_query->query_vars['category__in'] ) ) {
            return false;
        }

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

        if ( empty( $this->wp_query->query_vars['category__not_in'] ) ) {
            return false;
        }

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

        if ( empty( $this->wp_query->query_vars['category__and'] ) ) {
            return false;
        }

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

        if ( empty( $this->wp_query->query_vars['category_name'] ) ) {
            return false;
        }

        $cat = $this->wp_query->query_vars['category_name'];

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

        if ( empty( $this->wp_query->query_vars['tax_query'] ) ) {
            return false;
        }

        $query = $this->wp_query->query_vars['tax_query'];

        // Sanitize and validate the query through the WP_Tax_Query class
        $tax_query = new \WP_Tax_Query( $query );
        $return = $this->create_taxonomy_query( $tax_query->queries );

        return $return;
    }

    /**
     * WP_Query orderby parameter.
     *
     * @return string
     */
    protected function orderby() : string {
        $this->get_orderby();

        return '';
    }

    /**
     * WP_Query meta_query parameter.
     *
     * @return string|null
     */
    protected function meta_query() : ?string {
        if ( ! empty( $this->meta_query ) ) {
            return $this->meta_query;
        }

        $query = $this->wp_query->query_vars['meta_query'];

        // Sanitize and validate the query through the WP_Meta_Query class
        $meta_query = new \WP_Meta_Query( $query );

        $query = $this->create_meta_query( $meta_query->queries );

        if ( $query ) {
            $this->meta_query = $query;
            return $query;
        }
        else {
            return null;
        }
    }

    /**
     * Determine sortby query values.
     *
     * @return boolean Whether we have a qualified orderby or not.
     */
    private function get_orderby() : bool {
        if ( ! empty( $this->sortby ) ) {
            return true;
        }

        $query = $this->wp_query->query_vars;

        // Bail early if we don't have orderby defined
        if ( empty( $query['orderby'] ) ) {
            return true;
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
            is_string( $query['orderby'] ) &&
            ! empty( $query['orderby'] ) &&
            strpos( $query['orderby'], ' ' ) === false
        ) {
            $sortby[0]['orderby'] = $query['orderby'];
        }
        // If we have an array with key-value pairs
        elseif (
            is_array( $query['orderby'] ) &&
            ! empty( $query['orderby'] )
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
                return array_merge( $carry, [ '@' . $item['orderby'], $item['order'] ] );
            }, [] )
        );

        return true;
    }

    /**
     * Create a RediSearch taxonomy query from a single WP_Query tax_query block.
     *
     * This function runs itself recursively if the query has multiple levels.
     *
     * @param array  $query    The block to create the block from.
     * @param string $operator Possible operator of the parent array.
     * @return string
     */
    private function create_taxonomy_query( array $query, string $operator = 'AND' ) : string {

        $relation = $query['relation'] ?? $operator;
        unset( $query['relation'] );

        // Operator
        $operator_uppercase    = strtoupper( $clause['operator'] );
        $unsupported_operators = [ 'EXISTS', 'NOT EXISTS' ];

        // We do not support some operator types, so bail early if some of them is found.
        if ( in_array( $operator_uppercase, $unsupported_operators, true ) ) {
            return false;
        }

        // Determine the relation type
        $queries = [];

        foreach ( $query as $clause ) {
            if ( ! empty( $clause['taxonomy'] ) ) {
                switch ( $clause['field'] ) {
                    case 'name':

                        // Form clause by operator.
                        if ( $clause['operator'] === 'IN' ) {

                            $queries[] = sprintf(
                                '(@taxonomy_%s:{%s})',
                                $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }
                        elseif ( $clause['operator'] === 'NOT IN' ) {

                            $queries[] = sprintf(
                                '-(@taxonomy_%s:{%s})',
                                $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }

                        $this->add_search_field( 'taxonomy_' . $clause['taxonomy'] );

                        break;
                    case 'slug':
                        $taxonomy = $clause['taxonomy'] ?? false;

                        // Change slug to the term id.
                        // We are searching with the term id not with the term slug.
                        $clause['terms'] = $this->slugs_to_ids( $clause['terms'], $taxonomy );

                        // The fallthrough is intentional: we only turn the slugs into ids.
                    case 'term_id':

                        // Form clause by operator.
                        if ( $clause['operator'] === 'IN' ) {

                            $queries[] = sprintf(
                                '(@taxonomy_id_%s:{%s})',
                                $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }
                        elseif ( $clause['operator'] === 'NOT IN' ) {

                            $queries[] = sprintf(
                                '-(@taxonomy_id_%s:{%s})',
                                $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }

                        $this->add_search_field( 'taxonomy_id_' . $clause['taxonomy'] );

                        break;
                    default:
                        return false;
                }
            }
            // If we have multiple clauses in the block, run the function recursively.
            else {
                $queries[] = $this->create_taxonomy_query( $clause, $relation );
            }
        }

        // Compare the relation.
        if ( $relation === 'AND' ) {
            return count( $queries ) ? '(' . implode( ' ', $queries ) . ')' : '';
        }
        elseif ( $relation === 'OR' ) {
            return count( $queries ) ? '(' . implode( '|', $queries ) . ')' : '';
        }
    }

    /**
     * Create a RediSearch meta query from a single WP_Query meta_query block.
     *
     * @param array  $query    The block to create the block from.
     * @param string $operator Possible operator of the parent array.
     * @return string
     */
    private function create_meta_query( array $query, string $operator = 'AND' ) : ?string {

        $relation = $query['relation'] ?? $operator;
        unset( $query['relation'] );

        // Determine the relation type
        if ( $relation === 'AND' ) {
            $queries = [];

            foreach ( $query as $name => $clause ) {
                if ( ! empty( $clause['key'] ) ) {
                    $query = $this->create_meta_clause( $clause );

                    if ( ! $query ) {
                        return null;
                    }
                    else {
                        $queries[] = $query;
                    }

                    $this->add_search_field( $clause['key'] );

                    if ( ! is_numeric( $name ) ) {
                        $this->meta_clauses[ $name ] = $clause['key'];
                    }
                }
                else {
                    $queries[] = $this->create_meta_query( $clause, 'AND' );
                }
            }

            return '(' . implode( ' ', $queries ) . ')';
        }
        elseif ( $relation === 'OR' ) {
            $queries = [];

            foreach ( $query as $name => $clause ) {
                if ( ! empty( $clause['key'] ) ) {
                    $query = $this->create_meta_clause( $clause );

                    if ( ! $query ) {
                        return null;
                    }
                    else {
                        $queries[] = $query;
                    }

                    $this->add_search_field( $clause['key'] );

                    if ( ! is_numeric( $name ) ) {
                        $this->meta_clauses[ $name ] = $clause['key'];
                    }
                }
                else {
                    $queries[] = $this->create_meta_query( $clause, 'OR' );
                }
            }

            return '(' . implode( '|', $queries ) . ')';
        }
    }

    /**
     * Create a single meta clause from an array representation.
     *
     * @param array $clause The array to work with.
     * @return string|null
     */
    private function create_meta_clause( array $clause ) : ?string {
        $fields = Utility::format( $this->index_info['fields'] );

        // Find out the type of the field we are dealing with.
        $field_type = array_reduce( $fields, function( $carry = null, $item = null ) use ( $clause ) {
            if ( ! empty( $carry ) ) {
                return $carry;
            }

            $name = $item[0];

            if ( $name === $clause['key'] ) {
                return Utility::get_value( $item, 'type' );
            }

            return null;
        });

        // If the field doesn't have a type, it doesn't exist and we want to bail out.
        if ( ! $field_type ) {
            return null;
        }

        $compare = $clause['compare'] ?? '=';
        $type    = $clause['type'] ?? 'CHAR';

        // We do not support some compare types, so bail early if some of them is found.
        if ( in_array( strtoupper( $compare ), [ 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE' ], true ) ) {
            return null;
        }

        // If we have a date or datetime values, convert them to unixtime.
        switch ( $type ) {
            case 'DATE':
            case 'DATETIME':
                if ( is_array( $clause['value'] ) ) {
                    $clause['value'] = array_map( 'strtotime', $clause['value'] );
                }
                else {
                    $clause['value'] = strtotime( $clause['value'] );
                }
        }

        // Map compare types to functions
        $compare_map = [
            '='           => 'equal',
            '!='          => 'not_equal',
            '>'           => 'greater_than',
            '>='          => 'greater_or_equal_than',
            '>'           => 'less_than',
            '>='          => 'less_or_equal_than',
            'LIKE'        => 'like',
            'NOT LIKE'    => 'not like',
            'BETWEEN'     => 'between',
            'NOT BETWEEN' => 'not_between',
        ];

        // Run the appropriate function if it exists
        if ( method_exists( $this, 'meta_' . $compare_map[ strtoupper( $compare ) ] ) ) {
            return call_user_func( [ $this, 'meta_' . $compare_map[ strtoupper( $compare ) ] ], $clause, $field_type );
        }
        else {
            return null;
        }
    }

    /**
     * Meta clause generator for compare type =
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_equal( array $clause, string $field_type ) : ?string {
        switch ( $field_type ) {
            case 'TEXT':
            case 'NUMERIC':
                return sprintf(
                    '(@%s:%s)',
                    $clause['key'],
                    $clause['value']
                );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type !=
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_not_equal( array $clause, string $field_type ) : ?string {
        $return = $this->meta_equal( $clause, $field_type );

        if ( $return ) {
            return '-' . $return;
        }
        else {
            return $return;
        }
    }

    /**
     * Meta clause generator for compare type >
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_greater_than( array $clause, string $field_type ) : ?string {
        switch ( $field_type ) {
            case 'NUMERIC':
                return sprintf(
                    '(@%s:[(%s +inf])',
                    $clause['key'],
                    $clause['value']
                );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type >=
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_greater_or_equal_than( array $clause, string $field_type ) : ?string {
        switch ( $field_type ) {
            case 'NUMERIC':
                return sprintf(
                    '(@%s:[%s +inf])',
                    $clause['key'],
                    $clause['value']
                );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type <
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_less_than( array $clause, string $field_type ) : ?string {
        switch ( $field_type ) {
            case 'NUMERIC':
                return sprintf(
                    '(@%s:[-inf (%s])',
                    $clause['key'],
                    $clause['value']
                );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type <=
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_less_or_equal_than( array $clause, string $field_type ) : ?string {
        switch ( $field_type ) {
            case 'NUMERIC':
                return sprintf(
                    '(@%s:[-inf %s])',
                    $clause['key'],
                    $clause['value']
                );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type BETWEEN
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_between( array $clause, string $field_type ) : ?string {
        $value = $clause['value'];

        if ( ! is_array( $value ) || count( $value ) !== 2 ) {
            return null;
        }

        switch ( $field_type ) {
            case 'NUMERIC':
                return sprintf(
                    '(@%s:[%s %s])',
                    $clause['key'],
                    $value[0],
                    $value[1]
                );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type NOT BETWEEN
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_not_between( array $clause, string $field_type ) : ?string {
        $return = $this->meta_between( $clause, $field_type );

        if ( $return ) {
            return '-' . $return;
        }
        else {
            return $return;
        }
    }

    /**
     * Meta clause generator for compare type LIKE
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_like( array $clause, string $field_type ) : ?string {
        $value = $clause['value'];
        $like  = false;

        if ( strpos( $value, '%' ) === strlen( $value ) - 1 ) {
            $value = str_replace( '%', '*', $value );
        }
        elseif ( strpos( $value, '%' ) !== false ) {
            return null;
        }

        switch ( $field_type ) {
            case 'TEXT':
                return sprintf(
                    '(@%s:%s)',
                    $clause['key'],
                    $value
                );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type NOT LIKE
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    private function meta_not_like( array $clause, string $field_type ) : ?string {
        $return = $this->meta_like( $clause, $field_type );

        if ( $return ) {
            return '-' . $return;
        }
        else {
            return $return;
        }
    }

    /**
     * Convert a list of taxonomy slugs into IDs.
     *
     * @param array  $terms     The slugs.
     * @param string $taxonomy  The taxonomy with which to work.
     * @return array List of IDs.
     */
    private function slugs_to_ids( array $terms, string $taxonomy ) : array {
        return array_map( function( $term ) use ( $taxonomy ) {
            $term_obj = get_term_by( 'slug', $term, $taxonomy );

            return $term_obj->term_id;
        }, $terms );
    }
}
