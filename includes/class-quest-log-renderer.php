<?php
/**
 * MFSD Quest Log — Frontend Renderer
 * Outputs the full Quest Log page HTML: header, badge grids, chests, RAG evolution.
 */

if (!defined('ABSPATH')) exit;

class MFSD_Quest_Log_Renderer {

    /* ── Badge display config per week ── */
    const WEEK_CONFIG = array(
        1 => array(
            'title' => 'Week 1 — Future Self',
            'badges' => array(
                'badge_word_assoc'      => array('label' => 'Word Association', 'image' => 'badge_word_assoc.png'),
                'badge_junk_jobs'       => array('label' => 'Junk Jobs',        'image' => 'badge_junk_jobs.png'),
                'badge_who_am_i_1'      => array('label' => 'Who Am I',         'image' => 'badge_who_am_i_1.png'),
                'badge_super_strengths' => array('label' => 'Super Strengths',  'image' => 'badge_super_strengths.png'),
                'badge_rag_w1'          => array('label' => 'Weekly RAG',       'image' => 'badge_rag_w1.png'),
            ),
        ),
        2 => array(
            'title' => 'Week 2 — Growth Mindset',
            'badges' => array(
                'badge_fav_subject' => array('label' => 'Favourite Subject', 'image' => 'badge_locked.png'),
                'badge_barriers'    => array('label' => 'Barriers',          'image' => 'badge_locked.png'),
                'badge_dream_jobs'  => array('label' => 'Dream Jobs',        'image' => 'badge_locked.png'),
                'badge_who_am_i_2'  => array('label' => 'Who Am I (Part 2)', 'image' => 'badge_locked.png'),
                'badge_rag_w2'      => array('label' => 'Weekly RAG',        'image' => 'badge_locked.png'),
            ),
        ),
        3 => array(
            'title' => 'Week 3 — Marginal Gains',
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

            <?php $this->render_header($display_name, $balance, $character, $images_url); ?>

            <?php foreach (self::WEEK_CONFIG as $week_num => $week): ?>
                <?php $this->render_week_section($week_num, $week, $badges, $images_url); ?>
            <?php endforeach; ?>

            <?php $this->render_rag_evolution($badges, $images_url); ?>

            <?php $this->render_wallet_summary($balance); ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
       HEADER — avatar, name, coin balance
       ================================================================ */
    private function render_header($display_name, $balance, $character, $images_url) {
        $avatar_src = $images_url . 'ui/avatar_f.png';
        $character_src = null;

        if ($character && !empty($character['filename'])) {
            $character_src = $images_url . 'characters/' . $character['filename'];
        }
        ?>
        <div class="ql-header">
            <div class="ql-header-left">
                <div class="ql-avatar-frame">
                    <?php if ($character_src): ?>
                        <img src="<?php echo esc_url($character_src); ?>"
                             alt="<?php echo esc_attr($character['name'] ?? 'Avatar'); ?>"
                             class="ql-avatar-img"
                             onerror="this.src='<?php echo esc_url($avatar_src); ?>'">
                    <?php else: ?>
                        <img src="<?php echo esc_url($avatar_src); ?>" alt="Avatar" class="ql-avatar-img">
                    <?php endif; ?>
                </div>
                <div class="ql-header-info">
                    <h1 class="ql-player-name"><?php echo esc_html($display_name); ?></h1>
                    <?php if ($character): ?>
                        <div class="ql-character-title">The <?php echo esc_html($character['name']); ?> — <?php echo esc_html(ucfirst($character['group'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ql-header-right">
                <div class="ql-coin-balance">
                    <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="Coins" class="ql-coin-icon">
                    <span class="ql-coin-amount" id="ql-coin-amount"><?php echo (int) $balance; ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /* ================================================================
       WEEK SECTION — badge grid + chests
       ================================================================ */
    private function render_week_section($week_num, $week, $badges, $images_url) {
        $task_badge_slugs = array_keys($week['badges']);
        $earned_count = 0;
        foreach ($task_badge_slugs as $slug) {
            if (isset($badges[$slug])) $earned_count++;
        }
        $total = count($task_badge_slugs);
        $all_done = ($earned_count === $total);

        $complete_slug = 'badge_week' . $week_num . '_complete';
        $achiever_slug = 'badge_week' . $week_num . '_achiever';
        ?>
        <div class="ql-week-section" data-week="<?php echo $week_num; ?>">
            <div class="ql-week-header">
                <h2 class="ql-week-title"><?php echo esc_html($week['title']); ?></h2>
                <div class="ql-week-progress">
                    <div class="ql-progress-bar">
                        <div class="ql-progress-fill" style="width: <?php echo ($total > 0 ? round(($earned_count / $total) * 100) : 0); ?>%"></div>
                    </div>
                    <span class="ql-progress-text"><?php echo $earned_count; ?>/<?php echo $total; ?></span>
                </div>
            </div>

            <div class="ql-badge-grid">
                <?php foreach ($week['badges'] as $slug => $badge_config): ?>
                    <?php
                    $earned = isset($badges[$slug]);
                    $is_who_am_i = ($slug === 'badge_who_am_i_1');
                    $badge_image = $earned ? ($images_url . 'badges/' . $badge_config['image']) : ($images_url . 'badges/badge_locked.png');

                    /* Who Am I badge — use character portrait if earned */
                    if ($is_who_am_i && $earned && isset($badges[$slug]['metadata']['character'])) {
                        /* The character image renders inside a portal frame */
                    }

                    $coins = $earned ? ($badges[$slug]['coins_awarded'] ?? 10) : null;
                    ?>
                    <div class="ql-badge-card <?php echo $earned ? 'earned' : 'locked'; ?>" data-badge="<?php echo esc_attr($slug); ?>">
                        <div class="ql-badge-image-wrap">
                            <img src="<?php echo esc_url($badge_image); ?>"
                                 alt="<?php echo esc_attr($badge_config['label']); ?>"
                                 class="ql-badge-image">
                            <?php if ($earned): ?>
                                <div class="ql-badge-glow"></div>
                            <?php endif; ?>
                        </div>
                        <div class="ql-badge-label"><?php echo esc_html($badge_config['label']); ?></div>
                        <?php if ($earned && $coins): ?>
                            <div class="ql-badge-coins">+<?php echo $coins; ?> <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php /* Treasure chests */ ?>
            <div class="ql-chests">
                <?php
                $complete_earned = isset($badges[$complete_slug]);
                $achiever_earned = isset($badges[$achiever_slug]);

                $complete_img = $complete_earned ? 'chest_complete.png' : 'chest_locked.png';
                $achiever_img = $achiever_earned ? 'chest_achiever.png' : 'chest_locked.png';
                ?>
                <div class="ql-chest <?php echo $complete_earned ? 'earned' : 'locked'; ?>">
                    <img src="<?php echo esc_url($images_url . 'chests/' . $complete_img); ?>" alt="Week Complete" class="ql-chest-img">
                    <div class="ql-chest-label">Week Complete</div>
                    <?php if ($complete_earned): ?>
                        <div class="ql-chest-coins">+25 <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"></div>
                    <?php else: ?>
                        <div class="ql-chest-hint">Complete all 5 tasks</div>
                    <?php endif; ?>
                </div>
                <div class="ql-chest <?php echo $achiever_earned ? 'earned' : 'locked'; ?>">
                    <img src="<?php echo esc_url($images_url . 'chests/' . $achiever_img); ?>" alt="High Achiever" class="ql-chest-img">
                    <div class="ql-chest-label">High Achiever</div>
                    <?php if ($achiever_earned): ?>
                        <div class="ql-chest-coins">+50 <img src="<?php echo esc_url($images_url . 'ui/coin_icon.png'); ?>" alt="" class="ql-mini-coin"></div>
                    <?php else: ?>
                        <div class="ql-chest-hint">Complete all 5 within 7 days</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ================================================================
       RAG EVOLUTION — Spark → Ember → Blaze
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
                             onerror="this.style.display='none'">
                        <div class="ql-fire-label"><?php echo esc_html($stage['name']); ?></div>
                        <div class="ql-fire-week">Week <?php echo $num; ?> RAG</div>
                    </div>
                    <?php if ($num < 3): ?>
                        <div class="ql-fire-connector <?php echo $lit ? 'lit' : ''; ?>">
                            <span class="ql-fire-arrow">→</span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ================================================================
       WALLET SUMMARY
       ================================================================ */
    private function render_wallet_summary($balance) {
        ?>
        <div class="ql-wallet-summary">
            <h2 class="ql-section-title">Coin Wallet</h2>
            <div class="ql-wallet-balance-box">
                <div class="ql-wallet-balance-label">Current Balance</div>
                <div class="ql-wallet-balance-value" id="ql-wallet-balance"><?php echo (int) $balance; ?> coins</div>
            </div>
            <div class="ql-wallet-info">
                <p>Earn coins by completing activities and unlocking badges. Save up for the Arcade!</p>
                <div class="ql-wallet-rate">10 coins = 1 minute of arcade time</div>
            </div>
            <div class="ql-wallet-history" id="ql-wallet-history">
                <!-- Populated by JS on click -->
            </div>
            <button class="ql-btn ql-btn-secondary" id="ql-show-history" type="button">View Transaction History</button>
        </div>
        <?php
    }
}
