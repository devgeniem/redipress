<?php
/**
 * RediPress utility class file
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Settings;

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
    public static function format( array $source ): array {
        // Don't bother with an empty array
        if ( empty( $source ) ) {
            return $source;
        }

        // Cast the values into strings first
        $source = self::recursive_to_string( $source );

        // Remove possible first integer
        if ( isset( $source[0] ) && filter_var( $source[0], FILTER_VALIDATE_INT ) ) {
            unset( $source[0] );
        }

        // If the data to handle is a list rather than a key-value object, bail early.
        $list = array_reduce( $source, function ( $carry = true, $item = null ) {
            if ( $carry === false ) {
                return false;
            }

            return is_array( $item );
        });

        if ( $list ) {
            return $source;
        }

        // If we are dealing with an odd number of items, it's not a key-value object.
        if ( count( $source ) % 2 === 1 ) {
            return $source;
        }

        // Split the array into chunks of two
        $chunks = array_chunk( $source, 2 );

        $return = [];

        // Turn the chunks into key-value pairs
        foreach ( $chunks as $chunk ) {
            if ( ! isset( $chunk[1] ) ) {
                return $chunk;
            }

            $key = $chunk[0];

            if ( is_array( $chunk[1] ) ) {
                $value = self::format( $chunk[1] );
            }
            else {
                $value = $chunk[1];
            }

            $return[ $key ] = $value;
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
    public static function get_schema_fields( array $schema = [] ): array {
        return array_map( function ( $field ): ?string {
            return $field[1] ?? null;
        }, $schema );
    }

    /**
     * Get a value from an alternating array
     *
     * @param array  $array The array from which to search.
     * @param string $key   The key with which the value can be fetched.
     * @return mixed
     */
    public static function get_value( array $array, string $key ) {
        $index = array_search( $key, $array, true );

        if ( $index !== false && ! empty( $array[ ++$index ] ) ) {
            return $array[ $index ];
        }

        return null;
    }

    /**
     * Escapes a string value but prevents numeric values from being slashed.
     * Numeric values will be casted to the correct type.
     *
     * @param mixed  $string The string to escape.
     * @param string $meta_type MySQL data type.
     * @return mixed depending on the meta_type parameter and input string
     * the return value will be either string (or array of strings), int or float.
     */
    public static function escape_value_by_meta_type( $string, $meta_type = 'CHAR' ): mixed {

        // Possible numeric MySQL data type values for WP meta query are SIGNED, UNSIGNED, NUMERIC and DECIMAL.
        // For NUMERIC and DECIMAL declaration also data precicion and scale can be specified.
        // https://dev.mysql.com/doc/refman/8.0/en/numeric-type-syntax.html

        if ( is_numeric( $string ) && preg_match( '/^(?:SIGNED|UNSIGNED|NUMERIC(?:\(\d+(?:,\s?\d+)?\))?|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/', $meta_type ) ) {
            return floatval( $string ) ? (float) $string : (int) $string;
        }

        return self::escape_string( $string );
    }

    /**
     * Escape a string
     *
     * @param string|array|null $string The string or array of strings to escape.
     * @return string|array
     */
    public static function escape_string( $string = '' ) {
        if ( is_null( $string ) ) {
            return '';
        }

        $search = [
            '-',
            '.',
        ];

        $replace = [
            '\\-',
            '\\.',
        ];

        if ( Settings::get( 'escape_parentheses' ) ) {
            $search[]  = '(';
            $search[]  = ')';
            $replace[] = '\\(';
            $replace[] = '\\)';
        }

        return \str_replace( $search, $replace, $string );
    }
}
