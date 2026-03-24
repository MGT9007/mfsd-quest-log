<?php
/**
 * MFSD Quest Log — Wallet Transactions
 * Transactional coin ledger with running balance.
 */

if (!defined('ABSPATH')) exit;

class MFSD_Quest_Log_Wallet {

    const TABLE = 'mfsd_wallet';

    /** Coins per minute of arcade time (configurable) */
    public static function coins_per_minute() {
        return (int) get_option('mfsd_quest_coins_per_minute', 10);
    }

    /* ================================================================
       GET BALANCE — latest balance_after from ledger
       ================================================================ */
    public function get_balance($student_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return 0;

        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance_after FROM $table WHERE student_id = %d ORDER BY created_at DESC, id DESC LIMIT 1",
            $student_id
        ));

        return $balance !== null ? (int) $balance : 0;
    }

    /* ================================================================
       EARN — badge award or bonus
       ================================================================ */
    public function earn($student_id, $source, $coins, $description = '') {
        $current = $this->get_balance($student_id);
        $new_balance = $current + $coins;

        return $this->insert_transaction($student_id, 'earn', $source, $coins, 0, $new_balance, $description);
    }

    /* ================================================================
       BONUS — teacher awards extra coins
       ================================================================ */
    public function bonus($student_id, $source, $coins, $description = '') {
        $current = $this->get_balance($student_id);
        $new_balance = $current + $coins;

        return $this->insert_transaction($student_id, 'bonus', $source, $coins, 0, $new_balance, $description);
    }

    /* ================================================================
       SPEND — arcade play (phase 2)
       ================================================================ */
    public function spend($student_id, $source, $coins, $description = '') {
        $current = $this->get_balance($student_id);
        if ($coins > $current) return false; /* Insufficient funds */

        $new_balance = $current - $coins;
        $minutes = round($coins / self::coins_per_minute(), 1);

        return $this->insert_transaction($student_id, 'spend', $source, -$coins, $minutes, $new_balance, $description);
    }

    /* ================================================================
       REFUND — interrupted arcade session
       ================================================================ */
    public function refund($student_id, $source, $coins, $description = '') {
        $current = $this->get_balance($student_id);
        $new_balance = $current + $coins;

        return $this->insert_transaction($student_id, 'refund', $source, $coins, 0, $new_balance, $description);
    }

    /* ================================================================
       TRANSACTION HISTORY
       ================================================================ */
    public function get_history($student_id, $limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return array();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT transaction_type, source, coins, minutes_equivalent, balance_after, description, created_at
             FROM $table WHERE student_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
            $student_id, $limit
        ), ARRAY_A);
    }

    /* ================================================================
       TOTAL EARNED (lifetime, ignoring spends)
       ================================================================ */
    public function get_total_earned($student_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return 0;

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(coins) FROM $table WHERE student_id = %d AND transaction_type IN ('earn','bonus','refund')",
            $student_id
        ));

        return $total ? (int) $total : 0;
    }

    /* ================================================================
       INSERT TRANSACTION
       ================================================================ */
    private function insert_transaction($student_id, $type, $source, $coins, $minutes, $balance_after, $description) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        return $wpdb->insert($table, array(
            'student_id'         => $student_id,
            'transaction_type'   => $type,
            'source'             => $source,
            'coins'              => $coins,
            'minutes_equivalent' => $minutes,
            'balance_after'      => $balance_after,
            'description'        => $description,
        ));
    }

    /* ================================================================
       ADMIN — delete all transactions for a student
       ================================================================ */
    public function delete_student_transactions($student_id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::TABLE, array('student_id' => $student_id));
    }
}
