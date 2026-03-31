<?php
/**
 * Plugin Name: MFSD Quest Log
 * Description: Student badge/reward system — dark gaming theme with gem badges, treasure chests, coin wallet, and Spark/Ember/Blaze RAG evolution.
 * Version: 1.8.0
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

/* ── Autoload includes ── */
foreach (array('db', 'engine', 'wallet', 'renderer') as $f) {
    require_once __DIR__ . '/includes/class-quest-log-' . $f . '.php';
}

final class MFSD_Quest_Log {
    const VERSION      = '1.8.0';
    const NONCE_ACTION = 'mfsd_quest_log_nonce';

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('init',           array($this, 'register_assets'));
        add_shortcode('mfsd_quest_log',   array($this, 'shortcode'));
        add_shortcode('mfsd_coin_wallet', array($this, 'wallet_shortcode'));
        add_action('rest_api_init',  array($this, 'register_routes'));
        add_action('admin_menu',     array($this, 'admin_menu'));
    }

    /* ================================================================
       INSTALL — create DB tables
       ================================================================ */
    public function install() {
        MFSD_Quest_Log_DB::create_tables();
    }

    /* ================================================================
       ASSETS — conditional loading via shortcode
       ================================================================ */
    public function register_assets() {
        $base = plugin_dir_url(__FILE__);
        wp_register_style('mfsd-quest-log',  $base . 'assets/quest-log.css', array(), self::VERSION);
        wp_register_script('mfsd-quest-log', $base . 'assets/quest-log.js',  array(), self::VERSION, true);
    }

    /* ================================================================
       SHORTCODE — [mfsd_quest_log]
       ================================================================ */
    public function shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p style="text-align:center;padding:40px;color:#aaa;">Please log in to view your Quest Log.</p>';
        }

        $student_id = get_current_user_id();
        $user = get_userdata($student_id);
        if ($user && !in_array('student', (array)$user->roles) && !in_array('administrator', (array)$user->roles)) {
            return '<p style="text-align:center;padding:40px;color:#aaa;">The Quest Log is only available for students.</p>';
        }

        /* Run the badge evaluation engine — awards any newly earned badges */
        $engine = new MFSD_Quest_Log_Engine();
        $engine->evaluate_all($student_id);

        /* Gather data for the renderer */
        $db       = new MFSD_Quest_Log_DB();
        $wallet   = new MFSD_Quest_Log_Wallet();
        $renderer = new MFSD_Quest_Log_Renderer();

        $badges  = $db->get_student_badges($student_id);
        $balance = $wallet->get_balance($student_id);

        /* Who Am I character from personality test results */
        $character_data = $this->get_character_data($student_id);

        /* Student display name + avatar */
        $display_name = $user ? $user->display_name : 'Student';

        $images_url = plugin_dir_url(__FILE__) . 'assets/images/';

        wp_localize_script('mfsd-quest-log', 'MFSD_QUEST_CFG', array(
            'restBase'     => esc_url_raw(rest_url('mfsd-quest/v1')),
            'nonce'        => wp_create_nonce('wp_rest'),
            'studentId'    => $student_id,
            'displayName'  => $display_name,
            'balance'      => $balance,
            'badges'       => $badges,
            'character'    => $character_data,
            'imagesUrl'    => $images_url,
            'walletUrl'    => $this->get_wallet_page_url(),
        ));

        wp_enqueue_style('mfsd-quest-log');
        wp_enqueue_script('mfsd-quest-log');

        /* Output animation toggle CSS based on admin settings */
        wp_add_inline_style('mfsd-quest-log', $this->get_animation_css());

        /* Force dark theme body class */
        add_filter('body_class', function($classes) {
            $classes[] = 'mfsd-quest-log-active';
            return $classes;
        });

        return $renderer->render($student_id, $badges, $balance, $character_data, $display_name, $images_url);
    }

    /* ================================================================
       ANIMATION CSS — per-badge shimmer/coin + global toggles
       ================================================================ */
    private function get_animation_css() {
        $css = '';

        /* ── Per-badge shimmer ── */
        $shimmer_raw = get_option('mfsd_quest_shimmer_config', '{}');
        $shimmer_cfg = json_decode($shimmer_raw, true) ?: array();

        /* Default: shimmer off for all badges. Only enable those marked on. */
        $css .= ".ql-badge-card.earned .ql-badge-image-wrap::after { animation: none !important; content: none !important; }\n";

        $shimmer_on_count = 0;
        foreach ($shimmer_cfg as $slug => $cfg) {
            if (!empty($cfg['on'])) {
                $interval = max(2, (int) ($cfg['interval'] ?? 5));
                $slug_esc = esc_attr($slug);
                $css .= ".ql-badge-card.earned[data-badge=\"{$slug_esc}\"] .ql-badge-image-wrap::after { animation: ql-shimmer {$interval}s ease-in-out infinite !important; content: '' !important; }\n";
                $shimmer_on_count++;
            }
        }

        /* ── Per-location coin spin ── */
        $coin_raw  = get_option('mfsd_quest_coin_config', '{}');
        $coin_cfg  = json_decode($coin_raw, true) ?: array();
        $coin_hdr  = $coin_cfg['header'] ?? array('on' => true, 'interval' => 5);
        $coin_bdgs = $coin_cfg['badges'] ?? array();

        /* Header coin */
        if (!empty($coin_hdr['on'])) {
            $hdr_dur = max(2, (int) ($coin_hdr['interval'] ?? 5));
            $css .= ".ql-coin-icon { animation: ql-coin-spin {$hdr_dur}s ease-in-out infinite; }\n";
        } else {
            $css .= ".ql-coin-icon { animation: none !important; }\n";
        }

        /* Per-badge mini coin — default off, enable those marked on */
        $css .= ".ql-badge-coins .ql-mini-coin { animation: none; }\n";
        foreach ($coin_bdgs as $slug => $cfg) {
            if (!empty($cfg['on'])) {
                $interval = max(2, (int) ($cfg['interval'] ?? 4));
                $slug_esc = esc_attr($slug);
                $css .= ".ql-badge-card[data-badge=\"{$slug_esc}\"] .ql-badge-coins .ql-mini-coin { animation: ql-coin-spin {$interval}s ease-in-out infinite !important; }\n";
            }
        }

        /* ── Global animation toggles ── */
        if (get_option('mfsd_quest_anim_float', '1') !== '1') {
            $css .= ".ql-badge-card.earned .ql-badge-image { animation: none !important; }\n";
        }
        if (get_option('mfsd_quest_anim_border_glow', '1') !== '1') {
            $css .= ".ql-badge-card.earned { animation: none !important; }\n";
        }
        if (get_option('mfsd_quest_anim_fire_flicker', '1') !== '1') {
            $css .= ".ql-fire-stage.lit .ql-fire-img { animation: none !important; }\n";
        }
        if (get_option('mfsd_quest_anim_chest_wobble', '1') !== '1') {
            $css .= ".ql-chest.earned:hover .ql-chest-img { animation: none !important; }\n";
        }
        if (get_option('mfsd_quest_anim_locked_pulse', '1') !== '1') {
            $css .= ".ql-badge-card.locked .ql-badge-image { animation: none !important; }\n";
        }
        if (get_option('mfsd_quest_anim_progress_shine', '1') !== '1') {
            $css .= ".ql-progress-fill { animation: none !important; background-size: 100% 100% !important; }\n";
        }

        return $css;
    }

    /* ================================================================
       WHO AM I — read MBTI result + build character data
       ================================================================ */
    private function get_character_data($student_id) {
        global $wpdb;
        $results_table = $wpdb->prefix . 'mfsd_ptest_results';

        /* Check table exists */
        if ($wpdb->get_var("SHOW TABLES LIKE '$results_table'") !== $results_table) {
            return null;
        }

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT mbti_type FROM $results_table WHERE user_id = %d AND test_type IN ('COMBINED','MBTI') AND mbti_type IS NOT NULL ORDER BY created_at DESC LIMIT 1",
            $student_id
        ), ARRAY_A);

        if (!$result || empty($result['mbti_type'])) return null;

        $mbti = strtoupper($result['mbti_type']);
        $map  = self::mbti_character_map();
        $char = $map[$mbti] ?? null;
        if (!$char) return null;

        /* Avatar filename — must match the actual files in personality-test/assets/Avatars/ */
        $avatar_files = self::avatar_file_map();
        $filename = $avatar_files[$mbti] ?? ($char['name'] . '.png');

        /* Build the URL to the personality test plugin's existing Avatars folder */
        $avatars_url = '';
        $possible_dirs = array(
            'mfsd-personality-test/assets/Avatars/',
            'personality-test/assets/Avatars/',
        );
        foreach ($possible_dirs as $dir) {
            if (is_dir(WP_PLUGIN_DIR . '/' . $dir)) {
                $avatars_url = plugins_url($dir);
                break;
            }
        }
        if (empty($avatars_url)) {
            /* Fallback: quest log's own characters folder */
            $avatars_url = plugin_dir_url(__FILE__) . 'assets/images/characters/';
        }

        return array(
            'mbti'        => $mbti,
            'name'        => $char['name'],
            'group'       => $char['group'],
            'filename'    => $filename,
            'avatars_url' => $avatars_url,
        );
    }

    /**
     * Avatar filenames — must match the actual PNG files on disk.
     * These come from the personality test plugin's AVATAR_FILES mapping.
     */
    public static function avatar_file_map() {
        return array(
            'ISTJ' => 'Logistician.png',
            'ISFJ' => 'Defender.png',
            'ESTJ' => 'Executive.png',
            'ESFJ' => 'Consul.png',
            'INTJ' => 'Architect.png',
            'INTP' => 'Logician.png',
            'ENTJ' => 'Commander.png',
            'ENTP' => 'Debater.png',
            'INFJ' => 'Advocate.png',
            'INFP' => 'Mediatorv3.png',
            'ENFJ' => 'Protagonist.png',
            'ENFP' => 'Campaigner.png',
            'ISTP' => 'Virtuoso.png',
            'ISFP' => 'Adventurer.png',
            'ESTP' => 'Entrepreneur.png',
            'ESFP' => 'Entertainer.png',
        );
    }

    public static function mbti_character_map() {
        return array(
            'INTJ' => array('name' => 'Architect',    'group' => 'analysts'),
            'INTP' => array('name' => 'Logician',     'group' => 'analysts'),
            'ENTJ' => array('name' => 'Commander',    'group' => 'analysts'),
            'ENTP' => array('name' => 'Debater',      'group' => 'analysts'),
            'INFJ' => array('name' => 'Advocate',     'group' => 'diplomats'),
            'INFP' => array('name' => 'Mediator',     'group' => 'diplomats'),
            'ENFJ' => array('name' => 'Protagonist',  'group' => 'diplomats'),
            'ENFP' => array('name' => 'Campaigner',   'group' => 'diplomats'),
            'ISTJ' => array('name' => 'Logistician',  'group' => 'sentinels'),
            'ISFJ' => array('name' => 'Defender',     'group' => 'sentinels'),
            'ESTJ' => array('name' => 'Executive',    'group' => 'sentinels'),
            'ESFJ' => array('name' => 'Consul',       'group' => 'sentinels'),
            'ISTP' => array('name' => 'Virtuoso',     'group' => 'explorers'),
            'ISFP' => array('name' => 'Adventurer',   'group' => 'explorers'),
            'ESTP' => array('name' => 'Entrepreneur', 'group' => 'explorers'),
            'ESFP' => array('name' => 'Entertainer',  'group' => 'explorers'),
        );
    }

    /* ================================================================
       WALLET PAGE URL — admin-configurable
       ================================================================ */
    private function get_wallet_page_url() {
        $page_id = (int) get_option('mfsd_quest_wallet_page_id', 0);
        if ($page_id > 0) {
            $url = get_permalink($page_id);
            if ($url) return $url;
        }
        return '#'; /* Fallback if not configured */
    }

    /* ================================================================
       SHORTCODE — [mfsd_coin_wallet]
       ================================================================ */
    public function wallet_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p style="text-align:center;padding:40px;color:#aaa;">Please log in to view your Coin Wallet.</p>';
        }

        $student_id = get_current_user_id();
        $user = get_userdata($student_id);
        if ($user && !in_array('student', (array)$user->roles) && !in_array('administrator', (array)$user->roles)) {
            return '<p style="text-align:center;padding:40px;color:#aaa;">The Coin Wallet is only available for students.</p>';
        }

        $wallet   = new MFSD_Quest_Log_Wallet();
        $renderer = new MFSD_Quest_Log_Renderer();

        $balance        = $wallet->get_balance($student_id);
        $total_earned   = $wallet->get_total_earned($student_id);
        $history        = $wallet->get_history($student_id, 50);
        $display_name   = $user ? $user->display_name : 'Student';
        $images_url     = plugin_dir_url(__FILE__) . 'assets/images/';

        /* Find the quest log page URL for the back link */
        $quest_log_url = '';
        $ql_pages = get_posts(array(
            'post_type'  => 'page',
            'status'     => 'publish',
            's'          => '[mfsd_quest_log]',
            'numberposts' => 1,
        ));
        if (!empty($ql_pages)) {
            $quest_log_url = get_permalink($ql_pages[0]->ID);
        }

        wp_localize_script('mfsd-quest-log', 'MFSD_QUEST_CFG', array(
            'restBase'     => esc_url_raw(rest_url('mfsd-quest/v1')),
            'nonce'        => wp_create_nonce('wp_rest'),
            'studentId'    => $student_id,
            'balance'      => $balance,
        ));

        wp_enqueue_style('mfsd-quest-log');
        wp_enqueue_script('mfsd-quest-log');

        /* Force dark theme body class */
        add_filter('body_class', function($classes) {
            $classes[] = 'mfsd-quest-log-active';
            return $classes;
        });

        return $renderer->render_wallet_page($student_id, $balance, $total_earned, $history, $display_name, $images_url, $quest_log_url);
    }

    /* ================================================================
       REST API
       ================================================================ */
    public function register_routes() {
        register_rest_route('mfsd-quest/v1', '/badges', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_badges'),
            'permission_callback' => function() { return is_user_logged_in(); },
        ));
        register_rest_route('mfsd-quest/v1', '/wallet', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_wallet'),
            'permission_callback' => function() { return is_user_logged_in(); },
        ));
        register_rest_route('mfsd-quest/v1', '/wallet/history', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'api_wallet_history'),
            'permission_callback' => function() { return is_user_logged_in(); },
        ));
    }

    public function api_badges($req) {
        $student_id = get_current_user_id();
        $engine = new MFSD_Quest_Log_Engine();
        $engine->evaluate_all($student_id);
        $db = new MFSD_Quest_Log_DB();
        return array('ok' => true, 'badges' => $db->get_student_badges($student_id));
    }

    public function api_wallet($req) {
        $student_id = get_current_user_id();
        $wallet = new MFSD_Quest_Log_Wallet();
        return array('ok' => true, 'balance' => $wallet->get_balance($student_id));
    }

    public function api_wallet_history($req) {
        $student_id = get_current_user_id();
        $wallet = new MFSD_Quest_Log_Wallet();
        return array('ok' => true, 'transactions' => $wallet->get_history($student_id, 20));
    }

    /* ================================================================
       ADMIN
       ================================================================ */
    public function admin_menu() {
        add_menu_page(
            'Quest Log Admin',
            'Quest Log',
            'manage_options',
            'mfsd-quest-log',
            array($this, 'admin_page'),
            'dashicons-awards',
            31
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        include __DIR__ . '/admin-page.php';
    }
}

MFSD_Quest_Log::instance();