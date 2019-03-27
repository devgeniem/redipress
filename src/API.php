<?php
/**
 * Some utility functions to be used with RediPress
 */

namespace Geniem\RediPress;

/**
 * Get one post with its from RediPress index.
 *
 * @param mixed $post_id The ID to fetch.
 * @return WP_Post|null The post to fetch or null if not found.
 */
function get_post( $post_id ) : ?\WP_Post {
    $client = apply_filters( 'redipress/client', null );

    $index = Admin::get( 'index' );

    $result = $client->raw_command( 'FT.GET', [ $index, $post_id ] );

    $result = Utility::format( $result );

    if ( ! empty( $result['post_object'] ) ) {
        $post_object = maybe_unserialize( $result['post_object'] );

        if ( $post_object instanceof WP_Post ) {
            return $post_object;
        }
    }

    return null;
}
