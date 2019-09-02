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
    Geniem\RediPress\Utility,
    Smalot\PdfParser\Parser as PdfParser,
    PhpOffice\PhpWord\IOFactory;

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
        add_filter( 'redipress/create_index', [ $this, 'create' ], 50, 1 );
        add_filter( 'redipress/drop_index', [ $this, 'drop' ], 50, 1 );

        // Register external actions
        add_action( 'redipress/delete_post', [ $this, 'delete_post' ], 50, 1 );
        add_action( 'redipress/index_post', [ $this, 'upsert' ], 50, 3 );

        // Register indexing hooks
        add_action( 'save_post', [ $this, 'upsert' ], 10, 3 );
        add_action( 'delete_post', [ $this, 'delete' ], 10, 1 );

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
                'name'     => 'post_type',
                'sortable' => true,
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
            new TextField([
                'name'     => 'post_id',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'post_object',
                'sortable' => false,
            ]),
            new NumericField([
                'name'     => 'menu_order',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'post_status',
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
                'name'     => 'post_parent',
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

        // Remove possible duplicate fields
        $schema_fields = array_unique( $schema_fields );

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
        global $wpdb;

        do_action( 'redipress/before_index_all' );

        // phpcs:disable
        $ids = $wpdb->get_results( "SELECT ID FROM $wpdb->posts" ) ?? [];
        // phpcs:enable

        $count = count( $ids );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::success( 'Starting to index a total of ' . $count . ' posts.' );

            $progress = \WP_CLI\Utils\make_progress_bar( __( 'Indexing posts', 'redipress' ), $count );
        }
        else {
            $progress = null;
        }

        $posts = array_map( function( $row ) {
            return get_post( $row->ID );
        }, $ids );

        $posts = apply_filters( 'redipress/custom_posts', $posts );

        $result = array_map( function( $post ) use ( $progress ) {
            $language = apply_filters( 'redipress/post_language', $post->lang ?? null, $post );
            $language = apply_filters( 'redipress/post_language/' . $post->ID, $language, $post );

            $converted = $this->convert_post( $post );

            $this->add_post( $converted, $post->ID, $language );

            if ( ! empty( $progress ) ) {
                $progress->tick();
            }
        }, $posts );

        do_action( 'redipress/indexed_all', $result );

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
     * @param string|int $post_id The post ID, can be real or arbitrary.
     * @param \WP_Post   $post    The post object.
     * @param bool       $update  Whether this is an existing post being updated or not.
     * @return mixed
     */
    public function upsert( $post_id, \WP_Post $post, bool $update = null ) {
        // Run a list of checks if we really want to do this or not.
        if (
            wp_is_post_revision( $post_id ) ||
            defined( 'DOING_AUTOSAVE' )
        ) {
            return;
        }

        $converted = $this->convert_post( $post );

        $result = $this->add_post( $converted, $post_id );

        do_action( 'redipress/new_post_added', $result, $post );

        $this->maybe_write_to_disk( 'new_post_added' );

        return $result;
    }

    /**
     * Delete a post from the RediSearch database
     *
     * @param string|int $post_id The post ID, can be real or arbitrary.
     * @return void
     */
    public function delete( $post_id ) {
        $this->delete_post( $post_id );

        $this->maybe_write_to_disk( 'post_deleted' );
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

        $additions = array_filter( $additions, function( $item ) {
            return ! is_null( $item );
        });

        $additions = array_map( 'maybe_serialize', $additions );

        $search_index = [];
        $tax          = [];

        // Handle the taxonomies
        $taxonomies = get_taxonomies();

        $wanted_taxonomies = Admin::get( 'taxonomies' ) ?? [];

        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_the_terms( $post->ID, $taxonomy ) ?: [];

            if ( ! empty( $post->taxonomies[ $taxonomy ] ) ) {
                $custom_terms = get_terms([
                    'taxonomy'   => $taxonomy,
                    'include'    => $post->taxonomies[ $taxonomy ],
                    'hide_empty' => false,
                ]);

                $terms = array_merge( $terms, $custom_terms );
            }

            // Add the terms
            $term_string = implode( $this->get_tag_separator(), array_map( function( $term ) {
                return $term->name;
            }, $terms ) );

            // Add the terms
            $id_string = implode( $this->get_tag_separator(), array_map( function( $term ) {
                return $term->term_id;
            }, $terms ) );

            if ( ! empty( $term_string ) ) {
                $tax[ 'taxonomy_' . $taxonomy ] = $term_string;
            }

            if ( ! empty( $id_string ) ) {
                $tax[ 'taxonomy_id_' . $taxonomy ] = $id_string;
            }

            if ( in_array( $taxonomy, $wanted_taxonomies, true ) && ! empty( $term_string ) ) {
                $search_index[] = $term_string;
            }
        }

        // Gather the additional search index
        $search_index = apply_filters( 'redipress/search_index', implode( ' ', $search_index ), $post->ID, $post );
        $search_index = apply_filters( 'redipress/search_index/' . $post->ID, $search_index, $post );
        $search_index = $this->escape_dashes( $search_index );

        // Filter the post object that will be added to the database serialized.
        $post_object = apply_filters( 'redipress/post_object', $post );

        $post_title = apply_filters( 'redipress/post_title', $post->post_title );
        $post_title = $this->escape_dashes( $post_title );

        $post_excerpt = apply_filters( 'redipress/post_excerpt', $post->post_excerpt );
        $post_excerpt = $this->escape_dashes( $post_excerpt );

        $post_content = $this->get_post_content( $post );

        $post_status = apply_filters( 'redipress/post_status', $post->post_status ?? 'publish' );

        // Get rest of the fields
        $rest = [
            'post_id'        => $post->ID,
            'post_name'      => $post->post_name,
            'post_title'     => $post_title,
            'post_author_id' => $post->post_author,
            'post_excerpt'   => $post_excerpt,
            'post_content'   => $post_content,
            'post_type'      => $post->post_type,
            'post_parent'    => $post->post_parent,
            'post_status'    => $post_status,
            'post_object'    => serialize( $post_object ),
            'permalink'      => get_permalink( $post->ID ),
            'menu_order'     => absint( $post->menu_order ),
            'search_index'   => $search_index,
        ];

        do_action( 'redipress/indexed_post', $post );

        return $this->client->convert_associative( array_merge( $args, $rest, $tax, $additions ) );
    }

    /**
     * Escape dashes from string
     *
     * @param  string $string Unescaped string.
     * @return string         Escaped $string.
     */
    public function escape_dashes( string $string ) : string {
        $string = \str_replace( '-', '\\-', $string );
        return $string;
    }

    /**
     * Function to handle retrieving post content
     *
     * @param  \WP_Post $post Post object.
     * @return string         Post content.
     */
    public function get_post_content( \WP_Post $post ) : string {
        $post_content = $post->post_content;

        switch ( $post->post_type ) { // Handle post content by post type
            case 'attachment':
                switch ( $post->post_mime_type ) { // Different content retrieval function depending on mime type
                    case 'application/pdf': // pdf
                    case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': // docx
                    case 'application/msword': // doc
                    case 'application/rtf': // rtf
                    case 'application/vnd.oasis.opendocument.text': // odt
                        $file_content = $this->get_uploaded_media_content( $post );

                        // Different content parsing depending on mime type
                        if ( ! empty( $file_content ) ) {
                            switch ( $post->post_mime_type ) {
                                case 'application/pdf': // pdf
                                    $parser       = new PdfParser();
                                    $pdf          = $parser->parseContent( $file_content );
                                    $post_content = $pdf->getText();
                                    break;
                                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': // docx
                                case 'application/msword': // doc
                                case 'application/rtf': // rtf
                                case 'application/vnd.oasis.opendocument.text': // odt
                                    $mime_type_reader = [
                                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word2007',
                                        'application/msword'                                                      => 'MsDoc',
                                        'application/rtf'                                                         => 'RTF',
                                        'application/vnd.oasis.opendocument.text'                                 => 'ODText',
                                    ];

                                    // We need to create a temporary file to read from as PhpOffice\PhpWord can't read from string
                                    $tmpfile = \wp_tempnam();
                                    \file_put_contents( $tmpfile, $file_content ); // phpcs:ignore -- We need to write to disk temporarily
                                    $phpword = IOFactory::load( $tmpfile, $mime_type_reader[ $post->post_mime_type ] );
                                    \unlink( $tmpfile ); // phpcs:ignore -- We should remove the temporary file after it has been parsed

                                    $post_content = $this->io_factory_get_text( $phpword );
                                    break;
                                default:
                                    // There already is default post content
                                    break;
                            }
                        }
                        break;
                    default:
                        // There already is default post content
                        break;
                }
                break;
            default:
                // There already is default post content
                break;
        }

        // Handle the post content
        $post_content = \wp_strip_all_tags( $post_content, true );
        $post_content = \apply_filters( 'redipress/post_content', $post_content, $post );
        $post_content = $this->escape_dashes( $post_content );

        return $post_content;
    }

    /**
     * Get text recursively from IOFactory::load result
     *
     * @param  mixed $current Current item to check for text.
     * @return string         Text content.
     */
    public function io_factory_get_text( $current ) : string {
        $post_content = '';
        if ( \method_exists( $current, 'getText' ) ) {
            $post_content .= $current->getText() . "\n";
        }
        elseif ( \method_exists( $current, 'getSections' ) ) {
            foreach ( $current->getSections() as $section ) {
                $post_content .= $this->io_factory_get_text( $section );
            }
        }
        elseif ( \method_exists( $current, 'getElements' ) ) {
            foreach ( $current->getElements() as $element ) {
                $post_content .= $this->io_factory_get_text( $element );
            }
        }

        return $post_content;
    }

    /**
     * Get the content of a file uploaded to the media gallery
     *
     * @param  \WP_Post $post Attachment post object.
     * @return string|null    File content or null if couldn't retrieve.
     */
    public function get_uploaded_media_content( \WP_Post $post ) : ?string {
        $content   = null;
        $file_path = \get_attached_file( $post->ID );

        // File doesn't exist locally (wp stateless or similiar)
        if ( ! \file_exists( $file_path ) ) {
            $args    = \apply_filters( 'redipress/get_uploaded_media_content/wp_remote_get', [] );
            $request = \wp_remote_get( $post->guid, $args );
            if ( ! \is_wp_error( $request ) ) {
                $content = \wp_remote_retrieve_body( $request );
            }
        }
        else {
            $content = \file_get_contents( $file_path );
        }

        return $content;
    }

    /**
     * Add a post to the database
     *
     * @param array      $converted_post         The post in array form.
     * @param string|int $id                     The document ID for RediSearch.
     * @param string     $language               Possible language parameter for the post.
     * @return mixed
     */
    public function add_post( array $converted_post, $id, string $language = null ) {
        $command = [ $this->index, $id, 1, 'REPLACE' ];

        if ( ! empty( $language ) && $this->is_language_supported( $language ) ) {
            $command[] = 'LANGUAGE';
            $command[] = $language;
        }

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
     * Whether a language is supported in RediSearch or not.
     *
     * @param string $language The language name.
     * @return boolean
     */
    protected function is_language_supported( string $language ) : bool {
        return in_array( $language, [
            'arabic',
            'danish',
            'dutch',
            'english',
            'finnish',
            'french',
            'german',
            'hungarian',
            'italian',
            'norwegian',
            'portuguese',
            'romanian',
            'russian',
            'spanish',
            'swedish',
            'tamil',
            'turkish',
        ], true );
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
