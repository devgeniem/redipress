<?php
/**
 * RediPress post index class file
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
    Geniem\RediPress\Rest,
    WP_CLI\Utils;

/**
 * RediPress index class
 */
class PostIndex {

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
     * References for hooks used in this class for easier usage
     */
    const HOOKS = [
        'schedule_partial_index'       => 'redipress/cron/schedule_partial_index',
        'schedule_partial_index_limit' => 'redipress/cron/schedule_partial_index/limit',
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
     * ID of the post we are indexing.
     *
     * @var integer|null
     */
    protected static $indexing = null;

    /**
     * Construct the index object
     *
     * @param Client $client Client instance.
     */
    public function __construct( Client $client ) {
        $settings     = new Settings();
        $this->client = $client;

        // Get the index name from settings
        $this->index = $settings->get( 'index' );

        // Register AJAX functions
        Rest::register_api_call( '/create_index', [ $this, 'create' ], 'POST' );
        Rest::register_api_call( '/drop_index', [ $this, 'drop' ], 'DELETE' );
        Rest::register_api_call( '/schedule_index_all', [ $this, 'schedule_partial_index' ], 'POST', [
            'offset'    => [
                'description' => 'Offset to start indexing items from',
                'type'        => 'integer',
                'required'    => false,
                'default'     => 0,
            ],
        ]);

        // Register event hook for partial indexing
        \add_action( static::HOOKS['schedule_partial_index'], [ $this, 'schedule_partial_index' ], 50, 1 );

        // Reverse filter for getting the Index instance.
        add_filter( 'redipress/index_instance', function( $value ) {
            return $this;
        }, 1, 1 );

        // Register CLI bindings
        add_filter( 'redipress/cli/index_all', [ $this, 'index_all' ], 50, 2 );
        add_filter( 'redipress/cli/index_missing', [ $this, 'index_missing' ], 50, 2 );
        add_action( 'redipress/cli/index_single', [ $this, 'index_single' ], 50, 1 );
        add_filter( 'redipress/create_index', [ $this, 'create' ], 50, 1 );
        add_filter( 'redipress/drop_post_index', [ $this, 'drop' ], 50, 2 );

        // Register external actions
        add_action( 'redipress/delete_post', [ $this, 'delete_post' ], 50, 1 );
        add_action( 'redipress/index_post', [ $this, 'upsert' ], 50, 3 );

        // Register indexing hooks
        add_action( 'save_post', [ $this, 'upsert' ], 500, 3 );
        add_action( 'delete_post', [ $this, 'delete' ], 10, 1 );

        // Register taxonomy actions
        add_action( 'set_object_terms', [ $this, 'index_single' ], 50, 1 );

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
     * @param boolean $delete_data Whether to delete data in addition to the index or not.
     * @return mixed
     */
    public function drop( bool $delete_data = false ) {
        $args = $delete_data ? [ 'DD' ] : [];

        return $this->client->raw_command( 'FT.DROPINDEX', [ $this->index, ...$args ] );
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
            new TextField([
                'name' => 'post_author_id',
                'sortable' => true,
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
                'name'     => 'post_mime_type',
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
            $taxonomy = str_replace( '-', '_', $taxonomy );

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
                'separator' => self::get_tag_separator(),
            ]);
        }
    }

    /**
     * Get the schema fields
     *
     * @return array
     */
    public function get_schema_fields() : array {
        // Filter to add possible more fields.
        $schema_fields = apply_filters( 'redipress/schema_fields', $this->core_schema_fields );

        // Remove possible duplicate fields
        $schema_fields = array_unique( $schema_fields );

        $raw_schema = array_reduce(
            $schema_fields,
            // Convert SchemaField objects into raw arrays
            fn( ?array $c, SchemaField $field ) : array => array_merge( $c, $field->get() ),
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
     * Create a RediSearch index.
     *
     * @return mixed
     */
    public function create() {
        [ $options, $schema_fields, $raw_schema ] = $this->get_schema_fields();

        $return = $this->client->raw_command( 'FT.CREATE', array_merge( [ $this->index ], $options, $raw_schema ) );

        do_action( 'redipress/schema_created', $return, $options, $schema_fields, $raw_schema );

        $this->maybe_write_to_disk( 'schema_created' );

        return $return;
    }

    /**
     * Create a wp cron event chain to index all posts.
     *
     * @param  int|WP_Rest_Request $offset Int for offset or WP_Rest_Request on first run.
     * @return true|false|WP_Error         Result of next wp_schedule_single_event call or true on final run.
     */
    public function schedule_partial_index( $args = null ) {

        // If this was created by a user via the admin just schedule without running the actual index
        if ( $args instanceof \WP_REST_Request ) {

            // Make sure we don't create new cron jobs if one is already running
            $cron = \get_option( 'cron' );
            foreach ( $cron as $timestamp => $events ) {
                foreach ( $events as $hook => $args ) {
                    if ( $hook === static::HOOKS['schedule_partial_index'] ) {
                        return true;
                    }
                }
            }

            $offset = $args->get_param( 'offset' ) ?? 0;
            return \wp_schedule_single_event( time(), static::HOOKS['schedule_partial_index'], [ $offset ], true );
        }

        // Run index
        $offset = \is_int( $args ) ? $args : 0;
        $count  = $this->index_all([
            'limit'  => \apply_filters( static::HOOKS['schedule_partial_index_limit'], 400 ),
            'offset' => $offset,
        ]);

        // Schedule next run with new offset or if no posts left return true
        if ( $count ) {
            return \wp_schedule_single_event( time(), static::HOOKS['schedule_partial_index'], [ $offset + $count ], true );
        }
        else {
            return true;
        }
    }

    /**
     * Index all or a part of posts to the RediSearch database
     *
     * @param  array|null $args Array containing Limit & offset details or null if not doing a partial index.
     * @return int              Amount of items indexed.
     */
    public function index_all( array $args = null, array $query_args = [] ) : int {
        global $wpdb;

        define( 'WP_IMPORTING', true );

        \do_action( 'redipress/before_index_all', $request ?? null );
        // phpcs:disable
        if ( ! empty( $args['limit'] ) && ! empty( $args['offset'] ) ) {
            $query  = $wpdb->prepare( "SELECT ID FROM $wpdb->posts LIMIT %d OFFSET %d", $args['limit'], $args['offset'] );
        }
        else {
            if ( ! empty( $query_args ) ) {
                $wheres = [];
                $params = [];

                foreach ( $query_args as $key => $value ) {
                    $wheres[] = esc_sql( $key ) . ' = %s';
                    $params[] = $value;
                }

                $where = ' WHERE ' . implode( ' AND ', $wheres );
            }
            else {
                $where  = '';
                $params = [];
            }

            $query  = "SELECT ID FROM $wpdb->posts$where";
        }
        $q = $wpdb->prepare( $query, ...$params );

        $ids = $wpdb->get_results( $q );
        // phpcs:enable

        if ( empty( $ids ) ) {
            \WP_CLI::error( 'No posts matching the criteria were found.' );
            return 0;
        }

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
            self::$indexing = $post->ID;

            $language = apply_filters( 'redipress/post_language', $post->lang ?? null, $post );
            $language = apply_filters( 'redipress/post_language/' . $post->ID, $language, $post );

            // Sanity check.
            if ( ! $post instanceof \WP_Post ) {
                $progress->tick();
                return;
            }

            $converted = $this->convert_post( $post );

            $return = $this->add_post( $converted, self::get_document_id( $post ), $language );

            if ( ! empty( $progress ) ) {
                $progress->tick();
            }

            $this->free_memory();

            self::$indexing = null;

            return $return;
        }, $posts );

        \do_action( 'redipress/indexed_all', $result, $request ?? null );

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
        elseif ( ! empty( $post->doc_id ) ) {
            $id = $post->doc_id;
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
    public function index_missing( \WP_REST_Request $request = null, array $query_args = [] ) : int {
        global $wpdb;

        \do_action( 'redipress/before_index_all', $request );
        \do_action( 'redipress/before_index_missing', $request );

        \WP_CLI::success( 'Checking for already existing posts in the database...' );

        // phpcs:disable
        if ( $request instanceof \WP_REST_Request ) {
            $limit  = $request->get_param( 'limit' );
            $offset = $request->get_param( 'offset' );
            $query  = $wpdb->prepare( "SELECT ID FROM $wpdb->posts LIMIT %d OFFSET %d", $limit, $offset );
        }
        else {
            if ( ! empty( $query_args ) ) {
                $where = ' WHERE ';

                foreach ( $query_args as $key => $value ) {
                    $where .= $key . ' = "' . $value .'" ';
                }
            }
            else {
                $where = '';
            }

            $query  = "SELECT ID FROM $wpdb->posts$where";
        }
        $ids = $wpdb->get_results( $query ) ?? [];
        // phpcs:enable

        $count = count( $ids );

        $progress = \WP_CLI\Utils\make_progress_bar( __( 'Checking existing posts', 'redipress' ), $count );

        $posts = array_filter( $ids, function( $row ) use ( $progress ) {
            $present = \Geniem\RediPress\get_post( $row->ID );

            $progress->tick();

            return empty( $present );
        });

        $progress->finish();

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
            self::$indexing = $post->ID;

            $language = apply_filters( 'redipress/post_language', $post->lang ?? null, $post );
            $language = apply_filters( 'redipress/post_language/' . $post->ID, $language, $post );

            // Sanity check.
            if ( ! $post instanceof \WP_Post ) {
                $progress->tick();
                return;
            }

            $converted = $this->convert_post( $post );

            $return = $this->add_post( $converted, self::get_document_id( $post ), $language );

            if ( ! empty( $progress ) ) {
                $progress->tick();
            }

            $this->free_memory();

            self::$indexing = null;

            return $return;
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
     * @param integer $post_id  The post ID to index.
     * @return mixed
     */
    public function index_single( int $post_id ) {
        $post = get_post( $post_id );

        // Bail early if not found
        if ( ! $post ) {
            return;
        }

        self::$indexing = $post->ID;

        \do_action( 'redipress/before_index_post', $post );

        $converted = $this->convert_post( $post );

        $return = $this->add_post( $converted, self::get_document_id( $post ) );

        self::$indexing = null;

        return $return;
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

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        self::$indexing = $post->ID;

        \do_action( 'redipress/before_index_post', $post );

        $converted = $this->convert_post( $post );

        $result = $this->add_post( $converted, self::get_document_id( $post, $post_id ) );

        do_action( 'redipress/new_post_added', $result, $post );

        $this->maybe_write_to_disk( 'new_post_added' );

        self::$indexing = null;

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

        if ( ! $settings->get( 'disable_post_author_search' ) ) {
            $search_index[] = $args['post_author'];
        }

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
            $value = self::get( $post->ID, $field );

            $value = apply_filters( 'redipress/additional_field/' . $post->ID . '/' . $field, $value, $post );
            $value = apply_filters( 'redipress/additional_field/' . $field, $value, $post->ID, $post );

            $type = $this->get_field_type( $field );

            if ( $type === 'TAG' && is_array( $value ) ) {
                $value = implode( self::get_tag_separator(), $value );
            }

            // RediSearch doesn't accept boolean values
            if ( is_bool( $value ) ) {
                $value = (int) $value;
            }

            // Escape the string in all but numeric and tag fields
            if ( ! in_array( $type, [ 'NUMERIC', 'TAG' ] ) ) {
                $value = $this->escape_string( $value );
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
        if ( post_type_exists( $post->post_type ) ) {
            $taxonomies = get_object_taxonomies( $post->post_type );
        }
        else {
            $taxonomies = get_taxonomies();
        }

        $taxonomies = apply_filters( 'redipress/taxonomies', $taxonomies, $post->post_type, $post );

        $wanted_taxonomies = $settings->get( 'taxonomies' ) ?: [];

        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_the_terms( $post->ID, $taxonomy ) ?: [];

            if ( ! empty( $post->taxonomies[ $taxonomy ] ) ) {
                $custom_terms = get_terms([
                    'taxonomy'               => $taxonomy,
                    'include'                => $post->taxonomies[ $taxonomy ],
                    'hide_empty'             => false,
                    'update_term_meta_cache' => false,
                ]);

                $terms = array_merge( $terms, $custom_terms );
            }

            // Add the terms
            $term_string = implode( self::get_tag_separator(), array_column( $terms, 'name' ) );

            // Add the terms
            $search_term_string = implode( ' ', array_column( $terms, 'name' ) );

            // Add the terms
            $id_string = implode( self::get_tag_separator(), array_column( $terms, 'term_id' ) );

            // Add the terms
            $slug_string = implode( self::get_tag_separator(), array_column( $terms, 'slug' ) );

            $tax[ 'taxonomy_' . $taxonomy ] = $term_string ?? '';

            $tax[ 'taxonomy_id_' . $taxonomy ] = $id_string ?? '';

            $tax[ 'taxonomy_slug_' . $taxonomy ] = $slug_string ?? '';

            if ( in_array( $taxonomy, $wanted_taxonomies, true ) && ! empty( $term_string ) ) {
                $search_index[] = $search_term_string;
            }
        }

        // Change dashes from taxonomy slugs to underscores
        foreach ( $tax as $key => $value ) {
            if ( strpos( $key, '-' ) !== false ) {
                $new_key = str_replace( '-', '_', $key );

                $tax[ $new_key ] = $value;

                unset( $tax[ $key ] );
            }
        }

        // Gather the additional search index
        $search_index = array_merge( $search_index, (array) self::get( $post->ID, 'search_index' ) );
        $search_index = apply_filters( 'redipress/search_index', implode( ' ', $search_index ), $post->ID, $post );
        $search_index = apply_filters( 'redipress/search_index/' . $post->ID, $search_index, $post );

        $search_index = apply_filters( 'redipress/index_strings', $search_index, $post );
        $search_index = trim( $this->escape_string( $search_index ) );

        // Filter the post object that will be added to the database serialized.
        $post_object = apply_filters( 'redipress/post_object', $post );

        $post_title = apply_filters( 'redipress/post_title', $post->post_title );
        $post_title = apply_filters( 'redipress/index_strings', $post_title, $post );
        $post_title = $this->escape_string( $post_title );

        $post_excerpt = apply_filters( 'redipress/post_excerpt', $post->post_excerpt );
        $post_excerpt = apply_filters( 'redipress/index_strings', $post_excerpt, $post );
        $post_excerpt = $this->escape_string( $post_excerpt );

        $post_content = $this->get_post_content( $post );

        $post_status = apply_filters( 'redipress/post_status', $post->post_status ?? 'publish' );

        // Get rest of the fields
        $rest = [
            'post_id'        => $post->ID,
            'post_name'      => $this->escape_string( $post->post_name ),
            'post_title'     => $post_title,
            'post_author_id' => $post->post_author,
            'post_excerpt'   => $post_excerpt,
            'post_content'   => $post_content,
            'post_type'      => $this->escape_string( $post->post_type ),
            'post_parent'    => $post->post_parent,
            'post_mime_type' => $post->post_mime_type,
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
    public function escape_string( ?string $string = '' ) : string {
        return Utility::escape_string( $string );
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
                                    if ( ! $settings->get( 'disable_pdf_indexing' ) ) {
                                        try {
                                            $parser       = new PdfParser();
                                            $pdf          = $parser->parseContent( $file_content );
                                            $post_content = $pdf->getText();
                                        }
                                        catch( \Exception $e ) {
                                            error_log( 'RediPress PDF indexing error: ' . $e->getMessage() );
                                        }
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
        $post_content = $this->strip_tags_except_comments( $post_content );
        $post_content = \apply_filters( 'redipress/post_content', $post_content, $post );
        $post_content = apply_filters( 'redipress/index_strings', $post_content, $post );
        $post_content = $this->escape_string( $post_content );

        // Replace unwanted characters with space to keep spaces for example after a line break.
        $post_content = str_replace( '\t', ' ', $post_content );
        $post_content = str_replace( '\n', ' ', $post_content );
        $post_content = str_replace( '\r', ' ', $post_content );

        // Replace multiple whitespaces with a single space
        $post_content = preg_replace('/\s+/S', ' ', $post_content);

        return $post_content;
    }

    /**
     * Strip tags but save HTML comments.
     *
     * @param null|string $content The content to strip.
     * @return string
     */
    private function strip_tags_except_comments( ?string $content ) : string {
        if ( ! $content ) {
            return '';
        }

        $content = str_replace( '<!--', '=THEREISACOMMENTSTARTINGHERE=', $content );
        $content = str_replace( '-->', '=THEREISACOMMENTENDINGHERE=', $content );

        $content = \wp_strip_all_tags( $content, true );

        $content = str_replace( '=THEREISACOMMENTSTARTINGHERE=', '<!--', $content );
        $content = str_replace( '=THEREISACOMMENTENDINGHERE=', '-->', $content );

        return $content;
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
        $command = [ $this->index . ':' . $id ];

        $raw_command = array_merge( $command, $converted_post );

        $return = $this->client->raw_command( 'HSET', $raw_command );

        return $return;
    }

    /**
     * Delete a post from the database
     *
     * @param string|int $id The document ID for RediSearch.
     * @return mixed
     */
    public function delete_post( $id ) {
        $return = $this->client->raw_command( 'HDEL', [ $this->index . ':' . $id ] );

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
        $return = $this->client->raw_command( 'FT.SEARCH', [ $this->index, '@' . $field_name . ':(' . $value . ')' ] );

        // Nothing found.
        if ( empty( $return ) || (string) $return[0] === '0' ) {
            return 0;
        }

        $return = Utility::format( $return );

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
     * Store additional data for indexing from outside
     *
     * @param mixed  $post_id The post ID.
     * @param string $field   The field name.
     * @param mixed  $data    The data.
     * @param string $method  The method to use with multiple values. Defaults to "use_last". Possibilites: use_last, concat, concat_with_spaces, array_merge, sum, custom (needs filter).
     * @return void
     */
    public static function store( $post_id, string $field, $data, string $method = 'use_last' ) : void {
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

    /**
     * Returns the ID of the post that we are currently indexing.
     *
     * @return integer|null
     */
    public static function indexing() : ?int {
        return self::$indexing;
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

    /**
     * Free memory by emptying WordPress native caches
     *
     * @source https://10up.github.io/Engineering-Best-Practices/migrations/
     *
     * @return void
     */
    protected function free_memory() {
        if ( function_exists( 'Utils\\wp_clear_object_cache' ) ) {
            Utils\wp_clear_object_cache();
        }
    }
}
