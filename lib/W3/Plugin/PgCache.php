<?php

/**
 * W3 PgCache plugin
 */
require_once W3TC_LIB_W3_DIR . '/Plugin.php';

/**
 * Class W3_Plugin_PgCache
 */
class W3_Plugin_PgCache extends W3_Plugin {
    /**
     * Runs plugin
     */
    function run() {
        register_activation_hook(W3TC_FILE, array(
            &$this,
            'activate'
        ));

        register_deactivation_hook(W3TC_FILE, array(
            &$this,
            'deactivate'
        ));

        add_filter('cron_schedules', array(
            &$this,
            'cron_schedules'
        ));

        if ($this->_config->get_boolean('pgcache.enabled')) {
            if ($this->_config->get_string('pgcache.engine') == 'file' || $this->_config->get_string('pgcache.engine') == 'file_pgcache') {
                add_action('w3_pgcache_cleanup', array(
                    &$this,
                    'cleanup'
                ));
            }

            add_action('w3_pgcache_prime', array(
                &$this,
                'prime'
            ));

            add_action('publish_phone', array(
                &$this,
                'on_post_edit'
            ), 0);

            add_action('publish_post', array(
                &$this,
                'on_post_edit'
            ), 0);

            add_action('edit_post', array(
                &$this,
                'on_post_change'
            ), 0);

            add_action('delete_post', array(
                &$this,
                'on_post_edit'
            ), 0);

            add_action('comment_post', array(
                &$this,
                'on_comment_change'
            ), 0);

            add_action('edit_comment', array(
                &$this,
                'on_comment_change'
            ), 0);

            add_action('delete_comment', array(
                &$this,
                'on_comment_change'
            ), 0);

            add_action('wp_set_comment_status', array(
                &$this,
                'on_comment_status'
            ), 0, 2);

            add_action('trackback_post', array(
                &$this,
                'on_comment_change'
            ), 0);

            add_action('pingback_post', array(
                &$this,
                'on_comment_change'
            ), 0);

            add_action('switch_theme', array(
                &$this,
                'on_change'
            ), 0);

            add_action('edit_user_profile_update', array(
                &$this,
                'on_change'
            ), 0);
        }
    }

    /**
     * Returns plugin instance
     *
     * @return W3_Plugin_PgCache
     */
    function &instance() {
        static $instances = array();

        if (!isset($instances[0])) {
            $class = __CLASS__;
            $instances[0] = & new $class();
        }

        return $instances[0];
    }

    /**
     * Activate plugin action
     */
    function activate() {
        if ($this->_config->get_boolean('pgcache.enabled') && $this->_config->get_string('pgcache.engine') == 'file_pgcache') {
            /**
             * Disable enchanged mode if permalink structure is disabled
             */
            $permalink_structure = get_option('permalink_structure');

            if ($permalink_structure == '') {
                $this->_config->set('pgcache.engine', 'file');
                $this->_config->save();
            } else {
                if (w3_can_modify_rules(w3_get_pgcache_rules_core_path())) {
                    $this->write_rules_core();
                }

                if (w3_can_modify_rules(w3_get_pgcache_rules_cache_path())) {
                    $this->write_rules_cache();
                }
            }
        }

        if (!$this->locked()) {
            if (!@copy(W3TC_INSTALL_FILE_ADVANCED_CACHE, W3TC_ADDIN_FILE_ADVANCED_CACHE)) {
                w3_writable_error(W3TC_ADDIN_FILE_ADVANCED_CACHE);
            }

            if ((!defined('WP_CACHE') || !WP_CACHE) && !$this->enable_wp_cache()) {
                $activate_url = wp_nonce_url('plugins.php?action=activate&plugin=' . W3TC_FILE, 'activate-plugin_' . W3TC_FILE);
                $reactivate_button = sprintf('<input type="button" value="re-activate plugin" onclick="top.location.href = \'%s\'" />', addslashes($activate_url));
                $error = sprintf('<strong>%swp-config.php</strong> could not be written, please edit config and add:<br /><strong style="color:#f00;">define(\'WP_CACHE\', true);</strong> before <strong style="color:#f00;">require_once(ABSPATH . \'wp-settings.php\');</strong><br />then %s.', ABSPATH, $reactivate_button);

                w3_activate_error($error);
            }
        }

        $this->schedule();
        $this->schedule_prime();
    }

    /**
     * Deactivate plugin action
     */
    function deactivate() {
        $this->unschedule_prime();
        $this->unschedule();

        if (!$this->locked()) {
            $this->disable_wp_cache();
            @unlink(W3TC_ADDIN_FILE_ADVANCED_CACHE);
        }

        if (w3_can_modify_rules(w3_get_pgcache_rules_cache_path())) {
            $this->remove_rules_cache();
        }

        if (w3_can_modify_rules(w3_get_pgcache_rules_core_path())) {
            $this->remove_rules_core();
        }
    }

    /**
     * Schedules events
     */
    function schedule() {
        if ($this->_config->get_boolean('pgcache.enabled') && ($this->_config->get_string('pgcache.engine') == 'file' || $this->_config->get_string('pgcache.engine') == 'file_pgcache')) {
            if (!wp_next_scheduled('w3_pgcache_cleanup')) {
                wp_schedule_event(time(), 'w3_pgcache_cleanup', 'w3_pgcache_cleanup');
            }
        } else {
            $this->unschedule();
        }
    }

    /**
     * Schedule prime event
     */
    function schedule_prime() {
        if ($this->_config->get_boolean('pgcache.enabled') && $this->_config->get_boolean('pgcache.prime.enabled')) {
            if (!wp_next_scheduled('w3_pgcache_prime')) {
                wp_schedule_event(time(), 'w3_pgcache_prime', 'w3_pgcache_prime');
            }
        } else {
            $this->unschedule_prime();
        }
    }

    /**
     * Unschedules events
     */
    function unschedule() {
        if (wp_next_scheduled('w3_pgcache_cleanup')) {
            wp_clear_scheduled_hook('w3_pgcache_cleanup');
        }
    }

    /**
     * Unschedules prime
     */
    function unschedule_prime() {
        if (wp_next_scheduled('w3_pgcache_prime')) {
            wp_clear_scheduled_hook('w3_pgcache_prime');
        }
    }

    /**
     * Erases WP_CACHE define
     *
     * @param string $content
     * @return mixed
     */
    function erase_wp_cache($content) {
        $content = preg_replace("~\r\n\\/\\*\\* Enable W3 Total Cache \\*\\*?\\/.*?\\/\\/ Added by W3 Total Cache\r\n~s", '', $content);
        $content = preg_replace("~(\\/\\/\\s*)?define\\s*\\(\\s*['\"]?WP_CACHE['\"]?\\s*,.*?\\)\\s*;+\\r?\\n?~is", '', $content);

        return $content;
    }

    /**
     * Enables WP_CACHE
     *
     * @return boolean
     */
    function enable_wp_cache() {
        $config_path = w3_get_wp_config_path();
        $config_data = @file_get_contents($config_path);

        if ($config_data === false) {
            return false;
        }

        $new_config_data = $this->erase_wp_cache($config_data);
        $new_config_data = preg_replace('~<\?(php)?~', "\\0\r\n/** Enable W3 Total Cache */\r\ndefine('WP_CACHE', true); // Added by W3 Total Cache\r\n", $new_config_data, 1);

        if ($new_config_data != $config_data) {
            if (!@file_put_contents($config_path, $new_config_data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Disables WP_CACHE
     *
     * @return bool
     */
    function disable_wp_cache() {
        $config_path = w3_get_wp_config_path();
        $config_data = @file_get_contents($config_path);

        if ($config_data === false) {
            return false;
        }

        $new_config_data = $this->erase_wp_cache($config_data);

        if ($new_config_data != $config_data) {
            if (!@file_put_contents($config_path, $new_config_data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does disk cache cleanup
     *
     * @return void
     */
    function cleanup() {
        $engine = $this->_config->get_string('pgcache.engine');

        switch ($engine) {
            case 'file':
                require_once W3TC_LIB_W3_DIR . '/Cache/File/Manager.php';

                $w3_cache_file_manager = & new W3_Cache_File_Manager(array(
                    'cache_dir' => W3TC_CACHE_FILE_PGCACHE_DIR,
                    'clean_timelimit' => $this->_config->get_integer('timelimit.cache_gc')
                ));

                $w3_cache_file_manager->clean();
                break;

            case 'file_pgcache':
                require_once W3TC_LIB_W3_DIR . '/Cache/File/PgCache/Manager.php';

                $w3_cache_file_pgcache_manager = & new W3_Cache_File_PgCache_Manager(array(
                    'cache_dir' => W3TC_CACHE_FILE_PGCACHE_DIR,
                    'expire' => $this->_config->get_integer('browsercache.html.lifetime'),
                    'clean_timelimit' => $this->_config->get_integer('timelimit.cache_gc')
                ));

                $w3_cache_file_pgcache_manager->clean();
                break;
        }
    }

    /**
     * Prime cache
     *
     * @param integer $start
     * @return void
     */
    function prime($start = 0) {
        /**
         * Don't start cache prime if queues are still scheduled
         */
        if ($start == 0) {
            $crons = _get_cron_array();

            foreach ($crons as $timestamp => $hooks) {
                foreach ($hooks as $hook => $keys) {
                    foreach ($keys as $key => $data) {
                        if ($hook == 'w3_pgcache_prime' && count($data['args'])) {
                            return;
                        }
                    }
                }
            }
        }

        $interval = $this->_config->get_integer('pgcache.prime.interval');
        $limit = $this->_config->get_integer('pgcache.prime.limit');
        $sitemap_url = $this->_config->get_string('pgcache.prime.sitemap');
        $sitemap_xml = w3_http_get($sitemap_url);

        $queue = array();

        /**
         * Parse XML sitemap
         */
        if ($sitemap_xml) {
            $url_matches = null;

            if (preg_match_all('~<url>(.*)</url>~Uis', $sitemap_xml, $url_matches)) {
                $loc_matches = null;
                $priority_matches = null;

                $locs = array();

                foreach ($url_matches[1] as $url_match) {
                    $loc = '';
                    $priority = 0;

                    if (preg_match('~<loc>(.*)</loc>~is', $url_match, $loc_matches)) {
                        $loc = trim($loc_matches[1]);
                    }

                    if (preg_match('~<priority>(.*)</priority>~is', $url_match, $priority_matches)) {
                        $priority = (double) trim($priority_matches[1]);
                    }

                    if ($loc && $priority) {
                        $locs[$loc] = $priority;
                    }
                }

                arsort($locs);

                $queue = array_keys($locs);
            }
        }

        /**
         * Queue URLs
         */
        $urls = array_slice($queue, $start, $limit);

        if (count($queue) > ($start + $limit)) {
            wp_schedule_single_event(time() + $interval, 'w3_pgcache_prime', array(
                $start + $limit
            ));
        }

        /**
         * Make HTTP requests and prime cache
         */
        foreach ($urls as $url) {
            w3_http_get($url);
        }
    }

    /**
     * Cron schedules filter
     *
     * @paran array $schedules
     * @return array
     */
    function cron_schedules($schedules) {
        $gc_interval = $this->_config->get_integer('pgcache.file.gc');
        $prime_interval = $this->_config->get_integer('pgcache.prime.interval');

        return array_merge($schedules, array(
            'w3_pgcache_cleanup' => array(
                'interval' => $gc_interval,
                'display' => sprintf('[W3TC] Page Cache file GC (every %d seconds)', $gc_interval)
            ),
            'w3_pgcache_prime' => array(
                'interval' => $prime_interval,
                'display' => sprintf('[W3TC] Page Cache prime (every %d seconds)', $prime_interval)
            )
        ));
    }

    /**
     * Post edit action
     *
     * @param integer $post_id
     */
    function on_post_edit($post_id) {
        if ($this->_config->get_boolean('pgcache.cache.flush')) {
            $this->on_change();
        } else {
            $this->on_post_change($post_id);
        }
    }

    /**
     * Post change action
     *
     * @param integer $post_id
     */
    function on_post_change($post_id) {
        static $flushed_posts = array();

        if (!in_array($post_id, $flushed_posts)) {
            require_once W3TC_LIB_W3_DIR . '/PgCache.php';

            $w3_pgcache = & W3_PgCache::instance();
            $w3_pgcache->flush_post($post_id);

            $flushed_posts[] = $post_id;
        }
    }

    /**
     * Comment change action
     *
     * @param integer $comment_id
     */
    function on_comment_change($comment_id) {
        $post_id = 0;

        if ($comment_id) {
            $comment = get_comment($comment_id, ARRAY_A);
            $post_id = !empty($comment['comment_post_ID']) ? (int) $comment['comment_post_ID'] : 0;
        }

        $this->on_post_change($post_id);
    }

    /**
     * Comment status action
     *
     * @param integer $comment_id
     * @param string $status
     */
    function on_comment_status($comment_id, $status) {
        if ($status === 'approve' || $status === '1') {
            $this->on_comment_change($comment_id);
        }
    }

    /**
     * Change action
     */
    function on_change() {
        static $flushed = false;

        if (!$flushed) {
            require_once W3TC_LIB_W3_DIR . '/PgCache.php';

            $w3_pgcache = & W3_PgCache::instance();
            $w3_pgcache->flush();
        }
    }

    /**
     * Generates rules for WP dir
     *
     * @return string
     */
    function generate_rules_core() {
        switch (true) {
            case w3_is_apache():
                return $this->generate_rules_core_apache();

            case w3_is_nginx():
                return $this->generate_rules_core_nginx();
        }

        return false;
    }

    /**
     * Generates directives for file cache dir
     *
     * @return string
     */
    function generate_rules_cache() {
        switch (true) {
            case w3_is_apache():
                return $this->generate_rules_cache_apache();

            case w3_is_nginx():
                return $this->generate_rules_cache_nginx();
        }

        return false;
    }

    /**
     * Generates rules for WP dir
     *
     * @return string
     */
    function generate_rules_core_apache() {
        $is_network = w3_is_network();
        $is_vhost = w3_is_subdomain_install();

        $base_path = w3_get_base_path();
        $home_path = w3_get_home_path();
        $rewrite_base = ($is_network ? $base_path : $home_path);
        $cache_dir = w3_path(W3TC_CACHE_FILE_PGCACHE_DIR);
        $permalink_structure = get_option('permalink_structure');

        /**
         * Auto reject cookies
         */
        $reject_cookies = array(
            'comment_author',
            'wp-postpass'
        );

        /**
         * Auto reject URIs
         */
        $reject_uris = array(
            '\/wp-admin\/',
            '\/xmlrpc.php',
            '\/wp-(app|cron|login|register|mail)\.php'
        );

        /**
         * Reject cache for logged in users
         */
        if ($this->_config->get_boolean('pgcache.reject.logged')) {
            $reject_cookies = array_merge($reject_cookies, array(
                'wordpress_[a-f0-9]+',
                'wordpress_logged_in'
            ));
        }

        /**
         * Reject cache for home page
         */
        if (!$this->_config->get_boolean('pgcache.cache.home')) {
            $reject_uris[] = '^(\/|\/index.php)$';
        }

        /**
         * Reject cache for feeds
         */
        if (!$this->_config->get_boolean('pgcache.cache.feed')) {
            $reject_uris[] = '\/feed\/';
        }

        /**
         * Custom config
         */
        $reject_cookies = array_merge($reject_cookies, $this->_config->get_array('pgcache.reject.cookie'));
        $reject_uris = array_merge($reject_uris, $this->_config->get_array('pgcache.reject.uri'));
        $reject_uris = array_map('w3_parse_path', $reject_uris);
        $reject_user_agents = $this->_config->get_array('pgcache.reject.ua');
        $accept_files = $this->_config->get_array('pgcache.accept.files');

        /**
         * Generate directives
         */
        $rules = '';
        $rules .= W3TC_MARKER_BEGIN_PGCACHE_CORE . "\n";
        $rules .= "<IfModule mod_rewrite.c>\n";
        $rules .= "    RewriteEngine On\n";
        $rules .= "    RewriteBase " . $rewrite_base . "\n";
        $rules .= "    RewriteRule ^(.*\\/)?w3tc_rewrite_test$ $1?w3tc_rewrite_test=1 [L]\n";

        /**
         * Check for mobile redirect
         */
        if ($this->_config->get_boolean('mobile.enabled')) {
            $mobile_groups = $this->_config->get_array('mobile.rgroups');

            foreach ($mobile_groups as $mobile_group => $mobile_config) {
                $mobile_enabled = (isset($mobile_config['enabled']) ? (boolean) $mobile_config['enabled'] : false);
                $mobile_agents = (isset($mobile_config['agents']) ? (array) $mobile_config['agents'] : '');
                $mobile_redirect = (isset($mobile_config['redirect']) ? $mobile_config['redirect'] : '');

                if ($mobile_enabled && count($mobile_agents) && $mobile_redirect) {
                    $rules .= "    RewriteCond %{HTTP_USER_AGENT} (" . implode('|', $mobile_agents) . ") [NC]\n";
                    $rules .= "    RewriteRule .* " . $mobile_redirect . " [R,L]\n";
                }
            }
        }

        /**
         * Check for referrer redirect
         */
        if ($this->_config->get_boolean('referrer.enabled')) {
            $referrer_groups = $this->_config->get_array('referrer.rgroups');

            foreach ($referrer_groups as $referrer_group => $referrer_config) {
                $referrer_enabled = (isset($referrer_config['enabled']) ? (boolean) $referrer_config['enabled'] : false);
                $referrer_referrers = (isset($referrer_config['referrers']) ? (array) $referrer_config['referrers'] : '');
                $referrer_redirect = (isset($referrer_config['redirect']) ? $referrer_config['redirect'] : '');

                if ($referrer_enabled && count($referrer_referrers) && $referrer_redirect) {
                    $rules .= "    RewriteCond %{HTTP_COOKIE} w3tc_referrer=.*(" . implode('|', $referrer_referrers) . ") [NC]\n";
                    $rules .= "    RewriteRule .* " . $referrer_redirect . " [R,L]\n";
                }
            }
        }

        /**
         * Network mode rules
         */
        if ($is_network) {
            /**
             * Detect domain
             */
            $rules .= "    RewriteCond %{HTTP_HOST} ^(www\\.)?([a-z0-9\\-\\.]+\\.[a-z]+)\\.?(:[0-9]+)?$\n";
            $rules .= "    RewriteRule .* - [E=W3TC_DOMAIN:%2]\n";

            $replacement = '/w3tc-%{ENV:W3TC_DOMAIN}/';

            /**
             * If VHOST is off, detect blogname from URI
             */
            if (!$is_vhost) {
                $blognames = w3_get_blognames();

                if (count($blognames)) {
                    $rules .= "    RewriteCond %{REQUEST_URI} ^" . $base_path . "(" . implode('|', array_map('w3_preg_quote', $blognames)) . ")/\n";
                    $rules .= "    RewriteRule .* - [E=W3TC_BLOGNAME:%1.]\n";

                    $replacement = '/w3tc-%{ENV:W3TC_BLOGNAME}%{ENV:W3TC_DOMAIN}/';
                }
            }

            $cache_dir = preg_replace('~/w3tc.*/~U', $replacement, $cache_dir, 1);
        }

        /**
         * Set mobile groups
         */
        if ($this->_config->get_boolean('mobile.enabled')) {
            $mobile_groups = array_reverse($this->_config->get_array('mobile.rgroups'));

            foreach ($mobile_groups as $mobile_group => $mobile_config) {
                $mobile_enabled = (isset($mobile_config['enabled']) ? (boolean) $mobile_config['enabled'] : false);
                $mobile_agents = (isset($mobile_config['agents']) ? (array) $mobile_config['agents'] : '');
                $mobile_redirect = (isset($mobile_config['redirect']) ? $mobile_config['redirect'] : '');

                if ($mobile_enabled && count($mobile_agents) && !$mobile_redirect) {
                    $rules .= "    RewriteCond %{HTTP_USER_AGENT} (" . implode('|', $mobile_agents) . ") [NC]\n";
                    $rules .= "    RewriteRule .* - [E=W3TC_UA:_" . $mobile_group . "]\n";
                }
            }
        }

        /**
         * Set referrer groups
         */
        if ($this->_config->get_boolean('referrer.enabled')) {
            $referrer_groups = array_reverse($this->_config->get_array('referrer.rgroups'));

            foreach ($referrer_groups as $referrer_group => $referrer_config) {
                $referrer_enabled = (isset($referrer_config['enabled']) ? (boolean) $referrer_config['enabled'] : false);
                $referrer_referrers = (isset($referrer_config['referrers']) ? (array) $referrer_config['referrers'] : '');
                $referrer_redirect = (isset($referrer_config['redirect']) ? $referrer_config['redirect'] : '');

                if ($referrer_enabled && count($referrer_referrers) && !$referrer_redirect) {
                    $rules .= "    RewriteCond %{HTTP_COOKIE} w3tc_referrer=.*(" . implode('|', $referrer_referrers) . ") [NC]\n";
                    $rules .= "    RewriteRule .* - [E=W3TC_REF:_" . $referrer_group . "]\n";
                }
            }
        }

        /**
         * Set HTTPS
         */
        $rules .= "    RewriteCond %{HTTPS} =on\n";
        $rules .= "    RewriteRule .* - [E=W3TC_SSL:_ssl]\n";
        $rules .= "    RewriteCond %{SERVER_PORT} =443\n";
        $rules .= "    RewriteRule .* - [E=W3TC_SSL:_ssl]\n";

        /**
         * Set Accept-Encoding
         */
        if ($this->_config->get_boolean('browsercache.enabled') && $this->_config->get_boolean('browsercache.html.compression')) {
            $rules .= "    RewriteCond %{HTTP:Accept-Encoding} gzip\n";
            $rules .= "    RewriteRule .* - [E=W3TC_ENC:.gzip]\n";
        }

        /**
         * Don't accept POSTs
         */
        $rules .= "    RewriteCond %{REQUEST_METHOD} !=POST\n";

        /**
         * Query string should be empty
         */
        $rules .= "    RewriteCond %{QUERY_STRING} =\"\"\n";

        /**
         * Check permalink structure trailing slash
         */
        if (substr($permalink_structure, -1) == '/') {
            $rules .= "    RewriteCond %{REQUEST_URI} \\/$\n";
        } else {
            $rules .= "    RewriteCond %{REQUEST_URI} [^\\/]$\n";
        }

        /**
         * Don't accept rejected URIs
         */
        $rules .= "    RewriteCond %{REQUEST_URI} !(" . implode('|', $reject_uris) . ")";

        /**
         * Exclude files from rejected URIs list
         */
        if (count($accept_files)) {
            $rules .= " [NC,OR]\n    RewriteCond %{REQUEST_URI} (" . implode('|', array_map('w3_preg_quote', $accept_files)) . ") [NC]\n";
        } else {
            $rules .= " [NC]\n";
        }

        /**
         * Check for rejected cookies
         */
        $rules .= "    RewriteCond %{HTTP_COOKIE} !(" . implode('|', array_map('w3_preg_quote', $reject_cookies)) . ") [NC]\n";

        /**
         * Check for rejected user agents
         */
        if (count($reject_user_agents)) {
            $rules .= "    RewriteCond %{HTTP_USER_AGENT} !(" . implode('|', array_map('w3_preg_quote', $reject_user_agents)) . ") [NC]\n";
        }

        $cache_path = str_replace(w3_get_document_root(), '', $cache_dir);

        /**
         * Check if cache file exists
         */
        $rules .= "    RewriteCond \"%{DOCUMENT_ROOT}" . $cache_path . "/%{REQUEST_URI}/_index%{ENV:W3TC_UA}%{ENV:W3TC_REF}%{ENV:W3TC_SSL}.html%{ENV:W3TC_ENC}\" -f\n";

        /**
         * Make final rewrite
         */
        $rules .= "    RewriteRule .* \"" . $cache_path . "/%{REQUEST_URI}/_index%{ENV:W3TC_UA}%{ENV:W3TC_REF}%{ENV:W3TC_SSL}.html%{ENV:W3TC_ENC}\" [L]\n";
        $rules .= "</IfModule>\n";
        $rules .= W3TC_MARKER_END_PGCACHE_CORE . "\n";

        return $rules;
    }

    /**
     * Generates rules for WP dir
     *
     * @return string
     */
    function generate_rules_core_nginx() {
        $is_network = w3_is_network();
        $is_vhost = w3_is_subdomain_install();

        $base_path = w3_get_base_path();
        $cache_dir = w3_path(W3TC_CACHE_FILE_PGCACHE_DIR);
        $permalink_structure = get_option('permalink_structure');

        /**
         * Auto reject cookies
         */
        $reject_cookies = array(
            'comment_author',
            'wp-postpass'
        );

        /**
         * Auto reject URIs
         */
        $reject_uris = array(
            '\/wp-admin\/',
            '\/xmlrpc.php',
            '\/wp-(app|cron|login|register|mail)\.php'
        );

        /**
         * Reject cache for logged in users
         */
        if ($this->_config->get_boolean('pgcache.reject.logged')) {
            $reject_cookies = array_merge($reject_cookies, array(
                'wordpress_[a-f0-9]+',
                'wordpress_logged_in'
            ));
        }

        /**
         * Reject cache for home page
         */
        if (!$this->_config->get_boolean('pgcache.cache.home')) {
            $reject_uris[] = '^(\/|\/index.php)$';
        }

        /**
         * Reject cache for feeds
         */
        if (!$this->_config->get_boolean('pgcache.cache.feed')) {
            $reject_uris[] = '\/feed\/';
        }

        /**
         * Custom config
         */
        $reject_cookies = array_merge($reject_cookies, $this->_config->get_array('pgcache.reject.cookie'));
        $reject_uris = array_merge($reject_uris, $this->_config->get_array('pgcache.reject.uri'));
        $reject_uris = array_map('w3_parse_path', $reject_uris);
        $reject_user_agents = $this->_config->get_array('pgcache.reject.ua');
        $accept_files = $this->_config->get_array('pgcache.accept.files');

        /**
         * Generate rules
         */
        $rules = '';
        $rules .= W3TC_MARKER_BEGIN_PGCACHE_CORE . "\n";
        $rules .= "rewrite ^(.*\\/)?w3tc_rewrite_test$ $1?w3tc_rewrite_test=1 last;\n";

        /**
         * Check for mobile redirect
         */
        if ($this->_config->get_boolean('mobile.enabled')) {
            $mobile_groups = $this->_config->get_array('mobile.rgroups');

            foreach ($mobile_groups as $mobile_group => $mobile_config) {
                $mobile_enabled = (isset($mobile_config['enabled']) ? (boolean) $mobile_config['enabled'] : false);
                $mobile_agents = (isset($mobile_config['agents']) ? (array) $mobile_config['agents'] : '');
                $mobile_redirect = (isset($mobile_config['redirect']) ? $mobile_config['redirect'] : '');

                if ($mobile_enabled && count($mobile_agents) && $mobile_redirect) {
                    $rules .= "if (\$http_user_agent ~* \"(" . implode('|', $mobile_agents) . ")\") {\n";
                    $rules .= "    rewrite .* " . $mobile_redirect . " last;\n";
                    $rules .= "}\n";
                }
            }
        }

        /**
         * Check for referrer redirect
         */
        if ($this->_config->get_boolean('referrer.enabled')) {
            $referrer_groups = $this->_config->get_array('referrer.rgroups');

            foreach ($referrer_groups as $referrer_group => $referrer_config) {
                $referrer_enabled = (isset($referrer_config['enabled']) ? (boolean) $referrer_config['enabled'] : false);
                $referrer_referrers = (isset($referrer_config['referrers']) ? (array) $referrer_config['referrers'] : '');
                $referrer_redirect = (isset($referrer_config['redirect']) ? $referrer_config['redirect'] : '');

                if ($referrer_enabled && count($referrer_referrers) && $referrer_redirect) {
                    $rules .= "if (\$http_cookie ~* \"w3tc_referrer=.*(" . implode('|', $referrer_referrers) . ")\") {\n";
                    $rules .= "    rewrite .* " . $referrer_redirect . " last;\n";
                    $rules .= "}\n";
                }
            }
        }

        /**
         * Don't accept POSTs
         */
        $rules .= "set \$w3tc_rewrite 1;\n";
        $rules .= "if (\$request_method = POST) {\n";
        $rules .= "    set \$w3tc_rewrite 0;\n";
        $rules .= "}\n";

        /**
         * Query string should be empty
         */
        $rules .= "if (\$query_string != \"\") {\n";
        $rules .= "    set \$w3tc_rewrite 0;\n";
        $rules .= "}\n";

        /**
         * Check permalink structure trailing slash
         */
        if (substr($permalink_structure, -1) == '/') {
            $rules .= "if (\$request_uri !~ \\/$) {\n";
            $rules .= "    set \$w3tc_rewrite 0;\n";
            $rules .= "}\n";
        } else {
            $rules .= "if (\$request_uri !~ [^\\/]$) {\n";
            $rules .= "    set \$w3tc_rewrite 0;\n";
            $rules .= "}\n";
        }

        /**
         * Check for rejected URIs
         */
        if (!count($accept_files)) {
            $rules .= "if (\$request_uri ~* \"(" . implode('|', $reject_uris) . "\") {\n";
            $rules .= "    set \$w3tc_rewrite 0;\n";
            $rules .= "}\n";
        } else {
            $rules .= "set \$w3tc_rewrite2 1;\n";
            $rules .= "if (\$request_uri ~* \"(" . implode('|', $reject_uris) . ")\") {\n";
            $rules .= "    set \$w3tc_rewrite2 0;\n";
            $rules .= "}\n";

            $rules .= "if (\$request_uri ~* \"(" . implode('|', array_map('w3_preg_quote', $accept_files)) . ")\") {\n";
            $rules .= "    set \$w3tc_rewrite2 1;\n";
            $rules .= "}\n";

            $rules .= "if (\$w3tc_rewrite2 != 1) {\n";
            $rules .= "    set \$w3tc_rewrite 0;\n";
            $rules .= "}\n";
        }

        /**
         * Check for rejected cookies
         */
        $rules .= "if (\$http_cookie ~* \"(" . implode('|', array_map('w3_preg_quote', $reject_cookies)) . ")\") {\n";
        $rules .= "    set \$w3tc_rewrite 0;\n";
        $rules .= "}\n";

        /**
         * Check for rejected user agents
         */
        if (count($reject_user_agents)) {
            $rules .= "if (\$http_user_agent ~* \"(" . implode('|', array_map('w3_preg_quote', $reject_user_agents)) . ")\") {\n";
            $rules .= "    set \$w3tc_rewrite 0;\n";
            $rules .= "}\n";
        }

        /**
         * Network mode rules
         */
        if ($is_network) {
            /**
             * Detect domain
             */
            $rules .= "set \$w3tc_domain \"\";\n";
            $rules .= "if (\$http_host ~ ^(www\\.)?([a-z0-9\\-\\.]+\\.[a-z]+)\\.?(:[0-9]+)?$) {\n";
            $rules .= "    set \$w3tc_domain $2;\n";
            $rules .= "}\n";

            $replacement = '/w3tc-$w3tc_domain/';

            /**
             * If VHOST is off, detect blogname from URI
             */
            if (!$is_vhost) {
                $blognames = w3_get_blognames();

                if (count($blognames)) {
                    $rules .= "set \$w3tc_blogname \"\";\n";
                    $rules .= "if (\$request_uri ~ ^" . $base_path . "(" . implode('|', array_map('w3_preg_quote', $blognames)) . ")/) {\n";
                    $rules .= "    set \$w3tc_blogname $1.;\n";
                    $rules .= "}\n";

                    $replacement = '/w3tc-$w3tc_blogname$w3tc_domain/';
                }
            }

            $cache_dir = preg_replace('~/w3tc.*?/~', $replacement, $cache_dir, 1);
        }

        /**
         * Check mobile groups
         */
        $rules .= "set \$w3tc_ua \"\";\n";

        if ($this->_config->get_boolean('mobile.enabled')) {
            $mobile_groups = array_reverse($this->_config->get_array('mobile.rgroups'));

            foreach ($mobile_groups as $mobile_group => $mobile_config) {
                $mobile_enabled = (isset($mobile_config['enabled']) ? (boolean) $mobile_config['enabled'] : false);
                $mobile_agents = (isset($mobile_config['agents']) ? (array) $mobile_config['agents'] : '');
                $mobile_redirect = (isset($mobile_config['redirect']) ? $mobile_config['redirect'] : '');

                if ($mobile_enabled && count($mobile_agents) && !$mobile_redirect) {
                    $rules .= "if (\$http_user_agent ~* \"(" . implode('|', $mobile_agents) . ")\") {\n";
                    $rules .= "    set \$w3tc_ua _" . $mobile_group . ";\n";
                    $rules .= "}\n";
                }
            }
        }

        /**
         * Check referrer groups
         */
        $rules .= "set \$w3tc_ref \"\";\n";

        if ($this->_config->get_boolean('referrer.enabled')) {
            $referrer_groups = array_reverse($this->_config->get_array('referrer.rgroups'));

            foreach ($referrer_groups as $referrer_group => $referrer_config) {
                $referrer_enabled = (isset($referrer_config['enabled']) ? (boolean) $referrer_config['enabled'] : false);
                $referrer_referrers = (isset($referrer_config['referrers']) ? (array) $referrer_config['referrers'] : '');
                $referrer_redirect = (isset($referrer_config['redirect']) ? $referrer_config['redirect'] : '');

                if ($referrer_enabled && count($referrer_referrers) && !$referrer_redirect) {
                    $rules .= "if (\$http_cookie ~* \"w3tc_referrer=.*(" . implode('|', $referrer_referrers) . ")\") {\n";
                    $rules .= "    set \$w3tc_ref _" . $referrer_group . ";\n";
                    $rules .= "}\n";
                }
            }
        }

        $rules .= "set \$w3tc_ssl \"\";\n";
        $rules .= "if (\$scheme = https) {\n";
        $rules .= "    set \$w3tc_ssl _ssl;\n";
        $rules .= "}\n";
        $rules .= "set \$w3tc_enc \"\";\n";

        if ($this->_config->get_boolean('browsercache.enabled') && $this->_config->get_boolean('browsercache.html.compression')) {
            $rules .= "if (\$http_accept_encoding ~ gzip) {\n";
            $rules .= "    set \$w3tc_enc .gzip;\n";
            $rules .= "}\n";
        }

        $cache_path = str_replace(w3_get_document_root(), '', $cache_dir);

        $rules .= "if (!-f \"\$document_root" . $cache_path . "/\$request_uri/_index\$w3tc_ua\$w3tc_ref\$w3tc_ssl.html\$w3tc_enc\") {\n";
        $rules .= "    set \$w3tc_rewrite 0;\n";
        $rules .= "}\n";

        $rules .= "if (\$w3tc_rewrite = 1) {\n";
        $rules .= "    rewrite .* \"" . $cache_path . "/\$request_uri/_index\$w3tc_ua\$w3tc_ref\$w3tc_ssl.html\$w3tc_enc\" last;\n";
        $rules .= "}\n";
        $rules .= W3TC_MARKER_END_PGCACHE_CORE . "\n";

        return $rules;
    }

    /**
     * Generates directives for file cache dir
     *
     * @return string
     */
    function generate_rules_cache_apache() {
        $charset = get_option('blog_charset');
        $pingback_url = get_bloginfo('pingback_url');

        $browsercache = $this->_config->get_integer('browsercache.enabled');
        $compression = ($browsercache && $this->_config->get_boolean('browsercache.html.compression'));
        $expires = ($browsercache && $this->_config->get_boolean('browsercache.html.expires'));
        $lifetime = ($browsercache ? $this->_config->get_integer('browsercache.html.lifetime') : 0);
        $cache_control = ($browsercache && $this->_config->get_boolean('browsercache.html.cache.control'));
        $etag = ($browsercache && $this->_config->get_integer('browsercache.html.etag'));
        $w3tc = ($browsercache && $this->_config->get_integer('browsercache.html.w3tc'));

        $rules = '';
        $rules .= W3TC_MARKER_BEGIN_PGCACHE_CACHE . "\n";

        if ($etag) {
            $rules .= "FileETag MTime Size\n";
        }

        $rules .= "AddDefaultCharset " . ($charset ? $charset : 'UTF-8') . "\n";

        if ($compression) {
            $rules .= "<IfModule mod_mime.c>\n";
            $rules .= "    AddType text/html .gzip\n";
            $rules .= "    AddEncoding gzip .gzip\n";
            $rules .= "</IfModule>\n";
            $rules .= "<IfModule mod_deflate.c>\n";
            $rules .= "    SetEnvIfNoCase Request_URI \\.gzip$ no-gzip\n";
            $rules .= "</IfModule>\n";
        }

        if ($expires) {
            $rules .= "<IfModule mod_expires.c>\n";
            $rules .= "    ExpiresActive On\n";
            $rules .= "    ExpiresByType text/html M" . $lifetime . "\n";
            $rules .= "</IfModule>\n";
        }

        $rules .= "<IfModule mod_headers.c>\n";
        $rules .= "    Header set X-Pingback \"" . $pingback_url . "\"\n";

        if ($w3tc) {
            $rules .= "    Header set X-Powered-By \"" . W3TC_POWERED_BY . "\"\n";
        }

        if ($compression) {
            $rules .= "    Header set Vary \"Accept-Encoding, Cookie\"\n";
        } else {
            $rules .= "    Header set Vary \"Cookie\"\n";
        }

        if ($cache_control) {
            $cache_policy = $this->_config->get_string('browsercache.html.cache.policy');

            switch ($cache_policy) {
                case 'cache':
                    $rules .= "    Header set Pragma \"public\"\n";
                    $rules .= "    Header set Cache-Control \"public\"\n";
                    break;

                case 'cache_validation':
                    $rules .= "    Header set Pragma \"public\"\n";
                    $rules .= "    Header set Cache-Control \"public, must-revalidate, proxy-revalidate\"\n";
                    break;

                case 'cache_noproxy':
                    $rules .= "    Header set Pragma \"public\"\n";
                    $rules .= "    Header set Cache-Control \"public, must-revalidate\"\n";
                    break;

                case 'cache_maxage':
                    $rules .= "    Header set Pragma \"public\"\n";

                    if ($expires) {
                        $rules .= "    Header append Cache-Control \"public, must-revalidate, proxy-revalidate\"\n";
                    } else {
                        $rules .= "    Header set Cache-Control \"max-age=" . $lifetime . ", public, must-revalidate, proxy-revalidate\"\n";
                    }
                    break;

                case 'no_cache':
                    $rules .= "    Header set Pragma \"no-cache\"\n";
                    $rules .= "    Header set Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\"\n";
                    break;
            }
        }

        $rules .= "</IfModule>\n";
        $rules .= W3TC_MARKER_END_PGCACHE_CACHE . "\n";

        return $rules;
    }

    /**
     * Generates directives for file cache dir
     *
     * @return string
     */
    function generate_rules_cache_nginx() {
        $cache_root = w3_path(W3TC_CACHE_FILE_PGCACHE_DIR);
        $cache_dir = rtrim(str_replace(w3_get_document_root(), '', $cache_root), '/');

        if (w3_is_network()) {
            $cache_dir = preg_replace('~/w3tc.*?/~', '/w3tc.*?/', $cache_dir, 1);
        }

        $browsercache = $this->_config->get_integer('browsercache.enabled');
        $compression = ($browsercache && $this->_config->get_boolean('browsercache.html.compression'));
        $expires = ($browsercache && $this->_config->get_boolean('browsercache.html.expires'));
        $lifetime = ($browsercache ? $this->_config->get_integer('browsercache.html.lifetime') : 0);
        $cache_control = ($browsercache && $this->_config->get_boolean('browsercache.html.cache.control'));
        $w3tc = ($browsercache && $this->_config->get_integer('browsercache.html.w3tc'));

        $common_rules = '';

        if ($expires) {
            $common_rules .= "    expires modified " . $lifetime . "s;\n";
        }

        if ($w3tc) {
            $common_rules .= "    add_header X-Powered-By \"" . W3TC_POWERED_BY . "\";\n";
        }

        if ($compression) {
            $common_rules .= "    add_header Vary \"Accept-Encoding, Cookie\";\n";
        } else {
            $common_rules .= "    add_header Vary Cookie;\n";
        }

        if ($cache_control) {
            $cache_policy = $this->_config->get_string('browsercache.html.cache.policy');

            switch ($cache_policy) {
                case 'cache':
                    $common_rules .= "    add_header Pragma \"public\";\n";
                    $common_rules .= "    add_header Cache-Control \"public\";\n";
                    break;

                case 'cache_validation':
                    $common_rules .= "    add_header Pragma \"public\";\n";
                    $common_rules .= "    add_header Cache-Control \"public, must-revalidate, proxy-revalidate\";\n";
                    break;

                case 'cache_noproxy':
                    $common_rules .= "    add_header Pragma \"public\";\n";
                    $common_rules .= "    add_header Cache-Control \"public, must-revalidate\";\n";
                    break;

                case 'cache_maxage':
                    $common_rules .= "    add_header Pragma \"public\";\n";
                    $common_rules .= "    add_header Cache-Control \"max-age=" . $lifetime . ", public, must-revalidate, proxy-revalidate\";\n";
                    break;

                case 'no_cache':
                    $common_rules .= "    add_header Pragma \"no-cache\";\n";
                    $common_rules .= "    add_header Cache-Control \"max-age=0, private, no-store, no-cache, must-revalidate\";\n";
                    break;
            }
        }

        $rules = '';
        $rules .= W3TC_MARKER_BEGIN_PGCACHE_CACHE . "\n";

        $rules .= "location ~ " . $cache_dir . ".*html$ {\n";
        $rules .= $common_rules;
        $rules .= "}\n";

        if ($compression) {
            $rules .= "location ~ " . $cache_dir . ".*gzip$ {\n";
            $rules .= "    gzip off;\n";
            $rules .= "    types {}\n";
            $rules .= "    default_type text/html;\n";
            $rules .= $common_rules;
            $rules .= "    add_header Content-Encoding gzip;\n";
            $rules .= "}\n";
        }

        $rules .= W3TC_MARKER_END_PGCACHE_CACHE . "\n";

        return $rules;
    }

    /**
     * Writes directives to WP .htaccess
     *
     * @return boolean
     */
    function write_rules_core() {
        $path = w3_get_pgcache_rules_core_path();

        if (file_exists($path)) {
            $data = @file_get_contents($path);

            if ($data !== false) {
                $data = $this->erase_rules_wpsc($data);
            } else {
                return false;
            }
        } else {
            $data = '';
        }

        $replace_start = strpos($data, W3TC_MARKER_BEGIN_PGCACHE_CORE);
        $replace_end = strpos($data, W3TC_MARKER_END_PGCACHE_CORE);

        if ($replace_start !== false && $replace_end !== false && $replace_start < $replace_end) {
            $replace_length = $replace_end - $replace_start + strlen(W3TC_MARKER_END_PGCACHE_CORE) + 1;
        } else {
            $replace_start = false;
            $replace_length = 0;

            $search = array(
                W3TC_MARKER_BEGIN_BROWSERCACHE_NO404WP => 0,
                W3TC_MARKER_BEGIN_WORDPRESS => 0,
                W3TC_MARKER_END_MINIFY_CORE => strlen(W3TC_MARKER_END_MINIFY_CORE) + 1,
                W3TC_MARKER_END_BROWSERCACHE_CACHE => strlen(W3TC_MARKER_END_BROWSERCACHE_CACHE) + 1,
                W3TC_MARKER_END_PGCACHE_CACHE => strlen(W3TC_MARKER_END_PGCACHE_CACHE) + 1,
                W3TC_MARKER_END_MINIFY_CACHE => strlen(W3TC_MARKER_END_MINIFY_CACHE) + 1
            );

            foreach ($search as $string => $length) {
                $replace_start = strpos($data, $string);

                if ($replace_start !== false) {
                    $replace_start += $length;
                    break;
                }
            }
        }

        $rules = $this->generate_rules_core();

        if ($replace_start !== false) {
            $data = w3_trim_rules(substr_replace($data, $rules, $replace_start, $replace_length));
        } else {
            $data = w3_trim_rules($data . $rules);
        }

        return @file_put_contents($path, $data);
    }

    /**
     * Writes directives to file cache .htaccess
     *
     * @return boolean
     */
    function write_rules_cache() {
        $path = w3_get_pgcache_rules_cache_path();

        if (file_exists($path)) {
            $data = @file_get_contents($path);

            if ($data === false) {
                return false;
            }
        } else {
            $data = '';
        }

        $replace_start = strpos($data, W3TC_MARKER_BEGIN_PGCACHE_CACHE);
        $replace_end = strpos($data, W3TC_MARKER_END_PGCACHE_CACHE);

        if ($replace_start !== false && $replace_end !== false && $replace_start < $replace_end) {
            $replace_length = $replace_end - $replace_start + strlen(W3TC_MARKER_END_PGCACHE_CACHE) + 1;
        } else {
            $replace_start = false;
            $replace_length = 0;

            $search = array(
                W3TC_MARKER_BEGIN_BROWSERCACHE_CACHE => 0,
                W3TC_MARKER_BEGIN_MINIFY_CORE => 0,
                W3TC_MARKER_BEGIN_PGCACHE_CORE => 0,
                W3TC_MARKER_BEGIN_BROWSERCACHE_NO404WP => 0,
                W3TC_MARKER_BEGIN_WORDPRESS => 0,
                W3TC_MARKER_END_MINIFY_CACHE => strlen(W3TC_MARKER_END_MINIFY_CACHE) + 1
            );

            foreach ($search as $string => $length) {
                $replace_start = strpos($data, $string);

                if ($replace_start !== false) {
                    $replace_start += $length;
                    break;
                }
            }
        }

        $rules = $this->generate_rules_cache();

        if ($replace_start !== false) {
            $data = w3_trim_rules(substr_replace($data, $rules, $replace_start, $replace_length));
        } else {
            $data = w3_trim_rules($data . $rules);
        }

        return @file_put_contents($path, $data);
    }

    /**
     * Erases W3TC directives from config
     *
     * @param string $data
     * @return string
     */
    function erase_rules_core($data) {
        $data = w3_erase_rules($data, W3TC_MARKER_BEGIN_PGCACHE_CORE, W3TC_MARKER_END_PGCACHE_CORE);

        return $data;
    }

    /**
     * Erases W3TC directives from config
     *
     * @param string $data
     * @return string
     */
    function erase_rules_cache($data) {
        $data = w3_erase_rules($data, W3TC_MARKER_BEGIN_PGCACHE_CACHE, W3TC_MARKER_END_PGCACHE_CACHE);

        return $data;
    }

    /**
     * Erases WP Super Cache rules directives config
     *
     * @param string $data
     * @return string
     */
    function erase_rules_wpsc($data) {
        $data = w3_erase_rules($data, W3TC_MARKER_BEGIN_SUPERCACHE, W3TC_MARKER_END_SUPERCACHE);

        return $data;
    }

    /**
     * Removes W3TC directives from WP .htaccess
     *
     * @return boolean
     */
    function remove_rules_core() {
        $path = w3_get_pgcache_rules_core_path();

        if (file_exists($path)) {
            if (($data = @file_get_contents($path)) !== false) {
                $data = $this->erase_rules_core($data);

                return @file_put_contents($path, $data);
            }

            return false;
        }

        return true;
    }

    /**
     * Removes W3TC directives from file cache dir
     *
     * @return boolean
     */
    function remove_rules_cache() {
        $path = w3_get_pgcache_rules_cache_path();

        if (file_exists($path)) {
            if (($data = @file_get_contents($path)) !== false) {
                $data = $this->erase_rules_cache($data);

                return @file_put_contents($path, $data);
            }

            return false;
        }

        return true;
    }

    /**
     * Checks core directives
     *
     * @return boolean
     */
    function check_rules_core() {
        $path = w3_get_pgcache_rules_core_path();
        $search = $this->generate_rules_core();

        return (($data = @file_get_contents($path)) && strstr(w3_clean_rules($data), w3_clean_rules($search)) !== false);
    }

    /**
     * Checks cache directives
     *
     * @return boolean
     */
    function check_rules_cache() {
        $path = w3_get_pgcache_rules_cache_path();
        $search = $this->generate_rules_cache();

        return (($data = @file_get_contents($path)) && strstr(w3_clean_rules($data), w3_clean_rules($search)) !== false);
    }
}
