<?php
/**
 * RediPress text field class file
 */

namespace Geniem\RediPress\Entity;

/**
 * RediPress text field class
 */
class TextField extends SchemaField {

    /**
     * Field type
     *
     * @var string
     */
    public const TYPE = 'TEXT';

    /**
     * Possible search weight for the field
     *
     * @var float
     */
    public $weight = 1.0;

    /**
     * Whether the field is sortable or not
     *
     * Can only be declared on text, numeric and tag fields.
     *
     * @var boolean
     */
    public $sortable = false;

    /**
     * Prevents the field from using stemming if it's enabled
     *
     * Can only be declared on text fields
     *
     * @var boolean
     */
    public $nostem = false;

    /**
     * Field constructor
     *
     * @param array $args Associative array of following arguments to create a text field:
     *                    - name (string) required   Field name
     *                    - weight (float) optional  Weight in searches, defaults to 1.0
     *                    - sortable (bool) optional Whether the search is sortable by this field
     *                    - nostem (bool) optional   Disable stemming for this field even if it's used globally.
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

        $this->weight   = $args['weight'] ?? 1.0;
        $this->sortable = $args['sortable'] ?? false;
        $this->nostem   = $args['nostem'] ?? false;
    }

    /**
     * A method that returns the field as a one-dimensional array.
     *
     * @return array
     */
    public function export(): array {
        $export = [];

        if ( $this->nostem ) {
            $export[] = 'NOSTEM';
        }

        $export[] = 'WEIGHT';
        $export[] = $this->weight;

        if ( $this->sortable ) {
            $export[] = 'SORTABLE';
        }

        return $export;
    }
}
