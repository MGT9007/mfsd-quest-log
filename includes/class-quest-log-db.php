<?php
/**
 * MFSD Quest Log — Database Layer
 * Badge CRUD + table creation.
 */

if (!defined('ABSPATH')) exit;

class MFSD_Quest_Log_DB {

    const TBL_BADGES = 'mfsd_badges';
    const TBL_WALLET = 'mfsd_wallet';

    /* ================================================================
       CREATE TABLES
       ================================================================ */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $badges = $wpdb->prefix . self::TBL_BADGES;
        dbDelta("CREATE TABLE $badges (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT,
            student_id BIGINT(20) UNSIGNED NOT NULL,
            badge_slug VARCHAR(50) NOT NULL,
            coins_awarded INT DEFAULT 0,
            earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            metadata JSON NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_student_badge (student_id, badge_slug),
            KEY idx_student (student_id)
        ) ENGINE=InnoDB $charset;");

        $wallet = $wpdb->prefix . self::TBL_WALLET;
        dbDelta("CREATE TABLE $wallet (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT,
            student_id BIGINT(20) UNSIGNED NOT NULL,
            transaction_type ENUM('earn','spend','bonus','refund') NOT NULL,
            source VARCHAR(50) NOT NULL,
            coins INT NOT NULL,
            minutes_equivalent DECIMAL(5,1) DEFAULT 0,
            balance_after INT NOT NULL,
            description VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_student (student_id),
            KEY idx_student_balance (student_id, created_at DESC)
        ) ENGINE=InnoDB $charset;");
    }

    /* ================================================================
       BADGE QUERIES
       ================================================================ */

    /**
     * Check if a student has a specific badge.
     */
    public function has_badge($student_id, $badge_slug) {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_BADGES;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE student_id = %d AND badge_slug = %s",
            $student_id, $badge_slug
        ));
    }

    /**
     * Award a badge (insert row). Returns true if newly inserted, false if already exists.
     */
    public function award_badge($student_id, $badge_slug, $coins, $metadata = null) {
        if ($this->has_badge($student_id, $badge_slug)) return false;

        global $wpdb;
        $table = $wpdb->prefix . self::TBL_BADGES;

        $data = array(
            'student_id'    => $student_id,
            'badge_slug'    => $badge_slug,
            'coins_awarded' => $coins,
            'metadata'      => $metadata ? wp_json_encode($metadata) : null,
        );

        $wpdb->insert($table, $data);
        return $wpdb->insert_id > 0;
    }

    /**
     * Get all badges for a student (keyed by badge_slug for easy lookup).
     */
    public function get_student_badges($student_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_BADGES;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT badge_slug, coins_awarded, earned_at, metadata FROM $table WHERE student_id = %d ORDER BY earned_at ASC",
            $student_id
        ), ARRAY_A);

        $badges = array();
        foreach ($rows as $row) {
            $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;
            $badges[$row['badge_slug']] = $row;
        }
        return $badges;
    }

    /**
     * Get the earliest earned_at for a student's badges in a specific week.
     */
    public function get_week_badge_dates($student_id, $badge_slugs) {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_BADGES;

        $placeholders = implode(',', array_fill(0, count($badge_slugs), '%s'));
        $params = array_merge(array($student_id), $badge_slugs);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT badge_slug, earned_at FROM $table WHERE student_id = %d AND badge_slug IN ($placeholders)",
            ...$params
        ), ARRAY_A);
    }

    /**
     * Delete all badges for a student (admin tool).
     */
    public function delete_student_badges($student_id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::TBL_BADGES, array('student_id' => $student_id));
    }

    /**
     * Get badge count per student (for admin overview).
     */
    public function get_all_student_badge_counts() {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_BADGES;
        return $wpdb->get_results(
            "SELECT student_id, COUNT(*) as badge_count, SUM(coins_awarded) as total_coins
             FROM $table GROUP BY student_id ORDER BY total_coins DESC",
            ARRAY_A
        );
    }
}
