<?php
/**
 * RediPress index class file
 */

namespace Geniem\RediPress\Index;

use Geniem\RediPress\Settings,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Entity\NumericField,
    Geniem\RediPress\Entity\TagField,
    Geniem\RediPress\Entity\TextField,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility,
    Smalot\PdfParser\Parser as PdfParser,
    PhpOffice\PhpWord\IOFactory,
    Geniem\RediPress\Rest;

/**
 * RediPress index class
 */
class Index {

    /**
     * Which mime types are supported for parsing
     *
     * @var array An associative array with file extensions as keys and mime types as values.
     */
    const SUPPORTED_MIME_TYPES = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
        'rtf'  => 'application/rtf',
        'odt'  => 'application/vnd.oasis.opendocument.text',
    ];

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
        $settings = new Settings();
        $this->client = $client;

        // Get the index name from settings
        $this->index = $settings->get( 'index' );

        // Register AJAX functions
        Rest::register_api_call( '/create_index', [ $this, 'create' ], 'POST' );
        Rest::register_api_call( '/drop_index', [ $this, 'drop' ], 'DELETE' );
        Rest::register_api_call( '/index_all', [ $this, 'index_all' ], 'POST', [
            'limit'     => [
                'description' => 'How many items to index at a time.',
                'type'        => 'integer',
                'required'    => true,
            ],
            'offset'    => [
                'description' => 'Offset to start indexing items from',
                'type'        => 'integer',
                'required'    => false,
                'default'     => 0,
            ],
        ]);

        // Reverse filter for getting the Index instance.
        add_filter( 'redipress/index_instance', function( $value ) {
            return $this;
        }, 1, 1 );

        // Register CLI bindings
        add_action( 'redipress/cli/index_all', [ $this, 'index_all' ], 50, 0 );
        add_action( 'redipress/cli/index_missing', [ $this, 'index_missing' ], 50, 0 );
        add_action( 'redipress/cli/index_single', [ $this, 'index_single' ], 50, 1 );
        add_filter( 'redipress/create_index', [ $this, 'create' ], 50, 1 );
        add_filter( 'redipress/drop_index', [ $this, 'drop' ], 50, 1 );

        // Register external actions
        add_action( 'redipress/delete_post', [ $this, 'delete_post' ], 50, 1 );
        add_action( 'redipress/index_post', [ $this, 'upsert' ], 50, 3 );

        // Register indexing hooks
        add_action( 'save_post', [ $this, 'upsert' ], 500, 3 );
        add_action( 'delete_post', [ $this, 'delete' ], 10, 1 );

        $this->define_core_fields();
    }

    /**
     * Get total amount of posts to index
     *
     * @return int
     */
    public static function index_total() : int {
        global $wpdb;
        $ids = intval( $wpdb->get_row( "SELECT count(*) as count FROM $wpdb->posts" )->count ); // phpcs:ignore
        return $ids;
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
            new NumericField([
                'name'     => 'menu_order',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'post_status',
                'sortable' => true,
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

        if ( \is_multisite() ) {
            \array_unshift(
                $this->core_schema_fields,
                new TextField([
                    'name'     => 'blog_id',
                    'sortable' => true,
                ])
            );
        }

        // Add taxonomies to core fields
        $taxonomies = get_taxonomies();

        foreach ( $taxonomies as $taxonomy ) {
            $this->core_schema_fields[] = new TagField([
                'name'      => 'taxonomy_' . $taxonomy,
                'separator' => self::get_tag_separator(),
            ]);

            $this->core_schema_fields[] = new TagField([
                'name'      => 'taxonomy_id_' . $taxonomy,
                'separator' => self::get_tag_separator(),
            ]);

            $this->core_schema_fields[] = new TagField([
                'name'      => 'taxonomy_slug_' . $taxonomy,
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
     * @param  \WP_REST_Request|null $request Rest request details or null if not rest api request.
     * @return int                            Amount of items indexed.
     */
    public function index_all( \WP_REST_Request $request = null ) : int {
        global $wpdb;

        \do_action( 'redipress/before_index_all', $request );

        // phpcs:disable
        if ( $request instanceof \WP_REST_Request ) {
            $limit  = $request->get_param( 'limit' );
            $offset = $request->get_param( 'offset' );
            $query  = $wpdb->prepare( "SELECT ID FROM $wpdb->posts LIMIT %d OFFSET %d", $limit, $offset );
        }
        else {
            $query  = "SELECT ID FROM $wpdb->posts";
        }
        $ids = $wpdb->get_results( $query ) ?? [];
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

            $this->add_post( $converted, self::get_document_id( $post ), $language );

            if ( ! empty( $progress ) ) {
                $progress->tick();
            }
        }, $posts );

        \do_action( 'redipress/indexed_all', $result, $request );

        $this->maybe_write_to_disk( 'indexed_all' );

        if ( ! empty( $progress ) ) {
            $progress->finish();
        }

        return $count;
    }

    /**
     * Get a RediPress document ID for a post.
     *
     * @param \WP_Post $post    The post to deal with.
     * @param mixed    $post_id The current doc id set for the post.
     * @return string
     */
    public static function get_document_id( \WP_Post $post, $post_id = null ) : string {
        if ( $post_id && (string) $post->ID !== (string) $post_id ) {
            $id = $post_id;
        }
        else {
            $id = $post->ID;
        }
        if ( ! \is_multisite() ) {
            return $id;
        }
        else {
            return ( $post->blog_id ?? \get_current_blog_id() ) . '_' . $id;
        }
    }

    /**
     * Index all missing posts to the RediSearch database
     *
     * @param  \WP_REST_Request|null $request Rest request details or null if not rest api request.
     * @return int                            Amount of items indexed.
     */
    public function index_missing( \WP_REST_Request $request = null ) : int {
        global $wpdb;

        \do_action( 'redipress/before_index_missing', $request );

        // phpcs:disable
        if ( $request instanceof \WP_REST_Request ) {
            $limit  = $request->get_param( 'limit' );
            $offset = $request->get_param( 'offset' );
            $query  = $wpdb->prepare( "SELECT ID FROM $wpdb->posts LIMIT %d OFFSET %d", $limit, $offset );
        }
        else {
            $query  = "SELECT ID FROM $wpdb->posts";
        }
        $ids = $wpdb->get_results( $query ) ?? [];
        // phpcs:enable

        $count = count( $ids );

        $posts = array_filter( $ids, function( $row ) {
            $present = \Geniem\RediPress\get_post( $row->ID );

            return empty( $present );
        });

        $posts = array_map( function( $id ) {
            return \get_post( $id );
        }, $posts );

        $custom_posts = [];

        $custom_posts = apply_filters( 'redipress/custom_posts', $custom_posts );

        $count += count( $custom_posts );

        $custom_posts = array_filter( $custom_posts, function( $row ) {
            $present = \Geniem\RediPress\get_post( $row->ID );

            return empty( $present );
        });

        $posts = array_merge( $posts, $custom_posts );

        $new_count = count( $posts );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::success( 'Starting to index a total of ' . $new_count . ' posts. Skipped already existing ' . ( $count - $new_count ) . ' posts.' );

            $progress = \WP_CLI\Utils\make_progress_bar( __( 'Indexing posts', 'redipress' ), $new_count );
        }
        else {
            $progress = null;
        }

        $result = array_map( function( $post ) use ( $progress ) {
            $language = apply_filters( 'redipress/post_language', $post->lang ?? null, $post );
            $language = apply_filters( 'redipress/post_language/' . $post->ID, $language, $post );

            $converted = $this->convert_post( $post );

            $this->add_post( $converted, self::get_document_id( $post ), $language );

            if ( ! empty( $progress ) ) {
                $progress->tick();
            }
        }, $posts );

        \do_action( 'redipress/indexed_missing', $result, $request );

        $this->maybe_write_to_disk( 'indexed_missing' );

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

        return $this->add_post( $converted, self::get_document_id( $post ) );
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

        $result = $this->add_post( $converted, self::get_document_id( $post, $post_id ) );

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
        $post = \get_post( $post_id );

        if ( $post ) {
            $post_id = self::get_document_id( $post );
        }

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
        $settings = new Settings();

        \do_action( 'redipress/before_index_post', $post );

        $args         = [];
        $search_index = [];

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

        $search_index[] = $args['post_author'];

        // Get the post date
        $args['post_date'] = strtotime( $post->post_date ) ?: null;

        if ( empty( $this->index_info ) ) {
            // Get the RediSearch schema for possible additional fields
            $schema = $this->client->raw_command( 'FT.INFO', [ $this->index ] );

            $this->index_info = Utility::format( $schema );
        }

        $fields = Utility::get_schema_fields( $this->index_info['fields'] ?? [] );

        // Gather field names from hardcoded field for later.
        $core_field_names = array_map( [ $this, 'return_field_name' ], $this->core_schema_fields );

        $additional_fields = array_diff( $fields, $core_field_names );

        $additional_values = array_map( function( $field ) use ( $post ) {
            $value = apply_filters( 'redipress/additional_field/' . $post->ID . '/' . $field, null, $post );
            $value = apply_filters( 'redipress/additional_field/' . $field, $value, $post->ID, $post );

            $type = $this->get_field_type( $field );

            if ( $type === 'TAG' && is_array( $value ) ) {
                $value = implode( self::get_tag_separator(), $value );
            }

            // RediSearch doesn't accept boolean values
            if ( is_bool( $value ) ) {
                $value = (int) $value;
            }

            // Escape dashes in all but numeric fields
            if ( $type !== 'NUMERIC' ) {
                $value = $this->escape_dashes( $value );
            }

            return $value;
        }, $additional_fields );

        $additions = array_combine( $additional_fields, $additional_values );

        $additions = array_filter( $additions, function( $item ) {
            return ! is_null( $item );
        });

        $additions = array_map( 'maybe_serialize', $additions );

        $tax = [];

        // Handle the taxonomies
        $taxonomies = get_taxonomies();

        $wanted_taxonomies = $settings->get( 'taxonomies' ) ?: [];

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
            $term_string = implode( self::get_tag_separator(), array_map( function( $term ) {
                return $term->name;
            }, $terms ) );

            // Add the terms
            $search_term_string = implode( ' ', array_map( function( $term ) {
                return $term->name;
            }, $terms ) );

            // Add the terms
            $id_string = implode( self::get_tag_separator(), array_map( function( $term ) {
                return $term->term_id;
            }, $terms ) );

            // Add the terms
            $slug_string = implode( $this->get_tag_separator(), array_map( function( $term ) {
                return $term->slug;
            }, $terms ) );

            if ( ! empty( $term_string ) ) {
                $tax[ 'taxonomy_' . $taxonomy ] = $term_string;
            }

            if ( ! empty( $id_string ) ) {
                $tax[ 'taxonomy_id_' . $taxonomy ] = $id_string;
            }

            if ( ! empty( $slug_string ) ) {
                $tax[ 'taxonomy_slug_' . $taxonomy ] = $slug_string;
            }

            if ( in_array( $taxonomy, $wanted_taxonomies, true ) && ! empty( $term_string ) ) {
                $search_index[] = $search_term_string;
            }
        }

        // Gather the additional search index
        $search_index = apply_filters( 'redipress/search_index', implode( ' ', $search_index ), $post->ID, $post );
        $search_index = apply_filters( 'redipress/search_index/' . $post->ID, $search_index, $post );
        $search_index = apply_filters( 'redipress/index_strings', $search_index, $post );
        $search_index = $this->escape_dashes( $search_index );

        // Filter the post object that will be added to the database serialized.
        $post_object = apply_filters( 'redipress/post_object', $post );

        $post_title = apply_filters( 'redipress/post_title', $post->post_title );
        $post_title = apply_filters( 'redipress/index_strings', $post_title, $post );
        $post_title = $this->escape_dashes( $post_title );

        $post_excerpt = apply_filters( 'redipress/post_excerpt', $post->post_excerpt );
        $post_excerpt = apply_filters( 'redipress/index_strings', $post_excerpt, $post );
        $post_excerpt = $this->escape_dashes( $post_excerpt );

        $post_content = $this->get_post_content( $post );

        $post_status = apply_filters( 'redipress/post_status', $post->post_status ?? 'publish' );

        // Get rest of the fields
        $rest = [
            'post_id'        => $post->ID,
            'post_name'      => $this->escape_dashes( $post->post_name ),
            'post_title'     => $post_title,
            'post_author_id' => $post->post_author,
            'post_excerpt'   => $post_excerpt,
            'post_content'   => $post_content,
            'post_type'      => $this->escape_dashes( $post->post_type ),
            'post_parent'    => $post->post_parent,
            'post_status'    => $post_status,
            'post_object'    => serialize( $post_object ),
            'permalink'      => get_permalink( $post->ID ),
            'menu_order'     => absint( $post->menu_order ),
            'search_index'   => $search_index,
        ];

        if ( \is_multisite() ) {
            $rest['blog_id'] = $post->blog_id ?? \get_current_blog_id();
        }

        do_action( 'redipress/indexed_post', $post );

        return $this->client->convert_associative( array_merge( $args, $rest, $tax, $additions ) );
    }

    /**
     * Escape dashes from string
     *
     * @param  string $string Unescaped string.
     * @return string         Escaped $string.
     */
    public function escape_dashes( ?string $string = '' ) : string {
        if ( ! $string ) {
            $string = '';
        }

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
                // Check if mime type is supported
                if ( \in_array( $post->post_mime_type, static::SUPPORTED_MIME_TYPES, true ) ) {
                    $settings = new Settings();

                    // Check if mime type is enabled @TODO: throws a warning
                    $enabled_mime_types = $settings->get( 'mime_types' ) ?: \array_values( static::SUPPORTED_MIME_TYPES );
                    if ( \in_array( $post->post_mime_type, $enabled_mime_types, true ) ) {

                        // Get file content
                        $file_content = $this->get_uploaded_media_content( $post );

                        // Different content parsing depending on mime type
                        if ( ! empty( $file_content ) ) {
                            switch ( $post->post_mime_type ) {
                                case static::SUPPORTED_MIME_TYPES['pdf']:
                                    try {
                                        $parser       = new PdfParser();
                                        $pdf          = $parser->parseContent( $file_content );
                                        $post_content = $pdf->getText();
                                    }
                                    catch( \Exception $e ) {
                                        error_log( 'RediPress PDF indexing error: ' . $e->getMessage() );
                                    }
                                    break;
                                case static::SUPPORTED_MIME_TYPES['docx']:
                                case static::SUPPORTED_MIME_TYPES['doc']:
                                case static::SUPPORTED_MIME_TYPES['rtf']:
                                case static::SUPPORTED_MIME_TYPES['odt']:
                                    $mime_type_reader = [
                                        static::SUPPORTED_MIME_TYPES['docx'] => 'Word2007',
                                        static::SUPPORTED_MIME_TYPES['doc']  => 'MsDoc',
                                        static::SUPPORTED_MIME_TYPES['rtf']  => 'RTF',
                                        static::SUPPORTED_MIME_TYPES['odt']  => 'ODText',
                                    ];

                                    try {
                                        // We need to create a temporary file to read from as PhpOffice\PhpWord can't read from string
                                        $tmpfile = \wp_tempnam();
                                        \file_put_contents( $tmpfile, $file_content ); // phpcs:ignore -- We need to write to disk temporarily
                                        $phpword = IOFactory::load( $tmpfile, $mime_type_reader[ $post->post_mime_type ] );
                                        \unlink( $tmpfile ); // phpcs:ignore -- We should remove the temporary file after it has been parsed

                                        $post_content = $this->io_factory_get_text( $phpword );
                                    }
                                    catch( \Exception $e ) {
                                        error_log( 'RediPress Office indexing error: ' . $e->getMessage() );
                                    }
                                    break;
                                default:
                                    // There already is default post content
                                    break;
                            }
                        }
                    }
                }
                break;
            default:
                // There already is default post content
                break;
        }

        // Handle the post content
        $post_content = \wp_strip_all_tags( $post_content, true );
        $post_content = \apply_filters( 'redipress/post_content', $post_content, $post );
        $post_content = apply_filters( 'redipress/index_strings', $post_content, $post );
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
     * Delete document items by field name and value.
     *
     * @param string $field_name The RediPress index field name.
     * @param        $value      The value to look for by the name.
     *
     * @return int The number of items deleted.
     */
    public function delete_by_field( string $field_name, $value ) : int {
        // TODO: handle numeric fields.
        $return = $this->client->raw_command( 'FT.SEARCH', [ $this->index, '@' . $field_name .':(' . $value . ')' ] );

        $return = Utility::format( $return );

        if ( empty( $return ) ) {
            return 0;
        }

        foreach ( $return as $doc_id => $values ) {
            $this->delete_post( $doc_id );
        }

        return count( $return );
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

        // Allow overriding the setting via a filter
        $filter_writing = apply_filters( 'redipress/write_to_disk', null, $args );

        if ( $filter_writing ?? $settings->get( 'persist_index' ) ) {
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
    public static function get_tag_separator() : string {
        return apply_filters( 'redipress/tag_separator', self::TAG_SEPARATOR );
    }

    /**
     * Get RediSearch field type for a field
     *
     * @param string $key The key for which to fetch the field type.
     * @return string|null
     */
    protected function get_field_type( string $key ) : ?string {
        $fields = Utility::format( $this->index_info['fields'] );

        $field_type = array_reduce( $fields, function( $carry = null, $item = null ) use ( $key ) {
            if ( ! empty( $carry ) ) {
                return $carry;
            }

            $name = $item[0];

            if ( $name === $key ) {
                return Utility::get_value( $item, 'type' );
            }

            return null;
        });

        return $field_type;
    }
}
