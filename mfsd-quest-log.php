<?php
/**
 * Plugin Name: MFSD Quest Log
 * Description: Student badge/reward system — dark gaming theme with gem badges, treasure chests, coin wallet, and Spark/Ember/Blaze RAG evolution.
 * Version: 1.1.0
 * Author: MisterT9007
 */

if (!defined('ABSPATH')) exit;

/* ── Autoload includes ── */
foreach (array('db', 'engine', 'wallet', 'renderer') as $f) {
    require_once __DIR__ . '/includes/class-quest-log-' . $f . '.php';
}

final class MFSD_Quest_Log {
    const VERSION      = '1.0.0';
    const NONCE_ACTION = 'mfsd_quest_log_nonce';

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('init',           array($this, 'register_assets'));
        add_shortcode('mfsd_quest_log', array($this, 'shortcode'));
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
        wp_register_style('mfsd-quest-log',  $base . 'assets/css/quest-log.css', array(), self::VERSION);
        wp_register_script('mfsd-quest-log', $base . 'assets/js/quest-log.js',  array(), self::VERSION, true);
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
        ));

        wp_enqueue_style('mfsd-quest-log');
        wp_enqueue_script('mfsd-quest-log');

        /* Force dark theme body class */
        add_filter('body_class', function($classes) {
            $classes[] = 'mfsd-quest-log-active';
            return $classes;
        });

        return $renderer->render($student_id, $badges, $balance, $character_data, $display_name, $images_url);
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

        /* Gender — check ProfilePress custom field, default to 'male' */
        $gender = get_user_meta($student_id, 'gender', true);
        $gender = in_array(strtolower($gender), array('female', 'f')) ? 'female' : 'male';

        /* Characters with gendered variants */
        $gendered = array('Architect', 'Debater', 'Entrepreneur');
        if (in_array($char['name'], $gendered)) {
            $filename = $char['name'] . '_' . ucfirst($gender) . '.png';
        } else {
            $filename = $char['name'] . '.png';
        }

        return array(
            'mbti'     => $mbti,
            'name'     => $char['name'],
            'group'    => $char['group'],
            'gender'   => $gender,
            'filename' => $filename,
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
