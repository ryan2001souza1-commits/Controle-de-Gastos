document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.transaction-form');
    const typeSelect = document.getElementById('type');
    const categorySelect = document.getElementById('category_id');

    if (form && typeSelect && categorySelect) {
        let expenseCategories = [];
        let incomeCategories = [];

        try {
            expenseCategories = JSON.parse(form.dataset.expenseCategories || '[]');
            incomeCategories  = JSON.parse(form.dataset.incomeCategories  || '[]');
        } catch (err) {
            console.error('Erro ao carregar categorias:', err);
        }

        function populateCategories(type) {
            categorySelect.innerHTML = '';

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Sem categoria';
            categorySelect.appendChild(placeholder);

            const list = type === 'receita' ? incomeCategories : expenseCategories;
            list.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat.id;
                opt.textContent = cat.name;
                categorySelect.appendChild(opt);
            });
        }

        populateCategories(typeSelect.value);

        typeSelect.addEventListener('change', (e) => {
            populateCategories(e.target.value);
        });
    }

    const amountInputs = document.querySelectorAll('input[type="number"][name="amount"]');
    amountInputs.forEach(input => {
        input.addEventListener('blur', (e) => {
            const value = parseFloat(e.target.value);
            if (!isNaN(value)) {
                e.target.value = value.toFixed(2);
            }
        });
    });

    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'opacity 0.3s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
});
