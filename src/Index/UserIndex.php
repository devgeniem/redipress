<?php
/**
 * RediPress user index class file
 */

namespace Geniem\RediPress\Index;

use Geniem\RediPress\Settings,
    Geniem\RediPress\Entity\SchemaField,
    Geniem\RediPress\Entity\NumericField,
    Geniem\RediPress\Entity\TagField,
    Geniem\RediPress\Entity\TextField,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility,
    Geniem\RediPress\Rest;

/**
 * RediPress user index class
 */
class UserIndex {

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
        $settings     = new Settings();
        $this->client = $client;

        // Get the index name from settings
        $this->index = $settings->get( 'user_index' );

        // Register AJAX functions
        Rest::register_api_call( '/create_user_index', [ $this, 'create' ], 'POST' );
        Rest::register_api_call( '/drop_user_index', [ $this, 'drop' ], 'DELETE' );
        Rest::register_api_call( '/index_all_users', [ $this, 'index_all' ], 'POST', [
            'limit'  => [
                'description' => 'How many users to index at a time.',
                'type'        => 'integer',
                'required'    => true,
            ],
            'offset' => [
                'description' => 'Offset to start indexing users from',
                'type'        => 'integer',
                'required'    => false,
                'default'     => 0,
            ],
        ]);

        // Register CLI bindings
        add_action( 'redipress/cli/index_all_users', [ $this, 'index_all' ], 50, 0 );
        add_action( 'redipress/cli/index_missing_users', [ $this, 'index_missing' ], 50, 0 );
        add_action( 'redipress/cli/index_single_user', [ $this, 'index_single' ], 50, 1 );
        add_filter( 'redipress/create_user_index', [ $this, 'create' ], 50, 1 );
        add_filter( 'redipress/drop_user_index', [ $this, 'drop' ], 50, 1 );

        // Register external actions
        add_action( 'redipress/delete_user', [ $this, 'delete_user' ], 50, 1 );
        add_action( 'redipress/index_user', [ $this, 'upsert' ], 50, 3 );

        // Register indexing hooks
        add_action( 'profile_update', [ $this, 'upsert' ], 10, 1 );
        add_action( 'user_register', [ $this, 'upsert' ], 10, 1 );
        add_action( 'deleted_user', [ $this, 'delete' ], 10, 1 );

        $this->define_core_fields();
    }

    /**
     * Get total amount of posts to index
     *
     * @return int
     */
    public static function index_total() : int {
        global $wpdb;
        $ids = intval( $wpdb->get_row( "SELECT count(*) as count FROM $wpdb->users" )->count ); // phpcs:ignore
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
                'name'     => 'user_id',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'user_login',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'user_nicename',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'user_email',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'user_url',
                'sortable' => true,
            ]),
            new NumericField([
                'name'     => 'user_registered',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'display_name',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'nickname',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'first_name',
                'sortable' => true,
            ]),
            new TextField([
                'name'     => 'last_name',
                'sortable' => true,
            ]),
            new TagField([
                'name'      => 'roles',
                'separator' => self::get_tag_separator(),
            ]),
            new TextField([
                'name' => 'search_index',
            ]),
        ];

        if ( \is_multisite() ) {
            $this->core_schema_fields[] = new TagField([
                'name'      => 'blogs',
                'separator' => self::get_tag_separator(),
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
        $schema_fields = apply_filters( 'redipress/user_schema_fields', $this->core_schema_fields );

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

        $raw_schema = apply_filters( 'redipress/user_raw_schema', array_merge( [ $this->index, 'SCHEMA' ], $raw_schema ) );

        $return = $this->client->raw_command( 'FT.CREATE', $raw_schema );

        do_action( 'redipress/user_schema_created', $return, $schema_fields, $raw_schema );

        $this->maybe_write_to_disk( 'user_schema_created' );

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

        \do_action( 'redipress/before_index_all_users', $request );

        // phpcs:disable
        if ( $request instanceof \WP_REST_Request ) {
            $limit  = $request->get_param( 'limit' );
            $offset = $request->get_param( 'offset' );
            $query  = $wpdb->prepare( "SELECT ID FROM $wpdb->users LIMIT %d OFFSET %d", $limit, $offset );
        }
        else {
            $query  = "SELECT ID FROM $wpdb->users";
        }
        $ids = $wpdb->get_results( $query ) ?? [];
        // phpcs:enable

        $count = count( $ids );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::success( 'Starting to index a total of ' . $count . ' users.' );

            $progress = \WP_CLI\Utils\make_progress_bar( __( 'Indexing users', 'redipress' ), $count );
        }
        else {
            $progress = null;
        }

        $users = array_map( function( $row ) {
            return get_user_by( 'id', $row->ID );
        }, $ids );

        $users = apply_filters( 'redipress/custom_users', $users );

        $result = array_map( function( $user ) use ( $progress ) {
            $converted = $this->convert_user( $user );

            $this->add_user( $converted, self::get_document_id( $user ) );

            if ( ! empty( $progress ) ) {
                $progress->tick();
            }
        }, $users );

        \do_action( 'redipress/indexed_all_users', $result, $request );

        $this->maybe_write_to_disk( 'indexed_all_users' );

        if ( ! empty( $progress ) ) {
            $progress->finish();
        }

        return $count;
    }

    /**
     * Get a RediPress document ID for a user.
     *
     * @param \WP_User $user The user to deal with.
     * @return string
     */
    public static function get_document_id( \WP_User $user ) : string {
        return (string) $user->ID;
    }

    /**
     * Index a single user by its ID.
     *
     * @param integer $user_id The user ID to index.
     * @return mixed
     */
    public function index_single( int $user_id ) {
        $user = get_user_by( 'id', $user_id );

        $converted = $this->convert_user( $user );

        return $this->add_user( $converted, self::get_document_id( $user ) );
    }

    /**
     * Update or insert a user in the RediSearch database
     *
     * @param string|int $user_id The user ID, can be real or arbitrary.
     * @return mixed
     */
    public function upsert( $user_id ) {
        $user = get_user_by( 'id', $user_id );

        $converted = $this->convert_user( $user );

        $result = $this->add_user( $converted, self::get_document_id( $user ) );

        do_action( 'redipress/new_user_added', $result, $user );

        $this->maybe_write_to_disk( 'new_user_added' );

        return $result;
    }

    /**
     * Delete a user from the RediSearch database
     *
     * @param string|int $user_id The user ID, can be real or arbitrary.
     * @return void
     */
    public function delete( $user_id ) {
        $this->delete_user( $user_id );

        $this->maybe_write_to_disk( 'user_deleted' );
    }

    /**
     * Convert User object to Redis command
     *
     * @param \WP_User $user The user object to convert.
     * @return array
     */
    public function convert_user( \WP_User $user ) : array {
        $settings = new Settings();

        \do_action( 'redipress/before_index_user', $user );

        $args         = [];
        $search_index = [];

        // Get the RediSearch schema for possible additional fields
        $schema = $this->client->raw_command( 'FT.INFO', [ $this->index ] );
        $schema = Utility::format( $schema );
        $fields = Utility::get_schema_fields( $schema['fields'] ?? [] );

        // Gather field names from hardcoded field for later.
        $core_field_names  = array_map( [ $this, 'return_field_name' ], $this->core_schema_fields );
        $additional_fields = array_diff( $fields, $core_field_names );
        $additional_values = array_map( function( $field ) use ( $user ) {
            $value = apply_filters( 'redipress/additional_user_field/' . $user->ID . '/' . $field, null, $user );
            $value = apply_filters( 'redipress/additional_user_field/' . $field, $value, $user->ID, $user );

            switch ( $this->get_field_type( $field ) ) {
                case 'TAG':
                    if ( ! is_array( $value ) ) {
                        $value = [ $value ];
                    }

                    $value = implode( self::get_tag_separator(), $value );
                    break;
                default:
                    break;
            }

            return $value;
        }, $additional_fields );

        $additions = array_combine( $additional_fields, $additional_values );
        $additions = array_filter( $additions, function( $item ) {
            return ! is_null( $item );
        });
        $additions = array_map( 'maybe_serialize', $additions );

        // Gather the additional search index
        $search_index = apply_filters( 'redipress/user_search_index', implode( ' ', $search_index ), $user->ID, $user );
        $search_index = apply_filters( 'redipress/user_search_index/' . $user->ID, $search_index, $user );
        $search_index = apply_filters( 'redipress/user_index_strings', $search_index, $user );
        $search_index = $this->escape_dashes( $search_index );

        // Filter the post object that will be added to the database serialized.
        $user_object = apply_filters( 'redipress/user_object', $user );
        $user_object = serialize( $user_object );

        $user_login = apply_filters( 'redipress/user_login', $user->user_login );
        $user_login = apply_filters( 'redipress/user_index_strings', $user->user_login, $user );
        $user_login = $this->escape_dashes( $user_login );

        $user_nicename = apply_filters( 'redipress/user_nicename', $user->user_nicename );
        $user_nicename = apply_filters( 'redipress/user_index_strings', $user->user_nicename, $user );
        $user_nicename = $this->escape_dashes( $user_nicename );

        $user_email = apply_filters( 'redipress/user_email', $user->user_email );
        $user_email = apply_filters( 'redipress/user_index_strings', $user->user_email, $user );
        $user_email = $this->escape_dashes( $user_email );

        $user_url = apply_filters( 'redipress/user_url', $user->user_url );
        $user_url = $this->escape_dashes( $user_url );

        $user_registered = apply_filters( 'redipress/user_registered', $user->user_registered );
        $user_registered = strtotime( $user_registered ) ?: null;

        $display_name = apply_filters( 'redipress/display_name', $user->display_name );
        $display_name = apply_filters( 'redipress/user_index_strings', $user->display_name, $user );
        $display_name = $this->escape_dashes( $display_name );

        $nickname = apply_filters( 'redipress/nickname', $user->nickname );
        $nickname = apply_filters( 'redipress/user_index_strings', $user->nickname, $user );
        $nickname = $this->escape_dashes( $nickname );

        $first_name = apply_filters( 'redipress/first_name', $user->first_name );
        $first_name = apply_filters( 'redipress/user_index_strings', $user->first_name, $user );
        $first_name = $this->escape_dashes( $first_name );

        $last_name = apply_filters( 'redipress/last_name', $user->last_name );
        $last_name = apply_filters( 'redipress/user_index_strings', $user->last_name, $user );
        $last_name = $this->escape_dashes( $last_name );

        if ( \is_multisite() ) {
            $blogs = \get_blogs_of_user( $user->ID, true );

            $args['blogs'] = implode( self::get_tag_separator(), array_map( function( $blog ) {
                return $blog->userblog_id;
            }, $blogs ) );

            $args['roles'] = implode( self::get_tag_separator(), array_map( function( $blog ) use ( $user ) {
                return $this->get_user_roles_for_site( $user->ID, $blog->userblog_id );
            }, $blogs ) );
        }
        else {
            $args['roles'] = $this->get_user_roles_for_site( $user->ID, 1 );
        }

        // Get rest of the fields
        $rest = [
            'user_id'         => $user->ID,
            'user_login'      => $user_login,
            'user_nicename'   => $user_nicename,
            'user_email'      => $user_email,
            'user_url'        => $user_url,
            'user_registered' => $user_registered,
            'display_name'    => $display_name,
            'nickname'        => $nickname,
            'first_name'      => $first_name,
            'last_name'       => $last_name,
            'search_index'    => $search_index,
            'user_object'     => $user_object,
        ];

        do_action( 'redipress/indexed_user', $user );

        return $this->client->convert_associative( array_merge( $args, $rest, $additions ) );
    }

    /**
     * Get RediSearch field type for a field
     *
     * @param string $key The key for which to fetch the field type.
     * @return string|null
     */
    protected function get_field_type( string $key ) : ?string {
        $schema     = $this->client->raw_command( 'FT.INFO', [ $this->index ] );
        $index_info = Utility::format( $schema );

        $fields = Utility::format( $index_info['fields'] );

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
     * Get user roles for site
     *
     * @param int $user_id User.
     * @param int $userblog_id Blog ID.
     * @return string
     */
    public function get_user_roles_for_site( int $user_id, int $userblog_id ) : string {
        $obj = new \WP_User( $user_id, '', $userblog_id );

        $roles = array_map( function( $role ) use ( $userblog_id ) {
            return $userblog_id . '_' . $role;
        }, $obj->roles );

        return implode( self::get_tag_separator(), $roles );
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
     * Add a user to the database
     *
     * @param array      $converted_user         The user in array form.
     * @param string|int $id                     The document ID for RediSearch.
     * @return mixed
     */
    public function add_user( array $converted_user, $id ) {
        $command = [ $this->index, $id, 1, 'REPLACE' ];

        $raw_command = array_merge( $command, [ 'FIELDS' ], $converted_user );

        return $this->client->raw_command( 'FT.ADD', $raw_command );
    }

    /**
     * Delete a user from the database
     *
     * @param string|int $id The document ID for RediSearch.
     * @return mixed
     */
    public function delete_user( $id ) {
        $return = $this->client->raw_command( 'FT.DEL', [ $this->index, $id, 'DD' ] );

        do_action( 'redipress/user_deleted', $id, $return );

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
}
