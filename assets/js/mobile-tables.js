/**
 * Berufsmesse – Mobile Tables & UX Enhancements
 * Auto-transforms data tables into card layout on mobile.
 * Also adds scroll-fade hints, touch-friendly DnD fallback, and misc UX fixes.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. AUTO-CARD TABLES
    // Finds every overflow-x-auto table in admin pages and:
    //   a) Adds .mobile-cards class
    //   b) Adds data-label to every td based on the corresponding thead th
    // -------------------------------------------------------------------------
    function initMobileTables() {
        const wrappers = document.querySelectorAll('.overflow-x-auto, .mobile-table-wrapper');

        wrappers.forEach(wrapper => {
            const table = wrapper.querySelector('table');
            if (!table) return;

            // Skip tables that opted-out or already processed
            if (table.dataset.mobileProcessed) return;
            if (table.classList.contains('no-mobile-cards')) return;

            // Collect header labels
            const headers = [];
            table.querySelectorAll('thead tr th').forEach(th => {
                headers.push(th.textContent.trim());
            });

            if (headers.length === 0) return;

            // Add class
            table.classList.add('mobile-cards');
            wrapper.classList.add('mobile-table-wrapper');

            // Label each td
            table.querySelectorAll('tbody tr').forEach(tr => {
                const cells = tr.querySelectorAll('td');
                cells.forEach((td, i) => {
                    if (headers[i] !== undefined && !td.dataset.label) {
                        td.dataset.label = headers[i] || '';
                    }
                });
            });

            table.dataset.mobileProcessed = '1';
        });
    }

    // -------------------------------------------------------------------------
    // 2. SCROLL FADE HINTS on filter rows / tab bars
    // -------------------------------------------------------------------------
    function initScrollFadeHints() {
        // Target horizontally scrollable filter rows
        const selectors = [
            '.flex.gap-2',
            '.flex.border-b',
            '.filter-tabs',
            '.tab-bar',
        ];

        selectors.forEach(sel => {
            document.querySelectorAll(sel).forEach(el => {
                // Only apply if the element really overflows
                if (el.scrollWidth > el.clientWidth || el.classList.contains('overflow-x-auto')) {
                    if (!el.classList.contains('mobile-scroll-fade')) {
                        el.classList.add('mobile-scroll-fade', 'scrollbar-hide');
                    }
                }
            });
        });
    }

    // -------------------------------------------------------------------------
    // 3. TOUCH-FRIENDLY DnD FALLBACK
    // On touch devices, replaces drag-and-drop with a tap-to-select + tap-to-drop
    // pattern using a floating select panel.
    // -------------------------------------------------------------------------
    let selectedDraggable = null;

    function initTouchDnD() {
        // Only activate on touch-only devices
        if (!('ontouchstart' in window) && !navigator.maxTouchPoints) return;

        const draggables = document.querySelectorAll('[draggable="true"]');
        if (draggables.length === 0) return;

        // Show mobile hint if present
        document.querySelectorAll('.dnd-mobile-hint').forEach(el => {
            el.style.display = 'flex';
        });

        draggables.forEach(el => {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (selectedDraggable === el) {
                    // Deselect
                    el.classList.remove('ring-2', 'ring-blue-400', 'ring-offset-2');
                    selectedDraggable = null;
                    return;
                }

                // Clear previous selection
                if (selectedDraggable) {
                    selectedDraggable.classList.remove('ring-2', 'ring-blue-400', 'ring-offset-2');
                }

                selectedDraggable = el;
                el.classList.add('ring-2', 'ring-blue-400', 'ring-offset-2');

                showToastIfAvailable('Element ausgewählt – tippe auf den Zielbereich', 'info');
            });
        });

        // Drop zones: tap to drop
        const dropZones = document.querySelectorAll('[data-drop-zone], .drop-zone, .room-slot');
        dropZones.forEach(zone => {
            zone.addEventListener('click', function () {
                if (!selectedDraggable) return;

                // Simulate a drop event
                const dropEvent = new DragEvent('drop', {
                    bubbles: true,
                    cancelable: true,
                });

                // Patch dataTransfer if needed
                if (!dropEvent.dataTransfer) {
                    Object.defineProperty(dropEvent, 'dataTransfer', {
                        value: {
                            getData: () => selectedDraggable.dataset.id || selectedDraggable.id || '',
                            setData: () => {},
                        },
                    });
                }

                zone.dispatchEvent(dropEvent);

                // Clear selection
                selectedDraggable.classList.remove('ring-2', 'ring-blue-400', 'ring-offset-2');
                selectedDraggable = null;
            });
        });
    }

    // -------------------------------------------------------------------------
    // 4. REGISTRATION PAGE – Sticky Search Bar + Selection Summary Footer
    // -------------------------------------------------------------------------
    function initRegistrationMobileUX() {
        const searchBar = document.querySelector('#exhibitorSearch, [id*="search"]');
        if (searchBar) {
            const bar = searchBar.closest('.bg-white, .card, div');
            if (bar && window.innerWidth <= 768) {
                bar.style.position = 'sticky';
                bar.style.top = '0';
                bar.style.zIndex = '10';
                bar.style.boxShadow = '0 2px 8px rgba(0,0,0,0.08)';
            }
        }
    }

    // -------------------------------------------------------------------------
    // 5. SAFE AREA PADDING for fixed bottom elements
    // -------------------------------------------------------------------------
    function initSafeAreaPadding() {
        document.querySelectorAll('.fixed.bottom-0, .sticky.bottom-0').forEach(el => {
            el.classList.add('fixed-bottom-safe');
        });
    }

    // -------------------------------------------------------------------------
    // 6. BETTER MOBILE UX for PDF download buttons (loading state)
    // -------------------------------------------------------------------------
    function initPdfButtonFeedback() {
        document.querySelectorAll('a[href*="generate-"], a[href*="export-"], button[onclick*="generate"]').forEach(btn => {
            btn.addEventListener('click', function () {
                const orig = btn.innerHTML;
                // Show spinner for 3 seconds (PDF generation takes time)
                if (!btn.dataset.loadingActive) {
                    btn.dataset.loadingActive = '1';
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i><span>Wird erstellt…</span>';
                    btn.style.pointerEvents = 'none';
                    btn.style.opacity = '0.7';
                    setTimeout(() => {
                        btn.innerHTML = orig;
                        btn.style.pointerEvents = '';
                        btn.style.opacity = '';
                        delete btn.dataset.loadingActive;
                    }, 4000);
                }
            });
        });
    }

    // -------------------------------------------------------------------------
    // Helper: show a toast if the global showToast function is available
    // -------------------------------------------------------------------------
    function showToastIfAvailable(msg, type) {
        if (typeof showToast === 'function') {
            showToast(msg, type);
        }
    }

    // -------------------------------------------------------------------------
    // RUN ALL INITIALIZERS
    // -------------------------------------------------------------------------
    function init() {
        initMobileTables();
        initScrollFadeHints();
        initTouchDnD();
        initRegistrationMobileUX();
        initSafeAreaPadding();
        initPdfButtonFeedback();
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Also re-run on Turbo/page navigation events if applicable
    document.addEventListener('pageChanged', init);

    // Expose for manual calls (e.g. after dynamic table updates)
    window.mobileTablesInit = initMobileTables;

})();
