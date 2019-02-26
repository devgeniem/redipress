<?php
/**
 * RediPress index class file
 */

namespace Geniem\RediPress\Index;

use Geniem\RediPress\Admin,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Entity\NumericField,
    Geniem\RediPress\Entity\TagField,
    Geniem\RediPress\Entity\TextField,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility;

/**
 * RediPress index class
 */
class Index {

    /**
     * RediPress wrapper for the Predis client
     *
     * @var Client
     */
    protected $client;

    /**
     * Index
     *
     * @var string
     */
    protected $index;

    /**
     * Names for core fields
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
        $this->client = $client;

        // Get the index name from settings
        $this->index = Admin::get( 'index' );

        // Register AJAX functions
        dustpress()->register_ajax_function( 'redipress_create_index', [ $this, 'create' ] );
        dustpress()->register_ajax_function( 'redipress_drop_index', [ $this, 'drop' ] );
        dustpress()->register_ajax_function( 'redipress_index_all', [ $this, 'index_all' ] );

        // Register CLI bindings
        add_action( 'redipress/cli/index_all', [ $this, 'index_all' ], 50, 0 );
        add_action( 'redipress/cli/index_single', [ $this, 'index_single' ], 50, 1 );

        // Register indexing hooks
        add_action( 'save_post', [ $this, 'upsert' ], 10, 3 );

        $this->define_core_fields();
    }

    /**
     * Drop existing index.
     *
     * @return mixed
     */
    public function drop() {
        return $this->client->raw_command( 'FT.DROP', [ $this->index ] );
    }

    /**
     * Define core fields for the RediSearch schema
     *
     * @return void
     */
    public function define_core_fields() {
        // Define the WordPress core fields
        $this->core_schema_fields = [
            new TextField([
                'name'     => 'post_title',
                'weight'   => 5.0,
                'sortable' => true,
            ]),
            new TextField([
                'name' => 'post_name',
            ]),
            new TextField([
                'name' => 'post_content',
            ]),
            new TextField([
                'name' => 'post_type',
            ]),
            new TextField([
                'name'   => 'post_excerpt',
                'weight' => 2.0,
            ]),
            new TextField([
                'name' => 'post_author',
            ]),
            new NumericField([
                'name' => 'post_author_id',
            ]),
            new NumericField([
                'name'     => 'post_id',
                'sortable' => true,
            ]),
            new NumericField([
                'name'     => 'menu_order',
                'sortable' => true,
            ]),
            new TextField([
                'name' => 'permalink',
            ]),
            new NumericField([
                'name'     => 'post_date',
                'sortable' => true,
            ]),
            new TextField([
                'name' => 'search_index',
            ]),
        ];

        // Add taxonomies to core fields
        $taxonomies = get_taxonomies();

        foreach ( $taxonomies as $taxonomy ) {
            $this->core_schema_fields[] = new TagField([
                'name'      => 'taxonomy_' . $taxonomy,
                'separator' => $this->get_tag_separator(),
            ]);

            $this->core_schema_fields[] = new TagField([
                'name'      => 'taxonomy_id_' . $taxonomy,
                'separator' => $this->get_tag_separator(),
            ]);
        }
    }

    /**
     * Create a RediSearch index.
     *
     * @return mixed
     */
    public function create() {
        // Filter to add possible more fields.
        $schema_fields = apply_filters( 'redipress/schema_fields', $this->core_schema_fields );

        $raw_schema = array_reduce( $schema_fields,
            /**
             * Convert SchemaField objects into raw arrays
             *
             * @param array       $carry The array to gather.
             * @param SchemaField $item  The schema to convert.
             *
             * @return array
             */
            function( ?array $carry, SchemaField $item = null ) : array {
                return array_merge( $carry ?? [], $item->get() ?? [] );
            }
        );

        $raw_schema = apply_filters( 'redipress/raw_schema', array_merge( [ $this->index, 'SCHEMA' ], $raw_schema ) );

        $return = $this->client->raw_command( 'FT.CREATE', $raw_schema );

        do_action( 'redipress/schema_created', $return, $schema_fields, $raw_schema );

        $this->maybe_write_to_disk( 'schema_created' );

        return $return;
    }

    /**
     * Index all posts to the RediSearch database
     *
     * @return mixed
     */
    public function index_all() {
        do_action( 'redipress/before_index_all' );

        $args = [
            'posts_per_page' => -1,
            'post_type'      => 'any',
        ];

        $query = new \WP_Query( $args );

        $count = count( $query->posts );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $progress = \WP_CLI\Utils\make_progress_bar( __( 'Indexing posts', 'redipress' ), $count );
        }
        else {
            $progress = null;
        }

        $result = array_map( function( $post ) use ( $progress ) {
            $converted = $this->convert_post( $post );

            $return = $this->add_post( $converted, $post->ID );

            if ( ! empty( $progress ) ) {
                $progress->tick();
            }
        }, $query->posts );

        do_action( 'redipress/indexed_all', $result, $query );

        $this->maybe_write_to_disk( 'indexed_all' );

        if ( ! empty( $progress ) ) {
            $progress->finish();
        }

        return $count;
    }

    /**
     * Index a single post by its ID.
     *
     * @param integer $post_id The post ID to index.
     * @return mixed
     */
    public function index_single( int $post_id ) {
        $post = get_post( $post_id );

        $converted = $this->convert_post( $post );

        return $this->add_post( $converted, $post_id );
    }

    /**
     * Update or insert a post in the RediSearch database
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     * @param bool     $update  Whether this is an existing post being updated or not.
     * @return mixed
     */
    public function upsert( int $post_id, \WP_Post $post, bool $update ) {
        // Run a list of checks if we really want to do this or not.
        if (
            wp_is_post_revision( $post_id ) ||
            defined( 'DOING_AUTOSAVE' )
        ) {
            return;
        }

        // If post is not published, ensure it isn't in the index
        if ( $post->post_status !== 'publish' ) {
            $this->delete_post( $post_id );

            $this->maybe_write_to_disk( 'post_deleted' );

            return;
        }

        $converted = $this->convert_post( $post );

        $result = $this->add_post( $converted, $post_id );

        do_action( 'redipress/new_post_added', $result, $post );

        $this->maybe_write_to_disk( 'new_post_added' );

        return $result;
    }

    /**
     * Convert Post object to Redis command
     *
     * @param \WP_Post $post The post object to convert.
     * @return array
     */
    public function convert_post( \WP_Post $post ) : array {

        do_action( 'redipress/before_index_post', $post );

        $args = [];

        // Get the author data
        $author_field = apply_filters( 'redipress/post_author_field', 'display_name', $post->ID, $post );
        $user_object  = get_userdata( $post->post_author );

        if ( $user_object instanceof \WP_User ) {
            $post_author = $user_object->{ $author_field };
        }
        else {
            $post_author = '';
        }

        $args['post_author'] = apply_filters( 'redipress/post_author', $post_author, $post->ID, $post );

        // Get the post date
        $args['post_date'] = strtotime( $post->post_date ) ?: null;

        // Get the RediSearch schema for possible additional fields
        $schema = $this->client->raw_command( 'FT.INFO', [ $this->index ] );

        $schema = Utility::format( $schema );

        $fields = Utility::get_schema_fields( $schema['fields'] ?? [] );

        // Gather field names from hardcoded field for later.
        $core_field_names = array_map( [ $this, 'return_field_name' ], $this->core_schema_fields );

        $additional_fields = array_diff( $fields, $core_field_names );

        $additional_values = array_map( function( $field ) use ( $post ) {
            $value = apply_filters( 'redipress/additional_field/' . $field, null, $post->ID, $post );
            return apply_filters( 'redipress/additional_field/' . $post->ID . '/' . $field, $value, $post );
        }, $additional_fields );

        $additions = array_combine( $additional_fields, $additional_values );

        // Gather the additional search index
        $search_index = apply_filters( 'redipress/search_index', '', $post->ID, $post );
        $search_index = apply_filters( 'redipress/search_index/' . $post->ID, $search_index, $post );

        // Filter the post object that will be added to the database serialized.
        $post_object = apply_filters( 'redipress/post_object', $post );

        // Get rest of the fields
        $rest = [
            'post_id'        => $post->ID,
            'post_name'      => $post->post_name,
            'post_title'     => $post->post_title,
            'post_author_id' => $post->post_author,
            'post_excerpt'   => $post->post_excerpt,
            'post_content'   => wp_strip_all_tags( $post->post_content, true ),
            'post_type'      => $post->post_type,
            'post_object'    => serialize( $post_object ),
            'permalink'      => get_permalink( $post->ID ),
            'menu_order'     => absint( $post->menu_order ),
            'search_index'   => $search_index,
        ];

        // Handle the taxonomies
        $taxonomies = get_taxonomies();

        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_the_terms( $post->ID, $taxonomy ) ?: [];

            // Add the terms
            $term_string = implode( $this->get_tag_separator(), array_map( function( $term ) {
                return $term->name;
            }, $terms ) );

            // Add the terms
            $id_string = implode( $this->get_tag_separator(), array_map( function( $term ) {
                return $term->term_id;
            }, $terms ) );

            $rest[ 'taxonomy_' . $taxonomy ]    = $term_string;
            $rest[ 'taxonomy_id_' . $taxonomy ] = $id_string;
        }

        do_action( 'redipress/indexed_post', $post );

        return $this->client->convert_associative( array_merge( $args, $rest, $additions ) );
    }

    /**
     * Add a post to the database
     *
     * @param array      $converted_post         The post in array form.
     * @param string|int $id                     The document ID for RediSearch.
     * @return mixed
     */
    public function add_post( array $converted_post, $id ) {
        $command = [ $this->index, $id, 1, 'REPLACE', 'LANGUAGE', 'finnish' ];

        $raw_command = array_merge( $command, [ 'FIELDS' ], $converted_post );

        return $this->client->raw_command( 'FT.ADD', $raw_command );
    }

    /**
     * Delete a post from the database
     *
     * @param string|int $id The document ID for RediSearch.
     * @return mixed
     */
    public function delete_post( $id ) {
        $return = $this->client->raw_command( 'FT.DEL', [ $this->index, $id, 'DD' ] );

        do_action( 'redipress/post_deleted', $id, $return );

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
        // Allow overriding the setting via a filter
        $filter_writing = apply_filters( 'redipress/write_to_disk', null, $args );

        if ( $filter_writing ?? Admin::get( 'persist_index' ) ) {
            return $this->write_to_disk();
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
    private function return_field_name( SchemaField $field ) : string {
        return $field->name;
    }

    /**
     * Returns the tag separator value through a filter.
     *
     * @return string
     */
    protected function get_tag_separator() : string {
        return apply_filters( 'redipress/tag_separator', self::TAG_SEPARATOR );
    }
}
