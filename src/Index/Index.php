<?php
/**
 * RediPress index class file
 */

namespace Geniem\RediPress\Index;

use Geniem\RediPress\Settings,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Redis\Client;

/**
 * RediPress index class
 */
abstract class Index {

    /**
     * The index type
     */
    const INDEX_TYPE = self::INDEX_TYPE;

    /**
     * The Redis client wrapper
     *
     * @var Client
     */
    protected $client;

    /**
     * The index name
     *
     * @var string
     */
    protected $index;

    /**
     * Core fields
     *
     * These are stored for filtering purposes.
     *
     * @var array
     */
    protected $core_schema_fields = [];

    /**
     * The default tag separator.
     */
    protected const TAG_SEPARATOR = '*';

    /**
     * The static array in which external additional values are stored.
     *
     * @var array
     */
    protected static $additional = [];

    /**
     * Whether we will write the index to disk in shutdown hook.
     *
     * @var boolean
     */
    protected static $written = false;

    public function __construct( Client $client ) {
        $settings     = new Settings();
        $this->client = $client;

        // Get the index name from settings
        $this->index = $settings->get( "{${self::INDEX_TYPE}}_index" );

        // Reverse filter for getting the Index instance.
        add_filter( "redipress/{${self::INDEX_TYPE}}_index_instance", function() {
            return $this;
        }, 1, 0 );

        $this->core_schema_fields = $this->define_core_fields();
    }

    /**
     * Define core fields for the index
     *
     * @return array
     */
    abstract protected function define_core_fields() : array;

    /**
     * Create a RediSearch index.
     *
     * @return mixed
     */
    public function create() {
        [ $options, $schema_fields, $raw_schema ] = $this->get_schema_fields();

        $return = $this->client->raw_command( 'FT.CREATE', array_merge( [ $this->index ], $options, $raw_schema ) );

        do_action( 'redipress/schema_created', $return, $options, $schema_fields, $raw_schema );
        do_action( "redipress/{${self::INDEX_TYPE}}_schema_created", $return, $options, $schema_fields, $raw_schema );

        $this->maybe_write_to_disk( 'schema_created' );

        return $return;
    }

    /**
     * Drop existing index.
     *
     * @param boolean $delete_data Whether to delete data in addition to the index or not.
     * @return mixed
     */
    public function drop( bool $delete_data = false ) {
        $args = $delete_data ? [ 'DD' ] : [];

        return $this->client->raw_command( 'FT.DROPINDEX', [ $this->index, ...$args ] );
    }

    /**
     * Gather the schema fields for multisite index-creation
     *
     * @param string $key The run-time key to identify the gathering.
     * @return void
     */
    public function gather_schema_fields( string $key, bool $throw_error = true ) : void {
        [ $options, $schema_fields ] = $this->get_schema_fields();

        $fields = \get_option( "redipress_gather_fields_$key", [] );

        foreach ( $schema_fields as $field ) {
            $found = false;

            // If there is a field with the same name within the initial fields, find it
            foreach ( $fields as &$original_field ) {
                if ( $field->name === $original_field->name ) {
                    $original = serialize( $original_field );
                    $new      = serialize( $field );

                    if ( $original !== $new ) {
                        if ( $throw_error ) {
                            die( 'RediPress index creation error: conflicting fields with name ' . $field->name );
                        }

                        $original_field->conflict = true;

                        $field->conflict = true;

                        $fields[] = $field;
                    }
                }
            }

            if ( ! $found ) {
                $fields[] = $field;
            }
        }

        \update_option( "redipress_gather_fields_$key", $fields, false );
    }

    /**
     * Get the schema fields
     *
     * @return array
     */
    public function get_schema_fields() : array {
        // Filter to add possible more fields.
        $schema_fields = apply_filters( "redipress/index/{${self::INDEX_TYPE}}/schema_fields", $this->core_schema_fields );

        // Remove possible duplicate fields
        $schema_fields = array_unique( $schema_fields );

        $raw_schema = array_reduce(
            $schema_fields,
            // Convert SchemaField objects into raw arrays
            fn( ?array $c, SchemaField $field ) : array => array_merge( $c, $field->get() ),
            []
        );

        $raw_schema = apply_filters( "redipress/index/{${self::INDEX_TYPE}}/raw_schema", array_merge( [ 'SCHEMA' ], $raw_schema ) );

        $options = [
            'ON',
            'HASH',
            'PREFIX',
            '1',
            $this->index . ':',
            'MAXTEXTFIELDS',
            'STOPWORDS',
            '0',
        ];

        $options = apply_filters( "redipress/index/{${self::INDEX_TYPE}}/options", $options );

        return [
            $options,
            $schema_fields,
            $raw_schema,
        ];
    }

    /**
     * Write the index to the disk if the setting is on.
     *
     * @param mixed $args Special arguments to give to the filter if needed.
     *
     * @return mixed
     */
    public function maybe_write_to_disk( $args = null ) {
        $settings = new Settings();

        // Bail early if we don't want a persisting index.
        if ( ! $settings->get( 'persist_index' ) ) {
            return;
        }

        // Write immediately, if we want to do it every time.
        if ( $settings->get( 'write_every' ) ) {
            // Allow overriding the settings via a filter
            $filter_writing = apply_filters( 'redipress/write_to_disk', null, $args );

            if ( $filter_writing ) {
                return $this->write_to_disk();
            }
        }
        else {
            if ( self::$written ) {
                return true;
            }
            else {
                register_shutdown_function( [ $this, 'write_to_disk' ] );
                self::$written = true;
                return true;
            }
        }
    }

    /**
     * Write the index to the disk to persist it.
     *
     * @return mixed
     */
    public function write_to_disk() {
        return $this->client->raw_command( 'SAVE', [] );
    }
}