/**
 * Card Graph — Reusable Filter Component
 * Creates date-range and dropdown filters for any tab.
 */
const Filters = {
    /**
     * Render a filter bar into a container element.
     * @param {HTMLElement} container
     * @param {Array} filterDefs - Array of { type, name, label, options? }
     * @param {Function} onApply - Callback with filter values
     */
    render(container, filterDefs, onApply) {
        container.innerHTML = '';
        container.className = 'filter-bar';

        const values = {};

        filterDefs.forEach(def => {
            const group = document.createElement('div');
            group.className = 'filter-group';

            const label = document.createElement('label');
            label.textContent = def.label;
            group.appendChild(label);

            let input;
            if (def.type === 'date') {
                input = document.createElement('input');
                input.type = 'date';
                input.name = def.name;
                if (def.value) input.value = def.value;
            } else if (def.type === 'select') {
                input = document.createElement('select');
                input.name = def.name;
                const defaultOpt = document.createElement('option');
                defaultOpt.value = '';
                defaultOpt.textContent = 'All';
                input.appendChild(defaultOpt);
                (def.options || []).forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt.value;
                    o.textContent = opt.label;
                    input.appendChild(o);
                });
            } else {
                input = document.createElement('input');
                input.type = 'text';
                input.name = def.name;
                input.placeholder = def.placeholder || '';
            }

            values[def.name] = input;
            group.appendChild(input);
            container.appendChild(group);
        });

        // Apply button
        const btnGroup = document.createElement('div');
        btnGroup.className = 'filter-group';
        btnGroup.innerHTML = '<label>&nbsp;</label>';

        const applyBtn = document.createElement('button');
        applyBtn.className = 'btn btn-primary';
        applyBtn.textContent = 'Apply';
        applyBtn.addEventListener('click', () => {
            const result = {};
            for (const [name, input] of Object.entries(values)) {
                result[name] = input.value;
            }
            onApply(result);
        });
        btnGroup.appendChild(applyBtn);

        // Reset button
        const resetBtn = document.createElement('button');
        resetBtn.className = 'btn btn-secondary';
        resetBtn.textContent = 'Reset';
        resetBtn.style.marginLeft = '4px';
        resetBtn.addEventListener('click', () => {
            for (const input of Object.values(values)) {
                input.value = '';
            }
            onApply({});
        });
        btnGroup.appendChild(resetBtn);

        container.appendChild(btnGroup);

        return values;
    },

    /**
     * Get current filter values from a rendered filter bar.
     */
    getValues(filterInputs) {
        const result = {};
        for (const [name, input] of Object.entries(filterInputs)) {
            if (input.value) result[name] = input.value;
        }
        return result;
    }
};
