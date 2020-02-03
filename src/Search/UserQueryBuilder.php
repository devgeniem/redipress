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
class UserQueryBuilder extends QueryBuilder {

    /**
     * Group by
     * If $sortby is defined we need to groupby sortby tags.
     * We need to add @post_id by minimum to make sure that we have unique results.
     *
     * @var array
     */
    protected $groupby = [ '@user_id' ];

    /**
     * Return fields
     * The fields that we want the query to return from RediSearch.
     *
     * @var array
     */
    protected $return_fields = [ 'user_id', 'user_object' ];

    /**
     * Mapped query vars
     *
     * @var array
     */
    protected $query_vars = [
        'paged'               => null,
        'search'              => null,
        'blog_id'             => 'blog_id',
        'meta_query'          => null,
        'order'               => null,
        'orderby'             => null,
        'number'              => null,
        'offset'              => null,
        'meta_key'            => null,
        'meta_value'          => null,
        'meta_compare'        => null,
        'weight'              => null,
        'role'                => 'roles',
        'role__in'            => 'roles',
        'role__not_in'        => 'roles',
        'nicename'            => 'user_nicename',
        'nicename__in'        => 'user_nicename',
        'nicename__not_in'    => 'user_nicename',
        'login'               => 'user_login',
        'login__in'           => 'user_login',
        'login__not_in'       => 'user_login',
        'include'             => 'user_id',
        'exclude'             => 'user_id',
        'count_total'         => null,
        'fields'              => null,
        'who'                 => null,
        'has_published_posts' => null,
        'search_columns'      => null,
    ];

    /**
     * Must use search fields
     *
     * @var array
     */
    protected $must_use_search_fields = [];

    /**
     * Whether we want to use only the defined search fields or not.
     *
     * @var boolean
     */
    protected $use_only_defined = false;

    /**
     * Whether the builder is for posts or for users
     */
    const TYPE = 'user';

    /**
     * Query builder constructor
     *
     * @param \WP_User_Query $query      WP User Query object.
     * @param array          $index_info Index information.
     */
    public function __construct( \WP_User_Query $query, array $index_info ) {
        $this->query      = $query;
        $this->index_info = $index_info;

        $this->ignore_query_vars = apply_filters( 'redipress/ignore_query_vars', [
            'order',
            'orderby',
            'paged',
            'number',
            'offset',
            'meta_key',
        ]);

        $this->ignore_query_vars = apply_filters( 'redipress/ignore_query_vars/' . static::TYPE, $this->ignore_query_vars );

        // Allow adding support for query vars via a filter
        $this->query_vars = apply_filters( 'redipress/query_vars', $this->query_vars );
        $this->query_vars = apply_filters( 'redipress/query_vars/' . static::TYPE, $this->query_vars );

        $this->query_params = $this->query->query_vars;
    }

    /**
     * Returns a boolean whether we want to use only the defined search fields.
     *
     * @return boolean
     */
    public function use_only_defined_search_fields() : bool {
        return $this->use_only_defined;
    }

    /**
     * Returns a list of must use search fields added within this class.
     *
     * @return array
     */
    public function get_must_use_search_fields() : array {
        return $this->must_use_search_fields;
    }

    /**
     * WP_User_Query s parameter.
     *
     * @return string
     */
    protected function search() : string {
        return $this->conduct_search( 'search' );
    }

    /**
     * Get array'd blog_id query var.
     *
     * @return array
     */
    protected function get_blogs() : array {
        if ( empty( $this->query->query_vars['blog_id'] ) ) {
            $blog_id = \get_current_blog_id();
        }
        else {
            $blog_id = $this->query->query_vars['blog_id'];
        }

        return is_array( $blog_id ) ? $blog_id : [ $blog_id ];
    }

    /**
     * WP_User_Query blog_id parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function blog_id() : string {
        $clause = '@blogs:{' . implode( '|', $this->get_blogs() ) . '}';

        return $clause;
    }

    /**
     * WP_User_Query role parameter
     *
     * @return string Redisearch query condition.
     */
    protected function role() : string {
        if ( empty( $this->query->query_vars['role'] ) ) {
            return false;
        }
        else {
            $roles = $this->query->query_vars['role'];
        }

        $clause = '';

        if ( ! is_array( $roles ) ) {
            $roles = [ $roles ];
        }

        $roles = array_map( function( $role ) {
            return implode( '|', array_map( function( $blog ) use ( $role ) {
                return $blog . '_' . $role;
            }, $this->get_blogs() ) );
        }, $roles );

        $clause = '@roles:{' . implode( '|', $roles ) . '}';

        return $clause;
    }

    /**
     * WP_User_Query roles parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function role__not_in() : string {
        if ( empty( $this->query->query_vars['role__not_in'] ) ) {
            return false;
        }
        else {
            $roles = $this->query->query_vars['role__not_in'];
        }

        $clause = '';

        if ( ! is_array( $roles ) ) {
            $roles = [ $roles ];
        }

        $roles = array_map( function( $role ) {
            return implode( '|', array_map( function( $blog ) use ( $role ) {
                return $blog . '_' . $role;
            }, $this->get_blogs() ) );
        }, $roles );

        $clause = '-@roles:{' . implode( '|', $roles ) . '}';

        return $clause;
    }

    /**
     * WP_User_Query roles parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function role__in() : string {
        if ( empty( $this->query->query_vars['role__in'] ) ) {
            return false;
        }
        else {
            $roles = $this->query->query_vars['role__in'];
        }

        $clause = '';

        if ( ! is_array( $roles ) ) {
            $roles = [ $roles ];
        }

        $roles = array_map( function( $role ) {
            return implode( '|', array_map( function( $blog ) use ( $role ) {
                return $blog . '_' . $role;
            }, $this->get_blogs() ) );
        }, $roles );

        $clause = '@roles:{' . implode( '|', $roles ) . '}';

        return $clause;
    }

    /**
     * WP_User_Query nicename parameter
     *
     * @return string Redisearch query condition.
     */
    protected function nicename() : string {
        if ( empty( $this->query->query_vars['nicename'] ) ) {
            return false;
        }
        else {
            $nicename = $this->query->query_vars['nicename'];
        }

        $clause = '';

        if ( ! is_array( $nicename ) ) {
            $nicename = [ $nicename ];
        }

        $clause = '@user_nicename:(' . implode( '|', $nicename ) . ')';

        return $clause;
    }

    /**
     * WP_User_Query nicename__in parameter
     *
     * @return string Redisearch query condition.
     */
    protected function nicename__in() : string {
        if ( empty( $this->query->query_vars['nicename__in'] ) ) {
            return false;
        }
        else {
            $nicename = $this->query->query_vars['nicename__in'];
        }

        $clause = '';

        if ( ! is_array( $nicename ) ) {
            $nicename = [ $nicename ];
        }

        $clause = '@user_nicename:(' . implode( '|', $nicename ) . ')';

        return $clause;
    }

    /**
     * WP_User_Query nicename__not_in parameter
     *
     * @return string Redisearch query condition.
     */
    protected function nicename__not_in() : string {
        if ( empty( $this->query->query_vars['nicename__not_in'] ) ) {
            return false;
        }
        else {
            $nicename = $this->query->query_vars['nicename__not_in'];
        }

        $clause = '';

        if ( ! is_array( $nicename ) ) {
            $nicename = [ $nicename ];
        }

        $clause = '-@user_nicename:(' . implode( '|', $nicename ) . ')';

        return $clause;
    }

    /**
     * WP_User_Query login parameter
     *
     * @return string Redisearch query condition.
     */
    protected function login() : string {
        if ( empty( $this->query->query_vars['login'] ) ) {
            return false;
        }
        else {
            $login = $this->query->query_vars['login'];
        }

        $clause = '';

        if ( ! is_array( $login ) ) {
            $login = [ $login ];
        }

        $clause = '@user_login:(' . implode( '|', $login ) . ')';

        return $clause;
    }

    /**
     * WP_User_Query login__in parameter
     *
     * @return string Redisearch query condition.
     */
    protected function login__in() : string {
        if ( empty( $this->query->query_vars['login__in'] ) ) {
            return false;
        }
        else {
            $login = $this->query->query_vars['login__in'];
        }

        $clause = '';

        if ( ! is_array( $login ) ) {
            $login = [ $login ];
        }

        $clause = '@user_login:(' . implode( '|', $login ) . ')';

        return $clause;
    }

    /**
     * WP_User_Query login__not_in parameter
     *
     * @return string Redisearch query condition.
     */
    protected function login__not_in() : string {
        if ( empty( $this->query->query_vars['login__not_in'] ) ) {
            return false;
        }
        else {
            $login = $this->query->query_vars['login__not_in'];
        }

        $clause = '';

        if ( ! is_array( $login ) ) {
            $login = [ $login ];
        }

        $clause = '-@user_login:(' . implode( '|', $login ) . ')';

        return $clause;
    }

    /**
     * WP_User_Query include parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function include() : string {

        if ( empty( $this->query->query_vars['include'] ) ) {
            return false;
        }

        $include = $this->query->query_vars['include'];
        $clause  = '';

        if ( ! empty( $include ) && is_array( $include ) ) {
            $clause = '@user_id:(' . implode( '|', $include ) . ')';
        }

        return $clause;
    }

    /**
     * WP_User_Query exclude parameter.
     *
     * @return string Redisearch query condition.
     */
    protected function exclude() : string {

        if ( empty( $this->query->query_vars['exclude'] ) ) {
            return false;
        }

        $exclude = $this->query->query_vars['exclude'];
        $clause  = '';

        if ( ! empty( $exclude ) && is_array( $exclude ) ) {
            $clause = '-@user_id:{' . implode( '|', $exclude ) . '}';
        }

        return $clause;
    }

    /**
     * WP_User_Query search_columns parameter.
     *
     * @return string Empty string.
     */
    protected function search_columns() : string {
        if ( ! empty( $this->query->query_vars['search_columns'] ) ) {
            $this->must_use_search_fields = $this->query->query_vars['search_columns'];

            foreach ( $this->must_use_search_fields as &$column ) {
                $field_type = $this->get_field_type( $column );

                if ( ! $field_type ) {
                    return false;
                }

                if ( $column === 'ID' ) {
                    $column = 'user_id';
                }
            }

            $this->use_only_defined = true;
        }

        return '';
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
                case 'id':
                    $clause['orderby'] = 'user_id';
                    break;
                case 'display_name':
                case 'name':
                case 'login':
                case 'nicename':
                case 'email':
                case 'registered':
                case 'url':
                    $clause['orderby'] = strtolower( 'user_' . $clause['orderby'] );
                    break;
                case 'user_name':
                case 'user_login':
                case 'user_nicename':
                case 'user_email':
                case 'user_registered':
                case 'user_url':
                    $clause['orderby'] = strtolower( $clause['orderby'] );
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
