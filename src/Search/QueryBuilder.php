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
abstract class QueryBuilder {

    /**
     * WP Query or WP User Query object.
     *
     * @var WP_Query|WP_User_query
     */
    protected $query = null;

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
     * Return fields
     * The fields that we want the query to return from RediSearch.
     *
     * @var array
     */
    protected $return_fields = [];

    /**
     * Reduce functions for return fields
     *
     * @var array
     */
    protected $reduce_functions = [];

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
    protected $query_vars = [];

    /**
     * From which fields the search is conducted.
     *
     * @var array
     */
    protected $search_fields = [];

    /**
     * Get query instance.
     *
     * @return WP_Query|WP_User_Query
     */
    public function get_query_instance() {
        return $this->query;
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
     * Return the possible apply clauses.
     *
     * @return array
     */
    public function get_applies() : array {
        return [];
    }

    /**
     * Return the possible filters
     *
     * @return array
     */
    public function get_filters() : array {
        return [];
    }

    /**
     * Return the return fields
     *
     * @return array
     */
    public function get_return_fields() : array {
        return $this->return_fields;
    }

    /**
     * Return possible reduce functions
     *
     * @return array
     */
    public function get_reduce_functions() : array {
        return $this->reduce_functions;
    }

    /**
     * Get the RediSearch query based on the original query
     *
     * @return array
     */
    public function get_query() : array {
        // Ensure that the tax query gets parsed even if it wasn't implicitly defined.
        if ( empty( $this->query->query_vars['tax_query'] ) ) {
            $this->query->query_vars['tax_query'] = true;
        }

        $return = array_filter( array_map( function( string $query_var ) : string {
            if (
                in_array( $query_var, $this->ignore_query_vars, true ) ||
                empty( $this->query->query_vars[ $query_var ] )
            ) {
                return false;
            }

            if ( ! empty( $this->query_vars[ $query_var ] ) && is_string( $this->query_vars[ $query_var ] ) ) {
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

            // If we have a callable for the query var, possibly passed via a filter
            if ( isset( $this->query_vars[ $query_var ] ) && is_callable( $this->query_vars[ $query_var ] ) ) {
                return $this->query_vars[ $query_var ]( $this );
            }
            // Use the designated method if it exists.
            elseif ( method_exists( $this, $query_var ) ) {
                return $this->{ $query_var }();
            }
            // Special treatment for numeric fields.
            elseif ( $field_type === 'NUMERIC' ) {

                if ( empty( $this->query_vars[ $query_var ] ) || empty( $this->query->query_vars[ $query_var ] ) ) {
                    return false;
                }

                return '@' . $this->query_vars[ $query_var ] . ':[' . $this->query->query_vars[ $query_var ] . ' ' . $this->query->query_vars[ $query_var ] . ']';
            }
            // Otherwise we are dealing with an ordinary text field.
            else {

                if ( empty( $this->query->query_vars[ $query_var ] ) || empty( $this->query_vars[ $query_var ] ) ) {
                    return false;
                }

                return '@' . $this->query_vars[ $query_var ] . ':' . $this->query->query_vars[ $query_var ];
            }
        }, array_keys( $this->query->query_vars ) ) );

        // All minuses to the end of the line.
        usort( $return, function( $a, $b ) {
            return ( substr( $a, 0, 1 ) === '-' );
        });

        return array_merge(
            $return,
            $this->modifiers,
        );
    }

    /**
     * Helper function to add a filter for the search fields.
     *
     * @param string $field The field to add.
     * @return void
     */
    protected function add_search_field( string $field ) {
        $this->search_fields[] = $this->query_vars[ $field ] ?? $field;

        $this->search_fields = \array_unique( $this->search_fields );
    }

    /**
     * Returns a list of search fields added within this class.
     *
     * @return array
     */
    public function get_search_fields() : array {
        return $this->search_fields;
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

        $query_vars = $this->query_params;

        if ( $this->query->is_front_page ) {
            return false;
        }

        if ( ! empty( $query_vars['meta_query'] ) ) {
            $meta_query = $this->meta_query();
            if ( ! $meta_query && $meta_query !== '' ) {
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
     * Reduce functions handling
     *
     * @return string
     */
    protected function reduce_functions() : string {
        $this->reduce_functions = array_merge( $this->reduce_functions, $this->query->query['reduce_functions'] );

        return '';
    }

    /**
     * Search parameter.
     *
     * @param string $parameter The search parameter.
     *
     * @return string
     */
    protected function conduct_search( string $parameter ) : string {
        $terms = $this->query->query_vars[ $parameter ];

        // Add a filter for the raw search terms
        $terms = apply_filters( 'redipress/search_terms/raw', $terms );
        $terms = apply_filters( 'redipress/search_terms/raw/' . static::TYPE, $terms );

        // Remove a list of forbidden characters based on RediSearch restrictions.
        $forbidden_characters = str_split( ',.<>{}[]"\':;!@#$%^&()+=~' );

        $terms = str_replace( $forbidden_characters, array_fill( 0, count( $forbidden_characters ), ' ' ), $terms );

        // Special handling for minus signs preceded by space, otherwise dashes are fine.
        $terms = str_replace( ' -', ' ', $terms );

        // Add a filter for the search terms
        $terms = apply_filters( 'redipress/search_terms', $terms );
        $terms = apply_filters( 'redipress/search_terms/' . static::TYPE, $terms );

        // Escape dashes
        $terms = str_replace( '-', '\\-', $terms );

        // Filter for escaped search terms
        $terms = apply_filters( 'redipress/search_terms/escaped', $terms );
        $terms = apply_filters( 'redipress/search_terms/escaped/' . static::TYPE, $terms );

        $terms = \preg_replace_callback( '/[^\(\)\| ]+/', function( $word ) {
            if ( \mb_strlen( $word[0] === 0 ) ) {
                return '';
            }

            return \mb_strlen( $word[0] ) > 1 ? $word[0] . '*' : $word[0];
        }, $terms );

        $sort = explode( ' ', $terms ) ?: [];

        // Handle tildes
        $tilde = array_filter( $sort, function( $word ) {
            return strpos( $word, '~' ) === 0;
        });

        $rest = array_diff( $sort, $tilde );

        $this->modifiers = $tilde;

        return implode( ' ', $rest );
    }

    /**
     * Custom weights for certain parameters.
     *
     * @return string
     */
    protected function weight() : string {

        if ( empty( $this->query->query_vars['weight'] ) ) {
            return '';
        }

        $return = [];

        $weight = $this->query->query_vars['weight'];

        // Create weight clauses for post types
        if ( ! empty( $weight['post_type'] ) ) {
            $return = array_map(
                function( $weight, $post_type ) : string {
                    return sprintf(
                        '(~@post_type:%s => {$weight: %s})',
                        $post_type,
                        $weight
                    );
                },
                $weight['post_type'],
                array_keys( $weight['post_type'] )
            );
        }

        // Create weight clauses for authors
        if ( ! empty( $weight['author'] ) ) {
            $return = array_merge(
                $return,
                array_map(
                    function( $weight, $author ) : string {
                        return sprintf(
                            '(~@post_author:%s => {$weight: %s})',
                            $author,
                            $weight
                        );
                    },
                    $weight['author'],
                    array_keys( $weight['author'] )
                )
            );
        }

        // Create weight clauses for taxonomy terms
        if ( ! empty( $weight['taxonomy'] ) ) {
            foreach ( $weight['taxonomy'] as $taxonomy => $terms ) {
                $return = array_merge(
                    $return,
                    array_map(
                        function( $weight, $term ) use ( $taxonomy ) : string {
                            return sprintf(
                                '(~@taxonomy_id_%s:%s => {$weight: %s})',
                                $taxonomy,
                                $term,
                                $weight
                            );
                        },
                        $terms,
                        array_keys( $terms )
                    )
                );
            }
        }

        // Create weight clauses for meta values
        if ( ! empty( $weight['meta'] ) ) {
            foreach ( $weight['meta'] as $meta_key => $values ) {
                $return = array_merge(
                    $return,
                    array_filter( array_map(
                        function( $weight, $meta_value ) use ( $meta_key ) : ?string {
                            $field_type = $this->get_field_type( $meta_key );

                            if ( ! $field_type ) {
                                return null;
                            }

                            $this->add_search_field( $meta_key );

                            return sprintf(
                                '(~@%s:%s => {$weight: %s})',
                                $meta_key,
                                $meta_value,
                                $weight
                            );
                        },
                        $values,
                        array_keys( $values )
                    ) )
                );
            }
        }

        return implode( ' ', $return );
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
     * WP_Query groupby parameter.
     * This should be called after.
     *
     * @return string
     */
    public function get_groupby() : array {
        return $this->groupby;
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

        $meta_query = $this->query->meta_query;

        if ( empty( $meta_query->queries ) ) {
            return '';
        }

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
     * Create a RediSearch taxonomy query from a single WP_Query tax_query block.
     *
     * This function runs itself recursively if the query has multiple levels.
     *
     * @param array   $query    The block to create the block from.
     * @param string  $operator Possible operator of the parent array.
     * @param boolean $prefix   Whether to prefix the field with taxonomy_ or not.
     * @return string
     */
    public function create_taxonomy_query( array $query, string $operator = 'AND', bool $prefix = true ) : string {

        $relation = $query['relation'] ?? $operator;
        unset( $query['relation'] );

        // RediSearch doesn't support these tax query clause operators.
        $unsupported_operators = [
            'EXISTS',
            'NOT EXISTS',
        ];

        // Determine the relation type
        $queries = [];

        if ( empty( $query ) ) {
            return '';
        }

        foreach ( $query as $clause ) {

            // Operator
            $operator_uppercase = strtoupper( $clause['operator'] );

            // We do not support some operator types, so bail early if some of them is found.
            if ( in_array( $operator_uppercase, $unsupported_operators, true ) ) {
                return false;
            }

            // Escape clause terms
            $clause['terms'] = $this->escape_clause_terms( $clause['terms'] );

            if ( ! empty( $clause['taxonomy'] ) ) {
                switch ( $clause['field'] ) {
                    case 'name':
                        // Form clause by operator.
                        if ( $clause['operator'] === 'IN' ) {
                            $queries[] = sprintf(
                                '(@%s:{%s})',
                                $prefix ? 'taxonomy_' . $clause['taxonomy'] : $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }
                        elseif ( $clause['operator'] === 'NOT IN' ) {
                            $queries[] = sprintf(
                                '-(@%s:{%s})',
                                $prefix ? 'taxonomy_' . $clause['taxonomy'] : $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }

                        $this->add_search_field( 'taxonomy_' . $clause['taxonomy'] );

                        break;
                    case 'slug':
                        // Form clause by operator.
                        if ( $clause['operator'] === 'IN' ) {
                            $queries[] = sprintf(
                                '(@%s:{%s})',
                                $prefix ? 'taxonomy_slug_' . $clause['taxonomy'] : $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }
                        elseif ( $clause['operator'] === 'NOT IN' ) {
                            $queries[] = sprintf(
                                '-(@%s:{%s})',
                                $prefix ? 'taxonomy_slug_' . $clause['taxonomy'] : $clause['taxonomy'],
                                implode( '|', (array) $clause['terms'] )
                            );
                        }

                        $this->add_search_field( 'taxonomy_slug_' . $clause['taxonomy'] );

                        break;
                    case 'term_taxonomy_id':
                        $taxonomy = $clause['taxonomy'] ?? false;

                        // Change slug to the term id.
                        // We are searching with the term id not with the term slug.
                        $clause['terms'] = $this->terms_to_ids( $clause['terms'], $taxonomy, $clause['field'] );

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

        // All minuses to the end of the line.
        usort( $queries, function( $a, $b ) {
            return ( substr( $a, 0, 1 ) === '-' );
        });

        // Compare the relation.
        if ( $relation === 'AND' ) {
            switch ( count( $queries ) ) {
                case 0:
                    return '';
                case 1:
                    return $queries[0];
                default:
                    return '(' . implode( ' ', $queries ) . ')';
            }
        }
        elseif ( $relation === 'OR' ) {
            switch ( count( $queries ) ) {
                case 0:
                    return '';
                case 1:
                    return $queries[0];
                default:
                    return '(' . implode( '|', $queries ) . ')';
            }
        }
    }

    /**
     * Escape clause terms for the RediSearch query.
     *
     * @param mixed $terms Terms to be escaped.
     * @return array Escaped strings.
     */
    protected function escape_clause_terms( $terms ) : array {
        if ( ! is_array( $terms ) ) {
            $terms = [ $terms ];
        }

        $terms = array_map( function( $term ) {
            return \str_replace( '-', '\\-', $term );
        }, $terms );

        return $terms;
    }

    /**
     * Create a RediSearch meta query from a single WP_Query meta_query block.
     *
     * @param array  $query    The block to create the block from.
     * @param string $operator Possible operator of the parent array.
     * @return string
     */
    protected function create_meta_query( array $query, string $operator = 'AND' ) : ?string {

        $relation = $query['relation'] ?? $operator;
        unset( $query['relation'] );

        // Determine the relation type
        if ( $relation === 'AND' ) {
            $queries = [];

            foreach ( $query as $name => $clause ) {
                if ( ! empty( $clause['key'] ) ) {
                    if ( ! isset( $clause['value'] ) ) {
                        continue;
                    }

                    $query = $this->create_meta_clause( $clause );

                    if ( is_null( $query ) ) {
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

            // All minuses to the end of the line.
            usort( $queries, function( $a, $b ) {
                return ( substr( $a, 0, 1 ) === '-' );
            });

            $queries = array_filter( $queries, function( $query ) {
                return ! empty( $query );
            });

            switch ( count( $queries ) ) {
                case 0:
                    return '';
                case 1:
                    return $queries[0];
                default:
                    return '(' . implode( ' ', $queries ) . ')';
            }
        }
        elseif ( $relation === 'OR' ) {
            $queries = [];

            foreach ( $query as $name => $clause ) {
                if ( ! empty( $clause['key'] ) ) {
                    if ( ! isset( $clause['value'] ) ) {
                        continue;
                    }

                    $query = $this->create_meta_clause( $clause );

                    if ( is_null( $query ) ) {
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

            // All minuses to the end of the line.
            usort( $queries, function( $a, $b ) {
                return ( substr( $a, 0, 1 ) === '-' );
            });

            $queries = array_filter( $queries, function( $query ) {
                return ! empty( $query );
            });

            switch ( count( $queries ) ) {
                case 0:
                    return '';
                case 1:
                    return $queries[0];
                default:
                    return '(' . implode( '|', $queries ) . ')';
            }
        }
    }

    /**
     * Create a single meta clause from an array representation.
     *
     * @param array $clause The array to work with.
     * @return string|null
     */
    protected function create_meta_clause( array $clause ) : ?string {
        global $wpdb;

        // Filter out capability queries as they are handled differently
        if ( preg_match( '/^wp_\d+?_capabilities$/', $clause['key'] ) ) {
            return '';
        }

        // Find out the type of the field we are dealing with.
        $field_type = $this->get_field_type( $clause['key'] );

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

        // If we don't have a value to compare with, the clause should not be handled.
        // Appears when a meta_key is used only for sorting.
        if ( ! isset( $clause['value'] ) ) {
            return '';
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

        if ( $field_type === 'TAG' ) {
            $taxonomy = $clause['key'];
            $field    = 'name';
            $terms    = $clause['value'];

            switch ( $compare ) {
                case '=':
                case 'IN':
                    $operator = 'IN';
                    break;
                case '!=':
                case 'NOT IN':
                    $operator = 'NOT IN';
                    break;
                default:
                    return false;
            }

            return $this->create_taxonomy_query([
                [
                    'taxonomy' => $taxonomy,
                    'field'    => $field,
                    'terms'    => $terms,
                    'operator' => $operator,
                ],
            ], 'AND', false );
        }
        else {
            // Map compare types to functions
            $compare_map = [
                '='           => 'equal',
                '!='          => 'not_equal',
                '>'           => 'greater_than',
                '>='          => 'greater_or_equal_than',
                '>'           => 'less_than',
                '<='          => 'less_or_equal_than',
                'LIKE'        => 'like',
                'NOT LIKE'    => 'not_like',
                'BETWEEN'     => 'between',
                'NOT BETWEEN' => 'not_between',
                'IN'          => 'in',
                'NOT IN'      => 'not_in',
            ];

            // Escape dashes from the values
            $clause['value'] = str_replace( '-', '\\-', $clause['value'] );


            // Run the appropriate function if it exists
            if ( method_exists( $this, 'meta_' . $compare_map[ strtoupper( $compare ) ] ) ) {
                return call_user_func( [ $this, 'meta_' . $compare_map[ strtoupper( $compare ) ] ], $clause, $field_type );
            }
            else {
                return null;
            }
        }
    }

    /**
     * Get RediSearch field type for a field
     *
     * @param string $key The key for which to fetch the field type.
     * @return string|null
     */
    protected function get_field_type( string $key ) : ?string {
        $fields = Utility::format( $this->index_info['fields'] );

        $field_type = array_reduce( $fields, function( $carry = null, $item = null ) use ( $key ) {
            if ( ! empty( $carry ) ) {
                return $carry;
            }

            $name = $item[0];

            if ( $name === $key ) {
                return Utility::get_value( $item, 'type' );
            }

            return null;
        });

        return $field_type;
    }

    /**
     * Meta clause generator for compare type =
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    protected function meta_equal( array $clause, string $field_type ) : ?string {
        switch ( $field_type ) {
            case 'TEXT':
                return sprintf(
                    '(@%s:%s)',
                    $clause['key'],
                    $clause['value']
                );
            case 'NUMERIC':
                return sprintf(
                    '(@%s:[%s %s])',
                    $clause['key'],
                    $clause['value'],
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
    protected function meta_not_equal( array $clause, string $field_type ) : ?string {
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
    protected function meta_greater_than( array $clause, string $field_type ) : ?string {
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
    protected function meta_greater_or_equal_than( array $clause, string $field_type ) : ?string {
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
    protected function meta_less_than( array $clause, string $field_type ) : ?string {
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
    protected function meta_less_or_equal_than( array $clause, string $field_type ) : ?string {
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
    protected function meta_between( array $clause, string $field_type ) : ?string {
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
    protected function meta_not_between( array $clause, string $field_type ) : ?string {
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
    protected function meta_like( array $clause, string $field_type ) : ?string {
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
    protected function meta_not_like( array $clause, string $field_type ) : ?string {
        $return = $this->meta_like( $clause, $field_type );

        if ( $return ) {
            return '-' . $return;
        }
        else {
            return $return;
        }
    }

    /**
     * Meta clause generator for compare type IN
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    protected function meta_in( array $clause, string $field_type ) : ?string {
        switch ( $field_type ) {
            case 'TEXT':
                return sprintf(
                    '(@%s:(%s))',
                    $clause['key'],
                    implode( '|', (array) $clause['value'] )
                );
            case 'NUMERIC':
                return implode( '|', array_map( function( $value ) use ( $clause ) {
                    return sprintf(
                        '(@%s:[%s %s])',
                        $clause['key'],
                        $value,
                        $value
                    );
                }, $clause['value'] ) );
            default:
                return null;
        }
    }

    /**
     * Meta clause generator for compare type NOT IN
     *
     * @param array  $clause     The clause to work with.
     * @param string $field_type The field type we are working with.
     * @return string|null
     */
    protected function meta_not_in( array $clause, string $field_type ) : ?string {
        $return = $this->meta_in( $clause, $field_type );

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
     * @param string $field     The field to fetch the term by.
     * @return array List of IDs.
     */
    protected function terms_to_ids( array $terms, string $taxonomy, string $field = 'slug' ) : array {
        return array_map( function( $term ) use ( $taxonomy, $field ) {
            $term_obj = get_term_by( $field, $term, $taxonomy );

            return $term_obj->term_id;
        }, $terms );
    }

    /**
     * Escape dashes from string
     *
     * @param  string $string Unescaped string.
     * @return string         Escaped $string.
     */
    public function escape_dashes( ?string $string = '' ) : string {
        if ( ! $string ) {
            $string = '';
        }

        $string = \str_replace( '-', '\-', $string );
        return $string;
    }
}
