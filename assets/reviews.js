(function () {
  'use strict';
  function carousel(section) {
    const grid = section.querySelector('.aplr-grid');
    if (!grid || !(section.classList.contains('aplr-layout--carousel') || section.classList.contains('aplr-style--carousel'))) return () => {};
    const toggle = section.querySelector('[data-aplr-toggle]');
    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    const hover = matchMedia('(hover: hover)');
    let timer, userPaused = false, pointerDown = false, onscreen = true, destroyed = false;
    function blocked() {
      return destroyed || document.hidden || motion.matches || !onscreen || userPaused || pointerDown ||
        (hover.matches && section.matches(':hover')) || section.contains(document.activeElement);
    }
    function move(direction) {
      const first = grid.querySelector('.aplr-card');
      const step = first ? first.getBoundingClientRect().width + (parseFloat(getComputedStyle(grid).gap) || 0) : grid.clientWidth;
      const max = grid.scrollWidth - grid.clientWidth;
      const end = grid.scrollLeft >= max - 3;
      const beginning = grid.scrollLeft <= 3;
      const next = direction > 0 ? (end ? 0 : Math.min(max, grid.scrollLeft + step)) : (beginning ? max : Math.max(0, grid.scrollLeft - step));
      grid.scrollTo({ left: next, behavior: motion.matches ? 'auto' : 'smooth' });
    }
    function schedule() {
      clearTimeout(timer);
      if (toggle) { toggle.hidden = motion.matches; toggle.textContent = userPaused ? 'Resume autoplay' : 'Pause autoplay'; toggle.setAttribute('aria-pressed', String(userPaused)); }
      if (section.dataset.aplrAutoplay !== 'yes' || blocked() || grid.scrollWidth <= grid.clientWidth + 3) return;
      timer = setTimeout(() => { if (!blocked()) move(1); schedule(); }, 3000);
    }
    function enter() { clearTimeout(timer); }
    function down() { pointerDown = true; enter(); }
    function up() { pointerDown = false; schedule(); }
    function focusout() { queueMicrotask(schedule); }
    function click(event) {
      const arrow = event.target.closest('[data-aplr-move]');
      if (arrow) { move(Number(arrow.dataset.aplrMove)); schedule(); }
      if (event.target.closest('[data-aplr-toggle]')) { userPaused = !userPaused; schedule(); }
    }
    section.addEventListener('pointerenter', enter);
    section.addEventListener('pointerleave', schedule);
    section.addEventListener('pointerdown', down);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
    section.addEventListener('focusin', enter);
    section.addEventListener('focusout', focusout);
    section.addEventListener('click', click);
    document.addEventListener('visibilitychange', schedule);
    motion.addEventListener('change', schedule);
    const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => { onscreen = entries[0].isIntersecting; schedule(); }) : null;
    if (observer) observer.observe(section);
    schedule();
    return () => {
      destroyed = true; clearTimeout(timer); if (observer) observer.disconnect();
      section.removeEventListener('pointerenter', enter); section.removeEventListener('pointerleave', schedule);
      section.removeEventListener('pointerdown', down); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up);
      section.removeEventListener('focusin', enter); section.removeEventListener('focusout', focusout); section.removeEventListener('click', click);
      document.removeEventListener('visibilitychange', schedule); motion.removeEventListener('change', schedule);
    };
  }
  function start(mount) {
    if (mount.dataset.aplrStarted) return;
    mount.dataset.aplrStarted = 'yes';
    let timer, expiryTimer, controller, lastCheck = 0, destroy = () => {};
    let html = mount.innerHTML;
    let expires = Number(mount.dataset.aplrExpires || 0) * 1000;
    function clear() { destroy(); destroy = () => {}; mount.replaceChildren(); html = ''; }
    function expire() {
      clearTimeout(expiryTimer);
      if (expires && expires <= Date.now()) clear();
      else if (expires) expiryTimer = setTimeout(clear, Math.min(expires - Date.now(), 2147483647));
    }
    function init() { const section = mount.querySelector('.aplr'); if (section) destroy = carousel(section); }
    expire(); init();
    async function load() {
      if (controller || document.hidden || !mount.isConnected) return;
      clearTimeout(timer);
      controller = new AbortController();
      try {
        const args = JSON.parse(mount.dataset.aplrOptions || '{}');
        const body = new URLSearchParams(Object.assign({ action: 'aplr_public' }, args));
        const response = await fetch(mount.dataset.aplrEndpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body, signal: controller.signal });
        if (!response.ok) throw new Error('Unavailable');
        const result = await response.json();
        if (!result.success || typeof result.data.html !== 'string') throw new Error('Unavailable');
        lastCheck = Date.now();
        expires = Number(result.data.expires || 0) * 1000;
        if (result.data.html !== html) {
          const grid = mount.querySelector('.aplr-grid');
          const offset = grid ? grid.scrollLeft : 0;
          destroy();
          html = result.data.html;
          // HTML is escaped by the server. Credentials never enter the browser.
          mount.innerHTML = html;
          const updatedGrid = mount.querySelector('.aplr-grid');
          if (updatedGrid) updatedGrid.scrollLeft = offset;
          init();
        }
        expire();
      } catch (error) {
        // Preserve fresh content on transient errors and tab switches.
        expire();
      } finally {
        controller = null;
        if (mount.isConnected) timer = setTimeout(load, 300000);
      }
    }
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) clearTimeout(timer);
      else { expire(); if (Date.now() - lastCheck >= 300000) load(); else timer = setTimeout(load, 300000 - (Date.now() - lastCheck)); }
    });
    window.addEventListener('pageshow', event => { if (event.persisted) { expire(); load(); } });
    load();
  }
  document.querySelectorAll('.aplr-mount').forEach(start);
  // Offline style preview only; never injects demo data into the installed plugin.
  document.querySelectorAll('[data-aplr-preview] .aplr').forEach(carousel);
})();
