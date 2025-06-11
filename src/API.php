<?php
/**
 * Some utility functions to be used with RediPress
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Index\PostIndex;

/**
 * Get one post with its from RediPress index.
 *
 * @param mixed $post_id The ID to fetch.
 * @return \WP_Post|null The post to fetch or null if not found.
 */
function get_post( $post_id ) {
    $client = apply_filters( 'redipress/client', null );

    $index = Settings::get( 'posts_index' );

    $doc_id = PostIndex::get_document_id( \get_post( $post_id ), $post_id );

    $result = $client->raw_command( 'HGETALL', [ $index . ':' . $doc_id ] );

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

    return null;
}

/**
 * Update a single value in a RediPress document
 *
 * @param string  $doc_id Document ID to modify.
 * @param string  $field  Field to update.
 * @param mixed   $value  Value to update the field with.
 * @param integer $score  Possible weighing score to the value.
 * @param bool    $users  Whether to make the change to users table.
 * @return array
 */
function update_value( $doc_id, $field, $value, $score = 1, $users = false ) {
    $client = apply_filters( 'redipress/client', null );

    if ( $users ) {
        $index       = Settings::get( 'users_index' );
        $index_class = 'UserIndex';
    }
    else {
        $index       = Settings::get( 'posts_index' );
        $index_class = 'PostIndex';
    }

    $raw_schema = $client->raw_command( 'FT.INFO', [ $index ] );

    $index_info = Utility::format( $raw_schema );

    $type = get_field_type( $field, $index_info );

    if ( $type === 'TAG' && is_array( $value ) ) {
        $value = implode( $index_class::get_tag_separator(), $value );
    }

    // RediSearch doesn't accept boolean values
    if ( is_bool( $value ) ) {
        $value = (int) $value;
    }

    // Escape dashes in all but numeric fields
    if ( $type !== 'NUMERIC' ) {
        $value = escape_string( $value );
    }

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

/**
 * Escape dashes from string
 *
 * @param  string $string Unescaped string.
 * @return string         Escaped $string.
 */
function escape_string( ?string $string = '' ): string {
    return Utility::escape_string( $string );
}

/**
 * Get RediSearch field type for a field
 *
 * @param string $key The key for which to fetch the field type.
 * @param array  $index The index.
 * @return string|null
 */
function get_field_type( string $key, array $index ): ?string {
    $fields = Utility::format( $index['attributes'] );

    $field_type = array_reduce( $fields, function ( $carry = null, $item = null ) use ( $key ) {
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
 * Delete document from RediPress index.
 *
 * @param string $doc_id Document ID to delete.
 */
function delete_doc( $doc_id ) {

    $client = apply_filters( 'redipress/client', null );
    $index  = Settings::get( 'index' );

    if ( ! empty( $doc_id ) && ! empty( $client ) && ! empty( $index ) ) {

        // Do the delete.
        $client->raw_command(
            'FT.DEL',
            [
                $index,
                $doc_id,
                'DD',
            ]
        );
    }
}
