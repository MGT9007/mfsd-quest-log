<?php
/**
 * MFSD Quest Log — Admin Page
 * v1.7.0 — per-badge shimmer and coin spin controls.
 */

if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) wp_die('Unauthorized');

global $wpdb;

$db     = new MFSD_Quest_Log_DB();
$wallet = new MFSD_Quest_Log_Wallet();
$engine = new MFSD_Quest_Log_Engine();

/* All badge slugs with display names for the UI */
$all_badges = array(
    'Week 1' => array(
        'badge_word_assoc'      => 'Word Association',
        'badge_junk_jobs'       => 'Junk Jobs',
        'badge_who_am_i_1'      => 'Who Am I',
        'badge_super_strengths' => 'Super Strengths',
        'badge_rag_w1'          => 'Weekly RAG (Spark)',
    ),
    'Week 2' => array(
        'badge_fav_subject' => 'Favourite Subject',
        'badge_barriers'    => 'Barriers',
        'badge_dream_jobs'  => 'Dream Jobs',
        'badge_who_am_i_2'  => 'Who Am I (Part 2)',
        'badge_rag_w2'      => 'Weekly RAG (Ember)',
    ),
    'Week 3' => array(
        'badge_fifty_quid' => '£50 on Success',
        'badge_hp_wheel'   => 'HP Wheel',
        'badge_what_is_hp' => 'What is HP?',
        'badge_dream_life' => 'Dream Life',
        'badge_rag_w3'     => 'Weekly RAG (Blaze)',
    ),
);

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

    /* Wallet page setting */
    $wallet_page_id = (int) ($_POST['wallet_page_id'] ?? 0);
    update_option('mfsd_quest_wallet_page_id', $wallet_page_id);

    /* Global animation toggles */
    $global_anims = array('float', 'border_glow', 'fire_flicker', 'chest_wobble', 'locked_pulse', 'progress_shine');
    foreach ($global_anims as $key) {
        update_option('mfsd_quest_anim_' . $key, isset($_POST['anim_' . $key]) ? '1' : '0');
    }

    /* Per-badge shimmer config — stored as JSON */
    $shimmer_config = array();
    if (isset($_POST['shimmer']) && is_array($_POST['shimmer'])) {
        foreach ($_POST['shimmer'] as $slug => $cfg) {
            $shimmer_config[sanitize_key($slug)] = array(
                'on'       => isset($cfg['on']) ? true : false,
                'interval' => max(2, min(30, (int) ($cfg['interval'] ?? 5))),
            );
        }
    }
    update_option('mfsd_quest_shimmer_config', wp_json_encode($shimmer_config));

    /* Per-badge coin spin config — stored as JSON */
    $coin_config = array(
        'header' => array(
            'on'       => isset($_POST['coin_header_on']) ? true : false,
            'interval' => max(2, min(30, (int) ($_POST['coin_header_interval'] ?? 5))),
        ),
        'badges' => array(),
    );
    if (isset($_POST['coinspin']) && is_array($_POST['coinspin'])) {
        foreach ($_POST['coinspin'] as $slug => $cfg) {
            $coin_config['badges'][sanitize_key($slug)] = array(
                'on'       => isset($cfg['on']) ? true : false,
                'interval' => max(2, min(30, (int) ($cfg['interval'] ?? 4))),
            );
        }
    }
    update_option('mfsd_quest_coin_config', wp_json_encode($coin_config));

    echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
}

/* ── Load data ── */
$student_counts = $db->get_all_student_badge_counts();
$coins_per_min  = (int) get_option('mfsd_quest_coins_per_minute', 10);

/* Global anim settings */
$anim = array(
    'float'          => get_option('mfsd_quest_anim_float', '1') === '1',
    'border_glow'    => get_option('mfsd_quest_anim_border_glow', '1') === '1',
    'fire_flicker'   => get_option('mfsd_quest_anim_fire_flicker', '1') === '1',
    'chest_wobble'   => get_option('mfsd_quest_anim_chest_wobble', '1') === '1',
    'locked_pulse'   => get_option('mfsd_quest_anim_locked_pulse', '1') === '1',
    'progress_shine' => get_option('mfsd_quest_anim_progress_shine', '1') === '1',
);

/* Per-badge configs */
$shimmer_raw  = get_option('mfsd_quest_shimmer_config', '{}');
$shimmer_cfg  = json_decode($shimmer_raw, true) ?: array();
$coin_raw     = get_option('mfsd_quest_coin_config', '{}');
$coin_cfg     = json_decode($coin_raw, true) ?: array();
$coin_header  = $coin_cfg['header'] ?? array('on' => true, 'interval' => 5);
$coin_badges  = $coin_cfg['badges'] ?? array();

$students = get_users(array('role' => 'student', 'number' => 100, 'orderby' => 'display_name'));
?>

<div class="wrap">
    <h1>Quest Log Admin</h1>

    <h2 class="nav-tab-wrapper">
        <a class="nav-tab nav-tab-active" href="#" onclick="qlTab(event,'ql-tab-overview')">Overview</a>
        <a class="nav-tab" href="#" onclick="qlTab(event,'ql-tab-tools')">Tools</a>
        <a class="nav-tab" href="#" onclick="qlTab(event,'ql-tab-settings')">Settings</a>
    </h2>

    <!-- ═══════════════ OVERVIEW TAB ═══════════════ -->
    <div id="ql-tab-overview" class="ql-admin-tab">
        <h3>Student Badge Overview</h3>
        <?php if (empty($student_counts)): ?>
            <p>No badges earned yet.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead><tr><th>Student</th><th>Badges Earned</th><th>Total Coins Earned</th><th>Current Balance</th></tr></thead>
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

        <?php if (!empty($_GET['view_student'])):
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

    <!-- ═══════════════ TOOLS TAB ═══════════════ -->
    <div id="ql-tab-tools" class="ql-admin-tab" style="display:none;">
        <h3>Award Bonus Coins</h3>
        <form method="post" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:500px;margin-bottom:30px;">
            <?php wp_nonce_field('mfsd_quest_bonus'); ?>
            <table class="form-table">
                <tr><th>Student</th><td><select name="bonus_user_id" required><option value="">— Select —</option><?php foreach ($students as $s): ?><option value="<?php echo $s->ID; ?>"><?php echo esc_html($s->display_name); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th>Coins</th><td><input type="number" name="bonus_coins" min="1" max="100" value="10" required></td></tr>
                <tr><th>Description</th><td><input type="text" name="bonus_description" value="Teacher bonus" class="regular-text"></td></tr>
            </table>
            <button type="submit" name="mfsd_quest_bonus" class="button button-primary">Award Coins</button>
        </form>

        <h3>Re-evaluate Student Badges</h3>
        <form method="post" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:500px;margin-bottom:30px;">
            <?php wp_nonce_field('mfsd_quest_reevaluate'); ?>
            <p>Re-run the badge evaluation engine for a student (awards any missing badges).</p>
            <select name="reeval_user_id" required><option value="">— Select —</option><?php foreach ($students as $s): ?><option value="<?php echo $s->ID; ?>"><?php echo esc_html($s->display_name); ?></option><?php endforeach; ?></select>
            <button type="submit" name="mfsd_quest_reevaluate" class="button">Re-evaluate</button>
        </form>

        <h3>Clear Student Data</h3>
        <form method="post" style="background:#fff3cd;padding:20px;border:1px solid #ffc107;border-radius:8px;max-width:500px;">
            <?php wp_nonce_field('mfsd_quest_clear_student'); ?>
            <p><strong>Warning:</strong> This deletes all badges and wallet transactions for the selected student.</p>
            <select name="clear_user_id" required><option value="">— Select —</option><?php foreach ($students as $s): ?><option value="<?php echo $s->ID; ?>"><?php echo esc_html($s->display_name); ?></option><?php endforeach; ?></select>
            <button type="submit" name="mfsd_quest_clear_student" class="button" onclick="return confirm('Are you sure? This cannot be undone.');">Clear Data</button>
        </form>
    </div>

    <!-- ═══════════════ SETTINGS TAB ═══════════════ -->
    <div id="ql-tab-settings" class="ql-admin-tab" style="display:none;">
        <form method="post">
            <?php wp_nonce_field('mfsd_quest_settings'); ?>

            <!-- Arcade Economy -->
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:800px;margin-bottom:24px;">
                <h3 style="margin-top:0;">Arcade Economy</h3>
                <table class="form-table">
                    <tr>
                        <th>Coins per minute</th>
                        <td>
                            <input type="number" name="coins_per_minute" min="1" max="100" value="<?php echo $coins_per_min; ?>">
                            <p class="description">Default: 10 coins = 1 minute of arcade time.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Wallet Page -->
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:800px;margin-bottom:24px;">
                <h3 style="margin-top:0;">Coin Wallet Page</h3>
                <p class="description" style="margin-bottom:12px;">Select the page containing the <code>[mfsd_coin_wallet]</code> shortcode. The header coin balance on the Quest Log will link to this page.</p>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th>Wallet page</th>
                        <td>
                            <?php
                            $wallet_page_id = (int) get_option('mfsd_quest_wallet_page_id', 0);
                            wp_dropdown_pages(array(
                                'name'              => 'wallet_page_id',
                                'selected'          => $wallet_page_id,
                                'show_option_none'   => '— Not set (coin balance won\'t link) —',
                                'option_none_value'  => '0',
                            ));
                            ?>
                            <p class="description">Create a page with <code>[mfsd_coin_wallet]</code> then select it here.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ═══ Per-Badge Shimmer ═══ -->
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:800px;margin-bottom:24px;">
                <h3 style="margin-top:0;">Shimmer Sweep — Per Badge</h3>
                <p class="description" style="margin-bottom:12px;">Diagonal light sweep across earned badge images. Configure per badge.</p>
                <table class="widefat" style="max-width:700px;">
                    <thead><tr><th>Badge</th><th style="width:80px;text-align:center;">Enabled</th><th style="width:140px;">Interval (s)</th></tr></thead>
                    <tbody>
                    <?php foreach ($all_badges as $week_label => $badges): ?>
                        <tr><td colspan="3" style="background:#f0f0f1;font-weight:600;padding:8px 10px;"><?php echo esc_html($week_label); ?></td></tr>
                        <?php foreach ($badges as $slug => $label):
                            $s_cfg = $shimmer_cfg[$slug] ?? array('on' => false, 'interval' => 5);
                        ?>
                            <tr>
                                <td><?php echo esc_html($label); ?><br><code style="font-size:11px;color:#888;"><?php echo esc_html($slug); ?></code></td>
                                <td style="text-align:center;"><input type="checkbox" name="shimmer[<?php echo esc_attr($slug); ?>][on]" value="1" <?php checked(!empty($s_cfg['on'])); ?>></td>
                                <td><input type="number" name="shimmer[<?php echo esc_attr($slug); ?>][interval]" min="2" max="30" value="<?php echo (int)($s_cfg['interval'] ?? 5); ?>" style="width:60px;"> sec</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ═══ Per-Badge Coin Spin ═══ -->
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:800px;margin-bottom:24px;">
                <h3 style="margin-top:0;">Coin Spin — Per Location</h3>
                <p class="description" style="margin-bottom:12px;">Coin flip animation on the header wallet and on each badge's +coins indicator.</p>
                <table class="widefat" style="max-width:700px;">
                    <thead><tr><th>Location</th><th style="width:80px;text-align:center;">Enabled</th><th style="width:140px;">Interval (s)</th></tr></thead>
                    <tbody>
                        <tr style="background:#e8f4fd;">
                            <td><strong>Header Wallet Coin</strong></td>
                            <td style="text-align:center;"><input type="checkbox" name="coin_header_on" value="1" <?php checked(!empty($coin_header['on'])); ?>></td>
                            <td><input type="number" name="coin_header_interval" min="2" max="30" value="<?php echo (int)($coin_header['interval'] ?? 5); ?>" style="width:60px;"> sec</td>
                        </tr>
                    <?php foreach ($all_badges as $week_label => $badges): ?>
                        <tr><td colspan="3" style="background:#f0f0f1;font-weight:600;padding:8px 10px;"><?php echo esc_html($week_label); ?></td></tr>
                        <?php foreach ($badges as $slug => $label):
                            $c_cfg = $coin_badges[$slug] ?? array('on' => false, 'interval' => 4);
                        ?>
                            <tr>
                                <td><?php echo esc_html($label); ?> coin<br><code style="font-size:11px;color:#888;"><?php echo esc_attr($slug); ?></code></td>
                                <td style="text-align:center;"><input type="checkbox" name="coinspin[<?php echo esc_attr($slug); ?>][on]" value="1" <?php checked(!empty($c_cfg['on'])); ?>></td>
                                <td><input type="number" name="coinspin[<?php echo esc_attr($slug); ?>][interval]" min="2" max="30" value="<?php echo (int)($c_cfg['interval'] ?? 4); ?>" style="width:60px;"> sec</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ═══ Global Animation Toggles ═══ -->
            <div style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;max-width:800px;margin-bottom:24px;">
                <h3 style="margin-top:0;">Other Animations</h3>
                <p class="description" style="margin-bottom:12px;">Global on/off for each animation type.</p>
                <table class="form-table" style="margin-top:0;">
                    <tr><th><label>Gentle Float</label><br><span class="description" style="font-weight:normal;">Earned badges bob up and down</span></th>
                        <td><label><input type="checkbox" name="anim_float" value="1" <?php checked($anim['float']); ?>> Enabled</label></td></tr>
                    <tr><th><label>Border Glow</label><br><span class="description" style="font-weight:normal;">Earned cards pulse with gold border</span></th>
                        <td><label><input type="checkbox" name="anim_border_glow" value="1" <?php checked($anim['border_glow']); ?>> Enabled</label></td></tr>
                    <tr><th><label>Fire Flicker</label><br><span class="description" style="font-weight:normal;">Lit RAG stages wobble like flames</span></th>
                        <td><label><input type="checkbox" name="anim_fire_flicker" value="1" <?php checked($anim['fire_flicker']); ?>> Enabled</label></td></tr>
                    <tr><th><label>Chest Wobble</label><br><span class="description" style="font-weight:normal;">Earned chests jiggle on hover</span></th>
                        <td><label><input type="checkbox" name="anim_chest_wobble" value="1" <?php checked($anim['chest_wobble']); ?>> Enabled</label></td></tr>
                    <tr><th><label>Locked Pulse</label><br><span class="description" style="font-weight:normal;">Locked badges breathe in and out</span></th>
                        <td><label><input type="checkbox" name="anim_locked_pulse" value="1" <?php checked($anim['locked_pulse']); ?>> Enabled</label></td></tr>
                    <tr><th><label>Progress Bar Shine</label><br><span class="description" style="font-weight:normal;">Scrolling gradient stripe on progress bars</span></th>
                        <td><label><input type="checkbox" name="anim_progress_shine" value="1" <?php checked($anim['progress_shine']); ?>> Enabled</label></td></tr>
                </table>
            </div>

            <button type="submit" name="mfsd_quest_save_settings" class="button button-primary" style="font-size:15px;padding:8px 24px;">Save All Settings</button>
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