<?php
/**
 * RediPress schema class file
 */

namespace Geniem\RediPress\Entity;

/**
 * RediPress index class
 */
abstract class SchemaField {

    /**
     * Field type
     *
     * Possible values:
     * - TEXT
     * - NUMERIC
     * - GEO
     * - TAG
     *
     * @var string
     */
    public const TYPE = self::TYPE;

    /**
     * Field name
     *
     * @var string
     */
    public $name = '';

    /**
     * A method that returns the field's unique features as a one-dimensional array.
     *
     * @return array
     */
    abstract public function export(): array;

    /**
     * Get the whole defining one-dimensional array. Uses the required export() method.
     *
     * @return array
     */
    public function get(): array {
        $export = [
            $this->name,
            static::TYPE,
        ];

        $export = array_merge( $export, $this->export() );

        return $export;
    }

    /**
     * This is used to identify possible duplicates when creating the index
     *
     * @return string
     */
    public function __toString() {
        return $this->name;
    }
}
