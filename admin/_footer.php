</div><!-- /.page-inner -->
        </div><!-- /.page-content -->
    </div><!-- /.main -->
</div><!-- /.layout -->

<!-- ============================================================
     NAVORA ADMIN SHELL SCRIPT
     Frosted Glass · sidebar · search · notif refresh · fade-in
     ============================================================ -->
<script>
(function () {
    'use strict';

    /* -------------------------------------------------------------
       1) Sidebar toggle (mobile) — driven by #sidebarToggle
       ------------------------------------------------------------- */
    const sidebarToggle   = document.getElementById('sidebarToggle');
    const sidebar         = document.getElementById('adminSidebar');
    const sidebarBackdrop = document.getElementById('adminSidebarBackdrop');

    function setSidebarOpen(forceOpen) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', forceOpen);
        if (sidebarBackdrop) {
            sidebarBackdrop.classList.toggle('show', forceOpen);
        }
        if (sidebarToggle) {
            sidebarToggle.classList.toggle('is-hidden', forceOpen);
        }
        document.body.style.overflow = forceOpen ? 'hidden' : '';
    }

    if (window.innerWidth <= 992) {
        setSidebarOpen(false);
    }

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = sidebar.classList.contains('open');
            setSidebarOpen(!isOpen);
        });

        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', () => setSidebarOpen(false));
        }

        sidebar.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 992) setSidebarOpen(false);
            });
        });

        document.addEventListener('click', (e) => {
            if (
                sidebar.classList.contains('open') &&
                !sidebar.contains(e.target) &&
                e.target !== sidebarToggle &&
                !sidebarToggle.contains(e.target) &&
                (!sidebarBackdrop || e.target !== sidebarBackdrop)
            ) {
                setSidebarOpen(false);
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 992 && sidebar.classList.contains('open')) {
                setSidebarOpen(false);
            }
        });
    }

    /* -------------------------------------------------------------
       2) Search — "/" focuses input; ESC clears + blurs
       ------------------------------------------------------------- */
    const searchInput = document.getElementById('topSearch');
    const searchForm  = searchInput ? searchInput.closest('form') : null;

    if (searchInput && searchForm) {
        document.addEventListener('keydown', (e) => {
            const tag = document.activeElement?.tagName;
            const isTyping = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
            if (e.key === '/' && !isTyping) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                searchInput.value = '';
                searchInput.blur();
            }
        });

        searchForm.addEventListener('submit', (e) => {
            const q = searchInput.value.trim();
            if (!q) {
                e.preventDefault();
                searchInput.focus();
            }
        });
    }

    /* -------------------------------------------------------------
       3) Live count refresh (every 30s)
          - Bell badge: system notifications (notifications.is_read = 0)
          - Pending users button: users.status = 'pending'
          - Pending employers / jobs / reports are also returned,
            but we only render them on the dashboard tiles (server-side).
       ------------------------------------------------------------- */
    const topbarActions = document.querySelector('.topbar-actions');

    function renderBadge(btn, count, badgeClass) {
        if (!btn) return;
        let badge = btn.querySelector('.count-badge');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'count-badge ' + (badgeClass || '');
                btn.appendChild(badge);
            } else if (badgeClass) {
                badge.className = 'count-badge ' + badgeClass;
            }
            badge.textContent = count > 99 ? '99+' : count;
        } else if (badge) {
            badge.remove();
        }
    }

    function ensurePendingUsersBtn(count) {
        if (!topbarActions) return;
        let btn = document.getElementById('pendingUsersBtn');
        if (count > 0 && !btn) {
            // Create the button if the page loaded when the queue was empty
            btn = document.createElement('a');
            btn.id = 'pendingUsersBtn';
            btn.href = '/navora3/admin/users.php?status=pending';
            btn.className = 'icon-btn pending-users-btn';
            btn.title = count + ' user(s) awaiting approval';
            btn.innerHTML = '<i class="fas fa-user-clock"></i>' +
                '<span class="count-badge badge-amber" id="pendingUsersBadge"></span>';
            const notifToggle = document.getElementById('notifToggle');
            if (notifToggle && notifToggle.parentNode) {
                notifToggle.parentNode.insertBefore(btn, notifToggle);
            } else {
                topbarActions.appendChild(btn);
            }
        }
        if (btn) {
            btn.title = count + ' user(s) awaiting approval';
            renderBadge(btn, count, 'badge-amber');
        }
    }

    function refreshNotificationDot() {
        fetch('/navora3/admin/api/notif-count.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data) return;

                // 1) Bell — system notifications only
                const notifBtn = document.getElementById('notifToggle');
                if (notifBtn) {
                    renderBadge(notifBtn, data.unread_notifs ?? data.count ?? 0, '');
                }

                // 2) Pending users — amber button
                ensurePendingUsersBtn(data.pending_users ?? 0);
            })
            .catch(() => { /* silent */ });
    }

    refreshNotificationDot();
    setInterval(refreshNotificationDot, 30000);

    /* -------------------------------------------------------------
       4) Frosted card fade-in animation
       ------------------------------------------------------------- */
    document.querySelectorAll('.admin-stat, .card-panel, .table-panel').forEach((el, i) => {
        if (el.dataset.noFade !== undefined) return;

        el.style.opacity = '0';
        el.style.transform = 'translateY(10px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        setTimeout(() => {
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
        }, 50 * Math.min(i, 8));
    });

})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>