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
function get_post( $post_id ) { //}: ?\WP_Post {
    $settings = new Settings();
    $client   = apply_filters( 'redipress/client', null );

    $index = Settings::get( 'index' );

    $result = $client->raw_command( 'FT.GET', [ $index, $post_id ] );

    // If nothing is found, just return null
    if ( ! $result ) {
        return null;
    }

    $result = Utility::format( $result );

    if ( ! empty( $result['post_object'] ) ) {
        $post_object = maybe_unserialize( $result['post_object'] );

        if ( $post_object instanceof \WP_Post ) {
            return $post_object;
        }
    }

    return false;
}

/**
 * Update a single value in a RediPress document
 *
 * @param string $doc_id Document ID to modify.
 * @param string $field  Field to update.
 * @param string $value  Value to update the field with.
 * @param integer $score Possible weighing score to the value.
 * @return array
 */
function update_value( $doc_id, $field, $value, $score = 1 ) {
    $settings = new Settings();
    $client = apply_filters( 'redipress/client', null );

    $index = Settings::get( 'index' );

    $result_add = $client->raw_command(
        'FT.ADD',
        [
            $index,
            $doc_id,
            $score,
            'REPLACE',
            'PARTIAL',
            'FIELDS',
            $field,
            $value,
        ]
    );

    $result_add_hash = $client->raw_command(
        'FT.ADDHASH',
        [
            $index,
            $doc_id,
            $score,
            'REPLACE',
        ]
    );

    // Returns possible debugging data
    return [
        $result_add,
        $result_add_hash,
    ];
}
