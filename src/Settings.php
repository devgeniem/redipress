<?php
/**
 * RediPress settings class file
 */

namespace Geniem\RediPress;

/**
 * RediPress settings class
 */
class Settings {

    /**
     * Options key prefix.
     */
    public const PREFIX = 'redipress_';

    /**
     * Run appropriate functionalities.
     */
    public function __construct() {
        \add_action( 'admin_init', [ $this, 'configure' ] );
    }

    /**
     * Configure the admin page using the Settings API.
     */
    public function configure() {

        // Register settings
        \register_setting( $this->get_slug(), self::PREFIX . 'persist_index' );
        \register_setting( $this->get_slug(), self::PREFIX . 'hostname' );
        \register_setting( $this->get_slug(), self::PREFIX . 'port' );
        \register_setting( $this->get_slug(), self::PREFIX . 'password' );
        \register_setting( $this->get_slug(), self::PREFIX . 'index' );
        \register_setting( $this->get_slug(), self::PREFIX . 'post_types' );
        \register_setting( $this->get_slug(), self::PREFIX . 'taxonomies' );

        // General settings section
        \add_settings_section(
            $this->get_slug() . '-general-settings-section',
            __( 'General settings', 'redipress' ),
            [ $this, 'render_general_settings_section' ],
            $this->get_slug()
        );

        // Persistent index field
        \add_settings_field(
            $this->get_slug() . '-persist-index',
            __( 'Persistent index', 'redipress' ),
            [ $this, 'render_persist_index_field' ],
            $this->get_slug(),
            $this->get_slug() . '-general-settings-section',
            [
                'label_for' => self::PREFIX . 'persist_index',
            ]
        );

        // Redis settings section
        \add_settings_section(
            $this->get_slug() . '-redis-settings-section',
            __( 'Redis settings', 'redipress' ),
            [ $this, 'render_redis_settings_section' ],
            $this->get_slug()
        );

        // Hostname field
        \add_settings_field(
            $this->get_slug() . '-hostname',
            __( 'Hostname', 'redipress' ),
            [ $this, 'render_hostname_field' ],
            $this->get_slug(),
            $this->get_slug() . '-redis-settings-section',
            [
                'label_for' => self::PREFIX . 'hostname',
            ]
        );

        // Port field
        \add_settings_field(
            $this->get_slug() . '-port',
            __( 'Port', 'redipress' ),
            [ $this, 'render_port_field' ],
            $this->get_slug(),
            $this->get_slug() . '-redis-settings-section',
            [
                'label_for' => self::PREFIX . 'port',
            ]
        );

        // Password field
        \add_settings_field(
            $this->get_slug() . '-password',
            __( 'Password', 'redipress' ),
            [ $this, 'render_password_field' ],
            $this->get_slug(),
            $this->get_slug() . '-redis-settings-section',
            [
                'label_for' => self::PREFIX . 'password',
            ]
        );

        // Index name field
        \add_settings_field(
            $this->get_slug() . '-index-name',
            __( 'Index name', 'redipress' ),
            [ $this, 'render_index_name_field' ],
            $this->get_slug(),
            $this->get_slug() . '-redis-settings-section',
            [
                'label_for' => self::PREFIX . 'index',
            ]
        );

        // Post types section
        \add_settings_section(
            $this->get_slug() . '-post-types-section',
            __( 'Post types', 'redipress' ),
            [ $this, 'render_post_types_section' ],
            $this->get_slug()
        );

        // Post types option
        $post_types_option = self::get( 'post_types' );
        if ( ! empty( $post_types_option ) ) {
            $post_types_value = $post_types_option;
        }
        else {
            $post_types_value = [];
        }

        // Post types field
        \add_settings_field(
            $this->get_slug() . '-post-types',
            __( 'Post types', 'redipress' ),
            [ $this, 'render_post_types_field' ],
            $this->get_slug(),
            $this->get_slug() . '-post-types-section',
            [
                'label_for' => self::PREFIX . 'post_types',
                'name'      => self::PREFIX . 'post_types[]',
                'options'   => $this->get_post_types(),
                'value'     => $post_types_value,
            ]
        );

        // Taxonomies section
        \add_settings_section(
            $this->get_slug() . '-taxonomies-section',
            __( 'Taxonomies', 'redipress' ),
            [ $this, 'render_taxonomies_section' ],
            $this->get_slug()
        );

        // Taxonomies option
        $taxonomies_option = self::get( 'taxonomies' );
        if ( ! empty( $taxonomies_option ) ) {
            $taxonomies_value = $taxonomies_option;
        }
        else {
            $taxonomies_value = [];
        }

        // Taxonomies field
        \add_settings_field(
            $this->get_slug() . '-taxonomies',
            __( 'Taxonomies', 'redipress' ),
            [ $this, 'render_taxonomies_field' ],
            $this->get_slug(),
            $this->get_slug() . '-taxonomies-section',
            [
                'label_for' => self::PREFIX . 'taxonomies',
                'name'      => self::PREFIX . 'taxonomies[]',
                'options'   => $this->get_taxonomies(),
                'value'     => $taxonomies_value,
            ]
        );
    }

    /**
     * Get registered post types.
     *
     * @return array
     */
    public function get_post_types() : array {
        $post_types = \get_post_types([
            'public'              => true,
            'exclude_from_search' => false,
            'show_ui'             => true,
        ], 'names' );

        $post_types = array_map( function( $post_type ) {
            $post_type_obj = \get_post_type_object( $post_type );
            return $post_type_obj->labels->singular_name;
        }, $post_types );

        return $post_types;
    }

    /**
     * Get registered taxonomies.
     *
     * @return array
     */
    public function get_taxonomies() : array {
        $taxonomies = \get_taxonomies([
            'public' => true,
        ], 'object' );

        $taxonomies = array_map( function( $taxonomy ) {
            return $taxonomy->labels->name;
        }, $taxonomies );

        return $taxonomies;
    }

    /**
     * Get the capability required to view the admin page.
     *
     * @return string
     */
    public function get_capability() : string {
        return 'manage_options';
    }

    /**
     * Get the title of the admin page in the WordPress admin menu.
     *
     * @return string
     */
    public function get_menu_title() : string {
        return 'RediPress';
    }

    /**
     * Get the title of the admin page.
     *
     * @return string
     */
    public function get_page_title() : string {
        return 'RediPress';
    }

    /**
     * Get the parent slug of the admin page.
     *
     * @return string
     */
    public function get_parent_slug() : string {
        return 'options-general.php';
    }

    /**
     * Get the slug used by the admin page.
     *
     * @return string
     */
    public function get_slug() : string {
        return 'redipress';
    }

    /**
     * Render the plugin's admin page.
     */
    public function render_page() {
        ?>
            <div class="wrap" id="redipress-settings">
                <h1><?php echo $this->get_page_title(); ?></h1>
                <?php if ( ! empty( filter_input( INPUT_GET, 'updated', FILTER_SANITIZE_STRING ) ) ) : ?>
                    <div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible">
                        <p><strong><?php \esc_html_e( 'Settings saved.' ); ?></strong></p>
                        <button type="button" class="notice-dismiss">
                            <span class="screen-reader-text"><?php \esc_html_e( 'Dismiss this notice.' ); ?></span>
                        </button>
                    </div>
                <?php endif; ?>
                <form action="options.php" method="POST">
                    <?php
                        \settings_fields( $this->get_slug() );
                        \do_settings_sections( $this->get_slug() );
                        \submit_button( __( 'Save' ) );
                    ?>
                </form>
            </div>
        <?php
    }

    /**
     * Renders the general settings section.
     */
    public function render_general_settings_section() {
        ?>
            <p><?php \esc_html_e( 'Section description.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the persistent index field.
     */
    public function render_persist_index_field() {
        $name   = 'persist_index';
        $option = self::get( $name );
        ?>
            <input type="hidden" name="redipress_persist_index" value="0" />
            <input type="checkbox" name="redipress_persist_index" id="redipress_persist_index" value="1" <?php \checked( 1, $option ) . $this->disabled( $name ); ?>>
            <p class="description" id="persistent-index-description">
                <?php
                \esc_html_e( 'Whether the index should be written to disk after every write action or not.', 'redipress' );
                ?>
            </p>
        <?php
    }

    /**
     * Renders the redis settings section.
     */
    public function render_redis_settings_section() {
        ?>
            <p><?php \esc_html_e( 'Section description.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the hostname field.
     */
    public function render_hostname_field() {
        $name   = 'hostname';
        $option = self::get( $name );
        ?>
            <input type="text" name="redipress_hostname" id="redipress_hostname" value="<?php echo \esc_attr( $option ); ?>" <?php $this->disabled( $name ); ?>>
            <p class="description" id="hostname-description">
                <?php \esc_html_e( 'Redis server hostname.', 'redipress' ); ?>
            </p>
        <?php
    }

    /**
     * Renders the port field.
     */
    public function render_port_field() {
        $name   = 'port';
        $option = self::get( $name );
        ?>
            <input type="number" name="redipress_port" id="redipress_port" value="<?php echo \esc_attr( $option ); ?>" <?php $this->disabled( $name ); ?>>
            <p class="description" id="port-description">
                <?php \esc_html_e( 'Redis server port.', 'redipress' ); ?>
            </p>
        <?php
    }

    /**
     * Renders the password field.
     */
    public function render_password_field() {
        $name   = 'password';
        $option = self::get( $name );
        ?>
            <input type="password" name="redipress_password" id="redipress_password" value="<?php echo \esc_attr( $option ); ?>" <?php $this->disabled( $name ); ?>>
            <p class="description" id="password-description">
                <?php \esc_html_e( 'If your Redis server is not password protected, leave this field empty.', 'redipress' ); ?>
            </p>
        <?php
    }

    /**
     * Renders the index name field.
     */
    public function render_index_name_field() {
        $name   = 'index';
        $option = self::get( $name );
        ?>
            <input type="text" name="redipress_index" id="redipress_index" value="<?php echo \esc_attr( $option ); ?>" <?php $this->disabled( $name ); ?>>
            <p class="description" id="index-name-description">
                <?php \esc_html_e( 'RediSearch index name, must be unique within the database.', 'redipress' ); ?>
            </p>
        <?php
    }

    /**
     * Renders the post types section.
     */
    public function render_post_types_section() {
        ?>
            <p><?php \esc_html_e( 'Select the post types that will be included in the search results.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the post types field.
     */
    public function render_post_types_field( $args ) {
        ?>
            <fieldset>
                <legend class="screen-reader-text"><span><?php \esc_html_e( 'Post types', 'redipress' ); ?></span></legend>
                <input type="hidden" name="redipress_post_types" value="" />
        <?php
        foreach ( $args['options'] as $val => $title ) {
            printf(
                '<label for="%1$s_%3$s"><input type="checkbox" name="%2$s" id="%1$s_%3$s" value="%3$s" %4$s %5$s>%6$s</label>',
                $args['label_for'],
                $args['name'],
                $val,
                in_array( $val, $args['value'], true ) ? 'checked' : '',
                defined( strtoupper( self::PREFIX . 'post_types' ) ) ? 'disabled' : '',
                $title
            );
            echo '<br>';
        }
        ?>
            </fieldset>
        <?php
    }

    /**
     * Renders the taxonomies section.
     */
    public function render_taxonomies_section() {
        ?>
            <p><?php \esc_html_e( 'Select the taxonomies that will be included in the search results.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the taxonomies field.
     */
    public function render_taxonomies_field( array $args ) {
        ?>
            <fieldset>
                <legend class="screen-reader-text"><span><?php \esc_html_e( 'Taxonomies', 'redipress' ); ?></span></legend>
                <input type="hidden" name="redipress_taxonomies" value="" />
        <?php
        foreach ( $args['options'] as $val => $title ) {
            printf(
                '<label for="%1$s_%3$s"><input type="checkbox" name="%2$s" id="%1$s_%3$s" value="%3$s" %4$s %5$s>%6$s</label>',
                $args['label_for'],
                $args['name'],
                $val,
                in_array( $val, $args['value'], true ) ? 'checked' : '',
                defined( strtoupper( self::PREFIX . 'taxonomies' ) ) ? 'disabled' : '',
                $title
            );
            echo '<br>';
        }
        ?>
            </fieldset>
        <?php
    }

    /**
     * Disable field if constant is defined.
     *
     * @param string $option The option name.
     */
    protected function disabled( string $option ) {
        $key = self::PREFIX . $option;
        if ( defined( strtoupper( $key ) ) ) {
            echo 'disabled';
        }
    }

    /**
     * Return a value from constant and fallback to Options API only if it's not defined.
     *
     * @param string $option The option name.
     * @return mixed
     */
    public static function get( string $option ) {
        $key = self::PREFIX . $option;
        return defined( strtoupper( $key ) ) ? constant( strtoupper( $key ) ) : \get_option( $key );
    }

}
