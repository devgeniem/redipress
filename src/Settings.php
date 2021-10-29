<?php
/**
 * RediPress settings class file
 */

namespace Geniem\RediPress;

use Geniem\RediPress\Index\Index;

/**
 * RediPress settings class
 */
class Settings {

    /**
     * Options key prefix.
     */
    public const PREFIX = 'redipress_';

    /**
     * Index info
     *
     * @var array
     */
    protected $index_info;

    /**
     * Run appropriate functionalities.
     *
     * @param array|null $index_info Index information.
     */
    public function __construct( array $index_info = null ) {
        $this->index_info = $index_info;
        \add_action( 'admin_init', \Closure::fromCallable( [ $this, 'configure' ] ) );
    }

    /**
     * Configure the admin page using the Settings API.
     */
    private function configure() {

        // Register settings
        \register_setting( $this->get_slug(), self::PREFIX . 'persist_index' );
        \register_setting( $this->get_slug(), self::PREFIX . 'write_every' );
        \register_setting( $this->get_slug(), self::PREFIX . 'fallback' );
        \register_setting( $this->get_slug(), self::PREFIX . 'escape_parentheses' );
        \register_setting( $this->get_slug(), self::PREFIX . 'disable_post_author_search' );
        \register_setting( $this->get_slug(), self::PREFIX . 'hostname' );
        \register_setting( $this->get_slug(), self::PREFIX . 'port' );
        \register_setting( $this->get_slug(), self::PREFIX . 'password' );
        \register_setting( $this->get_slug(), self::PREFIX . 'index' );
        \register_setting( $this->get_slug(), self::PREFIX . 'use_user_query' );
        \register_setting( $this->get_slug(), self::PREFIX . 'user_index' );
        \register_setting( $this->get_slug(), self::PREFIX . 'post_types' );
        \register_setting( $this->get_slug(), self::PREFIX . 'taxonomies' );

        // General settings section
        \add_settings_section(
            $this->get_slug() . '-general-settings-section',
            __( 'General settings', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_general_settings_section' ] ),
            $this->get_slug()
        );

        // Persistent index field
        \add_settings_field(
            $this->get_slug() . '-persist-index',
            __( 'Persistent index', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_persist_index_field' ] ),
            $this->get_slug(),
            $this->get_slug() . '-general-settings-section',
            [
                'label_for' => self::PREFIX . 'persist_index',
            ]
        );

        // Write the index only once per execution
        \add_settings_field(
            $this->get_slug() . '-write-every',
            __( 'Write the index only once per execution', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_write_every' ] ),
            $this->get_slug(),
            $this->get_slug() . '-general-settings-section',
            [
                'label_for' => self::PREFIX . 'write_every',
            ]
        );

        // Fallback to MySQL if no results have been found from RediSearch
        \add_settings_field(
            $this->get_slug() . '-fallback',
            __( 'Fallback to MySQL if no results have been found from RediSearch', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_fallback' ] ),
            $this->get_slug(),
            $this->get_slug() . '-general-settings-section',
            [
                'label_for' => self::PREFIX . 'fallback',
            ]
        );

        // Escape the parentheses in search query
        \add_settings_field(
            $this->get_slug() . '-escape-parentheses',
            __( 'Escape the parentheses in search query', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_escape_parentheses' ] ),
            $this->get_slug(),
            $this->get_slug() . '-general-settings-section',
            [
                'label_for' => self::PREFIX . 'escape_parentheses',
            ]
        );

        // Include author name in search field
        \add_settings_field(
            $this->get_slug() . '-disable-post-author-search',
            __( 'Disable including author name in search', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_disable_post_author_search_field' ] ),
            $this->get_slug(),
            $this->get_slug() . '-general-settings-section'
        );

        // Index management buttons
        \add_settings_field(
            $this->get_slug() . '-index-buttons',
            __( 'Index', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_index_management' ] ),
            $this->get_slug(),
            $this->get_slug() . '-general-settings-section'
        );

        // Redis settings section
        \add_settings_section(
            $this->get_slug() . '-redis-settings-section',
            __( 'Redis settings', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_redis_settings_section' ] ),
            $this->get_slug()
        );

        // Hostname field
        \add_settings_field(
            $this->get_slug() . '-hostname',
            __( 'Hostname', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_hostname_field' ] ),
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
            \Closure::fromCallable( [ $this, 'render_port_field' ] ),
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
            \Closure::fromCallable( [ $this, 'render_password_field' ] ),
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
            \Closure::fromCallable( [ $this, 'render_index_name_field' ] ),
            $this->get_slug(),
            $this->get_slug() . '-redis-settings-section',
            [
                'label_for' => self::PREFIX . 'index',
            ]
        );

        // Use user query field
        \add_settings_field(
            $this->get_slug() . '-use-user-query',
            __( 'Use user query', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_use_user_query_field' ] ),
            $this->get_slug(),
            $this->get_slug() . '-redis-settings-section',
            [
                'label_for' => self::PREFIX . 'use_user_query',
            ]
        );

        // User index name field
        \add_settings_field(
            $this->get_slug() . '-user-index-name',
            __( 'User index name', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_user_index_name_field' ] ),
            $this->get_slug(),
            $this->get_slug() . '-redis-settings-section',
            [
                'label_for' => self::PREFIX . 'user_index',
            ]
        );

        // Post types section
        \add_settings_section(
            $this->get_slug() . '-post-types-section',
            __( 'Post types', 'redipress' ),
            \Closure::fromCallable( [ $this, 'render_post_types_section' ] ),
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
            \Closure::fromCallable( [ $this, 'render_post_types_field' ] ),
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
            \Closure::fromCallable( [ $this, 'render_taxonomies_section' ] ),
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
            \Closure::fromCallable( [ $this, 'render_taxonomies_field' ] ),
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
    private function get_post_types() : array {
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
    private function get_taxonomies() : array {
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
        $active_tab = $_GET['tab'] ?? 'analytics';
        ?>
            <div class="wrap" id="redipress-settings">
                <h1><?php echo $this->get_page_title(); ?></h1>
                <div class="nav-tab-wrapper">
                    <a href="?page=redipress&tab=analytics" class="nav-tab <?php echo $active_tab == 'analytics' ? 'nav-tab-active' : ''; ?>">
                        <?php \esc_html_e( 'Analytics', 'redipress' ); ?>
                    </a>
                    <a href="?page=redipress&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                        <?php \esc_html_e( 'Settings', 'redipress' ); ?>
                    </a>
                </div>

                <?php if ( ! empty( filter_input( INPUT_GET, 'updated', FILTER_SANITIZE_STRING ) ) ) : ?>
                    <div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible">
                        <p><strong><?php \esc_html_e( 'Settings saved.' ); ?></strong></p>
                        <button type="button" class="notice-dismiss">
                            <span class="screen-reader-text"><?php \esc_html_e( 'Dismiss this notice.' ); ?></span>
                        </button>
                    </div>
                <?php endif; ?>

                <?php
                if ( $active_tab === 'analytics' ) :
                    $this->render_analytics_layout();
                elseif ( $active_tab === 'settings' ) :
                    $this->render_settings_layout();
                endif;
                ?>
            </div>
        <?php
    }

    /**
     * Render the analytics layout.
     */
    private function render_analytics_layout() {
        ?>
        <div class="redipress-analytics">
            <div class="redipress-columns">
                <div class="redipress-column is-one-third">
                    <?php $this->render_most_popular_search_results(); ?>
                </div>
                <div class="redipress-column is-one-third">
                    <?php $this->render_most_popular_search_terms(); ?>
                </div>
                <div class="redipress-column is-one-third">
                    <?php $this->render_unsuccessful_searches(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the most popular search results table.
     */
    private function render_most_popular_search_results() {
        ?>
        <h2><?php \esc_html_e( 'Most popular search results', 'redipress' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>
                        <?php \esc_html_e( 'Result', 'redipress' ); ?>
                    </th>
                    <th class="table-col-narrow has-text-right">
                        <?php \esc_html_e( '#', 'redipress' ); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <a href="/path-to-page/" target="_blank">Result title</a>
                    </td>
                    <td class="table-col-narrow has-text-right">
                        123
                    </td>
                </tr>
                <tr>
                    <td>
                        <a href="/path-to-page-2/" target="_blank">Result title 2</a>
                    </td>
                    <td class="table-col-narrow has-text-right">
                        100
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the most popular search terms table.
     */
    private function render_most_popular_search_terms() {
        ?>
        <h2><?php \esc_html_e( 'Most popular search terms', 'redipress' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>
                        <?php \esc_html_e( 'Term', 'redipress' ); ?>
                    </th>
                    <th class="table-col-narrow has-text-right">
                        <?php \esc_html_e( '#', 'redipress' ); ?>
                    </th>
                    <th class="table-col-narrow has-text-right">
                        <?php \esc_html_e( 'Hits', 'redipress' ); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        lorem
                    </td>
                    <td class="table-col-narrow has-text-right">
                        20
                    </td>
                    <td class="table-col-narrow has-text-right">
                        123
                    </td>
                </tr>
                <tr>
                    <td>
                        ipsum
                    </td>
                    <td class="table-col-narrow has-text-right">
                        10
                    </td>
                    <td class="table-col-narrow has-text-right">
                        100
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render unsuccessful searches table.
     */
    private function render_unsuccessful_searches() {
        ?>
        <h2><?php \esc_html_e( 'Search terms which returned no results', 'redipress' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>
                        <?php \esc_html_e( 'Term', 'redipress' ); ?>
                    </th>
                    <th class="table-col-narrow has-text-right">
                        <?php \esc_html_e( '#', 'redipress' ); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        dolor
                    </td>
                    <td class="table-col-narrow has-text-right">
                        20
                    </td>
                </tr>
                <tr>
                    <td>
                        amet
                    </td>
                    <td class="table-col-narrow has-text-right">
                        10
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the settings layout.
     */
    private function render_settings_layout() {
        ?>
        <form action="options.php" method="POST">
            <?php
                \settings_fields( $this->get_slug() );
                \do_settings_sections( $this->get_slug() );
                \submit_button( __( 'Save' ) );
            ?>
        </form>
        <?php
    }

    /**
     * Renders the general settings section.
     */
    private function render_general_settings_section() {
        ?>
            <p><?php \esc_html_e( 'Section description.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the persistent index field.
     */
    private function render_persist_index_field() {
        $name   = 'persist_index';
        $option = self::get( $name );
        ?>
            <input type="hidden" name="redipress_persist_index" value="0" />
            <input type="checkbox" name="redipress_persist_index" id="redipress_persist_index" value="1" <?php \checked( 1, $option ) . $this->disabled( $name ); ?>>
            <p class="description" id="persistent-index-description">
                <?php
                \esc_html_e( 'Whether to store the index persistently or only in memory.', 'redipress' );
                ?>
            </p>
        <?php
    }

    /**
     * Renders the persistent index field.
     */
    private function render_write_every() {
        $name   = 'write_every';
        $option = self::get( $name );
        ?>
            <input type="hidden" name="redipress_write_every" value="0" />
            <input type="checkbox" name="redipress_write_every" id="redipress_write_every" value="1" <?php \checked( 1, $option ) . $this->disabled( $name ); ?>>
            <p class="description" id="write-every-description">
                <?php
                \esc_html_e( 'Whether the index should be written to disk after every write action or only once per execution.', 'redipress' );
                ?>
            </p>
        <?php
    }

    /**
     * Renders the fallback field.
     */
    private function render_fallback() {
        $name   = 'fallback';
        $option = self::get( $name );
        ?>
            <input type="hidden" name="redipress_fallback" value="0" />
            <input type="checkbox" name="redipress_fallback" id="redipress_fallback" value="1" <?php \checked( 1, $option ) . $this->disabled( $name ); ?>>
            <p class="description" id="fallback-description">
                <?php
                \esc_html_e( 'Whether to fallback to MySQL when no results are found from RediSearch or not.', 'redipress' );
                ?>
            </p>
        <?php
    }

    /**
     * Renders the escape parentheses field.
     */
    private function render_escape_parentheses() {
        $name   = 'escape_parentheses';
        $option = self::get( $name );
        ?>
            <input type="hidden" name="redipress_escape_parentheses" value="0" />
            <input type="checkbox" name="redipress_escape_parentheses" id="redipress_escape_parentheses" value="1" <?php \checked( 1, $option ) . $this->disabled( $name ); ?>>
            <p class="description" id="escape-parentheses-description">
                <?php
                \esc_html_e( 'Whether to escape parentheses in search queries or not.', 'redipress' );
                ?>
            </p>
        <?php
    }

    /**
     * Renders the index manipulation buttons.
     */
    private function render_index_management() {
        $current_index = $this->index_info['num_docs'] ?? 0 + ( $this->index_info['num_terms'] ?? 0 );
        $max_index     = Index::index_total();
        ?>
            <div>
                <p id="redipress_index_info"></p>
                <progress value="<?php echo \intval( $current_index ); ?>" max="<?php echo \intval( $max_index ); ?>" id="redipress_index_progress"></progress>
            </div>
            <div>
                <p>
                    <?php \esc_html_e( 'Items in index:', 'redipress' ); ?>
                    <span id="redipress_current_index"><?php echo \intval( $current_index ); ?></span>
                    <span id="redipress_index_count_delimeter">/</span>
                    <span id="redipress_max_index"><?php echo \intval( $max_index ); ?></span>
                </p>
            </div>
        <?php
        \submit_button( \__( 'Index all' ),    'primary',   'redipress_index_all',  false );
        \submit_button( \__( 'Create index' ), 'secondary', 'redipress_index',      false );
        \submit_button( \__( 'Delete index' ), 'delete',    'redipress_drop_index', false );
    }

    /**
     * Renders the redis settings section.
     */
    private function render_redis_settings_section() {
        ?>
            <p><?php \esc_html_e( 'Section description.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the hostname field.
     */
    private function render_hostname_field() {
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
    private function render_port_field() {
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
    private function render_password_field() {
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
    private function render_index_name_field() {
        $name   = 'index';
        $option = self::get( $name );
        ?>
            <input type="text" name="redipress_index" id="redipress_index" value="<?php echo \esc_attr( $option ) ?: 'posts'; ?>" <?php $this->disabled( $name ); ?>>
            <p class="description" id="index-name-description">
                <?php \esc_html_e( 'RediSearch index name, must be unique within the database.', 'redipress' ); ?>
            </p>
        <?php
    }

    /**
     * Renders the use user query field.
     */
    private function render_use_user_query_field() {
        $name   = 'use_user_query';
        $option = self::get( $name );
        ?>
            <input type="hidden" name="redipress_use_user_query" value="0" />
            <input type="checkbox" name="redipress_use_user_query" id="redipress_use_user_query" value="1" <?php \checked( 1, $option ) . $this->disabled( $name ); ?>>
            <p class="description" id="use-user-query-description">
                <?php
                \esc_html_e( 'Whether the user database is in use or not.', 'redipress' );
                ?>
            </p>
        <?php
    }

    /**
     * Renders the user index name field.
     */
    private function render_user_index_name_field() {
        $name   = 'user_index';
        $option = self::get( $name );
        ?>
            <input type="text" name="redipress_user_index" id="redipress_user_index" value="<?php echo \esc_attr( $option ) ?: 'users'; ?>" <?php $this->disabled( $name ); ?>>
            <p class="description" id="user-index-name-description">
                <?php \esc_html_e( 'RediSearch user index name, must be unique within the database.', 'redipress' ); ?>
            </p>
        <?php
    }

    /**
     * Renders the include author name in search field.
     */
    private function render_disable_post_author_search_field() {
        $name   = 'disable_post_author_search';
        $option = self::get( $name );
        ?>
            <input type="hidden" name="redipress_disable_post_author_search" value="1" />
            <input type="checkbox" name="redipress_disable_post_author_search" id="redipress_disable_post_author_search" value="1" <?php \checked( 1, $option ) . $this->disabled( $name ); ?>>
            <p class="description" id="disable-post-author-search-description">
                <?php
                \esc_html_e( 'Whether to disable including post author display name in the search index or not.', 'redipress' );
                ?>
            </p>
        <?php
    }

    /**
     * Renders the post types section.
     */
    private function render_post_types_section() {
        ?>
            <p><?php \esc_html_e( 'Select the post types that will be included in the search results.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the post types field.
     */
    private function render_post_types_field( $args ) {
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
    private function render_taxonomies_section() {
        ?>
            <p><?php \esc_html_e( 'Select the taxonomies that will be included in the search results.', 'redipress' ); ?></p>
        <?php
    }

    /**
     * Renders the taxonomies field.
     */
    private function render_taxonomies_field( array $args ) {
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
