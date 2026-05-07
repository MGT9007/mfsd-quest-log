<?php
/**
 * MFSD Quest Log — Frontend Renderer
 * v1.7.0 — removed character subtitle from header, Who Am I badge frame + character overlay.
 */

if (!defined('ABSPATH')) exit;

class MFSD_Quest_Log_Renderer {

    /* ── Badge display config per week ── */
    const WEEK_CONFIG = array(
        1 => array(
            'title' => 'Week 1 — Self-Awareness & The Solutions Lens',
            'badges' => array(
                'badge_solution_lens'   => array('label' => 'The Solution Lens', 'image' => 'badge_solution_lens.png'),
                'badge_word_assoc'      => array('label' => 'Word Association',  'image' => 'badge_word_assoc.png'),
                'badge_who_am_i_1'      => array('label' => 'Who Am I',          'image' => 'badge_who_am_i_1.png'),
                'badge_super_strengths' => array('label' => 'Super Strengths',   'image' => 'badge_super_strengths.png'),
                'badge_rag_w1'          => array('label' => 'Weekly RAG',        'image' => 'badge_rag_w1.png'),
            ),
        ),
        2 => array(
            'title' => 'Week 2 — Interests, Barriers & Dreams into Plans',
            'badges' => array(
                'badge_junk_jobs'   => array('label' => 'Junk Jobs',         'image' => 'badge_junk_jobs.png'),
                'badge_fav_subject' => array('label' => 'Favourite Subject', 'image' => 'badge_locked.png'),
                'badge_barriers'    => array('label' => 'Barriers',          'image' => 'badge_locked.png'),
                'badge_dream_jobs'  => array('label' => 'Dream Jobs',        'image' => 'badge_locked.png'),
                'badge_who_am_i_2'  => array('label' => 'Who Am I (Part 2)', 'image' => 'badge_locked.png'),
                'badge_rag_w2'      => array('label' => 'Weekly RAG',        'image' => 'badge_locked.png'),
            ),
        ),
        3 => array(
            'title' => 'Week 3 — High Performance & Future Direction',
            'badges' => array(
                'badge_fifty_quid' => array('label' => '£50 on Success',  'image' => 'badge_locked.png'),
                'badge_hp_wheel'   => array('label' => 'HP Wheel',        'image' => 'badge_locked.png'),
                'badge_what_is_hp' => array('label' => 'What is HP?',     'image' => 'badge_locked.png'),
                'badge_dream_life' => array('label' => 'Dream Life',      'image' => 'badge_locked.png'),
                'badge_rag_w3'     => array('label' => 'Weekly RAG',      'image' => 'badge_locked.png'),
            ),
        ),
    );

    /* ================================================================
       MAIN RENDER
       ================================================================ */
    public function render($student_id, $badges, $balance, $character, $display_name, $images_url) {
        ob_start();
        ?>
        <div class="mfsd-quest-log" id="mfsd-quest-log-root">

            <?php $this->render_header($student_id, $display_name, $balance, $images_url); ?>

            <?php foreach (self::WEEK_CONFIG as $week_num => $week): ?>
                <?php $this->render_week_section($week_num, $week, $badges, $character, $images_url); ?>
            <?php endforeach; ?>

            <?php $this->render_rag_evolution($badges, $images_url); ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
       GET PROFILE PICTURE — try every known method
       ================================================================ */
    private function get_profile_picture_url($student_id, $fallback_url) {
        /*
         * 1. Check all known ProfilePress / avatar user meta keys.
         *    ProfilePress Pro stores uploaded avatars as attachment IDs or URLs.
         */
        $meta_keys = array(
            'pp_profile_image',
            'profilepress_profile_image',
            'wp_user_avatar',
            'pp_custom_avatar',
            'simple_local_avatar',
            'metronet_avatar_override',
            'user_avatar',
        );
        foreach ($meta_keys as $key) {
            $val = get_user_meta($student_id, $key, true);
            if (!empty($val)) {
                if (is_numeric($val)) {
                    $url = wp_get_attachment_url((int) $val);
                    if ($url) return $url;
                } elseif (filter_var($val, FILTER_VALIDATE_URL)) {
                    return $val;
                }
            }
        }

        /*
         * 2. Parse get_avatar() HTML — ProfilePress hooks here.
         *    Extract the src and use it if it's NOT a Gravatar default.
         */
        $avatar_html = get_avatar($student_id, 128);
        if ($avatar_html && preg_match('/src=["\']([^"\']+)["\']/', $avatar_html, $matches)) {
            $src = html_entity_decode($matches[1]);
            if (!empty($src)) {
                /* Non-Gravatar = ProfilePress or custom avatar — always use it */
                if (strpos($src, 'gravatar.com') === false) {
                    return $src;
                }
                /* Gravatar with a real image (not mystery/blank default) */
                if (strpos($src, 'd=mystery') === false
                    && strpos($src, 'd=blank') === false
                    && strpos($src, 'd=mp') === false
                    && strpos($src, 'd=mm') === false) {
                    return $src;
                }
            }
        }

        return $fallback_url;
    }

    /* ================================================================
       HEADER — profile picture, student name, coins
       No personality type shown here — that belongs on the badge.
       ================================================================ */
    private function render_header($student_id, $display_name, $balance, $images_url) {
        $fallback_src = $images_url . 'ui/avatar_f.png';
        $avatar_src   = $this->get_profile_picture_url($student_id, $fallback_src);
        ?>
        <div class="ql-header">
            <div class="ql-header-left">
                <div class="ql-avatar-frame">
                    <img src="<?php echo esc_url($avatar_src); ?>"
                         alt="<?php echo esc_attr($display_name); ?>"
                         class="ql-avatar-img"
                         width="64" height="64"
                         style="width:64px;height:64px;max-width:64px;max-height:64px;object-fit:cover;border-radius:50%;"
                         onerror="this.src='<?php echo esc_url($fallback_src); ?>'">
                </div>
                <div class="ql-header-info">
                    <h1 class="ql-player-name"><?php echo esc_html($display_name); ?></h1>
                </div>
            </div>
            <div class="ql-header-right">
                <?php
                $wallet_page_id = (int) get_option('mfsd_quest_wallet_page_id', 0);
                $wallet_url = $wallet_page_id > 0 ? get_permalink($wallet_page_id) : '';
                $tag_open  = $wallet_url ? '<a href="' . esc_url($wallet_url) . '" class="ql-coin-balance ql-coin-link" title="View Coin Wallet">' : '<div class="ql-coin-balance">';
                $tag_close = $wallet_url ? '</a>' : '</div>';
                echo $tag_open;
                ?>
                    <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="Coins" class="ql-coin-icon"
                         width="28" height="28"
                         style="width:28px;height:28px;max-width:28px;max-height:28px;object-fit:contain;">
                    <span class="ql-coin-amount" id="ql-coin-amount"><?php echo (int) $balance; ?></span>
                <?php echo $tag_close; ?>
            </div>
        </div>
        <?php
    }

    /* ================================================================
       WEEK SECTION — badge grid + chests
       ================================================================ */
    private function render_week_section($week_num, $week, $badges, $character, $images_url) {
        $task_badge_slugs = array_keys($week['badges']);
        $earned_count = 0;
        foreach ($task_badge_slugs as $slug) {
            if (isset($badges[$slug])) $earned_count++;
        }
        $total = count($task_badge_slugs);

        $complete_slug = 'badge_week' . $week_num . '_complete';
        $achiever_slug = 'badge_week' . $week_num . '_achiever';
        ?>
        <div class="ql-week-section<?php echo $earned_count === 0 ? ' ql-collapsed' : ''; ?>" data-week="<?php echo $week_num; ?>" data-earned="<?php echo $earned_count; ?>">
            <div class="ql-week-header" role="button" tabindex="0" aria-expanded="<?php echo $earned_count > 0 ? 'true' : 'false'; ?>">
                <h2 class="ql-week-title"><?php echo esc_html($week['title']); ?></h2>
                <div class="ql-week-header-right">
                    <div class="ql-week-progress">
                        <div class="ql-progress-bar">
                            <div class="ql-progress-fill" style="width: <?php echo ($total > 0 ? round(($earned_count / $total) * 100) : 0); ?>%"></div>
                        </div>
                        <span class="ql-progress-text"><?php echo $earned_count; ?>/<?php echo $total; ?></span>
                    </div>
                    <span class="ql-week-chevron">&#9660;</span>
                </div>
            </div>

            <div class="ql-week-body">
            <div class="ql-badge-grid">
                <?php foreach ($week['badges'] as $slug => $badge_config): ?>
                    <?php
                    $earned = isset($badges[$slug]);
                    $is_who_am_i = in_array($slug, array('badge_who_am_i_1', 'badge_who_am_i_2'));
                    $has_character = ($is_who_am_i && $earned && $character && !empty($character['filename']));

                    /* Default: locked badge */
                    $badge_image = $images_url . 'badges/badge_locked.png';
                    if ($earned) {
                        $badge_prefix = isset($badge_config['plugin'])
                            ? plugins_url($badge_config['plugin'])
                            : $images_url . 'badges/';
                        $badge_image = $badge_prefix . $badge_config['image'];
                    }

                    /* Who Am I character overlay URL (used separately below) */
                    $character_url = '';
                    if ($has_character) {
                        $character_url = ($character['avatars_url'] ?? ($images_url . 'characters/')) . $character['filename'];
                    }

                    $coins = $earned ? ($badges[$slug]['coins_awarded'] ?? 10) : null;

                    /* Who Am I badge label shows character name when earned */
                    $badge_label = $badge_config['label'];
                    $badge_sublabel = '';
                    if ($has_character) {
                        $badge_sublabel = 'The ' . $character['name'];
                    }
                    ?>
                    <div class="ql-badge-card <?php echo $earned ? 'earned' : 'locked'; ?>" data-badge="<?php echo esc_attr($slug); ?>">
                        <?php if ($has_character): ?>
                        <!-- Who Am I: portal frame as background, character as foreground -->
                        <div class="ql-badge-image-wrap" style="width:80px;height:80px;max-width:80px;max-height:80px;overflow:visible;position:relative;margin:0 auto 10px;background:url('<?php echo esc_url($badge_image); ?>') center/contain no-repeat;">
                            <img src="<?php echo esc_url($character_url); ?>"
                                 alt="<?php echo esc_attr($badge_sublabel); ?>"
                                 width="52" height="52"
                                 style="width:52px;height:52px;max-width:52px;max-height:52px;object-fit:contain;display:block;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">
                        <?php else: ?>
                        <div class="ql-badge-image-wrap" style="width:80px;height:80px;max-width:80px;max-height:80px;overflow:hidden;position:relative;margin:0 auto 10px;">
                            <img src="<?php echo esc_url($badge_image); ?>"
                                 alt="<?php echo esc_attr($badge_label); ?>"
                                 class="ql-badge-image"
                                 width="80" height="80"
                                 style="width:80px;height:80px;max-width:80px;max-height:80px;object-fit:contain;display:block;">
                        <?php endif; ?>
                            <?php if ($earned): ?>
                                <div class="ql-badge-glow"></div>
                            <?php endif; ?>
                        </div>
                        <div class="ql-badge-label"><?php echo esc_html($badge_label); ?></div>
                        <?php if ($badge_sublabel): ?>
                            <div class="ql-badge-sublabel" style="font-size:10px;color:#f0ad4e;font-weight:500;margin-top:2px;"><?php echo esc_html($badge_sublabel); ?></div>
                        <?php endif; ?>
                        <?php if ($earned && $coins): ?>
                            <div class="ql-badge-coins">+<?php echo $coins; ?>
                                <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"
                                     width="14" height="14"
                                     style="width:14px;height:14px;max-width:14px;max-height:14px;object-fit:contain;display:inline-block;">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ql-chests">
                <?php
                $complete_earned = isset($badges[$complete_slug]);
                $achiever_earned = isset($badges[$achiever_slug]);
                $complete_img = $complete_earned ? 'chest_complete.png' : 'chest_locked.png';
                $achiever_img = $achiever_earned ? 'chest_achiever.png' : 'chest_locked.png';
                ?>
                <div class="ql-chest <?php echo $complete_earned ? 'earned' : 'locked'; ?>">
                    <img src="<?php echo esc_url($images_url . 'chests/' . $complete_img); ?>" alt="Week Complete" class="ql-chest-img"
                         width="64" height="64"
                         style="width:64px;height:64px;max-width:64px;max-height:64px;object-fit:contain;">
                    <div class="ql-chest-label">Week Complete</div>
                    <?php if ($complete_earned): ?>
                        <div class="ql-chest-coins">+25
                            <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"
                                 width="14" height="14"
                                 style="width:14px;height:14px;max-width:14px;max-height:14px;object-fit:contain;display:inline-block;">
                        </div>
                    <?php else: ?>
                        <div class="ql-chest-hint">Complete all <?php echo $total; ?> tasks</div>
                    <?php endif; ?>
                </div>
                <div class="ql-chest <?php echo $achiever_earned ? 'earned' : 'locked'; ?>">
                    <img src="<?php echo esc_url($images_url . 'chests/' . $achiever_img); ?>" alt="High Achiever" class="ql-chest-img"
                         width="64" height="64"
                         style="width:64px;height:64px;max-width:64px;max-height:64px;object-fit:contain;">
                    <div class="ql-chest-label">High Achiever</div>
                    <?php if ($achiever_earned): ?>
                        <div class="ql-chest-coins">+50
                            <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"
                                 width="14" height="14"
                                 style="width:14px;height:14px;max-width:14px;max-height:14px;object-fit:contain;display:inline-block;">
                        </div>
                    <?php else: ?>
                        <div class="ql-chest-hint">Complete all <?php echo $total; ?> within 7 days</div>
                    <?php endif; ?>
                </div>
            </div>
            </div><!-- /.ql-week-body -->
        </div>
        <?php
    }

    /* ================================================================
       RAG EVOLUTION — Spark > Ember > Blaze
       ================================================================ */
    private function render_rag_evolution($badges, $images_url) {
        $stages = array(
            1 => array('name' => 'Spark',  'slug' => 'badge_rag_w1', 'image_lit' => 'fire/spark_lit.png',  'image_dark' => 'fire/spark_dark.png'),
            2 => array('name' => 'Ember',  'slug' => 'badge_rag_w2', 'image_lit' => 'fire/ember_lit.png',  'image_dark' => 'fire/ember_dark.png'),
            3 => array('name' => 'Blaze',  'slug' => 'badge_rag_w3', 'image_lit' => 'fire/blaze_lit.png',  'image_dark' => 'fire/blaze_dark.png'),
        );
        ?>
        <div class="ql-rag-evolution">
            <h2 class="ql-section-title">Reflection Journey</h2>
            <p class="ql-section-sub">Complete your Weekly RAG to evolve your fire!</p>
            <div class="ql-fire-path">
                <?php foreach ($stages as $num => $stage): ?>
                    <?php
                    $lit = isset($badges[$stage['slug']]);
                    $img = $lit ? $stage['image_lit'] : $stage['image_dark'];
                    ?>
                    <div class="ql-fire-stage <?php echo $lit ? 'lit' : 'dark'; ?>">
                        <img src="<?php echo esc_url($images_url . $img); ?>"
                             alt="<?php echo esc_attr($stage['name']); ?>"
                             class="ql-fire-img"
                             width="72" height="72"
                             style="width:72px;height:72px;max-width:72px;max-height:72px;object-fit:contain;"
                             onerror="this.style.display='none'">
                        <div class="ql-fire-label"><?php echo esc_html($stage['name']); ?></div>
                        <div class="ql-fire-week">Week <?php echo $num; ?> RAG</div>
                    </div>
                    <?php if ($num < 3): ?>
                        <div class="ql-fire-connector <?php echo $lit ? 'lit' : ''; ?>">
                            <span class="ql-fire-arrow">&rarr;</span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ================================================================
       WALLET PAGE — [mfsd_coin_wallet] shortcode output
       ================================================================ */
    public function render_wallet_page($student_id, $balance, $total_earned, $history, $display_name, $images_url, $quest_log_url) {
        $coins_per_min  = MFSD_Quest_Log_Wallet::coins_per_minute();
        $arcade_minutes = $coins_per_min > 0 ? round($balance / $coins_per_min, 1) : 0;
        ob_start();
        ?>
        <div class="mfsd-quest-log mfsd-wallet-page" id="mfsd-quest-log-root">

            <?php if ($quest_log_url): ?>
                <a href="<?php echo esc_url($quest_log_url); ?>" class="ql-back-link">&larr; Back to Quest Log</a>
            <?php endif; ?>

            <div class="ql-wallet-hero">
                <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="Coins" class="ql-wallet-hero-coin"
                     width="56" height="56"
                     style="width:56px;height:56px;max-width:56px;max-height:56px;object-fit:contain;">
                <h1 class="ql-wallet-hero-title">Coin Wallet</h1>
                <p class="ql-wallet-hero-name"><?php echo esc_html($display_name); ?></p>
            </div>

            <div class="ql-wallet-stats">
                <div class="ql-wallet-stat-card ql-wallet-stat-balance">
                    <div class="ql-wallet-stat-label">Current Balance</div>
                    <div class="ql-wallet-stat-value" id="ql-coin-amount"><?php echo (int) $balance; ?></div>
                    <div class="ql-wallet-stat-unit">coins</div>
                </div>
                <div class="ql-wallet-stat-card">
                    <div class="ql-wallet-stat-label">Total Earned</div>
                    <div class="ql-wallet-stat-value"><?php echo (int) $total_earned; ?></div>
                    <div class="ql-wallet-stat-unit">coins</div>
                </div>
                <div class="ql-wallet-stat-card">
                    <div class="ql-wallet-stat-label">Arcade Time</div>
                    <div class="ql-wallet-stat-value"><?php echo $arcade_minutes; ?></div>
                    <div class="ql-wallet-stat-unit">minutes available</div>
                </div>
            </div>

            <div class="ql-wallet-rate-info">
                <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"
                     width="14" height="14" style="width:14px;height:14px;max-width:14px;max-height:14px;object-fit:contain;display:inline-block;">
                <?php echo $coins_per_min; ?> coins = 1 minute of arcade time
            </div>

            <div class="ql-wallet-history-section">
                <h2 class="ql-section-title">Transaction History</h2>
                <?php if (empty($history)): ?>
                    <p style="text-align:center;padding:20px;color:#8b949e;">No transactions yet — start earning badges!</p>
                <?php else: ?>
                    <div class="ql-wallet-history-list">
                        <?php foreach ($history as $tx):
                            $positive = $tx['coins'] >= 0;
                            $sign     = $positive ? '+' : '';
                            $cls      = $positive ? 'positive' : 'negative';
                            $desc     = !empty($tx['description']) ? $tx['description'] : ($tx['source'] ?? 'Transaction');
                            $date     = '';
                            if (!empty($tx['created_at'])) {
                                $ts = strtotime($tx['created_at']);
                                $date = $ts ? date_i18n('j M Y', $ts) : $tx['created_at'];
                            }
                        ?>
                            <div class="ql-tx-row">
                                <div class="ql-tx-left">
                                    <span class="ql-tx-type ql-tx-type-<?php echo esc_attr($tx['transaction_type']); ?>"><?php echo esc_html(ucfirst($tx['transaction_type'])); ?></span>
                                    <span class="ql-tx-desc"><?php echo esc_html($desc); ?></span>
                                </div>
                                <div class="ql-tx-right">
                                    <span class="ql-tx-amount <?php echo $cls; ?>"><?php echo $sign . $tx['coins']; ?>
                                        <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"
                                             width="12" height="12" style="width:12px;height:12px;max-width:12px;max-height:12px;object-fit:contain;display:inline-block;">
                                    </span>
                                    <span class="ql-tx-date"><?php echo esc_html($date); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}