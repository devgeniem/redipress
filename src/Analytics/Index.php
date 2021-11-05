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
     * Returns the tag separator value through a filter.
     *
     * @return string
     */
    public static function get_tag_separator() : string {
        return apply_filters( 'redipress/tag_separator', self::TAG_SEPARATOR );
    }
}
