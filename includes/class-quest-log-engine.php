<?php
/**
 * MFSD Quest Log — Badge Evaluation Engine
 * Checks wp_mfsd_task_progress and awards badges + wallet transactions.
 * v1.0.2 — fixed personality_test task slug
 */

if (!defined('ABSPATH')) exit;

class MFSD_Quest_Log_Engine {

    /* ── Badge → Task mappings per week ──
       These must match the exact task_slug values in wp_mfsd_task_progress.
       If a badge isn't awarding, check the slug matches the database. */
    const WEEK_BADGES = array(
        1 => array(
            'badge_word_assoc'      => 'word_association',
            'badge_junk_jobs'       => 'junk_jobs',
            'badge_who_am_i_1'      => 'personality_test_week_1',
            'badge_super_strengths' => 'super_strengths',
            'badge_rag_w1'          => 'rag_week_1',
        ),
        2 => array(
            'badge_fav_subject' => 'favourite_subject',
            'badge_barriers'    => 'barriers',
            'badge_dream_jobs'  => 'dream_jobs',
            'badge_who_am_i_2'  => 'who_am_i_part_2',
            'badge_rag_w2'      => 'rag_week_2',
        ),
        3 => array(
            'badge_fifty_quid' => 'fifty_on_success',
            'badge_hp_wheel'   => 'hp_wheel',
            'badge_what_is_hp' => 'what_is_hp',
            'badge_dream_life' => 'dream_life',
            'badge_rag_w3'     => 'rag_week_3',
        ),
    );

    /* ── Coin values ── */
    const COIN_TASK          = 10;
    const COIN_RAG_SPARK     = 10;
    const COIN_RAG_EMBER     = 15;
    const COIN_RAG_BLAZE     = 20;
    const COIN_WEEK_COMPLETE = 25;
    const COIN_WEEK_ACHIEVER = 50;

    /* ── RAG badge slugs for evolution tracking ── */
    const RAG_BADGES = array(
        1 => 'badge_rag_w1',
        2 => 'badge_rag_w2',
        3 => 'badge_rag_w3',
    );

    /* ── RAG coin values ── */
    const RAG_COINS = array(
        1 => 10,  // Spark
        2 => 15,  // Ember
        3 => 20,  // Blaze
    );

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
        $task_statuses = $this->get_all_task_statuses($student_id);

        foreach (self::WEEK_BADGES as $week_num => $badges) {
            $this->evaluate_week($student_id, $week_num, $badges, $task_statuses);
        }
    }

    /* ================================================================
       PER-WEEK EVALUATION
       ================================================================ */
    private function evaluate_week($student_id, $week_num, $badges, $task_statuses) {
        $completed_count = 0;

        foreach ($badges as $badge_slug => $task_slug) {
            $status = $task_statuses[$task_slug] ?? 'not_started';

            if ($status === 'completed') {
                $completed_count++;
                $this->maybe_award_task_badge($student_id, $badge_slug, $task_slug, $week_num);
            }
        }

        /* Week completion badges */
        if ($completed_count === count($badges)) {
            $complete_slug = 'badge_week' . $week_num . '_complete';
            if (!$this->db->has_badge($student_id, $complete_slug)) {
                $this->db->award_badge($student_id, $complete_slug, self::COIN_WEEK_COMPLETE);
                $this->wallet->earn($student_id, $complete_slug, self::COIN_WEEK_COMPLETE,
                    'Week ' . $week_num . ' completed — all tasks done!');
            }

            /* Achiever — all 5 completed within 7 days of the first badge */
            $achiever_slug = 'badge_week' . $week_num . '_achiever';
            if (!$this->db->has_badge($student_id, $achiever_slug)) {
                if ($this->check_achiever($student_id, $badges)) {
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
    private function maybe_award_task_badge($student_id, $badge_slug, $task_slug, $week_num) {
        if ($this->db->has_badge($student_id, $badge_slug)) return;

        /* Determine coin value — RAG badges have different values */
        $rag_slug = self::RAG_BADGES[$week_num] ?? null;
        if ($badge_slug === $rag_slug) {
            $coins = self::RAG_COINS[$week_num] ?? self::COIN_TASK;
        } else {
            $coins = self::COIN_TASK;
        }

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
    private function check_achiever($student_id, $badges) {
        $task_badge_slugs = array_keys($badges);
        $dates = $this->db->get_week_badge_dates($student_id, $task_badge_slugs);

        if (count($dates) < count($task_badge_slugs)) return false;

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
            'badge_word_assoc'      => 'Word Association',
            'badge_junk_jobs'       => 'Junk Jobs',
            'badge_who_am_i_1'      => 'Who Am I',
            'badge_super_strengths' => 'Super Strengths',
            'badge_rag_w1'          => 'RAG Spark',
            'badge_week1_complete'  => 'Week 1 Complete',
            'badge_week1_achiever'  => 'Week 1 Achiever',
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