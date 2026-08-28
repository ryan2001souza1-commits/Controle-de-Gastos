document.addEventListener('DOMContentLoaded', () => {
    const typeSelect = document.getElementById('type');
    const categorySelect = document.getElementById('category_id');

    if (!typeSelect || !categorySelect) {
        return;
    }

    const allOptions = Array.from(categorySelect.options);

    function updateCategories() {
        const selectedType = typeSelect.value;
        categorySelect.innerHTML = '';

        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'Sem categoria';
        categorySelect.appendChild(emptyOption);

        allOptions.forEach(option => {
            if (
                option.value !== '' &&
                option.dataset.type === selectedType
            ) {
                categorySelect.appendChild(option.cloneNode(true));
            }
        });

        categorySelect.value = '';
    }

    typeSelect.addEventListener('change', updateCategories);
    updateCategories();
});
