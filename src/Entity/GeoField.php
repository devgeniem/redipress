<?php
/**
 * RediPress geo field class file
 */

namespace Geniem\RediPress\Entity;

/**
 * RediPress geo field class
 */
class GeoField extends SchemaField {

    /**
     * Field type
     *
     * @var string
     */
    public const TYPE = 'GEO';

    /**
     * Field constructor
     *
     * @param array $args Associative array of following arguments to create a numeric field:
     *                    - name (string) required   Field name
     *
     * @throws \Exception If required parameter is not present.
     */
    public function __construct( array $args ) {
        if ( empty( $args['name'] ) ) {
            throw new \Exception( __( 'Field name is required in defining new schema fields for RediSearch.' ) );
        }
        else {
            $this->name = $args['name'];
        }
    }

    /**
     * A method that returns the field as a one-dimensional array.
     *
     * @return array
     */
    public function export() : array {
        $export = [];

        return $export;
    }
}
