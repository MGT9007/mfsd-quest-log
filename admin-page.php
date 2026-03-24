<?php
/**
 * MFSD Quest Log — Admin Page
 * Student badge overview, wallet management, and data tools.
 */

if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) wp_die('Unauthorized');

global $wpdb;

$db     = new MFSD_Quest_Log_DB();
$wallet = new MFSD_Quest_Log_Wallet();
$engine = new MFSD_Quest_Log_Engine();

/* ── Handle admin actions ── */

// Award bonus coins
if (isset($_POST['mfsd_quest_bonus']) && check_admin_referer('mfsd_quest_bonus')) {
    $uid   = (int) $_POST['bonus_user_id'];
    $coins = (int) $_POST['bonus_coins'];
    $desc  = sanitize_text_field($_POST['bonus_description'] ?? 'Teacher bonus');
    if ($uid > 0 && $coins > 0) {
        $wallet->bonus($uid, 'teacher_bonus', $coins, $desc);
        echo '<div class="notice notice-success"><p>' . $coins . ' bonus coins awarded to user #' . $uid . '.</p></div>';
    }
}

// Clear student data
if (isset($_POST['mfsd_quest_clear_student']) && check_admin_referer('mfsd_quest_clear_student')) {
    $uid = (int) $_POST['clear_user_id'];
    if ($uid > 0) {
        $db->delete_student_badges($uid);
        $wallet->delete_student_transactions($uid);
        echo '<div class="notice notice-success"><p>All Quest Log data cleared for user #' . $uid . '.</p></div>';
    }
}

// Re-evaluate a student
if (isset($_POST['mfsd_quest_reevaluate']) && check_admin_referer('mfsd_quest_reevaluate')) {
    $uid = (int) $_POST['reeval_user_id'];
    if ($uid > 0) {
        $engine->evaluate_all($uid);
        echo '<div class="notice notice-success"><p>Badges re-evaluated for user #' . $uid . '.</p></div>';
    }
}

// Settings
if (isset($_POST['mfsd_quest_save_settings']) && check_admin_referer('mfsd_quest_settings')) {
    $cpm = max(1, (int) ($_POST['coins_per_minute'] ?? 10));
    update_option('mfsd_quest_coins_per_minute', $cpm);
    echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
}

/* ── Load overview data ── */
$student_counts = $db->get_all_student_badge_counts();
$coins_per_min  = (int) get_option('mfsd_quest_coins_per_minute', 10);

/* ── Get students list ── */
$students = get_users(array('role' => 'student', 'number' => 100, 'orderby' => 'display_name'));
?>

<div class="wrap">
    <h1>Quest Log Admin</h1>

    <!-- TABS -->
    <h2 class="nav-tab-wrapper">
        <a class="nav-tab nav-tab-active" href="#" onclick="qlTab(event,'ql-tab-overview')">Overview</a>
        <a class="nav-tab" href="#" onclick="qlTab(event,'ql-tab-tools')">Tools</a>
        <a class="nav-tab" href="#" onclick="qlTab(event,'ql-tab-settings')">Settings</a>
    </h2>

    <!-- OVERVIEW TAB -->
    <div id="ql-tab-overview" class="ql-admin-tab">
        <h3>Student Badge Overview</h3>
        <?php if (empty($student_counts)): ?>
            <p>No badges earned yet.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Badges Earned</th>
                        <th>Total Coins Earned</th>
                        <th>Current Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($student_counts as $row):
                        $user = get_userdata($row['student_id']);
                        $balance = $wallet->get_balance($row['student_id']);
                    ?>
                        <tr>
                            <td><?php echo $user ? esc_html($user->display_name) : 'User #' . $row['student_id']; ?></td>
                            <td><?php echo (int) $row['badge_count']; ?></td>
                            <td><?php echo (int) $row['total_coins']; ?></td>
                            <td><?php echo $balance; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="margin-top:30px;">Individual Student Badges</h3>
        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="mfsd-quest-log">
            <label>Select Student:
                <select name="view_student">
                    <option value="">— Select —</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?php echo $s->ID; ?>" <?php selected(isset($_GET['view_student']) ? (int)$_GET['view_student'] : 0, $s->ID); ?>>
                            <?php echo esc_html($s->display_name); ?> (#<?php echo $s->ID; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button">View</button>
        </form>

        <?php
        if (!empty($_GET['view_student'])):
            $view_id = (int) $_GET['view_student'];
            $view_user = get_userdata($view_id);
            $view_badges = $db->get_student_badges($view_id);
            $view_balance = $wallet->get_balance($view_id);
            $view_history = $wallet->get_history($view_id, 30);
        ?>
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;">
                <h4><?php echo $view_user ? esc_html($view_user->display_name) : 'User #' . $view_id; ?> — Balance: <?php echo $view_balance; ?> coins</h4>

                <h5>Badges (<?php echo count($view_badges); ?>)</h5>
                <?php if (empty($view_badges)): ?>
                    <p>No badges earned.</p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th>Badge</th><th>Coins</th><th>Earned At</th><th>Metadata</th></tr></thead>
                        <tbody>
                        <?php foreach ($view_badges as $slug => $b): ?>
                            <tr>
                                <td><strong><?php echo esc_html(MFSD_Quest_Log_Engine::get_badge_description($slug)); ?></strong><br><code><?php echo esc_html($slug); ?></code></td>
                                <td><?php echo (int) $b['coins_awarded']; ?></td>
                                <td><?php echo esc_html($b['earned_at']); ?></td>
                                <td><small><?php echo $b['metadata'] ? esc_html(wp_json_encode($b['metadata'])) : '—'; ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h5 style="margin-top:20px;">Recent Transactions</h5>
                <?php if (empty($view_history)): ?>
                    <p>No transactions.</p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th>Type</th><th>Source</th><th>Coins</th><th>Balance After</th><th>Description</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($view_history as $tx): ?>
                            <tr>
                                <td><?php echo esc_html($tx['transaction_type']); ?></td>
                                <td><code><?php echo esc_html($tx['source']); ?></code></td>
                                <td style="color:<?php echo $tx['coins'] >= 0 ? '#2e7d32' : '#c62828'; ?>;font-weight:600;"><?php echo ($tx['coins'] >= 0 ? '+' : '') . $tx['coins']; ?></td>
                                <td><?php echo (int) $tx['balance_after']; ?></td>
                                <td><?php echo esc_html($tx['description']); ?></td>
                                <td><?php echo esc_html($tx['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TOOLS TAB -->
    <div id="ql-tab-tools" class="ql-admin-tab" style="display:none;">

        <h3>Award Bonus Coins</h3>
        <form method="post" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:500px;margin-bottom:30px;">
            <?php wp_nonce_field('mfsd_quest_bonus'); ?>
            <table class="form-table">
                <tr>
                    <th>Student</th>
                    <td>
                        <select name="bonus_user_id" required>
                            <option value="">— Select —</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo $s->ID; ?>"><?php echo esc_html($s->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr><th>Coins</th><td><input type="number" name="bonus_coins" min="1" max="100" value="10" required></td></tr>
                <tr><th>Description</th><td><input type="text" name="bonus_description" value="Teacher bonus" class="regular-text"></td></tr>
            </table>
            <button type="submit" name="mfsd_quest_bonus" class="button button-primary">Award Coins</button>
        </form>

        <h3>Re-evaluate Student Badges</h3>
        <form method="post" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:500px;margin-bottom:30px;">
            <?php wp_nonce_field('mfsd_quest_reevaluate'); ?>
            <p>Re-run the badge evaluation engine for a student (awards any missing badges).</p>
            <select name="reeval_user_id" required>
                <option value="">— Select —</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?php echo $s->ID; ?>"><?php echo esc_html($s->display_name); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="mfsd_quest_reevaluate" class="button">Re-evaluate</button>
        </form>

        <h3>Clear Student Data</h3>
        <form method="post" style="background:#fff3cd;padding:20px;border:1px solid #ffc107;border-radius:8px;max-width:500px;">
            <?php wp_nonce_field('mfsd_quest_clear_student'); ?>
            <p><strong>Warning:</strong> This deletes all badges and wallet transactions for the selected student.</p>
            <select name="clear_user_id" required>
                <option value="">— Select —</option>
                <?php foreach ($students as $s): ?>
                    <option value="<?php echo $s->ID; ?>"><?php echo esc_html($s->display_name); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="mfsd_quest_clear_student" class="button" onclick="return confirm('Are you sure? This cannot be undone.');">Clear Data</button>
        </form>
    </div>

    <!-- SETTINGS TAB -->
    <div id="ql-tab-settings" class="ql-admin-tab" style="display:none;">
        <form method="post" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:500px;">
            <?php wp_nonce_field('mfsd_quest_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Coins per minute (Arcade)</th>
                    <td>
                        <input type="number" name="coins_per_minute" min="1" max="100" value="<?php echo $coins_per_min; ?>">
                        <p class="description">Default: 10 coins = 1 minute of arcade time.</p>
                    </td>
                </tr>
            </table>
            <button type="submit" name="mfsd_quest_save_settings" class="button button-primary">Save Settings</button>
        </form>
    </div>
</div>

<script>
function qlTab(e, tabId) {
    e.preventDefault();
    document.querySelectorAll('.ql-admin-tab').forEach(function(t) { t.style.display = 'none'; });
    document.querySelectorAll('.nav-tab').forEach(function(t) { t.classList.remove('nav-tab-active'); });
    document.getElementById(tabId).style.display = 'block';
    e.target.classList.add('nav-tab-active');
}
</script>
