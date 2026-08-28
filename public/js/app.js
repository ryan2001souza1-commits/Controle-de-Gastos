document.addEventListener('DOMContentLoaded', () => {
    const typeSelect = document.getElementById('type');
    const categorySelect = document.getElementById('category_id');

    if (typeSelect && categorySelect) {
        typeSelect.addEventListener('change', (e) => {
            categorySelect.disabled = e.target.value === 'income';
            if (e.target.value === 'income') {
                categorySelect.value = '';
            }
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
