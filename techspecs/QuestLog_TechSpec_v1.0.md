# Quest Log — Technical Specification v1.0

**Plugin directory:** `mfsd-quest-log/`
**Shortcode(s):** `[mfsd_quest_log]`, `[mfsd_coin_wallet]`
**Version:** 1.8.0
**Author:** MisterT9007
**Purpose:** Central gamification plugin for the MFSD High Performance Pathway. Displays a student's earned badges across three programme weeks, tracks a coin wallet earned through task completion, and visualises a Spark→Ember→Blaze RAG evolution for weekly reflection activities. The plugin operates as a passive evaluator — on every page load it reads `wp_mfsd_task_progress` for the current student, awards any newly-earned badges, and credits coins to the wallet ledger automatically. It is the authoritative source of badge ownership and coin balance for the entire platform.

---

## File Structure

| File | Purpose |
|------|---------|
| `mfsd-quest-log.php` | Bootstrap: singleton class, activation hook, shortcode registration, REST API registration, admin menu, asset registration, animation CSS generation, MBTI character data resolution |
| `admin-page.php` | WordPress admin UI — three-tab panel (Overview, Tools, Settings) for badge inspection, bonus coin awards, data clearing, and per-badge animation configuration |
| `includes/class-quest-log-db.php` | Database layer: `wp_mfsd_badges` CRUD, table creation via `dbDelta`, badge existence checks, per-student badge retrieval |
| `includes/class-quest-log-engine.php` | Badge evaluation engine: reads `wp_mfsd_task_progress`, awards task badges + RAG badges + week-completion chests, checks 7-day achiever window |
| `includes/class-quest-log-wallet.php` | Coin ledger: `earn()`, `spend()`, `bonus()`, `refund()` methods, running `balance_after` per row, balance calculation, transaction history |
| `includes/class-quest-log-renderer.php` | PHP HTML renderer: produces the complete Quest Log page (header, week sections, RAG evolution panel) and the standalone Coin Wallet page |
| `assets/quest-log.css` | Dark gaming theme (circuit-board background, animated badges, fire stages, responsive grid) |
| `assets/quest-log.js` | Client-side: collapsible week sections, badge tooltip on click, IntersectionObserver stagger entrance, coin counter animation on load |
| `assets/images/badges/` | Badge PNG images (see Assets section) |
| `assets/images/characters/` | MBTI character avatar PNGs (fallback — primary source is `mfsd-personality-test` plugin) |
| `assets/images/chests/` | Treasure chest PNGs for week-completion rewards |
| `assets/images/fire/` | Spark/Ember/Blaze lit and dark state PNGs |
| `assets/images/ui/` | Coin icon, default avatar frame, arcade button, progress bar fill |
| `README.md` | Installation notes, asset structure reference, coin economy table |

---

## Database Schema

### wp_mfsd_badges

Tracks every badge ever earned by a student. One row per student+badge combination (enforced by `UNIQUE KEY`).

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT(20) UNSIGNED AUTO_INCREMENT` | Primary key |
| `student_id` | `BIGINT(20) UNSIGNED NOT NULL` | WordPress user ID. Note: column is named `student_id`, NOT `user_id`. All cross-plugin calls must use this column name. |
| `badge_slug` | `VARCHAR(50) NOT NULL` | Machine-readable badge identifier (see Badge System section) |
| `coins_awarded` | `INT DEFAULT 0` | Coins credited to wallet at award time |
| `earned_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | When the badge was awarded |
| `metadata` | `JSON NULL` | Optional JSON blob — used for Who Am I badge to store `character`, `gender`, `group` |

Indexes: `PRIMARY KEY (id)`, `UNIQUE KEY unique_student_badge (student_id, badge_slug)`, `KEY idx_student (student_id)`

### wp_mfsd_wallet

Transactional coin ledger. Every credit and debit is a row. Balance is always read as the `balance_after` from the most recent row, not stored as a running total column.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT(20) UNSIGNED AUTO_INCREMENT` | Primary key |
| `student_id` | `BIGINT(20) UNSIGNED NOT NULL` | WordPress user ID |
| `transaction_type` | `ENUM('earn','spend','bonus','refund') NOT NULL` | Type of transaction |
| `source` | `VARCHAR(50) NOT NULL` | Badge slug or source identifier (e.g. `badge_word_assoc`, `teacher_bonus`, `arcade_session`) |
| `coins` | `INT NOT NULL` | Positive for earn/bonus/refund; negative (stored as negative integer) for spend |
| `minutes_equivalent` | `DECIMAL(5,1) DEFAULT 0` | Populated only for `spend` rows: `coins / coins_per_minute` |
| `balance_after` | `INT NOT NULL` | Running balance after this transaction — used as the authoritative balance |
| `description` | `VARCHAR(255) NULL` | Human-readable description shown in transaction history |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | Transaction timestamp |

Indexes: `PRIMARY KEY (id)`, `KEY idx_student (student_id)`, `KEY idx_student_balance (student_id, created_at DESC)`

---

## Badge System

Badges are organised into three programme weeks. Each task badge maps 1:1 to a `task_slug` in `wp_mfsd_task_progress`. Two additional "chest" badges per week are awarded automatically by the engine when conditions are met.

### WEEK_BADGES constant (in `MFSD_Quest_Log_Engine`)

#### Week 1 — Self-Awareness & The Solutions Lens

| Badge Slug | Task Slug | Display Name | Coins | Badge Image |
|------------|-----------|--------------|-------|-------------|
| `badge_solution_lens` | `solution_lens` | The Solution Lens | 10 | `badge_solution_lens.png` |
| `badge_word_assoc` | `word_association` | Word Association | 10 | `badge_word_assoc.png` |
| `badge_who_am_i_1` | `personality_test_week_1` | Who Am I | 10 | `badge_who_am_i_1.png` + character overlay |
| `badge_super_strengths` | `super_strengths` | Super Strengths | 10 | `badge_super_strengths.png` |
| `badge_rag_w1` | `rag_week_1` | Weekly RAG (Spark) | 10 | `badge_rag_w1.png` |
| `badge_week1_complete` | _(all 5 above)_ | Week 1 Complete | 25 | `chest_complete.png` |
| `badge_week1_achiever` | _(all 5 within 7 days)_ | Week 1 Achiever | 50 | `chest_achiever.png` |

#### Week 2 — Interests, Barriers & Dreams into Plans

| Badge Slug | Task Slug | Display Name | Coins | Badge Image |
|------------|-----------|--------------|-------|-------------|
| `badge_junk_jobs` | `junk_jobs` | Junk Jobs | 10 | `badge_junk_jobs.png` |
| `badge_fav_subject` | `favourite_subject` | Favourite Subject | 10 | `badge_locked.png` (artwork TBD) |
| `badge_barriers` | `barriers` | Barriers | 10 | `badge_locked.png` (artwork TBD) |
| `badge_dream_jobs` | `dream_jobs` | Dream Jobs | 10 | `badge_locked.png` (artwork TBD) |
| `badge_who_am_i_2` | `who_am_i_part_2` | Who Am I (Part 2) | 10 | `badge_locked.png` (artwork TBD) |
| `badge_rag_w2` | `rag_week_2` | Weekly RAG (Ember) | 15 | `badge_locked.png` (artwork TBD) |
| `badge_week2_complete` | _(all 6 above)_ | Week 2 Complete | 25 | `chest_complete.png` |
| `badge_week2_achiever` | _(all 6 within 7 days)_ | Week 2 Achiever | 50 | `chest_achiever.png` |

#### Week 3 — High Performance & Future Direction

| Badge Slug | Task Slug | Display Name | Coins | Badge Image |
|------------|-----------|--------------|-------|-------------|
| `badge_fifty_quid` | `fifty_on_success` | £50 on Success | 10 | `badge_locked.png` (artwork TBD) |
| `badge_hp_wheel` | `hp_wheel` | HP Wheel | 10 | `badge_locked.png` (artwork TBD) |
| `badge_what_is_hp` | `what_is_hp` | What is HP? | 10 | `badge_locked.png` (artwork TBD) |
| `badge_dream_life` | `dream_life` | Dream Life | 10 | `badge_locked.png` (artwork TBD) |
| `badge_rag_w3` | `rag_week_3` | Weekly RAG (Blaze) | 20 | `badge_locked.png` (artwork TBD) |
| `badge_week3_complete` | _(all 5 above)_ | Week 3 Complete | 25 | `chest_complete.png` |
| `badge_week3_achiever` | _(all 5 within 7 days)_ | Week 3 Achiever | 50 | `chest_achiever.png` |

### Cross-Plugin Badge Image Resolution

The `WEEK_CONFIG` constant in `MFSD_Quest_Log_Renderer` supports an optional `plugin` key in any badge config array. When present, the badge image URL is constructed as `plugins_url($badge_config['plugin'])` rather than from the local `assets/images/badges/` folder. This allows external plugins to supply their own badge artwork without copying images into the Quest Log's directory. As of v1.8.0 no badges use this mechanism — all images are either in the local `badges/` folder or fall back to `badge_locked.png`.

### Who Am I Badge Special Behaviour

`badge_who_am_i_1` and `badge_who_am_i_2` render with a composite visual when earned: the badge frame image (`badge_who_am_i_1.png`) is the CSS background of the image wrapper, and the student's MBTI character portrait is positioned absolutely at 52×52px centred over it. The character filename is resolved via `MFSD_Quest_Log::avatar_file_map()` and the image URL is loaded from `mfsd-personality-test/assets/Avatars/` if that plugin directory exists, falling back to the local `assets/images/characters/` folder. A sublabel "The [CharacterName]" is displayed beneath the badge label.

The engine also stores `character`, `gender`, and `group` in the `metadata` JSON column when awarding `badge_who_am_i_1`. Gender is read from user meta key `gender`; values `female` or `f` map to `female`, everything else to `male`.

---

## Coin Wallet

### Ledger Model

The wallet uses an append-only ledger. There is no mutable "balance" column. Every credit or debit creates a new row containing `balance_after`, which is the current balance plus or minus `coins`. The current balance is always retrieved by fetching the single most recent row ordered by `created_at DESC, id DESC`.

This design means balance reads are O(1) index lookups, the full transaction history is always recoverable, and race conditions are eliminated because each transaction calculates its own `balance_after` based on the immediately preceding row.

### MFSD_Quest_Log_Wallet Methods

| Method | Transaction Type | Description |
|--------|-----------------|-------------|
| `earn($student_id, $source, $coins, $description)` | `earn` | Awards coins for a badge or activity. `$source` is the badge slug. |
| `bonus($student_id, $source, $coins, $description)` | `bonus` | Teacher-awarded bonus coins via admin panel. `$source` is typically `teacher_bonus`. |
| `spend($student_id, $source, $coins, $description)` | `spend` | Deducts coins for arcade play. Returns `false` if insufficient balance. Calculates `minutes_equivalent`. |
| `refund($student_id, $source, $coins, $description)` | `refund` | Returns coins from an interrupted/cancelled arcade session. |
| `get_balance($student_id)` | — | Returns current balance (int). Returns 0 if table missing. |
| `get_total_earned($student_id)` | — | Sums `coins` for all `earn`, `bonus`, and `refund` rows (lifetime total, excluding spends). |
| `get_history($student_id, $limit)` | — | Returns most recent `$limit` transactions ordered newest-first. |
| `delete_student_transactions($student_id)` | — | Admin tool: deletes all wallet rows for a student. |
| `coins_per_minute()` | — | Static method. Returns admin-configured rate (option `mfsd_quest_coins_per_minute`, default 10). |

### Coin Economy

| Badge / Event | Coins |
|---------------|-------|
| Any task badge (non-RAG) | 10 |
| RAG Spark (Week 1) | 10 |
| RAG Ember (Week 2) | 15 |
| RAG Blaze (Week 3) | 20 |
| Week Complete chest | 25 |
| High Achiever chest | 50 |
| Teacher bonus | Admin-configured (1–100) |

Arcade redemption rate: 10 coins = 1 minute by default (configurable via admin Settings tab, option `mfsd_quest_coins_per_minute`).

---

## RAG Evolution System

RAG stands for Reflection/Action/Growth (aligns with the `mfsd-weekly-rag` plugin). The three weekly RAG completion badges drive a visual "fire evolution" displayed below the badge weeks.

| Stage | Badge Slug | Task Slug | Coins | Lit Image | Dark Image |
|-------|------------|-----------|-------|-----------|------------|
| Spark | `badge_rag_w1` | `rag_week_1` | 10 | `fire/spark_lit.png` | `fire/spark_dark.png` |
| Ember | `badge_rag_w2` | `rag_week_2` | 15 | `fire/ember_lit.png` | `fire/ember_dark.png` |
| Blaze | `badge_rag_w3` | `rag_week_3` | 20 | `fire/blaze_lit.png` | `fire/blaze_dark.png` |

Each stage is displayed as a card with a 72×72 fire image. Earned stages use the `_lit` image, receive an orange border glow, and have the fire flicker CSS animation applied. Unearned stages use the `_dark` image and are rendered at 40% opacity with 70% grayscale. Connector arrows between stages turn orange when the preceding stage is lit.

The RAG evolution panel is always shown regardless of which stages are lit. It is rendered after all three week sections.

---

## Key Flows

### Quest Log Page Load (`[mfsd_quest_log]`)

1. Access check: user must be logged in with `student` or `administrator` role.
2. `MFSD_Quest_Log_Engine::evaluate_all($student_id)` is called — reads `wp_mfsd_task_progress`, awards any unawarded badges, and credits coins. This is idempotent; `has_badge()` guards every award.
3. `MFSD_Quest_Log_DB::get_student_badges($student_id)` returns all earned badges keyed by slug.
4. `MFSD_Quest_Log_Wallet::get_balance($student_id)` returns current coin balance.
5. `get_character_data($student_id)` queries `wp_mfsd_ptest_results` for the most recent COMBINED or MBTI result and resolves the character name, group, avatar filename, and avatar URL.
6. `wp_localize_script` passes `MFSD_QUEST_CFG` (restBase, nonce, studentId, displayName, balance, badges array, character data, imagesUrl, walletUrl) to the frontend JS.
7. Animation inline CSS is generated via `get_animation_css()` using admin-saved options and injected via `wp_add_inline_style`.
8. `MFSD_Quest_Log_Renderer::render()` outputs the full HTML structure.
9. JS initialises: stagger entrance animations, collapsible week headers, badge tooltips on click, coin counter count-up animation.

### Badge Award Flow (inside `evaluate_all`)

1. Engine calls `get_all_task_statuses($student_id)` — single query returning all `task_slug => status` pairs from `wp_mfsd_task_progress`.
2. For each week, each badge slug is checked: if `task_statuses[$task_slug] === 'completed'` AND `!has_badge($student_id, $badge_slug)`, the badge is awarded.
3. RAG badges (`badge_rag_w1/2/3`) receive week-specific coin values (10/15/20); all other task badges receive 10 coins.
4. `award_badge()` inserts into `wp_mfsd_badges`; `wallet->earn()` inserts into `wp_mfsd_wallet`.
5. After all task badges are checked, if `completed_count === count($badges)` (all tasks in the week done), the `badge_weekN_complete` chest is awarded (25 coins).
6. The 7-day achiever check runs: `get_week_badge_dates()` fetches earned timestamps for all task badges in the week; if max - min <= 604800 seconds, `badge_weekN_achiever` is awarded (50 coins).

### Coin Wallet Page Load (`[mfsd_coin_wallet]`)

1. Access check: logged in, `student` or `administrator` role.
2. `get_balance()`, `get_total_earned()`, `get_history($student_id, 50)` are called.
3. Arcade minutes are calculated: `balance / coins_per_minute`.
4. `render_wallet_page()` outputs a hero section (coin icon, title, student name), three stat cards (balance, total earned, arcade minutes), the coins-per-minute rate info, and a transaction history list (up to 50 rows, newest first).
5. A "Back to Quest Log" link is included if a page containing `[mfsd_quest_log]` can be found via `get_posts`.

---

## REST API / AJAX Endpoints

All routes are under the `mfsd-quest/v1` namespace. All require `is_user_logged_in()` — the nonce (`wp_rest`) is created server-side via `wp_localize_script` and passed in `MFSD_QUEST_CFG.nonce`.

| Route | Method | Auth | Description |
|-------|--------|------|-------------|
| `/wp-json/mfsd-quest/v1/badges` | `GET` | Cookie nonce (`wp_rest`) | Re-evaluates badges for the current user, then returns the full `badges` array keyed by slug. |
| `/wp-json/mfsd-quest/v1/wallet` | `GET` | Cookie nonce (`wp_rest`) | Returns `{ ok: true, balance: <int> }` for the current user. |
| `/wp-json/mfsd-quest/v1/wallet/history` | `GET` | Cookie nonce (`wp_rest`) | Returns the 20 most recent wallet transactions for the current user. |

There are no POST endpoints in the current version. Coin spending (for arcade play) is anticipated as a Phase 2 addition.

No `wp_ajax_*` actions are registered — the plugin uses the WP REST API exclusively for frontend communication.

---

## Admin Panel

Located at **WP Admin > Quest Log** (`page=mfsd-quest-log`, capability `manage_options`, dashicons-awards, menu position 31).

The page is rendered by `admin-page.php` and uses a simple tab system (Overview / Tools / Settings) driven by inline JavaScript (`qlTab()`). All form submissions use WordPress nonces verified with `check_admin_referer()`.

### Overview Tab
- Aggregated table: all students with badges, badge count, total coins earned, current balance. Sorted by total coins descending.
- Student drill-down: select any student to view their individual badges (slug, display name, coins, earned_at, metadata) and last 30 wallet transactions.

### Tools Tab
- **Award Bonus Coins**: select student, enter coin amount (1–100) and description. Calls `wallet->bonus()` with source `teacher_bonus`.
- **Re-evaluate Student Badges**: runs `engine->evaluate_all($uid)` for any student. Safe to run multiple times — duplicate awards are blocked by `has_badge()`.
- **Clear Student Data**: deletes all badges (`delete_student_badges()`) and all wallet transactions (`delete_student_transactions()`) for a selected student. Requires JS `confirm()`.

### Settings Tab

All settings are saved to `wp_options` when the single form is submitted (nonce `mfsd_quest_settings`).

| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `mfsd_quest_coins_per_minute` | int | 10 | Arcade economy rate |
| `mfsd_quest_wallet_page_id` | int | 0 | Page ID for `[mfsd_coin_wallet]` shortcode — makes header coin balance a clickable link |
| `mfsd_quest_shimmer_config` | JSON string | `{}` | Per-badge shimmer sweep: `{ badge_slug: { on: bool, interval: int } }` |
| `mfsd_quest_coin_config` | JSON string | `{}` | Coin spin config: `{ header: { on, interval }, badges: { badge_slug: { on, interval } } }` |
| `mfsd_quest_anim_float` | `'0'`/`'1'` | `'1'` | Earned badge float animation |
| `mfsd_quest_anim_border_glow` | `'0'`/`'1'` | `'1'` | Earned card border glow pulse |
| `mfsd_quest_anim_fire_flicker` | `'0'`/`'1'` | `'1'` | RAG fire stage flicker |
| `mfsd_quest_anim_chest_wobble` | `'0'`/`'1'` | `'1'` | Chest wobble on hover |
| `mfsd_quest_anim_locked_pulse` | `'0'`/`'1'` | `'1'` | Locked badge breathe animation |
| `mfsd_quest_anim_progress_shine` | `'0'`/`'1'` | `'1'` | Progress bar scrolling gradient |

Animation CSS is generated server-side in `get_animation_css()` and injected as inline style on the `mfsd-quest-log` stylesheet handle. Animations disabled in admin are overridden with `animation: none !important`.

---

## Assets / Images

### Badge Images (`assets/images/badges/`)

| Filename | Used For |
|----------|----------|
| `badge_solution_lens.png` | Week 1 — Solution Lens task (note: not listed in README but referenced in `WEEK_CONFIG`) |
| `badge_word_assoc.png` | Week 1 — Word Association task |
| `badge_junk_jobs.png` | Week 2 — Junk Jobs task |
| `badge_who_am_i_1.png` | Week 1 — Who Am I task (portal frame; character portrait overlaid at runtime) |
| `badge_super_strengths.png` | Week 1 — Super Strengths task |
| `badge_rag_w1.png` | Week 1 — Weekly RAG (Spark) |
| `badge_locked.png` | Fallback / locked state — all Week 2 and Week 3 badges without dedicated artwork |
| `badge_word_assoc_old.png` | Legacy — superseded by `badge_word_assoc.png` |
| `badge_junk_jobs_old.png` | Legacy — superseded by `badge_junk_jobs.png` |
| `badge_rag_w1_old.png` | Legacy — superseded by `badge_rag_w1.png` |

### Character Avatars (`assets/images/characters/`)

Local fallback portraits used when `mfsd-personality-test/assets/Avatars/` is unavailable.

| MBTI | Primary Filename (personality-test plugin) | Local Fallback |
|------|--------------------------------------------|----------------|
| INTJ | `Architect.png` | `Architect.png` / `ArchitectFemale.png` |
| INTP | `Logician.png` | `Logician.png` |
| ENTJ | `Commander.png` | `Commander.png` |
| ENTP | `Debater.png` | `Debater_Male.png` / `DebaterFemale.png` |
| INFJ | `Advocate.png` | `Advocate.png` |
| INFP | `Mediatorv3.png` | `Mediator.png` |
| ENFJ | `Protagonist.png` | `Protagonist.png` |
| ENFP | `Campaigner.png` | `Campaigner.png` |
| ISTJ | `Logistician.png` | `Logistician.png` |
| ISFJ | `Defender.png` | `Defender.png` |
| ESTJ | `Executive.png` | `Executive.png` |
| ESFJ | `Consul.png` | `Consul.png` |
| ISTP | `Virtuoso.png` | `Virtuoso.png` |
| ISFP | `Adventurer.png` | `Adventurer.png` |
| ESTP | `Entrepreneur.png` | `Entrepreneur.png` / `EntrepreneurFermale.png` |
| ESFP | `Entertainer.png` | `Entertaioner.png` (note: typo in filename on disk) |

Avatar URL resolution order:
1. Check `mfsd-personality-test/assets/Avatars/` (canonical plugin path)
2. Check `personality-test/assets/Avatars/` (alternate plugin slug)
3. Fall back to local `assets/images/characters/`

### Chest Images (`assets/images/chests/`)

| Filename | Used For |
|----------|----------|
| `chest_complete.png` | Earned "Week Complete" chest badge |
| `chest_achiever.png` | Earned "High Achiever" chest badge |
| `chest_locked.png` | Unearned state for both chests |

### Fire Images (`assets/images/fire/`)

| Filename | Stage | State |
|----------|-------|-------|
| `spark_lit.png` | Week 1 | Earned |
| `spark_dark.png` | Week 1 | Not earned |
| `ember_lit.png` | Week 2 | Earned |
| `ember_dark.png` | Week 2 | Not earned |
| `blaze_lit.png` | Week 3 | Earned |
| `blaze_dark.png` | Week 3 | Not earned |

### UI Images (`assets/images/ui/`)

| Filename | Purpose |
|----------|---------|
| `coin_icon.png` | Header wallet, badge coin indicators, wallet page hero |
| `avatar_f.png` | Default avatar frame when no profile picture found |
| `arcade_button.png` | Reserved for Phase 2 arcade integration |
| `progress_bar_fill.png` | Reserved for Phase 2 |

---

## Profile Picture Resolution

`get_profile_picture_url()` in the renderer attempts to find a real student avatar using the following priority order:

1. Check user meta keys: `pp_profile_image`, `profilepress_profile_image`, `wp_user_avatar`, `pp_custom_avatar`, `simple_local_avatar`, `metronet_avatar_override`, `user_avatar`. Numeric values are treated as attachment IDs; URL strings are used directly.
2. Parse `get_avatar()` HTML and extract the `src` attribute. Non-Gravatar URLs are used as-is. Gravatar URLs are accepted only if they are not mystery/blank/mp/mm defaults.
3. Fall back to `assets/images/ui/avatar_f.png`.

---

## CSS Animations

All animations are defined in `quest-log.css` and are toggled on/off via inline CSS generated server-side in `get_animation_css()`. All animations respect `prefers-reduced-motion: reduce`.

| Animation | CSS Keyframe | Default | Admin Toggle |
|-----------|-------------|---------|-------------|
| Badge shimmer sweep | `ql-shimmer` | Off (per-badge) | Per-badge on/off + interval |
| Gentle float (earned badges) | `ql-float` | On | Global: `anim_float` |
| Coin spin (header) | `ql-coin-spin` | On | Coin config: header on/off + interval |
| Coin spin (per badge) | `ql-coin-spin` | Off (per-badge) | Per-badge on/off + interval |
| Border glow (earned cards) | `ql-border-glow` | On | Global: `anim_border_glow` |
| Fire flicker (lit RAG stages) | `ql-fire-flicker` | On | Global: `anim_fire_flicker` |
| Chest wobble on hover | `ql-chest-wobble` | On | Global: `anim_chest_wobble` |
| Locked badge pulse | `ql-locked-pulse` | On | Global: `anim_locked_pulse` |
| Progress bar shine | `ql-progress-shine` | On | Global: `anim_progress_shine` |
| Coin pop (JS-triggered) | `ql-coin-pop` | On | None (always on) |
| Badge glow radial | `ql-pulse` | On | None (always on) |

Stagger delays are applied via `:nth-child` selectors for shimmer, float, and border-glow so cards animate sequentially rather than all at once.

---

## Security

- **Shortcode access**: both `[mfsd_quest_log]` and `[mfsd_coin_wallet]` check `is_user_logged_in()` and verify the user has the `student` or `administrator` role before rendering any content.
- **REST endpoints**: all three routes use `permission_callback => fn() => is_user_logged_in()`. The nonce (`wp_rest`) is passed from PHP via `wp_localize_script` — frontend JS must include it in the `X-WP-Nonce` header (enforced by WP core REST infrastructure).
- **Admin forms**: all POST handlers call `check_admin_referer()` with action-specific nonces (`mfsd_quest_bonus`, `mfsd_quest_reevaluate`, `mfsd_quest_clear_student`, `mfsd_quest_settings`). Page access is guarded by `current_user_can('manage_options')`.
- **Database queries**: all queries with user-supplied parameters use `$wpdb->prepare()`. Admin text inputs are sanitized with `sanitize_text_field()` and `sanitize_key()` before storage.
- **Output escaping**: all user-supplied strings rendered in HTML are escaped with `esc_html()`, `esc_attr()`, or `esc_url()`.
- **No direct file access**: all PHP files begin with `if (!defined('ABSPATH')) exit;`.

---

## Inter-Plugin Dependencies

### Reads from external tables

| Table | Plugin | Purpose |
|-------|--------|---------|
| `wp_mfsd_task_progress` | `mfsd-ordering` | Source of truth for task completion status. The engine reads `task_slug` and `status` columns filtered by `student_id`. Column name is `student_id` (not `user_id`). |
| `wp_mfsd_ptest_results` | `mfsd-personality-test` | MBTI type for Who Am I character display. Reads `mbti_type` column; filters by `test_type IN ('COMBINED','MBTI')`. |

Both tables are checked for existence before querying (`SHOW TABLES LIKE ...`) so the plugin degrades gracefully if those plugins are inactive.

### How other plugins award badges

Other plugins **do not** call the Quest Log engine directly. The engine is a pull-model evaluator: it reads `wp_mfsd_task_progress` and awards badges automatically. To make a plugin award a Quest Log badge, the plugin must:

1. Write a row to `wp_mfsd_task_progress` with the correct `task_slug` and `status = 'completed'` for the student.
2. The Quest Log engine will pick it up on the next page load of `[mfsd_quest_log]` or on a REST `/badges` call.

If a plugin needs to trigger immediate badge evaluation (e.g. after an activity completes), it can instantiate `MFSD_Quest_Log_Engine` directly:

```php
if (class_exists('MFSD_Quest_Log_Engine')) {
    $engine = new MFSD_Quest_Log_Engine();
    $engine->evaluate_all($student_id);
}
```

### How other plugins access badge/wallet data

The DB and Wallet classes are public and can be instantiated directly:

```php
// Check if student has a specific badge
$db = new MFSD_Quest_Log_DB();
$has_badge = $db->has_badge($student_id, 'badge_word_assoc');

// Get current coin balance
$wallet = new MFSD_Quest_Log_Wallet();
$balance = $wallet->get_balance($student_id);

// Award a bonus (e.g. from leaderboard plugin)
$wallet->bonus($student_id, 'leaderboard_bonus', 20, 'Top of the week!');
```

**Important:** The `wp_mfsd_badges` table uses `student_id` as the column name, not `user_id`. Any raw SQL querying this table must use the correct column name.

### Plugins that register task slugs (trigger badges)

| Plugin | Task Slug | Badge Slug |
|--------|-----------|------------|
| `mfsd-solution-lens` | `solution_lens` | `badge_solution_lens` |
| `mfsd-word-association` | `word_association` | `badge_word_assoc` |
| `mfsd-personality-test` | `personality_test_week_1` | `badge_who_am_i_1` |
| `mfsd-super-strengths-v2` | `super_strengths` | `badge_super_strengths` |
| `mfsd-weekly-rag` | `rag_week_1`, `rag_week_2`, `rag_week_3` | `badge_rag_w1/2/3` |
| `ai-junk-jobs` | `junk_jobs` | `badge_junk_jobs` |
| `ai-dream-jobs` | `dream_jobs` | `badge_dream_jobs` |
| `high-performance-wheel` | `hp_wheel` | `badge_hp_wheel` |
| _(TBD)_ | `favourite_subject`, `barriers`, `who_am_i_part_2`, `fifty_on_success`, `what_is_hp`, `dream_life` | Weeks 2–3 badges |

---

## Version History

| Version | Notes |
|---------|-------|
| 1.8.0 | Current. Per-badge shimmer and coin spin admin controls. `get_animation_css()` generates inline CSS from stored JSON config. |
| 1.7.0 | Removed character subtitle from header (personality type display moved onto the Who Am I badge card only). Who Am I badge frame + character portrait overlay. Per-badge shimmer and coin spin controls in admin (admin-page.php v1.7.0 comment). |
| 1.2.0 | Dark Gaming Theme CSS (comment in quest-log.css). |
| 1.0.2 | Fixed `personality_test` task slug — changed to `personality_test_week_1` to match `wp_mfsd_task_progress` (comment in engine). |
| 1.0.0 | Initial release. |
