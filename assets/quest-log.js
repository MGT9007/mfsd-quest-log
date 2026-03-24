(function () {
    'use strict';

    const cfg = window.MFSD_QUEST_CFG || {};
    const root = document.getElementById('mfsd-quest-log-root');
    if (!root) return;

    /* ================================================================
       WALLET HISTORY — load on button click
       ================================================================ */
    const historyBtn = document.getElementById('ql-show-history');
    const historyBox = document.getElementById('ql-wallet-history');
    let historyLoaded = false;

    if (historyBtn && historyBox) {
        historyBtn.addEventListener('click', async function () {
            if (historyLoaded) {
                historyBox.style.display = historyBox.style.display === 'none' ? 'block' : 'none';
                historyBtn.textContent = historyBox.style.display === 'none' ? 'View Transaction History' : 'Hide History';
                return;
            }

            historyBtn.textContent = 'Loading...';
            historyBtn.disabled = true;

            try {
                const res = await fetch(cfg.restBase + '/wallet/history', {
                    method: 'GET',
                    headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' },
                    credentials: 'same-origin'
                });

                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();

                if (data.ok && data.transactions && data.transactions.length > 0) {
                    historyBox.innerHTML = data.transactions.map(function (tx) {
                        const positive = tx.coins >= 0;
                        const sign = positive ? '+' : '';
                        const cls = positive ? 'positive' : 'negative';
                        const date = formatDate(tx.created_at);
                        const desc = tx.description || tx.source || 'Transaction';

                        return '<div class="ql-tx-row">' +
                            '<span class="ql-tx-desc">' + escHtml(desc) + '</span>' +
                            '<span class="ql-tx-amount ' + cls + '">' + sign + tx.coins + '</span>' +
                            '<span class="ql-tx-date">' + date + '</span>' +
                            '</div>';
                    }).join('');
                } else {
                    historyBox.innerHTML = '<div style="text-align:center;padding:16px;color:#8b949e;">No transactions yet — start earning badges!</div>';
                }

                historyBox.style.display = 'block';
                historyLoaded = true;
                historyBtn.textContent = 'Hide History';
                historyBtn.disabled = false;
            } catch (err) {
                console.error('Wallet history error:', err);
                historyBtn.textContent = 'View Transaction History';
                historyBtn.disabled = false;
            }
        });
    }

    /* ================================================================
       BADGE CARD INTERACTIONS — tap to see details
       ================================================================ */
    const badgeCards = root.querySelectorAll('.ql-badge-card.earned');
    badgeCards.forEach(function (card) {
        card.style.cursor = 'pointer';
        card.addEventListener('click', function () {
            const slug = card.dataset.badge;
            if (!slug || !cfg.badges || !cfg.badges[slug]) return;

            const badge = cfg.badges[slug];
            const label = card.querySelector('.ql-badge-label');
            const name = label ? label.textContent : slug;

            /* Simple earned date tooltip */
            let existing = card.querySelector('.ql-badge-tooltip');
            if (existing) {
                existing.remove();
                return;
            }

            /* Remove any other open tooltips */
            root.querySelectorAll('.ql-badge-tooltip').forEach(function (t) { t.remove(); });

            const tip = document.createElement('div');
            tip.className = 'ql-badge-tooltip';
            tip.style.cssText = 'position:absolute;bottom:100%;left:50%;transform:translateX(-50%);' +
                'background:#30363d;color:#e0e0e0;padding:8px 12px;border-radius:8px;font-size:11px;' +
                'white-space:nowrap;z-index:10;pointer-events:none;margin-bottom:6px;' +
                'box-shadow:0 4px 12px rgba(0,0,0,0.3);';

            let tipText = name + ' — earned ' + formatDate(badge.earned_at);
            if (badge.coins_awarded) tipText += ' (+' + badge.coins_awarded + ' coins)';

            tip.textContent = tipText;
            card.style.position = 'relative';
            card.appendChild(tip);

            setTimeout(function () { tip.remove(); }, 3000);
        });
    });

    /* ================================================================
       ENTRANCE ANIMATIONS — stagger badge cards
       ================================================================ */
    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                const cards = entry.target.querySelectorAll('.ql-badge-card');
                cards.forEach(function (card, i) {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(16px)';
                    card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    card.style.transitionDelay = (i * 80) + 'ms';

                    requestAnimationFrame(function () {
                        card.style.opacity = '';
                        card.style.transform = '';
                    });
                });
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.2 });

    root.querySelectorAll('.ql-badge-grid').forEach(function (grid) {
        observer.observe(grid);
    });

    /* ================================================================
       COIN COUNTER ANIMATION — on load
       ================================================================ */
    const coinEl = document.getElementById('ql-coin-amount');
    if (coinEl && cfg.balance > 0) {
        animateCounter(coinEl, 0, cfg.balance, 800);
    }

    function animateCounter(el, from, to, duration) {
        const start = performance.now();
        function tick(now) {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            /* Ease out */
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.round(from + (to - from) * eased);
            if (progress < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    /* ================================================================
       HELPERS
       ================================================================ */
    function formatDate(dateStr) {
        if (!dateStr) return '';
        try {
            const d = new Date(dateStr.replace(' ', 'T'));
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
        } catch (e) {
            return dateStr;
        }
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

})();
