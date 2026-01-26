<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HSP_Cache_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_hsp_cache_clear', array( __CLASS__, 'handle_clear_cache' ) );
        add_action( 'admin_post_hsp_cache_run_tests', array( __CLASS__, 'handle_run_tests' ) );
        add_action( 'admin_post_hsp_cache_clear_current', array( __CLASS__, 'handle_clear_current' ) );
        add_action( 'admin_post_hsp_cache_clear_all', array( __CLASS__, 'handle_clear_all' ) );
        add_action( 'admin_post_hsp_cache_rebuild_current', array( __CLASS__, 'handle_rebuild_current' ) );
        add_action( 'admin_post_hsp_cache_rebuild_all', array( __CLASS__, 'handle_rebuild_all' ) );
        add_action( 'admin_post_hsp_cache_restore_defaults', array( __CLASS__, 'handle_restore_defaults' ) );
        add_action( 'admin_post_hsp_cache_run_preload', array( __CLASS__, 'handle_run_preload' ) );
        add_action( 'admin_post_hsp_cache_run_db_cleanup', array( __CLASS__, 'handle_run_db_cleanup' ) );
        add_action( 'admin_post_hsp_cache_optimize_db', array( __CLASS__, 'handle_optimize_db' ) );
        add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar' ), 100 );
        add_action( 'update_option_' . HSP_Cache_Settings::OPTION_KEY, array( __CLASS__, 'handle_settings_update' ), 10, 2 );
    }

    public static function register_menu() {
        add_options_page(
            __( 'HSP Smart Cache', 'hsp-smart-cache' ),
            __( 'HSP Smart Cache', 'hsp-smart-cache' ),
            'manage_options',
            'hsp-smart-cache',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function handle_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
        }
        check_admin_referer( 'hsp_cache_clear' );
        HSP_Cache_Plugin::flush_all_caches();
        wp_safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&cache=cleared' ) );
        exit;
    }

    public static function handle_clear_current() {
        self::ensure_admin_bar_access( 'hsp_cache_clear_current' );
        $url = self::get_target_url();
        if ( $url ) {
            HSP_Cache_Page::clear_cache_for_url( $url );
        }
        self::redirect_back();
    }

    public static function handle_clear_all() {
        self::ensure_admin_bar_access( 'hsp_cache_clear_all' );
        HSP_Cache_Plugin::flush_all_caches();
        self::redirect_back();
    }

    public static function handle_rebuild_current() {
        self::ensure_admin_bar_access( 'hsp_cache_rebuild_current' );
        $url = self::get_target_url();
        if ( $url ) {
            HSP_Cache_Page::clear_cache_for_url( $url );
            HSP_Cache_Page::warm_url( $url );
        }
        self::redirect_back();
    }

    public static function handle_rebuild_all() {
        self::ensure_admin_bar_access( 'hsp_cache_rebuild_all' );
        HSP_Cache_Plugin::flush_all_caches();
        HSP_Cache_Page::warm_urls( array( home_url( '/' ) ) );
        if ( HSP_Cache_Settings::get( 'preload_enabled' ) ) {
            HSP_Cache_Preload::run();
        }
        self::redirect_back();
    }

    public static function register_admin_bar( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $wp_admin_bar->add_node(
            array(
                'id'    => 'hsp-cache',
                'title' => __( 'HSP Cache', 'hsp-smart-cache' ),
                'href'  => admin_url( 'options-general.php?page=hsp-smart-cache' ),
            )
        );

        $is_frontend = ! is_admin();

        if ( $is_frontend ) {
            $wp_admin_bar->add_node(
                array(
                    'id'     => 'hsp-cache-clear-current',
                    'parent' => 'hsp-cache',
                    'title'  => __( 'Delete cache of current page', 'hsp-smart-cache' ),
                    'href'   => self::action_url( 'hsp_cache_clear_current' ),
                )
            );
            $wp_admin_bar->add_node(
                array(
                    'id'     => 'hsp-cache-rebuild-current',
                    'parent' => 'hsp-cache',
                    'title'  => __( 'Rebuild current page cache', 'hsp-smart-cache' ),
                    'href'   => self::action_url( 'hsp_cache_rebuild_current' ),
                )
            );
        }

        $wp_admin_bar->add_node(
            array(
                'id'     => 'hsp-cache-clear-all',
                'parent' => 'hsp-cache',
                'title'  => __( 'Delete all cache files', 'hsp-smart-cache' ),
                'href'   => self::action_url( 'hsp_cache_clear_all' ),
            )
        );

        $wp_admin_bar->add_node(
            array(
                'id'     => 'hsp-cache-rebuild-all',
                'parent' => 'hsp-cache',
                'title'  => __( 'Rebuild all caches', 'hsp-smart-cache' ),
                'href'   => self::action_url( 'hsp_cache_rebuild_all' ),
            )
        );
    }

    protected static function action_url( $action ) {
        $args = array(
            'action' => $action,
            '_wpnonce' => wp_create_nonce( $action ),
        );

        $url = admin_url( 'admin-post.php' );
        $referer = wp_get_referer();
        if ( $referer ) {
            $args['hsp_return'] = rawurlencode( $referer );
        }

        return add_query_arg( $args, $url );
    }

    protected static function get_target_url() {
        $return = filter_input( INPUT_GET, 'hsp_return', FILTER_UNSAFE_RAW );
        if ( ! empty( $return ) ) {
            $return = rawurldecode( $return );
            return esc_url_raw( $return );
        }
        $referer = wp_get_referer();
        if ( $referer ) {
            return esc_url_raw( $referer );
        }
        return home_url( '/' );
    }

    protected static function redirect_back() {
        $url = self::get_target_url();
        wp_safe_redirect( $url );
        exit;
    }

    protected static function ensure_admin_bar_access( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
        }
        check_admin_referer( $nonce_action );
    }

    public static function handle_run_tests() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
        }
        check_admin_referer( 'hsp_cache_run_tests' );
        $results = HSP_Cache_Tests::run();
        set_transient( 'hsp_cache_test_results', $results, 300 );
        wp_safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&tests=done' ) );
        exit;
    }

    public static function handle_restore_defaults() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
        }
        check_admin_referer( 'hsp_cache_restore_defaults' );
        update_option( HSP_Cache_Settings::OPTION_KEY, HSP_Cache_Settings::defaults() );
        HSP_Cache_Object::sync_dropin();
        HSP_Cache_Page::clear_cache();
        HSP_Cache_Minify::clear_cache();
        wp_safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&settings=restored' ) );
        exit;
    }

    public static function handle_run_preload() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
        }
        check_admin_referer( 'hsp_cache_run_preload' );
        $result = HSP_Cache_Preload::run();
        set_transient( 'hsp_cache_preload_result', $result, 300 );
        wp_safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&preload=done' ) );
        exit;
    }

    public static function handle_run_db_cleanup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
        }
        check_admin_referer( 'hsp_cache_run_db_cleanup' );
        HSP_Cache_Maintenance::run_db_cleanup();
        wp_safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=cleaned' ) );
        exit;
    }

    public static function handle_optimize_db() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'hsp-smart-cache' ) );
        }
        check_admin_referer( 'hsp_cache_optimize_db' );
        HSP_Cache_Maintenance::optimize_tables();
        wp_safe_redirect( admin_url( 'options-general.php?page=hsp-smart-cache&db=optimized' ) );
        exit;
    }

    public static function handle_settings_update( $old_value, $new_value ) {
        HSP_Cache_Object::sync_dropin();
        HSP_Cache_Page::clear_cache();
        HSP_Cache_Minify::clear_cache();
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options = get_option( HSP_Cache_Settings::OPTION_KEY, HSP_Cache_Settings::defaults() );
        $options = wp_parse_args( $options, HSP_Cache_Settings::defaults() );
        $test_results = get_transient( 'hsp_cache_test_results' );
        $cache_notice = filter_input( INPUT_GET, 'cache', FILTER_UNSAFE_RAW );
        $settings_notice = filter_input( INPUT_GET, 'settings', FILTER_UNSAFE_RAW );
        $preload_notice = filter_input( INPUT_GET, 'preload', FILTER_UNSAFE_RAW );
        $db_notice = filter_input( INPUT_GET, 'db', FILTER_UNSAFE_RAW );
        $tests_notice = filter_input( INPUT_GET, 'tests', FILTER_UNSAFE_RAW );

        $cache_notice = $cache_notice ? sanitize_key( $cache_notice ) : '';
        $settings_notice = $settings_notice ? sanitize_key( $settings_notice ) : '';
        $preload_notice = $preload_notice ? sanitize_key( $preload_notice ) : '';
        $db_notice = $db_notice ? sanitize_key( $db_notice ) : '';
        $tests_notice = $tests_notice ? sanitize_key( $tests_notice ) : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'HSP Smart Cache', 'hsp-smart-cache' ); ?></h1>
            <?php if ( $cache_notice === 'cleared' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Cache cleared.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $settings_notice === 'restored' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Defaults restored.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $preload_notice === 'done' ) : ?>
                <?php $preload_result = get_transient( 'hsp_cache_preload_result' ); ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Preload completed.', 'hsp-smart-cache' ); ?> <?php if ( is_array( $preload_result ) ) { /* translators: %d is the number of warmed URLs. */ echo esc_html( sprintf( __( '%d URLs warmed.', 'hsp-smart-cache' ), $preload_result['count'] ) ); } ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'cleaned' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database cleanup completed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $db_notice === 'optimized' ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__( 'Database optimization completed.', 'hsp-smart-cache' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $tests_notice === 'done' && is_array( $test_results ) ) : ?>
                <div class="notice notice-info">
                    <p><?php echo esc_html__( 'Cache tests completed.', 'hsp-smart-cache' ); ?></p>
                    <ul>
                        <?php foreach ( $test_results as $result ) : ?>
                            <li>
                                <?php echo $result['status'] ? '✅' : '❌'; ?>
                                <?php echo esc_html( $result['label'] ); ?>
                                <?php if ( ! empty( $result['details'] ) ) : ?>
                                    - <?php echo esc_html( $result['details'] ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'hsp_cache_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Page Cache', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[page_cache]" value="1" <?php checked( $options['page_cache'] ); ?> title="<?php echo esc_attr__( 'Cache full HTML pages for visitors.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable page caching for anonymous visitors', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Cache TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[page_cache_ttl]" value="<?php echo esc_attr( $options['page_cache_ttl'] ); ?>" title="<?php echo esc_attr__( 'How long a page stays cached before regeneration.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[cache_logged_in]" value="1" <?php checked( $options['cache_logged_in'] ); ?> title="<?php echo esc_attr__( 'Enable only if logged-in users see cache-safe content.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Allow page caching for logged-in users', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Can serve private data if enabled on sites with personalized content.', 'hsp-smart-cache' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Browser Cache Headers', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[browser_cache]" value="1" <?php checked( $options['browser_cache'] ); ?> title="<?php echo esc_attr__( 'Send Cache-Control and ETag headers for cached pages.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Send Cache-Control headers for cacheable pages', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'HTML cache TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[browser_cache_html_ttl]" value="<?php echo esc_attr( $options['browser_cache_html_ttl'] ); ?>" title="<?php echo esc_attr__( 'Browser cache lifetime for HTML pages.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Asset cache TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[browser_cache_asset_ttl]" value="<?php echo esc_attr( $options['browser_cache_asset_ttl'] ); ?>" title="<?php echo esc_attr__( 'Browser cache lifetime for static assets.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Static Asset Caching', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[static_asset_cache]" value="1" <?php checked( $options['static_asset_cache'] ); ?> title="<?php echo esc_attr__( 'Enable long-lived cache headers for CSS/JS/fonts/images.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable browser caching for WordPress/theme/plugin assets', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Static asset TTL (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="60" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[static_asset_ttl]" value="<?php echo esc_attr( $options['static_asset_ttl'] ); ?>" title="<?php echo esc_attr__( 'Cache lifetime for assets via web server rules.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[static_asset_immutable]" value="1" <?php checked( $options['static_asset_immutable'] ); ?> title="<?php echo esc_attr__( 'Add immutable directive to encourage long-term caching.', 'hsp-smart-cache' ); ?>" />
                                    <?php echo esc_html__( 'Add immutable directive where supported', 'hsp-smart-cache' ); ?>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[static_asset_auto_write]" value="1" <?php checked( $options['static_asset_auto_write'] ); ?> title="<?php echo esc_attr__( 'Automatically update .htaccess if writable.', 'hsp-smart-cache' ); ?>" />
                                    <?php echo esc_html__( 'Auto-write .htaccess rules when possible', 'hsp-smart-cache' ); ?>
                                </label>
                            </p>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Incorrect .htaccess rules can break site access. Use with care.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[static_asset_compression]" value="1" <?php checked( $options['static_asset_compression'] ); ?> title="<?php echo esc_attr__( 'Enable gzip/deflate compression via web server rules.', 'hsp-smart-cache' ); ?>" />
                                    <?php echo esc_html__( 'Enable gzip/deflate compression', 'hsp-smart-cache' ); ?>
                                </label>
                            </p>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: If your server already manages compression, double-compression can cause issues.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label><?php echo esc_html__( 'Apache .htaccess snippet', 'hsp-smart-cache' ); ?></label><br />
                                <textarea
                                    class="large-text"
                                    rows="8"
                                    readonly
                                    id="hsp-htaccess-preview"
                                    data-template-cache="<?php echo esc_attr( HSP_Cache_Static_Assets::get_htaccess_rules( 0, false, false ) ); ?>"
                                    data-template-cache-immutable="<?php echo esc_attr( HSP_Cache_Static_Assets::get_htaccess_rules( 0, true, false ) ); ?>"
                                    data-template-cache-compress="<?php echo esc_attr( HSP_Cache_Static_Assets::get_htaccess_rules( 0, false, true ) ); ?>"
                                    data-template-cache-immutable-compress="<?php echo esc_attr( HSP_Cache_Static_Assets::get_htaccess_rules( 0, true, true ) ); ?>"
                                ><?php echo esc_textarea( HSP_Cache_Static_Assets::get_htaccess_rules( $options['static_asset_ttl'], $options['static_asset_immutable'], $options['static_asset_compression'] ) ); ?></textarea>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Render Blocking Optimization', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_defer_js]" value="1" <?php checked( $options['render_defer_js'] ); ?> title="<?php echo esc_attr__( 'Add defer to scripts to reduce render blocking.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Add defer to enqueued scripts', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Deferring scripts may break plugins that expect blocking execution.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Defer exclusions (script handles, one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_defer_exclusions]" placeholder="jquery\nwp-embed" title="<?php echo esc_attr__( 'Script handles that must not be deferred.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_defer_exclusions'] ); ?></textarea>
                                </label>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_async_js]" value="1" <?php checked( $options['render_async_js'] ); ?> title="<?php echo esc_attr__( 'Use async when defer is disabled.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Add async to enqueued scripts (if defer is off)', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Async can change execution order and break dependencies.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Async exclusions (script handles, one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_async_exclusions]" placeholder="jquery\nwp-embed" title="<?php echo esc_attr__( 'Script handles that must not be async.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_async_exclusions'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Preconnect URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_preconnect_urls]" placeholder="https://fonts.gstatic.com" title="<?php echo esc_attr__( 'Origins to preconnect for faster TLS/handshake.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preconnect_urls'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Preload font URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_preload_fonts]" placeholder="https://example.com/fonts/myfont.woff2" title="<?php echo esc_attr__( 'Font files to preload early.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preload_fonts'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Preload CSS URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_preload_css]" placeholder="https://example.com/style.css" title="<?php echo esc_attr__( 'Stylesheets to preload before render.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_preload_css'] ); ?></textarea>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Inline critical CSS', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="5" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[render_critical_css]" placeholder="/* Critical CSS */" title="<?php echo esc_attr__( 'CSS injected inline to speed up first render.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['render_critical_css'] ); ?></textarea>
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Additional Performance', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[perf_lazy_images]" value="1" <?php checked( $options['perf_lazy_images'] ); ?> title="<?php echo esc_attr__( 'Adds loading="lazy" to images where safe.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable native lazy-loading for images', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[perf_lazy_iframes]" value="1" <?php checked( $options['perf_lazy_iframes'] ); ?> title="<?php echo esc_attr__( 'Adds loading="lazy" to iframes where safe.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable native lazy-loading for iframes', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[perf_decoding_async]" value="1" <?php checked( $options['perf_decoding_async'] ); ?> title="<?php echo esc_attr__( 'Hints browser to decode images asynchronously.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Add decoding="async" to images', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[perf_disable_emojis]" value="1" <?php checked( $options['perf_disable_emojis'] ); ?> title="<?php echo esc_attr__( 'Remove emoji scripts and styles for faster loads.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Disable emoji scripts and styles', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[perf_disable_embeds]" value="1" <?php checked( $options['perf_disable_embeds'] ); ?> title="<?php echo esc_attr__( 'Disable oEmbed discovery and scripts.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Disable WordPress embeds', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[perf_disable_dashicons]" value="1" <?php checked( $options['perf_disable_dashicons'] ); ?> title="<?php echo esc_attr__( 'Remove Dashicons for non-logged-in visitors.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Disable Dashicons for guests', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Disabling embeds or Dashicons can affect themes or plugins that rely on them.', 'hsp-smart-cache' ); ?>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'DNS prefetch URLs (one per line)', 'hsp-smart-cache' ); ?>
                                    <textarea class="large-text" rows="3" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[perf_dns_prefetch_urls]" placeholder="//fonts.googleapis.com" title="<?php echo esc_attr__( 'Hostnames to DNS-prefetch.', 'hsp-smart-cache' ); ?>"><?php echo esc_textarea( $options['perf_dns_prefetch_urls'] ); ?></textarea>
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Cache Preload', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[preload_enabled]" value="1" <?php checked( $options['preload_enabled'] ); ?> title="<?php echo esc_attr__( 'Allow manual preload of URLs from sitemap.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable cache preloading', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Sitemap URL', 'hsp-smart-cache' ); ?>
                                    <input type="url" class="regular-text" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[preload_sitemap_url]" value="<?php echo esc_attr( $options['preload_sitemap_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/sitemap.xml' ) ); ?>" title="<?php echo esc_attr__( 'Sitemap to pull URLs from for warming.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Max URLs to warm', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="1" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[preload_limit]" value="<?php echo esc_attr( $options['preload_limit'] ); ?>" title="<?php echo esc_attr__( 'Limit number of URLs warmed per run.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'Request timeout (seconds)', 'hsp-smart-cache' ); ?>
                                    <input type="number" min="3" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[preload_timeout]" value="<?php echo esc_attr( $options['preload_timeout'] ); ?>" title="<?php echo esc_attr__( 'Timeout per warmed URL.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Minification', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[minify_html]" value="1" <?php checked( $options['minify_html'] ); ?> title="<?php echo esc_attr__( 'Remove whitespace/comments from HTML.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Minify HTML', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[minify_css]" value="1" <?php checked( $options['minify_css'] ); ?> title="<?php echo esc_attr__( 'Create minified CSS files in the cache.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Minify CSS files', 'hsp-smart-cache' ); ?>
                            </label><br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[minify_js]" value="1" <?php checked( $options['minify_js'] ); ?> title="<?php echo esc_attr__( 'Create minified JS files in the cache.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Minify JS files', 'hsp-smart-cache' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Object Cache', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[object_cache]" value="1" <?php checked( $options['object_cache'] ); ?> title="<?php echo esc_attr__( 'Use file-based persistent object cache drop-in.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable file-based persistent object cache (drop-in)', 'hsp-smart-cache' ); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php echo esc_html__( 'Warning: Object cache drop-ins can conflict with other cache plugins.', 'hsp-smart-cache' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'CDN', 'hsp-smart-cache' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[cdn_enabled]" value="1" <?php checked( $options['cdn_enabled'] ); ?> title="<?php echo esc_attr__( 'Rewrite static asset URLs to your CDN.', 'hsp-smart-cache' ); ?>" />
                                <?php echo esc_html__( 'Enable CDN URL rewriting for static assets', 'hsp-smart-cache' ); ?>
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__( 'CDN Base URL', 'hsp-smart-cache' ); ?>
                                    <input type="url" class="regular-text" name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[cdn_url]" value="<?php echo esc_attr( $options['cdn_url'] ); ?>" placeholder="https://cdn.example.com" title="<?php echo esc_attr__( 'Base URL used for CDN rewriting.', 'hsp-smart-cache' ); ?>" />
                                </label>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />

            <style>
                .hsp-action-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                    gap: 16px;
                }
                .hsp-action-card {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 8px;
                    padding: 16px;
                }
                .hsp-action-card h2 {
                    margin: 0 0 12px;
                    font-size: 14px;
                }
                .hsp-action-card .button {
                    margin: 4px 0;
                }
                .hsp-action-card .description {
                    margin: 8px 0 0;
                }
            </style>

            <div class="hsp-action-grid">
                <div class="hsp-action-card">
                    <h2><?php echo esc_html__( 'Cache Actions', 'hsp-smart-cache' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="hsp_cache_clear" />
                        <?php wp_nonce_field( 'hsp_cache_clear' ); ?>
                        <?php submit_button( __( 'Clear All Caches', 'hsp-smart-cache' ), 'secondary' ); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="hsp_cache_run_tests" />
                        <?php wp_nonce_field( 'hsp_cache_run_tests' ); ?>
                        <?php submit_button( __( 'Run Cache Tests', 'hsp-smart-cache' ), 'secondary' ); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="hsp_cache_restore_defaults" />
                        <?php wp_nonce_field( 'hsp_cache_restore_defaults' ); ?>
                        <?php submit_button( __( 'Restore Defaults', 'hsp-smart-cache' ), 'secondary' ); ?>
                    </form>
                </div>

                <div class="hsp-action-card">
                    <h2><?php echo esc_html__( 'Cache Preload', 'hsp-smart-cache' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="hsp_cache_run_preload" />
                        <?php wp_nonce_field( 'hsp_cache_run_preload' ); ?>
                        <?php submit_button( __( 'Run Cache Preload', 'hsp-smart-cache' ), 'secondary' ); ?>
                    </form>
                </div>

                <div class="hsp-action-card">
                    <h2><?php echo esc_html__( 'Database Maintenance', 'hsp-smart-cache' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="hsp_cache_run_db_cleanup" />
                        <?php wp_nonce_field( 'hsp_cache_run_db_cleanup' ); ?>
                        <?php submit_button( __( 'Run Database Cleanup', 'hsp-smart-cache' ), 'secondary' ); ?>
                    </form>
                    <p class="description" style="color:#b32d2e;">
                        <?php echo esc_html__( 'Warning: Database cleanup deletes revisions, trashed posts, and expired transients.', 'hsp-smart-cache' ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="hsp_cache_optimize_db" />
                        <?php wp_nonce_field( 'hsp_cache_optimize_db' ); ?>
                        <?php submit_button( __( 'Optimize Database Tables', 'hsp-smart-cache' ), 'secondary' ); ?>
                    </form>
                    <p class="description" style="color:#b32d2e;">
                        <?php echo esc_html__( 'Warning: Table optimization can be slow on large databases.', 'hsp-smart-cache' ); ?>
                    </p>
                </div>
            </div>
            <script>
                (function() {
                    var preview = document.getElementById('hsp-htaccess-preview');
                    if (!preview) { return; }

                    function getVal(name) {
                        var el = document.querySelector('[name="<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[' + name + ']"]');
                        if (!el) { return null; }
                        if (el.type === 'checkbox') { return el.checked; }
                        return el.value;
                    }

                    function updatePreview() {
                        var ttl = parseInt(getVal('static_asset_ttl'), 10);
                        if (isNaN(ttl) || ttl < 60) { ttl = 60; }

                        var immutable = !!getVal('static_asset_immutable');
                        var compress = !!getVal('static_asset_compression');
                        var enabled = !!getVal('static_asset_cache');

                        if (!enabled) {
                            preview.value = '';
                            return;
                        }

                        var template = preview.dataset.templateCache;
                        if (immutable && compress) {
                            template = preview.dataset.templateCacheImmutableCompress;
                        } else if (immutable) {
                            template = preview.dataset.templateCacheImmutable;
                        } else if (compress) {
                            template = preview.dataset.templateCacheCompress;
                        }

                        preview.value = template.replace('max-age=0', 'max-age=' + ttl);
                    }

                    document.addEventListener('input', function(e) {
                        if (!e.target || !e.target.name) { return; }
                        if (e.target.name.indexOf('<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[static_asset_') === 0) {
                            updatePreview();
                        }
                    });

                    document.addEventListener('change', function(e) {
                        if (!e.target || !e.target.name) { return; }
                        if (e.target.name.indexOf('<?php echo esc_attr( HSP_Cache_Settings::OPTION_KEY ); ?>[static_asset_') === 0) {
                            updatePreview();
                        }
                    });

                    updatePreview();
                })();
            </script>
        </div>
        <?php
    }
}
