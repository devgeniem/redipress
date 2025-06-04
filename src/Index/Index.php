<?php
/**
 * RediPress index class file
 */

namespace Geniem\RediPress\Index;

use Geniem\RediPress\Settings,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility;

/**
 * RediPress index class
 */
abstract class Index {

    /**
     * Placeholder to suppress linter warnings
     */
    const INDEX_TYPE = 'default';

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
     * The index info
     *
     * @var array
     */
    protected $index_info;

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

    /**
     * Define core fields for the index
     *
     * @return array
     */
    abstract protected function define_core_fields(): array;

    /**
     * The constructor
     *
     * @param Client $client The client instance.
     */
    public function __construct( Client $client ) {
        $settings     = new Settings();
        $this->client = $client;

        $index_type = static::INDEX_TYPE;

        // Get the index name from settings
        $this->index = $settings->get( "{$index_type}_index" );

        // Reverse filter for getting the Index instance.
        add_filter( "redipress/{$index_type}_index_instance", function () {
            return $this;
        }, 1, 0 );

        $this->core_schema_fields = $this->define_core_fields();
    }

    /**
     * Create a RediSearch index.
     *
     * @return mixed
     */
    public function create() {
        $index_type = static::INDEX_TYPE;

        [ $options, $schema_fields, $raw_schema ] = $this->get_schema_fields();

        $return = $this->client->raw_command( 'FT.CREATE', array_merge( [ $this->index ], $options, $raw_schema ) );

        do_action( 'redipress/schema_created', $return, $options, $schema_fields, $raw_schema );
        do_action( "redipress/{$index_type}_schema_created", $return, $options, $schema_fields, $raw_schema );

        $this->maybe_write_to_disk( 'schema_created' );

        return $return;
    }

    /**
     * Drop existing index.
     *
     * @param mixed $not_in_use Not in use.
     * @param array $assoc_args Possible additional arguments.
     * @return mixed
     */
    public function drop( $not_in_use, ?array $assoc_args = [] ) {
        $args = isset( $assoc_args['delete_data'] ) ? [ 'DD' ] : [];

        $return = $this->client->raw_command( 'FT.DROPINDEX', [ $this->index, ...$args ] );

        return $return;
    }

    /**
     * Gather the schema fields for multisite index-creation
     *
     * @param string $key The run-time key to identify the gathering.
     * @return void
     */
    public function gather_schema_fields( string $key, bool $throw_error = true ): void {
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

            $fields[] = $field;
        }

        \update_option( "redipress_gather_fields_$key", $fields, false );
    }

    /**
     * Get the schema fields
     *
     * @return array
     */
    public function get_schema_fields(): array {
        $index_type = static::INDEX_TYPE;

        // Filter to add possible more fields.
        $schema_fields = apply_filters( "redipress/index/{$index_type}/schema_fields", $this->core_schema_fields );

        // Remove possible duplicate fields
        $schema_fields = array_unique( $schema_fields );

        $raw_schema = array_reduce(
            $schema_fields,
            // Convert SchemaField objects into raw arrays
            fn( ?array $c, SchemaField $field ): array => array_merge( $c, $field->get() ),
            []
        );

        $raw_schema = apply_filters( "redipress/index/{$index_type}/raw_schema", array_merge( [ 'SCHEMA' ], $raw_schema ) );

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

        $options = apply_filters( "redipress/index/{$index_type}/options", $options );

        return [
            $options,
            $schema_fields,
            $raw_schema,
        ];
    }

    /**
     * Add a document to Redis
     *
     * @param array  $converted_document The document to add in an alternating array format.
     * @param string $document_id The document ID.
     * @return mixed
     */
    protected function add_document( array $converted_document, string $document_id ) {
        $command = [ $this->index . ':' . $document_id ];

        $raw_command = array_merge( $command, $converted_document );

        $return = $this->client->raw_command( 'HSET', $raw_command );

        return $return;
    }

    /**
     * Delete a document from Redis
     *
     * @param string $document_id The document ID.
     * @return mixed
     */
    protected function delete_document( string $document_id ) {
        $return = $this->client->raw_command( 'DEL', [ $document_id ] );

        do_action( 'redipress/post_deleted', $document_id, $return );

        return $return;
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
        elseif ( self::$written ) {
                return true;
		}
		else {
			register_shutdown_function( [ $this, 'write_to_disk' ] );
			self::$written = true;
			return true;
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

    /**
     * A helper function to use in gathering field names from objects.
     *
     * @param SchemaField $field The field object to handle.
     * @return string
     */
    protected function return_field_name( SchemaField $field ): string {
        return $field->name;
    }

    /**
     * Returns the tag separator value through a filter.
     *
     * @return string
     */
    public static function get_tag_separator(): string {
        return apply_filters( 'redipress/tag_separator', self::TAG_SEPARATOR );
    }

    /**
     * Get RediSearch field type for a field
     *
     * @param string $key The key for which to fetch the field type.
     * @return string|null
     */
    protected function get_field_type( string $key ): ?string {
        $fields = Utility::format( $this->index_info['attributes'] );

        $field_type = array_reduce( $fields, function ( $carry = null, $item = null ) use ( $key ) {
            if ( ! empty( $carry ) ) {
                return $carry;
            }

            if ( $item[1] === $key ) {
                return Utility::get_value( $item, 'type' );
            }

            return null;
        });

        return $field_type;
    }

    /**
     * Set the index info
     *
     * @param array $info The data to set.
     * @return void
     */
    public function set_info( array $info ): void {
        $this->index_info = $info;
    }

    /**
     * Get the index info
     *
     * @return array
     */
    public function get_info(): array {
        return $this->index_info;
    }

    /**
     * Store additional data for indexing from outside
     *
     * @param mixed  $post_id The post ID.
     * @param string $field   The field name.
     * @param mixed  $data    The data.
     * @param string $method  The method to use with multiple values. Defaults to "use_last". Possibilites: use_last, concat, concat_with_spaces, array_merge, sum, custom (needs filter).
     * @return void
     */
    public static function store( $post_id, string $field, $data, string $method = 'use_last' ): void {
        if ( ! isset( self::$additional[ $post_id ] ) ) {
            self::$additional[ $post_id ] = [];
        }

        $original = self::$additional[ $post_id ][ $field ] ?? '';

        switch ( $method ) {
            case 'use_last':
                if ( is_array( $data ) ) {
                    $data = array_pop( $data );
                }

                self::$additional[ $post_id ][ $field ] = $data;
                break;
            case 'concat':
                if ( is_array( $data ) ) {
                    $data = implode( '', $data );
                }

                self::$additional[ $post_id ][ $field ] = $original . $data;
                break;
            case 'concat_with_spaces':
                if ( is_array( $data ) ) {
                    $data = implode( ' ', $data );
                }

                self::$additional[ $post_id ][ $field ] = $original . ' ' . $data;
                break;
            case 'array_merge':
                if ( ! is_array( $original ) ) {
                    if ( empty( $original ) ) {
                        $original = [];
                    }
                    else {
                        $original = [ $original ];
                    }
                }

                if ( ! is_array( $data ) ) {
                    $data = [ $data ];
                }

                self::$additional[ $post_id ][ $field ] = array_merge( $original, $data );
                break;
            case 'sum':
                if ( is_array( $data ) ) {
                    $data = array_sum( $data );
                }

                self::$additional[ $post_id ][ $field ] = $original + $data;
                break;
            default:
                self::$additional[ $post_id ][ $field ] = apply_filters( "redipress/additional_field/method/$method", $data, $original );
                break;
        }
    }

    /**
     * Get additional data for a post by field name
     *
     * @param mixed   $post_id The post ID.
     * @param string  $field   The field name.
     * @param boolean $purge   Whether to purge the data after reading.
     * @return mixed
     */
    public static function get( $post_id, string $field, bool $purge = false ) {
        $value = self::$additional[ $post_id ][ $field ] ?? null;

        if ( $value && ( $purge || defined( 'WP_IMPORTING' ) ) ) {
            self::$additional[ $post_id ][ $field ] = null;
        }

        return $value;
    }
}
