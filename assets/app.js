/**
 * Leafty Agriculture Information System - Production JS
 * Modules: PJAX Router, Custom Select/Combobox Enhancer, Global Modal, Toast System
 */

(function () {
  'use strict';

  /* ─── 1. Toast Notification System ────────────────────────────────── */
  function showToast(message, type = 'info', duration = 3800) {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `sp-toast ${type}`;

    let iconSvg = '';
    if (type === 'success') {
      iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#15803D" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>';
    } else if (type === 'error') {
      iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
    } else if (type === 'warning') {
      iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    } else {
      iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0369A1" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
    }

    toast.innerHTML = `
      <div style="display:flex;align-items:center;gap:0.5rem;flex:1;">
        ${iconSvg}
        <span>${message}</span>
      </div>
      <button type="button" style="background:none;border:none;cursor:pointer;color:#7E8F85;font-size:1.1rem;line-height:1;" aria-label="Close">&times;</button>
    `;

    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));

    const dismiss = () => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 250);
    };

    toast.querySelector('button').addEventListener('click', dismiss);
    if (duration > 0) {
      setTimeout(dismiss, duration);
    }
  }
  window.showToast = showToast;

  /* ─── 2. Global Modal Helpers ──────────────────────────────────────── */
  function openModal(id) {
    const el = typeof id === 'string' ? document.getElementById(id) : id;
    if (el) {
      el.classList.add('open');
      // Synchronize custom selects with populated native select values
      el.querySelectorAll('select').forEach(sel => {
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  }
  window.openModal = openModal;

  function closeModal(id) {
    const el = typeof id === 'string' ? document.getElementById(id) : id;
    if (el) el.classList.remove('open');
  }
  window.closeModal = closeModal;

  /* ─── 3. PJAX Router & Progress Bar ───────────────────────────────── */
  const progressBar = {
    el: null,
    init() {
      if (!this.el) {
        this.el = document.getElementById('pjax-progress');
        if (!this.el) {
          this.el = document.createElement('div');
          this.el.id = 'pjax-progress';
          document.body.prepend(this.el);
        }
      }
    },
    start() {
      this.init();
      this.el.style.width = '20%';
      this.el.classList.add('loading');
      setTimeout(() => {
        if (this.el.classList.contains('loading')) this.el.style.width = '65%';
      }, 150);
    },
    done() {
      if (!this.el) return;
      this.el.style.width = '100%';
      setTimeout(() => {
        this.el.classList.remove('loading');
        this.el.style.width = '0%';
      }, 250);
    }
  };

  async function loadPjax(url, pushState = true, options = {}) {
    const targetUrl = (typeof url === 'string' && url.trim() !== '') ? url : window.location.href;
    const container = document.getElementById('pjax-container');
    if (!container) {
      window.location.href = targetUrl;
      return;
    }

    progressBar.start();
    try {
      const fetchOpts = {
        headers: {
          'X-PJAX': 'true',
          'X-Requested-With': 'XMLHttpRequest'
        },
        ...options
      };

      const res = await fetch(targetUrl, fetchOpts);
      if (res.redirected) {
        window.location.href = res.url;
        return;
      }
      if (!res.ok) {
        throw new Error(`HTTP error ${res.status}`);
      }

      const text = await res.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(text, 'text/html');

      // Update document title
      const newTitle = doc.querySelector('title');
      if (newTitle) {
        document.title = newTitle.textContent;
      }

      // Replace container content
      const newContainer = doc.getElementById('pjax-container');
      if (newContainer) {
        container.innerHTML = newContainer.innerHTML;
      } else {
        container.innerHTML = text;
      }

      // Close any open modals
      document.querySelectorAll('.sp-modal-backdrop.open').forEach(m => m.classList.remove('open'));

      // Re-evaluate script tags inside container so page functions execute
      const scripts = container.querySelectorAll('script');
      scripts.forEach(oldScript => {
        const newScript = document.createElement('script');
        Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
        newScript.textContent = oldScript.textContent;
        oldScript.parentNode.replaceChild(newScript, oldScript);
      });

      if (pushState) {
        history.pushState({ pjax: true, url: targetUrl }, '', targetUrl);
      }

      updateActiveNavLinks(targetUrl);
      initComponents();

      // Bug Fix #2: After PJAX loads the dashboard, Chart.js CDN scripts that
      // are inside the container get re-executed (the script re-runner above does
      // that), but only AFTER initComponents() runs.  Those inline scripts call
      // initDashboardCharts() themselves, so no extra call is needed here.
      // However, if Chart.js itself was not yet on the page (first PJAX nav to
      // the dashboard), wait for it to load before calling initDashboardCharts.
      const chartScripts = container.querySelectorAll('script[src*="chart.js"], script[src*="chart.umd"]');
      if (chartScripts.length > 0 && typeof window.initDashboardCharts === 'function') {
        // CDN script tag was re-injected; give it a tick to execute, then draw.
        const firstChartScript = chartScripts[chartScripts.length - 1];
        firstChartScript.addEventListener('load', () => {
          if (typeof window.initDashboardCharts === 'function') {
            window.initDashboardCharts();
          }
        });
      }

      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      console.warn('PJAX load error, falling back to standard load:', err);
      const fallbackUrl = (typeof targetUrl === 'string' && targetUrl.trim() !== '') ? targetUrl : window.location.href;
      window.location.href = fallbackUrl;
    } finally {
      progressBar.done();
    }
  }
  window.loadPjax = loadPjax;

  function updateActiveNavLinks(currentUrl) {
    const parsed = new URL(currentUrl, window.location.origin);
    const path = parsed.pathname.split('/').pop() || 'index.php';

    document.querySelectorAll('.nav-pill-link, .mobile-nav-item').forEach(link => {
      const linkPath = link.getAttribute('href') || '';
      if (linkPath === path || (path === '' && linkPath === 'index.php')) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });
  }

  window.addEventListener('popstate', (e) => {
    if (e.state && e.state.pjax) {
      loadPjax(e.state.url, false);
    } else {
      loadPjax(window.location.href, false);
    }
  });

  /* ─── 4. Custom Select & Search Combobox Enhancer ──────────────────── */
  function enhanceSelects() {
    document.querySelectorAll('select.form-control, select.enhance-select').forEach(select => {
      if (select.dataset.enhanced === 'true' || select.hasAttribute('data-no-enhance')) return;

      select.dataset.enhanced = 'true';
      select.style.display = 'none';

      const container = document.createElement('div');
      container.className = 'custom-select-container';

      const trigger = document.createElement('div');
      trigger.className = 'custom-select-trigger';
      trigger.tabIndex = 0;

      const triggerText = document.createElement('span');
      const selectedOption = select.options[select.selectedIndex];
      triggerText.textContent = selectedOption ? selectedOption.text : '-- Select --';

      const triggerIcon = document.createElement('span');
      triggerIcon.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>`;

      trigger.appendChild(triggerText);
      trigger.appendChild(triggerIcon);
      container.appendChild(trigger);

      const panel = document.createElement('div');
      panel.className = 'custom-select-panel';

      if (select.options.length > 8) {
        const searchBox = document.createElement('div');
        searchBox.className = 'custom-select-search';
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search options...';
        searchBox.appendChild(searchInput);
        panel.appendChild(searchBox);
      }

      const optionsList = document.createElement('ul');
      optionsList.className = 'custom-select-options';

      Array.from(select.options).forEach((opt, idx) => {
        const li = document.createElement('li');
        li.className = 'custom-select-option' + (idx === select.selectedIndex ? ' selected' : '');
        li.textContent = opt.text;
        li.dataset.value = opt.value;
        optionsList.appendChild(li);
      });

      panel.appendChild(optionsList);
      container.appendChild(panel);

      select.parentNode.insertBefore(container, select.nextSibling);
    });
  }

  /* ─── 5. Document-Level Event Delegation ──────────────────────────── */
  let activeDeleteForm = null;

  // Clicks delegation
  document.addEventListener('click', (e) => {
    // A. Delete Confirmation Modal Buttons
    if (e.target.closest('#confirm-modal-btn')) {
      e.preventDefault();
      const modalBackdrop = document.getElementById('confirm-modal-backdrop');
      if (modalBackdrop) modalBackdrop.classList.remove('open');
      if (activeDeleteForm) {
        const formToSubmit = activeDeleteForm;
        activeDeleteForm = null;
        formToSubmit.submit();
      }
      return;
    }
    if (e.target.closest('#confirm-modal-cancel, #confirm-modal-close')) {
      e.preventDefault();
      const modalBackdrop = document.getElementById('confirm-modal-backdrop');
      if (modalBackdrop) modalBackdrop.classList.remove('open');
      activeDeleteForm = null;
      return;
    }
    if (e.target.id === 'confirm-modal-backdrop') {
      e.target.classList.remove('open');
      activeDeleteForm = null;
      return;
    }

    // B. Modal Close buttons (.sp-modal-close or [data-modal-close])
    const modalClose = e.target.closest('.sp-modal-close, [data-modal-close]');
    if (modalClose) {
      e.preventDefault();
      const modal = modalClose.closest('.sp-modal-backdrop');
      if (modal) modal.classList.remove('open');
      return;
    }
    // Clicking directly on modal backdrop closes it
    if (e.target.classList.contains('sp-modal-backdrop')) {
      e.target.classList.remove('open');
      return;
    }

    // C. Modal Open triggers ([data-modal-target])
    const modalTrigger = e.target.closest('[data-modal-target]');
    if (modalTrigger) {
      e.preventDefault();
      const targetId = modalTrigger.getAttribute('data-modal-target');
      openModal(targetId);
      return;
    }

    // D. Dropdown toggles
    const dropdownToggle = e.target.closest('.dropdown-toggle');
    if (dropdownToggle) {
      e.preventDefault();
      e.stopPropagation();
      const dropdown = dropdownToggle.closest('.dropdown');
      const wasOpen = dropdown.classList.contains('open');
      document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
      if (!wasOpen) dropdown.classList.add('open');
      return;
    }
    // Close dropdowns when clicking outside
    if (!e.target.closest('.dropdown')) {
      document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
    }

    // E. Mobile Bottom Sheets
    const sheetTrigger = e.target.closest('[data-sheet-target]');
    if (sheetTrigger) {
      e.preventDefault();
      const targetId = sheetTrigger.getAttribute('data-sheet-target');
      const sheet = document.getElementById(targetId);
      const backdrop = document.getElementById('sheet-backdrop');
      if (sheet && backdrop) {
        backdrop.classList.add('open');
        sheet.classList.add('open');
      }
      return;
    }
    if (e.target.closest('.bottom-sheet-handle') || e.target.id === 'sheet-backdrop') {
      const backdrop = document.getElementById('sheet-backdrop');
      if (backdrop) backdrop.classList.remove('open');
      document.querySelectorAll('.bottom-sheet.open').forEach(s => s.classList.remove('open'));
      return;
    }

    // F. Custom Select Trigger Click
    const selectTrigger = e.target.closest('.custom-select-trigger');
    if (selectTrigger) {
      e.stopPropagation();
      const container = selectTrigger.closest('.custom-select-container');
      const wasOpen = container.classList.contains('open');
      document.querySelectorAll('.custom-select-container.open').forEach(c => {
        if (c !== container) c.classList.remove('open');
      });
      if (!wasOpen) {
        container.classList.add('open');
        const searchInput = container.querySelector('.custom-select-search input');
        if (searchInput) {
          searchInput.value = '';
          container.querySelectorAll('.custom-select-option').forEach(opt => opt.style.display = '');
          setTimeout(() => searchInput.focus(), 50);
        }
      } else {
        container.classList.remove('open');
      }
      return;
    }

    // G. Custom Select Option Click
    const selectOption = e.target.closest('.custom-select-option');
    if (selectOption && selectOption.dataset.value !== undefined) {
      e.stopPropagation();
      const container = selectOption.closest('.custom-select-container');
      const nativeSelect = container.previousElementSibling;
      if (nativeSelect && nativeSelect.tagName === 'SELECT') {
        nativeSelect.value = selectOption.dataset.value;
        const triggerText = container.querySelector('.custom-select-trigger span:first-child');
        if (triggerText) triggerText.textContent = selectOption.textContent;

        container.querySelectorAll('.custom-select-option').forEach(li => {
          li.classList.toggle('selected', li === selectOption);
        });

        nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
      }
      container.classList.remove('open');
      return;
    }

    // Close open custom selects when clicking outside
    if (!e.target.closest('.custom-select-container')) {
      document.querySelectorAll('.custom-select-container.open').forEach(c => c.classList.remove('open'));
    }

    // H. Internal Navigation Links (PJAX)
    const link = e.target.closest('a');
    if (link) {
      const href = link.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
      if (link.hasAttribute('download') || link.getAttribute('target') === '_blank' || link.hasAttribute('data-no-pjax')) return;

      // Never intercept logout — always do a full navigation so the session
      // is properly destroyed and the browser gets the redirect to login.php.
      const hrefPath = href.split('?')[0].split('/').pop();
      if (hrefPath === 'logout.php') {
        window.location.href = link.href;
        return;
      }

      const url = new URL(link.href, window.location.origin);
      if (url.origin !== window.location.origin) return;

      e.preventDefault();
      loadPjax(link.href, true);
    }
  });

  // Submit delegation (Forms & Delete confirmation)
  document.addEventListener('submit', (e) => {
    const form = e.target;

    // Delete confirmation modal interception
    if (form.hasAttribute('data-confirm-delete') || form.hasAttribute('data-confirm')) {
      e.preventDefault();
      activeDeleteForm = form;
      const modalBackdrop = document.getElementById('confirm-modal-backdrop');
      const modalTitle    = document.getElementById('confirm-modal-title');
      const modalMsg      = document.getElementById('confirm-modal-msg');
      const recordName    = form.getAttribute('data-record-name') || 'this record';

      if (modalTitle) modalTitle.textContent = 'Confirm Soft Deletion';
      if (modalMsg) {
        modalMsg.innerHTML = `Are you sure you want to deactivate / delete <strong>${recordName}</strong>?<br><br><span style="color:#52635A;font-size:0.85rem;">This operation uses Soft Deletes (<code>is_deleted = TRUE</code>) and will be blocked if active child dependencies are attached to it.</span>`;
      }
      if (modalBackdrop) modalBackdrop.classList.add('open');
      return;
    }

    // PJAX Form Submission
    if (form.hasAttribute('data-pjax')) {
      e.preventDefault();
      // Safely read action attribute without colliding with inputs named "action"
      const rawAction = form.getAttribute('action');
      let targetUrl = '';
      if (rawAction && rawAction.trim() !== '' && rawAction.trim() !== '#') {
        targetUrl = rawAction.trim();
      } else {
        const curUrl = new URL(window.location.href);
        const fileName = curUrl.pathname.split('/').filter(Boolean).pop();
        targetUrl = (fileName && fileName.endsWith('.php')) ? fileName + curUrl.search : curUrl.href;
      }

      const method = (form.method || 'GET').toUpperCase();
      if (method === 'GET') {
        const formData = new FormData(form);
        const params = new URLSearchParams(formData).toString();
        const baseAction = targetUrl.split('?')[0];
        const actionUrl = baseAction + (params ? '?' + params : '');
        loadPjax(actionUrl, true);
      } else {
        const formData = new FormData(form);
        loadPjax(targetUrl, false, {
          method: 'POST',
          body: formData
        });
      }
    }
  });

  // Input filtering for custom select search
  document.addEventListener('input', (e) => {
    if (e.target.matches('.custom-select-search input')) {
      const filter = e.target.value.toLowerCase();
      const container = e.target.closest('.custom-select-container');
      const options = container.querySelectorAll('.custom-select-option');
      let matches = 0;
      options.forEach(opt => {
        const match = opt.textContent.toLowerCase().includes(filter);
        opt.style.display = match ? '' : 'none';
        if (match) matches++;
      });

      let noMatch = container.querySelector('.custom-select-no-match');
      if (matches === 0) {
        if (!noMatch) {
          noMatch = document.createElement('li');
          noMatch.className = 'custom-select-option custom-select-no-match';
          noMatch.style.color = '#7E8F85';
          noMatch.style.cursor = 'default';
          noMatch.textContent = 'No matching options';
          container.querySelector('.custom-select-options').appendChild(noMatch);
        }
        noMatch.style.display = '';
      } else if (noMatch) {
        noMatch.style.display = 'none';
      }
    }
  });

  // Native Select change -> update custom select display
  document.addEventListener('change', (e) => {
    if (e.target.tagName === 'SELECT') {
      const container = e.target.nextElementSibling;
      if (container && container.classList.contains('custom-select-container')) {
        const triggerText = container.querySelector('.custom-select-trigger span:first-child');
        const selectedOpt = e.target.options[e.target.selectedIndex];
        if (triggerText) {
          triggerText.textContent = selectedOpt ? selectedOpt.text : '-- Select --';
        }
        container.querySelectorAll('.custom-select-option').forEach(li => {
          li.classList.toggle('selected', li.dataset.value === e.target.value);
        });
      }
    }
  });

  /* ─── 6. Unified Component Initializer ─────────────────────────────── */
  function initComponents() {
    enhanceSelects();
  }

  document.addEventListener('DOMContentLoaded', () => {
    initComponents();
  });
})();
