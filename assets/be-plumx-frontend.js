(() => {
  const cfg = (window.BE_PLUMX || {});
  const storageKey = cfg.storageKey || 'be_plumx_consent';

  const qsa = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  const hasRememberedConsent = () => {
    if (!cfg.requireConsent) return true;
    if (!cfg.rememberConsent) return false;
    try { return window.localStorage.getItem(storageKey) === '1'; } catch (e) { return false; }
  };

  const rememberConsent = () => {
    if (!cfg.rememberConsent) return;
    try { window.localStorage.setItem(storageKey, '1'); } catch (e) {}
  };

  const loadScriptOnce = (src) => new Promise((resolve, reject) => {
    if (window.__plumX && window.__plumX.widgets) return resolve();

    const existing = document.querySelector('script[data-be-plumx="1"]');
    if (existing) {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', () => reject());
      return;
    }

    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.dataset.bePlumx = "1";
    s.onload = () => resolve();
    s.onerror = () => reject();
    document.head.appendChild(s);
  });

  const initWidgets = () => {
    try {
      if (window.__plumX && window.__plumX.widgets && typeof window.__plumX.widgets.init === 'function') {
        window.__plumX.widgets.init();
      }
    } catch (e) {}
  };

  const isVisible = (el) => {
    if (!el) return false;
    const cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return false;
    return (el.offsetWidth > 0 && el.offsetHeight > 0);
  };

  const hasNonEmptyContent = (el) => {
    if (!el) return false;
    const hasMedia = el.querySelector('svg, img, canvas, iframe');
    if (hasMedia) return true;
    const txt = (el.textContent || '').replace(/\s+/g, ' ').trim();
    return txt.length > 0;
  };

  const markEmptyCard = (card) => {
    if (!card || card.dataset.bePlumxEmpty === '1') return;
    card.dataset.bePlumxEmpty = '1';
    card.classList.add('is-empty');
    card.setAttribute('aria-hidden', 'true');
    card.style.display = 'none';
  };

  const showCard = (card) => {
    if (!card) return;
    card.dataset.bePlumxEmpty = '0';
    card.classList.remove('is-empty');
    card.classList.remove('be-plumx-state--pending');
    card.style.removeProperty('display');
  };

  const showBody = (card) => {
    const body = card ? card.querySelector('.be-plumx-card__body') : null;
    if (body) body.style.removeProperty('display');
    card && card.classList.remove('be-plumx-state--blocked');
  };

  const cardHasAnyVisiblePlumxOutput = (card) => {
    // PlumX typically injects elements with plumx-* classes or content into the anchor.
    const nodes = qsa('[class*="plumx"]', card);
    for (const n of nodes) {
      if (!isVisible(n)) continue;
      if (n.classList && n.classList.contains('be-plumx') && !hasNonEmptyContent(n)) continue;
      if (hasNonEmptyContent(n)) return true;
    }
    return false;
  };

  const evaluateCards = () => {
    qsa('.be-plumx-card').forEach(card => {
      if (card.dataset.bePlumxEmpty === '1') return;

      const state = card.getAttribute('data-be-plumx-state') || '';
      const hasBlocker = !!card.querySelector('.be-plumx-blocker');

      // Blocked cards should be visible (blocker UI), body hidden until loaded.
      if (state === 'blocked' || hasBlocker) {
        showCard(card);
        // keep body hidden until consent click / remembered consent
        return;
      }

      // Pending cards: show only if PlumX produced content, else keep hidden.
      if (cardHasAnyVisiblePlumxOutput(card)) {
        showCard(card);
      } else if (cfg.hideWrapperWhenEmpty) {
        // Don't mark empty immediately — wait for later timeouts/observer.
        // Here we just keep it hidden.
      }
    });
  };

  const finalizeEmpty = () => {
    if (!cfg.hideWrapperWhenEmpty) return;
    qsa('.be-plumx-card').forEach(card => {
      if (card.dataset.bePlumxEmpty === '1') return;
      const hasBlocker = !!card.querySelector('.be-plumx-blocker');
      if (hasBlocker) return; // never auto-hide blocker card
      if (cardHasAnyVisiblePlumxOutput(card)) return;

      // Still no output => empty
      markEmptyCard(card);
    });
  };

  const observePlumxRenders = () => {
    const cards = qsa('.be-plumx-card');
    if (!cards.length) return;

    const debounceMap = new WeakMap();
    const observer = new MutationObserver((mutations) => {
      const touched = new Set();
      mutations.forEach(m => {
        const card = m.target && m.target.closest ? m.target.closest('.be-plumx-card') : null;
        if (card) touched.add(card);
      });

      touched.forEach(card => {
        const prev = debounceMap.get(card);
        if (prev) window.clearTimeout(prev);
        const t = window.setTimeout(() => {
          evaluateCards();
        }, 150);
        debounceMap.set(card, t);
      });
    });

    cards.forEach(card => {
      observer.observe(card, { childList: true, subtree: true, attributes: true });
    });

    evaluateCards();
  };

  const loadPlumX = async () => {
    const src = cfg.scriptUrl;
    if (!src) return;

    await loadScriptOnce(src);
    initWidgets();
    observePlumxRenders();
    // staged checks (PlumX can be slow)
    window.setTimeout(evaluateCards, 800);
    window.setTimeout(evaluateCards, 1500);
    window.setTimeout(evaluateCards, 3000);
    window.setTimeout(evaluateCards, 6000);
    window.setTimeout(() => { evaluateCards(); finalizeEmpty(); }, 10000);
  };

  // Consent click
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-be-plumx-action="load"]');
    if (!btn) return;

    e.preventDefault();
    btn.disabled = true;

    const card = btn.closest('.be-plumx-card');

    try {
      await loadPlumX();
      rememberConsent();

      // remove blocker + show body
      const blocker = card ? card.querySelector('.be-plumx-blocker') : null;
      if (blocker) blocker.remove();
      if (card) {
        showBody(card);
        // Now that blocker is gone, treat it like pending until content or empty.
        card.setAttribute('data-be-plumx-state', 'pending');
        card.classList.add('be-plumx-state--pending');
        evaluateCards();
      }
    } catch (err) {
      btn.disabled = false;
    }
  });

  document.addEventListener('DOMContentLoaded', async () => {
    // Ensure blocked cards are visible (blocker UI) even if CSS didn't load yet
    qsa('.be-plumx-card.be-plumx-state--blocked').forEach(showCard);

    if (!cfg.requireConsent) {
      // Script is already enqueued server-side; we just wait for PlumX to render
      observePlumxRenders();
      window.setTimeout(evaluateCards, 800);
      window.setTimeout(evaluateCards, 1500);
      window.setTimeout(evaluateCards, 3000);
      window.setTimeout(evaluateCards, 6000);
      window.setTimeout(() => { evaluateCards(); finalizeEmpty(); }, 10000);
      return;
    }

    if (hasRememberedConsent()) {
      try {
        await loadPlumX();
        // remove blockers globally
        qsa('.be-plumx-blocker').forEach(b => b.remove());
        qsa('.be-plumx-card').forEach(card => {
          showBody(card);
          card.setAttribute('data-be-plumx-state','pending');
          card.classList.add('be-plumx-state--pending');
        });
        evaluateCards();
        window.setTimeout(() => { evaluateCards(); finalizeEmpty(); }, 5000);
      } catch (e) {}
    }
  });
})();
