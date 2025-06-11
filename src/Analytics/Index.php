<?php
/**
 * RediPress analytics index class file
 */

namespace Geniem\RediPress\Analytics;

use Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Entity\NumericField,
    Geniem\RediPress\Entity\TagField,
    Geniem\RediPress\Entity\TextField,
    Geniem\RediPress\Settings;

/**
 * RediPress analytics index class
 */
class Index {

    /**
     * RediPress wrapper for the Predis client
     *
     * @var Client
     */
    protected $client;

    /**
     * Index name
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
     * Construct the index object
     *
     * @param Client $client Client instance.
     */
    public function __construct( Client $client ) {
        $settings     = new Settings();
        $this->client = $client;

        // Get the index name from settings
        $this->index = $settings->get( 'analytics_index' );

        $this->define_fields();

        add_filter( 'redipress/analytics/create_index', [ $this, 'create' ], 50, 1 );
        add_filter( 'redipress/analytics/drop_index', [ $this, 'drop' ], 50, 1 );
    }

    /**
     * Define core fields for the RediSearch schema
     *
     * @return void
     */
    protected function define_fields() {
        // Define the WordPress core fields
        $this->core_schema_fields = [
            // Unique ID for this very search
            new TextField([
                'name' => 'id',
            ]),
            // Search timestamp as UNIX time
            new NumericField([
                'name'     => 'timestamp',
                'sortable' => true,
            ]),
            // Which search did the request came from
            new TextField([
                'name'     => 'search',
                'sortable' => true,
            ]),
            // The keywords used
            new TagField([
                'name'      => 'keywords',
                'separator' => self::get_tag_separator(),
            ]),
            // The possible processed keywords
            new TextField([
                'name'      => 'processed_keywords',
                'separator' => self::get_tag_separator(),
            ]),
            // How many results did we get
            new NumericField([
                'name'     => 'results',
                'sortable' => true,
            ]),
            // A identifying hash for the user
            new TextField([
                'name'     => 'user',
                'sortable' => true,
            ]),
        ];

        if ( \is_multisite() ) {
            \array_unshift(
                $this->core_schema_fields,
                new TextField([
                    'name'     => 'blog_id',
                    'sortable' => true,
                ])
            );
        }
    }

    /**
     * Get the schema fields
     *
     * @return array
     */
    public function get_schema_fields(): array {
        // Filter to add possible more fields.
        $schema_fields = apply_filters( 'redipress/schema_fields', $this->core_schema_fields );

        // Remove possible duplicate fields
        $schema_fields = array_unique( $schema_fields );

        $raw_schema = array_reduce(
            $schema_fields,
            // Convert SchemaField objects into raw arrays
            fn( ?array $c, SchemaField $field ): array => array_merge( $c, $field->get() ),
            []
        );

        $raw_schema = apply_filters( 'redipress/raw_schema', array_merge( [ 'SCHEMA' ], $raw_schema ) );

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

        $options = apply_filters( 'redipress/index_options', $options );

        return [
            $options,
            $schema_fields,
            $raw_schema,
        ];
    }

    /**
     * Create a RediSearch index.
     *
     * @return mixed
     */
    public function create() {
        [ $options, $schema_fields, $raw_schema ] = $this->get_schema_fields();

        $return = $this->client->raw_command( 'FT.CREATE', array_merge( [ $this->index ], $options, $raw_schema ) );

        do_action( 'redipress/schema_created', $return, $options, $schema_fields, $raw_schema );

        $this->write_to_disk();

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
     * Write the index to the disk to persist it.
     *
     * @return mixed
     */
    public function write_to_disk() {
        return $this->client->raw_command( 'SAVE', [] );
    }

    /**
     * Returns the tag separator value through a filter.
     *
     * @return string
     */
    public static function get_tag_separator(): string {
        return apply_filters( 'redipress/tag_separator', self::TAG_SEPARATOR );
    }
}
