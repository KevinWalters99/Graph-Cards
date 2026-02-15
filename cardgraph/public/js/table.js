/**
 * Card Graph — Reusable Sortable/Paginated Table Component
 */
const DataTable = {
    /**
     * Render a data table with sorting and pagination.
     * @param {HTMLElement} container
     * @param {Object} config
     * @param {Array} config.columns - [{ key, label, format?, align?, sortable? }]
     * @param {Array} config.data - Array of row objects
     * @param {number} config.total - Total row count (for pagination)
     * @param {number} config.page - Current page
     * @param {number} config.perPage - Rows per page
     * @param {string} config.sortKey - Current sort column
     * @param {string} config.sortDir - 'asc' or 'desc'
     * @param {Function} config.onSort - Callback(key)
     * @param {Function} config.onPage - Callback(page)
     * @param {Function} config.onRowClick - Optional callback(row)
     */
    render(container, config) {
        const {
            columns, data, total, page, perPage,
            sortKey, sortDir, onSort, onPage, onRowClick
        } = config;

        container.innerHTML = '';
        const wrapper = document.createElement('div');
        wrapper.className = 'table-container';

        // Build table
        const table = document.createElement('table');
        table.className = 'data-table';

        // Header
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        columns.forEach(col => {
            const th = document.createElement('th');
            th.textContent = col.label;
            if (col.align) th.className = `text-${col.align}`;
            if (col.sortable !== false && onSort) {
                if (sortKey === col.key) {
                    th.classList.add(sortDir === 'asc' ? 'sorted-asc' : 'sorted-desc');
                }
                th.addEventListener('click', () => onSort(col.key));
            }
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        // Body
        const tbody = document.createElement('tbody');
        if (data.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = columns.length;
            td.className = 'text-center text-muted';
            td.style.padding = '40px';
            td.textContent = 'No data found';
            tr.appendChild(td);
            tbody.appendChild(tr);
        } else {
            data.forEach(row => {
                const tr = document.createElement('tr');
                if (onRowClick) {
                    tr.className = 'clickable';
                    tr.addEventListener('click', () => onRowClick(row));
                }
                columns.forEach(col => {
                    const td = document.createElement('td');
                    if (col.align) td.className = `text-${col.align}`;
                    if (col.render) {
                        const content = col.render(row);
                        if (typeof content === 'string') {
                            td.innerHTML = content;
                        } else if (content instanceof HTMLElement) {
                            td.appendChild(content);
                        }
                    } else if (col.format) {
                        td.textContent = col.format(row[col.key], row);
                    } else {
                        td.textContent = row[col.key] ?? '';
                    }
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
        }
        table.appendChild(tbody);
        wrapper.appendChild(table);

        // Pagination
        if (total > perPage) {
            const totalPages = Math.ceil(total / perPage);
            const pag = document.createElement('div');
            pag.className = 'pagination';

            const info = document.createElement('span');
            const start = (page - 1) * perPage + 1;
            const end = Math.min(page * perPage, total);
            info.textContent = `Showing ${start}-${end} of ${total}`;
            pag.appendChild(info);

            const controls = document.createElement('div');
            controls.className = 'pagination-controls';

            // Previous
            const prevBtn = document.createElement('button');
            prevBtn.textContent = 'Prev';
            prevBtn.disabled = page <= 1;
            prevBtn.addEventListener('click', () => onPage(page - 1));
            controls.appendChild(prevBtn);

            // Page numbers (show max 5)
            const startPage = Math.max(1, page - 2);
            const endPage = Math.min(totalPages, startPage + 4);
            for (let i = startPage; i <= endPage; i++) {
                const btn = document.createElement('button');
                btn.textContent = i;
                if (i === page) btn.className = 'active';
                btn.addEventListener('click', () => onPage(i));
                controls.appendChild(btn);
            }

            // Next
            const nextBtn = document.createElement('button');
            nextBtn.textContent = 'Next';
            nextBtn.disabled = page >= totalPages;
            nextBtn.addEventListener('click', () => onPage(page + 1));
            controls.appendChild(nextBtn);

            pag.appendChild(controls);
            wrapper.appendChild(pag);
        }

        container.appendChild(wrapper);
    }
};
