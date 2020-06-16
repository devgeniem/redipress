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
        // Filter the query before RediPress to handle localizations.
        add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 9, 2 );
        // Add the query var filter
        add_filter( 'redipress/query_vars', [ $this, 'add_query_var' ], 10, 1 );
    }

    /**
     * Filter WordPress posts requests before RediPress.
     *
     * This fixes the main query on multisites where the main query
     * is set to query posts from multiple sites.
     *
     * @param array|null $posts An empty array of posts.
     * @param \WP_Query  $query The WP_Query object.
     * @return array Results or null if no results.
     */
    public function posts_pre_query( ?array $posts, \WP_Query $query ) : ?array {
        if ( method_exists( $query, 'is_main_query' ) && $query->is_main_query() ) {
            $blog_id  = \get_current_blog_id();
            $blog_var = $query->get( 'blog' );

            // If querying from all blogs or
            // the blog array contains more than just the current blog id or
            // the blog id doesn't match the current one.
            if (
                $blog_var === 'all' ||
                (
                    is_array( $blog_var ) &&
                    count( [ $blog_id ] + $blog_var ) > 1
                ) ||
                (
                    is_scalar( $blog_var ) &&
                    intval( $blog_var ) !== $blog_id
                )
            ) {

                // Current lang.
                $lang        = $query->query['lang'];
                $tax_queries = $query->get( 'tax_query' ) ?? [];

                // Fail fast.
                if ( empty( $lang ) || empty( $tax_queries ) ) {
                    return $posts;
                }

                // Find the PLL language query and replace the id with the slug.
                foreach ( $tax_queries as $idx => $tax_query ) {

                    if ( $taxonomy === 'language' && $field === 'term_taxonomy_id' ) {

                        // Bail early if the term is not found for some reason.
                        if ( ! $term ) {
                            continue;
                        }

                        // Convert the id query to a slug query.
                        $slug_query = [
                            'taxonomy' => 'language',
                            'field'    => 'slug',
                            'terms'    => [ $lang ],
                            'operator' => 'IN',
                        ];

                        // Replace the query.
                        $tax_queries[ $idx ] = $slug_query;
                    }
                }

                // Recreate the tax queries.
                $query->tax_query = new \WP_Tax_Query( $tax_queries );
            }
        }

        return $posts;
    }

    /**
     * Add the lang query var to the RediPress allowed query vars
     *
     * @param array $query_vars The query_vars to modify.
     * @return array
     */
    public function add_query_var( array $query_vars ) : array {
        $query_vars['lang'] = function ( \Geniem\RediPress\Search\QueryBuilder $query_builder ) : string {
            $query = $query_builder->get_query_instance();

            // No need to modify the query if this is the main query.
            if ( method_exists( $query, 'is_main_query' ) && $query->is_main_query() ) {
                return '';
            }

            $slug = $query->query['lang'] ?? $query->query_vars['lang'];

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
