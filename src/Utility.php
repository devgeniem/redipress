<?php
/**
 * RediPress utility class file
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Entity\SchemaField;

/**
 * RediPress utility class
 */
class Utility {

    /**
     * Format a RediSearch output array to an associative key-value array.
     *
     * @param array $source The original array to format.
     * @return array
     */
    public static function format( array $source ) : array {
        // Don't bother with an empty array
        if ( empty( $source ) ) {
            return $source;
        }

        // Cast the values into strings first
        $source = self::recursive_to_string( $source );

        // Remove possible first integer
        if ( filter_var( $source[0], FILTER_VALIDATE_INT ) ) {
            unset( $source[0] );
        }

        // Split the array into chunks of two
        $chunks = array_chunk( $source, 2 );

        $return = [];

        // Turn the chunks into key-value pairs
        foreach ( $chunks as $chunk ) {
            $return[ $chunk[0] ] = $chunk[1] ?? null;
        }

        return $return;
    }

    /**
     * Cast all values into strings
     *
     * @param mixed $var The variable to handle.
     * @return mixed
     */
    public static function recursive_to_string( $var ) {
        if ( is_array( $var ) ) {
            return array_map( [ __CLASS__, __FUNCTION__ ], $var );
        }
        else {
            return (string) $var;
        }
    }

    /**
     * Get a list of field names from an array formatted list of schema fields
     *
     * @param array $schema The array to get the names from.
     * @return array
     */
    public static function get_schema_fields( array $schema = [] ) : array {
        return array_map( function( $field ) : ?string {
            return $field[0] ?? null;
        }, $schema );
    }
}