# MFSD Quest Log Plugin

Student badge/reward system with dark gaming theme — gem badges, treasure chests, coin wallet, and Spark/Ember/Blaze RAG evolution.

## Installation

1. Upload the `mfsd-quest-log` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Place the shortcode `[mfsd_quest_log]` on a page below the Student Portal
4. Ensure the image assets are in the correct directories (see below)

## Shortcode

```
[mfsd_quest_log]
```

Only visible to logged-in users with the `student` role (or `administrator`).

## Required Asset Structure

Place your artwork files in `assets/images/`:

```
assets/images/
├── badges/
│   ├── badge_word_assoc.png
│   ├── badge_junk_jobs.png
│   ├── badge_who_am_i_1.png
│   ├── badge_super_strengths.png
│   ├── badge_rag_w1.png
│   └── badge_locked.png          ← used for Week 2+3 and all locked states
├── chests/
│   ├── chest_complete.png
│   ├── chest_achiever.png
│   └── chest_locked.png
├── characters/                    ← Who Am I personality portraits
│   ├── Architect.png              (or Architect_Male.png / Architect_Female.png)
│   ├── Logician.png
│   ├── Commander.png
│   ├── Debater.png                (gendered variant)
│   ├── Advocate.png
│   ├── Mediator.png
│   ├── Protagonist.png
│   ├── Campaigner.png
│   ├── Logistician.png
│   ├── Defender.png
│   ├── Executive.png
│   ├── Consul.png
│   ├── Virtuoso.png
│   ├── Adventurer.png
│   ├── Entrepreneur.png           (gendered variant)
│   └── Entertainer.png
├── fire/
│   ├── spark_lit.png
│   ├── spark_dark.png
│   ├── ember_lit.png
│   ├── ember_dark.png
│   ├── blaze_lit.png
│   └── blaze_dark.png
└── ui/
    ├── avatar_f.png               ← default avatar frame
    ├── coin_icon.png
    ├── arcade_button.png          ← phase 2
    └── progress_bar_fill.png      ← phase 2
```

## Database Tables

Created on activation:

- `wp_mfsd_badges` — tracks earned badges per student
- `wp_mfsd_wallet` — transactional coin ledger

Reads from existing:

- `wp_mfsd_task_progress` — task completion status
- `wp_mfsd_ptest_results` — MBTI type for Who Am I character

## Pre-Build Tasks (from Architecture Doc)

- [ ] Add gender field to student profile (ProfilePress custom field)
- [ ] Wire character images into Who Am I plugin
- [ ] Produce Week 2 and 3 badge artwork (locked placeholder works until then)

## Coin Economy

| Badge Type          | Coins |
|---------------------|-------|
| Task badge          | 10    |
| RAG Spark (Week 1)  | 10    |
| RAG Ember (Week 2)  | 15    |
| RAG Blaze (Week 3)  | 20    |
| Week complete        | 25    |
| Week high achiever   | 50    |

10 coins = 1 minute arcade time (configurable in admin settings)

## Admin Features

- Overview: see all students' badge counts and coin balances
- Individual view: drill into any student's badges and transaction history
- Tools: award bonus coins, re-evaluate badges, clear student data
- Settings: configure coins-per-minute rate

## File Structure

```
mfsd-quest-log/
├── mfsd-quest-log.php              # Bootstrap, activation, shortcode
├── admin-page.php                   # WordPress admin interface
├── includes/
│   ├── class-quest-log-db.php       # Database layer (badge CRUD)
│   ├── class-quest-log-engine.php   # Badge evaluation engine
│   ├── class-quest-log-wallet.php   # Wallet transactions
│   └── class-quest-log-renderer.php # Frontend HTML output
├── assets/
│   ├── css/quest-log.css            # Dark gaming theme
│   ├── js/quest-log.js              # Interactions & animations
│   └── images/                      # See asset structure above
└── README.md
```
