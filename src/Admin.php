<?php
/**
 * RediPress admin class file
 */

namespace Geniem\RediPress;

use Geniem\ACF\RuleGroup,
    Geniem\ACF\Group,
    Geniem\ACF\Field,
    Geniem\RediPressPlugin;

/**
 * RediPress admin class
 */
class Admin {

    /**
     * ACF Codifier key prefix
     */
    public const PREFIX = 'redipress_';

    /**
     * Options page field group
     *
     * @var Geniem\ACF\Group
     */
    protected $field_group = null;

    /**
     * Run appropriate functionalities
     */
    public function __construct() {

        // Create an options page for the plugin
        $this->create_options_page();

        // Register field group for the options page
        $this->register_field_group();

        // Register tab and fields for status display
        $this->fields_status();

        // Register general settings tab
        $this->fields_settings();

        // Register tab and fields for the Redis server settings
        $this->fields_redis();

        // Register tab and fields for the post type selections
        $this->fields_post_types();

        // Register tab and fields for the taxonomy selections
        $this->fields_taxonomies();

        // Enable constant overriding
        $fields = $this->field_group->get_fields();

        array_walk( $fields, function( $field ) {
            $this->handle_fields( $field );
        });
    }

    /**
     * A recursive helper function to add filters to fields.
     *
     * @param Field $field The field object to handle.
     * @return void
     */
    private function handle_fields( $field ) {
        if ( $field instanceof Field\GroupableField ) {
            $fields = $field->get_fields();
            array_walk( $fields, [ $this, 'handle_fields' ] );
        }
        else {
            $field->load_field( [ $this, 'load_field' ] );
            $field->load_value( [ $this, 'load_value' ] );
        }
    }

    /**
     * Create the options page with ACF functionalities
     *
     * @return void
     */
    protected function create_options_page() {
        \acf_add_options_page([
            'page_title'      => 'RediPress',
            'capability'      => 'manage_options',
            'parent_slug'     => 'options-general.php',
            'redirect'        => true,
            'menu_slug'       => 'redipress',
            'post_id'         => 'redipress',
            'autoload'        => true,
            'update_button'   => __( 'Save', 'redipress' ),
            'updated_message' => __( 'Settings saved', 'redipress' ),
        ]);
    }

    /**
     * Register ACF Codifier fields for the options page
     *
     * @return void
     */
    protected function register_field_group() {
        $field_group = new Group( __( 'RediPress Settings', 'redipress' ) );
        $field_group->set_key( self::PREFIX );

        $rule = new RuleGroup();
        $rule->add_rule( 'options_page', '==', 'redipress' );

        $field_group->add_rule_group( $rule );

        $field_group->register();

        $this->field_group = $field_group;
    }

    /**
     * Register tab and fields for status display
     *
     * @return void
     */
    protected function fields_status() {
        $tab = new Field\Tab( __( 'Indexing', 'redipress' ) );
        $tab->set_key( self::PREFIX . 'indexing' )
            ->set_placement( 'left' );

        $index = new Field\PHP( __( 'Indexing', 'redipress' ) );
        $index->run( function( $field ) {
            dustpress()->render([
                'partial' => 'redipress_admin_indexing',
                'data'    => false,
                'echo'    => true,
            ]);
        });

        $tab->add_fields([
            $index,
        ]);

        $this->field_group->add_field( $tab );
    }

    /**
     * Register general settings tab
     *
     * @return void
     */
    protected function fields_settings() {
        $tab = new Field\Tab( __( 'General settings' ) );
        $tab->set_key( self::PREFIX . 'general_settings' )
            ->set_placement( 'left' );

        $persist = new Field\TrueFalse( __( 'Persistent index', 'redipress' ) );
        $persist->set_key( self::PREFIX . 'persist_index' )
            ->set_instructions( __( 'Whether the index should be written to disk after every write action or not.', 'redipress' ) )
            ->set_name( 'persist_index' );

        $tab->add_fields([
            $persist,
        ]);

        $this->field_group->add_field( $tab );
    }

    /**
     * Register tab and fields for the Redis server settings
     *
     * @return void
     */
    protected function fields_redis() {
        $tab = new Field\Tab( __( 'Redis settings' ) );
        $tab->set_key( self::PREFIX . 'redis_server' )
            ->set_placement( 'left' );

        $hostname = new Field\Text( __( 'Hostname', 'redipress' ) );
        $hostname->set_key( self::PREFIX . 'hostname' )
            ->set_name( 'hostname' )
            ->set_required()
            ->set_instructions( __( 'Redis server hostname', 'redipress' ) )
            ->set_placeholder( '127.0.0.1' );

        $port = new Field\Number( __( 'Port', 'redipress' ) );
        $port->set_key( self::PREFIX . 'port' )
            ->set_name( 'port' )
            ->set_required()
            ->set_instructions( __( 'Redis server port', 'redipress' ) )
            ->set_step( 1 )
            ->set_min( 0 )
            ->set_max( 65535 )
            ->set_default_value( 6379 )
            ->set_placeholder( 6379 );

        $password = new Field\Password( __( 'Password', 'redipress' ) );
        $password->set_key( self::PREFIX . 'password' )
            ->set_name( 'password' )
            ->set_instructions( __( 'If your Redis server is not password protected, leave this field empty.', 'redipress' ) );

        $database = new Field\Number( __( 'Database', 'redipress' ) );
        $database->set_key( self::PREFIX . 'database' )
            ->set_name( 'database' )
            ->set_required()
            ->set_instructions( __( 'Redis database number.', 'redipress' ) )
            ->set_step( 1 )
            ->set_min( 0 )
            ->set_default_value( 0 )
            ->set_placeholder( 0 );

        $index = new Field\Text( 'Index name', 'redipress' );
        $index->set_key( self::PREFIX . 'index' )
            ->set_name( 'index' )
            ->set_required()
            ->set_instructions( __( 'RediSearch index name, must be unique within the database', 'redipress' ) );

        $tab->add_fields([
            $hostname,
            $port,
            $password,
            $database,
            $index,
        ]);

        $this->field_group->add_field( $tab );
    }

    /**
     * Register tab and fields for the post type selections
     *
     * @return void
     */
    protected function fields_post_types() {
        $tab = new Field\Tab( __( 'Post types' ) );
        $tab->set_key( self::PREFIX . 'post_types' )
            ->set_placement( 'left' );

        $post_type_list = get_post_types([
            'public'              => true,
            'exclude_from_search' => false,
            'show_ui'             => true,
        ]);

        $post_types = new Field\Checkbox( __( 'Post types', 'redipress' ) );
        $post_types->set_key( self::PREFIX . 'post_types' )
            ->set_instructions( __( 'Select the post types that will be included in the search results.', 'redipress' ) )
            ->allow_toggle()
            ->set_choices( array_map( function( $post_type ) {
                $post_type_obj = get_post_type_object( $post_type );
                return $post_type_obj->labels->singular_name;
            }, $post_type_list ));

        $tab->add_fields([
            $post_types,
        ]);

        $this->field_group->add_field( $tab );
    }

    /**
     * Register tab and fields for the taxonomy selections
     *
     * @return void
     */
    protected function fields_taxonomies() {
        $tab = new Field\Tab( __( 'Taxonomies' ) );
        $tab->set_key( self::PREFIX . 'taxonomies' )
            ->set_placement( 'left' );

        $post_type_list = get_taxonomies([
            'public'  => true,
            'show_ui' => true,
        ], 'objects' );

        $taxonomies = new Field\Checkbox( __( 'Taxonomies', 'redipress' ) );
        $taxonomies->set_key( self::PREFIX . 'taxonomies' )
            ->set_instructions( __( 'Select the taxonomies that will be included in the search results.', 'redipress' ) )
            ->allow_toggle()
            ->set_choices( array_map( function( $post_type ) {
                return $post_type->labels->name;
            }, $post_type_list ));

        $tab->add_fields([
            $taxonomies,
        ]);

        $this->field_group->add_field( $tab );
    }

    /**
     * An ACF hook function to override the field values from constants if available.
     *
     * @param array $field The field object.
     * @return array
     */
    public function load_field( $field ) : array {
        if ( defined( strtoupper( $field['key'] ) ) ) {
            $field['value']        = constant( strtoupper( $field['key'] ) );
            $field['readonly']     = true;
            $field['instructions'] = __( 'Value is defined in a constant and can\'t be changed here.', 'redipress' );
        }

        return $field;
    }

    /**
     * An ACF hook function to override the field values from constants if available.
     *
     * @param mixed  $value   The original value of the field.
     * @param string $post_id The post ID of the value. Here it is alwayas a string.
     * @param array  $field   The field object.
     * @return mixed
     */
    public function load_value( $value, $post_id, $field ) {
        if ( defined( strtoupper( $field['key'] ) ) ) {
            $value = constant( strtoupper( $field['key'] ) );
        }

        return $value;
    }

    /**
     * Return a value from an option page field.
     *
     * @param string $option The option name.
     * @return mixed
     */
    public static function get( string $option ) {
        return get_field( $option, 'redipress' );
    }
}
