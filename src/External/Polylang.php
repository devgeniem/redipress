<?php
/**
 * RediPress Polylang extension
 */

namespace Geniem\RediPress\External;

/**
 * RediPress Polylang extension class
 */
class Polylang {

    /**
     * Constructor
     */
    public function __construct() {
        // Add the query var filter
        add_filter( 'redipress/query_vars', [ $this, 'add_query_var' ], 10, 1 );
    }

    /**
     * Add the lang query var to the RediPress allowed query vars
     *
     * @param array $query_vars The query_vars to modify.
     * @return array
     */
    public function add_query_var( array $query_vars ) : array {
        $query_vars['lang'] = function ( \Geniem\RediPress\Search\QueryBuilder $query_builder ) : string {
            $query = $query_builder->get_wp_query();

            $slug = $query->query['lang'];

            if ( empty( $slug ) ) {
                return '';
            }

            $term_clause = [
                [
                    'field'    => 'slug',
                    'terms'    => [ $slug ],
                    'taxonomy' => 'language',
                    'operator' => 'IN',
                ],
            ];

            return $query_builder->create_taxonomy_query( $term_clause );
        };

        return $query_vars;
    }
}
