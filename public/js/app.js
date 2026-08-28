document.addEventListener('DOMContentLoaded', () => {
    // ====== Filtro dinâmico de categorias ======
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

    // ====== Sidebar toggle (mobile) ======
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (sidebar && toggle) {
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 &&
                !sidebar.contains(e.target) &&
                !toggle.contains(e.target) &&
                sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        });
    }
});
