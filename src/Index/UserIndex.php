<?php
/**
 * RediPress user index class file
 */

namespace Geniem\RediPress\Index;

use Geniem\RediPress\Settings,
    Geniem\RediPress\Entity\NumericField,
    Geniem\RediPress\Entity\TagField,
    Geniem\RediPress\Entity\TextField,
    Geniem\RediPress\Redis\Client,
    Geniem\RediPress\Utility,
    Geniem\RediPress\Rest;

/**
 * RediPress user index class
 */
class UserIndex extends Index {

    /**
     * The index type
     */
    const INDEX_TYPE = 'users';

    /**
     * The corresponding query class name
     */
    const INDEX_QUERY_CLASS = '\\Geniem\\RediPress\\UserQuery';

    /**
     * Construct the index object
     *
     * @param Client $client Client instance.
     */
    public function __construct( Client $client ) {
        parent::__construct( $client );

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
        add_action( 'redipress/cli/index_all_users', [ $this, 'index_all' ], 50, 0 ); // @phpstan-ignore return.void
        add_action( 'redipress/cli/index_missing_users', [ $this, 'index_missing' ], 50, 0 );
        add_action( 'redipress/cli/index_single_user', [ $this, 'index_single' ], 50, 1 ); // @phpstan-ignore return.void
        add_filter( 'redipress/index/users/create', [ $this, 'create' ], 50, 1 );
        add_filter( 'redipress/index/users/drop', [ $this, 'drop' ], 50, 1 );

        // Register external actions
        add_action( 'redipress/delete_user', [ $this, 'delete_user' ], 50, 1 ); // @phpstan-ignore return.void
        add_action( 'redipress/index_user', [ $this, 'upsert' ], 50, 3 ); // @phpstan-ignore return.void

        // Register indexing hooks
        add_action( 'profile_update', [ $this, 'upsert' ], 10, 1 ); // @phpstan-ignore return.void
        add_action( 'user_register', [ $this, 'upsert' ], 10, 1 ); // @phpstan-ignore return.void
        add_action( 'deleted_user', [ $this, 'delete' ], 10, 1 );
    }

    /**
     * Get total amount of posts to index
     *
     * @return int
     */
    public static function index_total(): int {
        global $wpdb;
        $ids = intval( $wpdb->get_row( "SELECT count(*) as count FROM $wpdb->users" )->count ); // phpcs:ignore
        return $ids;
    }

    /**
     * Define core fields for the RediSearch schema
     *
     * @return array
     */
    public function define_core_fields(): array {
        // Define the WordPress core fields
        $core_schema_fields = [
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
                'name'     => 'locale',
                'sortable' => true,
            ]),
            new TextField([
                'name' => 'search_index',
            ]),
        ];

        if ( \is_multisite() ) {
            $core_schema_fields[] = new TagField([
                'name'      => 'blogs',
                'separator' => self::get_tag_separator(),
            ]);
        }

        return $core_schema_fields;
    }

    /**
     * Index all posts to the RediSearch database
     *
     * @param  \WP_REST_Request|null $request Rest request details or null if not rest api request.
     * @return int                            Amount of items indexed.
     */
    public function index_all( ?\WP_REST_Request $request = null ): int {
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

        $users = array_map( function ( $row ) {
            return get_user_by( 'id', $row->ID );
        }, $ids );

        $users = apply_filters( 'redipress/custom_users', $users );

        $result = array_map( function ( $user ) use ( $progress ) {
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
    public static function get_document_id( \WP_User $user ): string {
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
        $this->delete_user( $this->index . ':' . $user_id );

        $this->maybe_write_to_disk( 'user_deleted' );
    }

    /**
     * Convert User object to Redis command
     *
     * @param \WP_User $user The user object to convert.
     * @return array
     */
    public function convert_user( \WP_User $user ): array {
        $settings = new Settings();

        \do_action( 'redipress/before_index_user', $user );

        $args         = [];
        $search_index = [];

        // Get the RediSearch schema for possible additional fields
        $schema = $this->client->raw_command( 'FT.INFO', [ $this->index ] );
        $schema = Utility::format( $schema );
        $fields = Utility::get_schema_fields( $schema['attributes'] ?? [] );

        // Gather field names from hardcoded field for later.
        $core_field_names  = array_map( [ $this, 'return_field_name' ], $this->core_schema_fields );
        $additional_fields = array_diff( $fields, $core_field_names );
        $additional_values = array_map( function ( $field ) use ( $user ) {
            $value = self::get( 'user_' . $user->ID, $field );

            $value = apply_filters( 'redipress/additional_user_field/' . $user->ID . '/' . $field, $value, $user );
            $value = apply_filters( 'redipress/additional_user_field/' . $field, $value, $user->ID, $user );
            $type  = $this->get_field_type( $field );

            switch ( $type ) {
                case 'TAG':
                    if ( ! is_array( $value ) ) {
                        $value = [ $value ];
                    }

                    $value = implode( self::get_tag_separator(), $value );
                    break;
                default:
                    break;
            }

            // RediSearch doesn't accept boolean values
            if ( is_bool( $value ) ) {
                $value = (int) $value;
            }

            // Escape dashes in all but numeric fields
            if ( ! in_array( $type, [ 'NUMERIC', 'GEO', 'TAG' ] ) ) {
                $value = $this->escape_string( $value );
            }

            return $value;
        }, $additional_fields );

        $additions = array_combine( $additional_fields, $additional_values );
        $additions = array_filter( $additions, function ( $item ) {
            return ! is_null( $item );
        });
        $additions = array_map( 'maybe_serialize', $additions );

        // Gather the additional search index
        $search_index = apply_filters( 'redipress/user_search_index', implode( ' ', $search_index ), $user->ID, $user );
        $search_index = apply_filters( 'redipress/user_search_index/' . $user->ID, $search_index, $user );
        $search_index = apply_filters( 'redipress/user_index_strings', $search_index, $user );
        $search_index = $this->escape_string( $search_index );

        // Filter the post object that will be added to the database serialized.
        $user_object = apply_filters( 'redipress/user_object', $user );
        $user_object = serialize( $user_object );

        $user_login = apply_filters( 'redipress/user_login', $user->user_login );
        $user_login = apply_filters( 'redipress/user_index_strings', $user_login, $user );
        $user_login = $this->escape_string( $user_login );

        $user_nicename = apply_filters( 'redipress/user_nicename', $user->user_nicename );
        $user_nicename = apply_filters( 'redipress/user_index_strings', $user_nicename, $user );
        $user_nicename = $this->escape_string( $user_nicename );

        $user_email = apply_filters( 'redipress/user_email', $user->user_email );
        $user_email = apply_filters( 'redipress/user_index_strings', $user_email, $user );
        $user_email = $this->escape_string( $user_email );

        $user_url = apply_filters( 'redipress/user_url', $user->user_url );
        $user_url = $this->escape_string( $user_url );

        $user_registered = apply_filters( 'redipress/user_registered', $user->user_registered );
        $user_registered = strtotime( $user_registered ) ?: null;

        $display_name = apply_filters( 'redipress/display_name', $user->display_name );
        $display_name = apply_filters( 'redipress/user_index_strings', $display_name, $user );
        $display_name = $this->escape_string( $display_name );

        $nickname = apply_filters( 'redipress/nickname', $user->nickname );
        $nickname = apply_filters( 'redipress/user_index_strings', $nickname, $user );
        $nickname = $this->escape_string( $nickname );

        $first_name = apply_filters( 'redipress/first_name', $user->first_name );
        $first_name = apply_filters( 'redipress/user_index_strings', $first_name, $user );
        $first_name = $this->escape_string( $first_name );

        $last_name = apply_filters( 'redipress/last_name', $user->last_name );
        $last_name = apply_filters( 'redipress/user_index_strings', $last_name, $user );
        $last_name = $this->escape_string( $last_name );

        $locale = apply_filters( 'redipress/user_locale', \get_user_locale( $user->ID ) );
        $locale = apply_filters( 'redipress/user_index_strings', $locale, $user );
        $locale = $this->escape_string( $locale );

        if ( \is_multisite() ) {
            $blogs = \get_blogs_of_user( $user->ID, true );

            $args['blogs'] = implode( self::get_tag_separator(), array_map( function ( $blog ) {
                return $blog->userblog_id;
            }, $blogs ) );

            $args['roles'] = implode( self::get_tag_separator(), array_map( function ( $blog ) use ( $user ) {
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
            'locale'          => $locale,
            'search_index'    => $search_index,
            'user_object'     => $user_object,
        ];

        do_action( 'redipress/indexed_user', $user );

        return $this->client->convert_associative( array_merge( $args, $rest, $additions ) );
    }

    /**
     * Get user roles for site
     *
     * @param int $user_id User.
     * @param int $userblog_id Blog ID.
     * @return string
     */
    public function get_user_roles_for_site( int $user_id, int $userblog_id ): string {
        $obj = new \WP_User( $user_id, '', $userblog_id );

        $roles = array_map( function ( $role ) use ( $userblog_id ) {
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
    public function escape_string( ?string $string = '' ): string {
        return Utility::escape_string( $string );
    }

    /**
     * Add a user to the database
     *
     * @param array      $converted_user         The user in array form.
     * @param string|int $id                     The document ID for RediSearch.
     * @return mixed
     */
    public function add_user( array $converted_user, $id ) {
        return $this->add_document( $converted_user, $id );
    }

    /**
     * Delete a user from the database
     *
     * @param string|int $id The document ID for RediSearch.
     * @return mixed
     */
    public function delete_user( $id ) {
        return $this->delete_document( $id );
    }
}
