<?php
/**
 * RediPress numeric field class file
 */

namespace Geniem\RediPress\Entity;

/**
 * RediPress numeric field class
 */
class NumericField extends SchemaField {

    /**
     * Field type
     *
     * @var string
     */
    public const TYPE = 'NUMERIC';

    /**
     * Whether the field is sortable or not
     *
     * Can only be declared on text, numeric and tag fields.
     *
     * @var boolean
     */
    public $sortable = false;

    /**
     * Whether the field is un-normalized.
     *
     * @var boolean
     */
    public $unf = false;

    /**
     * Field constructor
     *
     * @param array $args Associative array of following arguments to create a numeric field:
     *                    - name (string) required   Field name
     *                    - sortable (bool) optional Whether the search is sortable by this field.
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

        $this->sortable = $args['sortable'] ?? false;
        $this->unf      = $args['unf'] ?? $this->unf;
    }

    /**
     * A method that returns the field as a one-dimensional array.
     *
     * @return array
     */
    public function export() : array {
        $export = [];

        if ( $this->sortable ) {
            $export[] = 'SORTABLE';
        }

        if ( $this->unf ) {
            $export[] = 'UNF';
        }

        return $export;
    }
}
