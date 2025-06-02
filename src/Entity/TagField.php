<?php
/**
 * RediPress tag field class file
 */

namespace Geniem\RediPress\Entity;

/**
 * RediPress tag field class
 */
class TagField extends SchemaField {

    /**
     * Field type
     *
     * @var string
     */
    public const TYPE = 'TAG';

    /**
     * Separator for tag input
     *
     * @var string
     */
    public $separator = '*';

    /**
     * Field constructor
     *
     * @param array $args Associative array of following arguments to create a text field:
     *      - name (string) required   Field name
     *      - nostem (string) optional   Separator for the input. Defaults to comma. Can be any printable character.
     *
     * @throws \Exception If required parameter is not present.
     */
    public function __construct( array $args ) {
        if ( empty( $args['name'] ) ) {
            throw new \Exception( \esc_html( 'Field name is required in defining new schema fields for RediSearch.' ) );
        }
        else {
            $this->name = $args['name'];
        }

        $this->separator = $args['separator'] ?? $this->separator;
    }

    /**
     * A method that returns the field as a one-dimensional array.
     *
     * @return array
     */
    public function export(): array {
        $export = [];

        if ( $this->separator ) {
            $export[] = 'SEPARATOR';
            $export[] = $this->separator;
        }

        return $export;
    }
}
