<?php
/**
 * MFSD Quest Log — Badge Evaluation Engine
 * Checks wp_mfsd_task_progress and awards badges + wallet transactions.
 * v1.0.2 — fixed personality_test task slug
 */

if (!defined('ABSPATH')) exit;

class MFSD_Quest_Log_Engine {

    /* ── Week-milestone coin values ──
       Per-task badge/coin config now lives in wp_mfsd_task_order (mfsd-ordering),
       read via mfsd_get_course_badge_config() — see evaluate_all()/evaluate_week().
       These two remain constants: week-milestone chest rewards, not per-task. */
    const COIN_WEEK_COMPLETE = 25;
    const COIN_WEEK_ACHIEVER = 50;

    private $db;
    private $wallet;

    public function __construct() {
        $this->db     = new MFSD_Quest_Log_DB();
        $this->wallet = new MFSD_Quest_Log_Wallet();
    }

    /* ================================================================
       MAIN EVALUATION — run on every page load of the Quest Log
       ================================================================ */
    public function evaluate_all($student_id) {
        $course_id = (int) get_option('mfsd_quest_course_id', 0);
        if (!$course_id || !function_exists('mfsd_get_course_badge_config')) return;

        $badge_config  = mfsd_get_course_badge_config($course_id);
        $task_statuses = $this->get_all_task_statuses($student_id);

        foreach ($badge_config as $week_num => $week_tasks) {
            $this->evaluate_week($student_id, $week_num, $week_tasks, $task_statuses);
        }
    }

    /* ================================================================
       PER-WEEK EVALUATION
       ================================================================ */
    private function evaluate_week($student_id, $week_num, $week_tasks, $task_statuses) {
        $completed_count     = 0;
        $counted_total       = 0;
        $counted_badge_slugs = array();

        foreach ($week_tasks as $task_slug => $cfg) {
            $counts = !empty($cfg['counts_for_week_badge']);
            if ($counts) {
                $counted_total++;
                if (!empty($cfg['badge_slug'])) {
                    $counted_badge_slugs[] = $cfg['badge_slug'];
                }
            }

            $status = $task_statuses[$task_slug] ?? 'not_started';
            if ($status === 'completed') {
                if (!empty($cfg['badge_slug'])) {
                    $this->maybe_award_task_badge(
                        $student_id, $cfg['badge_slug'], $task_slug, $week_num,
                        (int) ($cfg['coin_value'] ?? 10)
                    );
                }
                if ($counts) {
                    $completed_count++;
                }
            }
        }

        /* Week completion badges — denominator is tasks flagged counts_for_week_badge,
           not every task in the week (lets an "Other"/bonus task exist without
           blocking week completion). Guard against an empty/misconfigured week
           vacuously matching 0 === 0. */
        if ($counted_total > 0 && $completed_count === $counted_total) {
            $complete_slug = 'badge_week' . $week_num . '_complete';
            if (!$this->db->has_badge($student_id, $complete_slug)) {
                $this->db->award_badge($student_id, $complete_slug, self::COIN_WEEK_COMPLETE);
                $this->wallet->earn($student_id, $complete_slug, self::COIN_WEEK_COMPLETE,
                    'Week ' . $week_num . ' completed — all tasks done!');
            }

            /* Achiever — all counted tasks completed within 7 days of the first badge */
            $achiever_slug = 'badge_week' . $week_num . '_achiever';
            if (!$this->db->has_badge($student_id, $achiever_slug)) {
                if ($this->check_achiever($student_id, $counted_badge_slugs)) {
                    $this->db->award_badge($student_id, $achiever_slug, self::COIN_WEEK_ACHIEVER);
                    $this->wallet->earn($student_id, $achiever_slug, self::COIN_WEEK_ACHIEVER,
                        'Week ' . $week_num . ' achiever — completed within 7 days!');
                }
            }
        }
    }

    /* ================================================================
       AWARD A SINGLE TASK BADGE
       ================================================================ */
    private function maybe_award_task_badge($student_id, $badge_slug, $task_slug, $week_num, $coins) {
        if ($this->db->has_badge($student_id, $badge_slug)) return;

        /* Build metadata */
        $metadata = array('task_slug' => $task_slug, 'week' => $week_num);

        /* Special: Who Am I badge — include character info */
        if ($badge_slug === 'badge_who_am_i_1') {
            $character = $this->get_character_metadata($student_id);
            if ($character) {
                $metadata = array_merge($metadata, $character);
            }
        }

        $this->db->award_badge($student_id, $badge_slug, $coins, $metadata);
        $this->wallet->earn($student_id, $badge_slug, $coins,
            $this->get_badge_description($badge_slug) . ' badge earned');
    }

    /* ================================================================
       ACHIEVER CHECK — all badges earned within 7 days of first
       ================================================================ */
    private function check_achiever($student_id, $badge_slugs) {
        if (empty($badge_slugs)) return false;

        $dates = $this->db->get_week_badge_dates($student_id, $badge_slugs);

        if (count($dates) < count($badge_slugs)) return false;

        $timestamps = array_map(function($row) {
            return strtotime($row['earned_at']);
        }, $dates);

        $earliest = min($timestamps);
        $latest   = max($timestamps);

        return ($latest - $earliest) <= (7 * DAY_IN_SECONDS);
    }

    /* ================================================================
       TASK STATUS READER — reads wp_mfsd_task_progress
       ================================================================ */
    private function get_all_task_statuses($student_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mfsd_task_progress';

        /* Check table exists */
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT task_slug, status FROM $table WHERE student_id = %d",
            $student_id
        ), ARRAY_A);

        $statuses = array();
        foreach ($rows as $row) {
            $statuses[$row['task_slug']] = $row['status'];
        }
        return $statuses;
    }

    /* ================================================================
       CHARACTER METADATA for Who Am I badge
       ================================================================ */
    private function get_character_metadata($student_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mfsd_ptest_results';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return null;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT mbti_type FROM $table WHERE user_id = %d AND test_type IN ('COMBINED','MBTI') AND mbti_type IS NOT NULL ORDER BY created_at DESC LIMIT 1",
            $student_id
        ), ARRAY_A);

        if (!$result || empty($result['mbti_type'])) return null;

        $map  = MFSD_Quest_Log::mbti_character_map();
        $mbti = strtoupper($result['mbti_type']);
        $char = $map[$mbti] ?? null;
        if (!$char) return null;

        $gender = get_user_meta($student_id, 'gender', true);
        $gender = in_array(strtolower($gender), array('female', 'f')) ? 'female' : 'male';

        return array(
            'character' => $char['name'],
            'gender'    => $gender,
            'group'     => $char['group'],
        );
    }

    /* ================================================================
       BADGE DISPLAY NAMES
       ================================================================ */
    public static function get_badge_description($slug) {
        $names = array(
            'badge_solution_lens'   => 'The Solution Lens',
            'badge_word_assoc'      => 'Word Association',
            'badge_junk_jobs'       => 'Dream & Junk Jobs',
            'badge_who_am_i_1'      => 'Who Am I',
            'badge_super_strengths'          => 'Super Strengths',
            'badge_ss_complete_steverman'    => 'Super Strengths — Steverman',
            'badge_ss_complete_supersteve'   => 'Super Strengths — Supersteve',
            'badge_ss_complete_wondersteve'  => 'Super Strengths — Wondersteve',
            'badge_ss_complete_harley_steve' => 'Super Strengths — Harley Steve',
            'badge_ss_winner_steverman'      => 'Super Strengths Winner — Steverman',
            'badge_ss_winner_supersteve'     => 'Super Strengths Winner — Supersteve',
            'badge_ss_winner_wondersteve'    => 'Super Strengths Winner — Wondersteve',
            'badge_ss_winner_harley_steve'   => 'Super Strengths Winner — Harley Steve',
            'badge_rag_w1'          => 'RAG Spark',
            'badge_week1_complete'  => 'Week 1 Complete',
            'badge_week1_achiever'  => 'Week 1 Achiever',
            'badge_life_wheel'      => 'Wheel of Life',
            'badge_fav_subject'     => 'Favourite Subject',
            'badge_barriers'        => 'Barriers',
            'badge_dream_jobs'      => 'Dream Jobs',
            'badge_who_am_i_2'      => 'Who Am I (Part 2)',
            'badge_rag_w2'          => 'RAG Ember',
            'badge_week2_complete'  => 'Week 2 Complete',
            'badge_week2_achiever'  => 'Week 2 Achiever',
            'badge_fifty_quid'      => '£50 on Success',
            'badge_hp_wheel'        => 'HP Wheel',
            'badge_what_is_hp'      => 'What is HP?',
            'badge_dream_life'      => 'Dream Life',
            'badge_rag_w3'          => 'RAG Blaze',
            'badge_week3_complete'  => 'Week 3 Complete',
            'badge_week3_achiever'  => 'Week 3 Achiever',
        );
        return $names[$slug] ?? ucwords(str_replace(array('badge_', '_'), array('', ' '), $slug));
    }
}