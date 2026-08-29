function togglePassword(btn) {
    const targetId = btn.dataset.target;
    const input = document.getElementById(targetId);
    if (!input) return;
    const willShow = input.type === 'password';
    const hadFocus = document.activeElement === input;
    const selStart = input.selectionStart;
    const selEnd = input.selectionEnd;
    input.type = willShow ? 'text' : 'password';
    if (hadFocus) {
        input.focus();
        try { input.setSelectionRange(selStart, selEnd); } catch (_) {}
    }
    const openIcon  = btn.querySelector('.eye-open, .auth-eye-open');
    const closedIcon = btn.querySelector('.eye-closed, .auth-eye-closed');
    if (openIcon)   openIcon.style.display   = willShow ? 'none' : '';
    if (closedIcon) closedIcon.style.display = willShow ? '' : 'none';
    btn.setAttribute('aria-label', willShow ? 'Ocultar senha' : 'Mostrar senha');
    btn.setAttribute('aria-pressed', willShow ? 'true' : 'false');
}

function initPasswordToggles(root = document) {
    root.querySelectorAll('.toggle-password, .auth-toggle-pw').forEach(btn => {
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            togglePassword(btn);
        });
    });
}

function whenReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
}

whenReady(() => {
    initPasswordToggles();
    // delegado — garante clique no ícone/SVG e em conteúdo carregado depois
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.auth-toggle-pw, .toggle-password');
        if (!btn) return;
        // se já tem bound direto, deixa o handler direto cuidar e evita duplo toggle
        if (btn.dataset.bound === '1') return;
        e.preventDefault();
        togglePassword(btn);
    });

    /* ============================================================
       AUTH PAGES — validação de confirmação de senha
       ============================================================ */
    const pwd        = document.getElementById('password');
    const pwdConfirm = document.getElementById('password_confirm');
    const pwdHint    = document.getElementById('passwordMatchHint');

    if (pwd && pwdConfirm && pwdHint) {
        const validate = () => {
            if (!pwdConfirm.value) {
                pwdHint.textContent = '';
                pwdHint.className = 'auth-hint';
                pwdConfirm.setCustomValidity('');
                return;
            }
            if (pwd.value === pwdConfirm.value) {
                pwdHint.textContent = '✓ Senhas conferem';
                pwdHint.className = 'auth-hint match';
                pwdConfirm.setCustomValidity('');
            } else {
                pwdHint.textContent = '✕ As senhas não coincidem';
                pwdHint.className = 'auth-hint no-match';
                pwdConfirm.setCustomValidity('As senhas não coincidem');
            }
        };
        pwd.addEventListener('input', validate);
        pwdConfirm.addEventListener('input', validate);
    }

    /* ============================================================
       AUTH PAGES — requisitos visuais de senha (cadastro)
       ============================================================ */
    const pwdRules = document.getElementById('passwordRules');
    if (pwd && pwdRules) {
        const checks = {
            len:     v => v.length >= 8,
            upper:   v => /[A-Z]/.test(v),
            num:     v => /\d/.test(v),
            special: v => /[^A-Za-z0-9]/.test(v),
        };
        const items = pwdRules.querySelectorAll('li[data-rule]');
        const validate = () => {
            items.forEach(li => {
                const rule = li.dataset.rule;
                const ok = checks[rule] ? checks[rule](pwd.value) : false;
                li.classList.toggle('ok', ok);
            });
        };
        pwd.addEventListener('input', validate);
        validate();
    }

    /* ============================================================
       Filtro dinâmico de categorias (formulário "Novo Lançamento")
       ============================================================ */
    const typeSelect = document.getElementById('type');
    const categorySelect = document.getElementById('category_id');

    if (typeSelect && categorySelect) {
        const allOptions = Array.from(categorySelect.options);

        function updateCategories() {
            const selectedType = typeSelect.value;
            categorySelect.innerHTML = '';

            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = 'Sem categoria';
            categorySelect.appendChild(emptyOption);

            allOptions.forEach(option => {
                if (option.value !== '' && option.dataset.type === selectedType) {
                    categorySelect.appendChild(option.cloneNode(true));
                }
            });

            categorySelect.value = '';
        }

        typeSelect.addEventListener('change', updateCategories);
        updateCategories();
    }

    /* ============================================================
       Sidebar toggle (mobile) + backdrop
       ============================================================ */
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar && toggle) {
        const openSidebar = () => {
            sidebar.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
            if (backdrop) backdrop.classList.add('is-open');
        };
        const closeSidebar = () => {
            sidebar.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
            if (backdrop) backdrop.classList.remove('is-open');
        };
        toggle.addEventListener('click', () => {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
        backdrop && backdrop.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
        });
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 &&
                !sidebar.contains(e.target) &&
                !toggle.contains(e.target) &&
                sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });
    }

    /* ============================================================
       Atalhos de filtro de período
       ============================================================ */
    const startInput = document.getElementById('inputStartDate');
    const endInput = document.getElementById('inputEndDate');
    const filterForm = document.getElementById('filterForm');

    if (startInput && endInput && filterForm) {
        document.querySelectorAll('.filter-shortcut').forEach(btn => {
            btn.setAttribute('role', 'tab');
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-shortcut').forEach(b => {
                    b.removeAttribute('aria-selected');
                    b.classList.remove('is-active');
                });
                btn.setAttribute('aria-selected', 'true');
                btn.classList.add('is-active');
                const range = btn.dataset.range;
                const today = new Date();
                let start, end;

                if (range === 'today') {
                    start = end = formatYMD(today);
                } else if (range === 'month') {
                    start = formatYMD(new Date(today.getFullYear(), today.getMonth(), 1));
                    end   = formatYMD(new Date(today.getFullYear(), today.getMonth() + 1, 0));
                } else if (range === 'last-month') {
                    start = formatYMD(new Date(today.getFullYear(), today.getMonth() - 1, 1));
                    end   = formatYMD(new Date(today.getFullYear(), today.getMonth(), 0));
                }

                if (start && end) {
                    startInput.value = start;
                    endInput.value = end;
                    filterForm.submit();
                }
            });
        });
    }

    function formatYMD(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    /* ============================================================
       Validação visual do formulário de lançamento
       ============================================================ */
    const txForm = document.getElementById('addTransactionForm');
    if (txForm) {
        txForm.addEventListener('submit', (e) => {
            let valid = true;
            const desc = txForm.querySelector('#description');
            const amt  = txForm.querySelector('#amount');
            const date = txForm.querySelector('#date');

            clearError(txForm.querySelector('#grp-description'));
            clearError(txForm.querySelector('#grp-amount'));
            clearError(txForm.querySelector('#grp-date'));

            if (!desc || !desc.value.trim()) {
                setError(txForm.querySelector('#grp-description'));
                valid = false;
            }
            if (!amt || parseFloat(amt.value) <= 0) {
                setError(txForm.querySelector('#grp-amount'));
                valid = false;
            }
            if (!date || !date.value) {
                setError(txForm.querySelector('#grp-date'));
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
            } else {
                const btn = document.getElementById('btnSubmit');
                if (btn) {
                    btn.disabled = true;
                    btn.style.opacity = '0.7';
                    btn.innerHTML = 'Salvando…';
                }
            }
        });
    }

    function setError(group) {
        if (group) group.classList.add('has-error');
    }
    function clearError(group) {
        if (group) group.classList.remove('has-error');
    }

    /* ============================================================
       Confirmação de exclusão
       ============================================================ */
    document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', (e) => {
            if (!confirm('Tem certeza que deseja excluir este lançamento? Esta ação não pode ser desfeita.')) {
                e.preventDefault();
            }
        });
    });

    /* ============================================================
       Auto-fade dos alerts
       ============================================================ */
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-6px)';
            setTimeout(() => alert.remove(), 350);
        }, 4500);
    });
});
