/**
 * Card Graph — Classifications Admin
 *
 * Manages transcription classifier patterns (transition phrases, closing
 * indicators, price patterns, etc.) stored in CG_TranscriptionClassifiers.
 */
var ClassificationsAdmin = {
    initialized: false,
    classifiers: [],
    categories: [],
    activeCategory: null,
    editingId: null,

    CATEGORY_META: {
        closing:        { label: 'Closing Phrases',     color: '#c62828', icon: 'Sale confirmed / item sold' },
        opening:        { label: 'Opening/Transitions',  color: '#1565c0', icon: 'Next card introduced' },
        price:          { label: 'Price Patterns',       color: '#2e7d32', icon: 'Dollar amounts spoken' },
        giveaway:       { label: 'Giveaway Indicators',  color: '#6a1b9a', icon: 'Free card / giveaway' },
        false_positive: { label: 'False Positives',      color: '#e65100', icon: 'NOT item boundaries' },
        structural:     { label: 'Structural Patterns',  color: '#37474f', icon: 'Auction mechanics' }
    },

    RELIABILITY_COLORS: {
        very_high: '#1b5e20',
        high:      '#2e7d32',
        medium:    '#f57f17',
        low:       '#c62828'
    },

    init: function() {
        var panel = document.getElementById('tx-panel-classifiers');
        if (!panel) return;

        if (!this.initialized) {
            this.renderSkeleton(panel);
            this.initialized = true;
        }

        this.loadClassifiers();
    },

    renderSkeleton: function(panel) {
        var h = [];
        h.push('<div class="clf-container">');

        // Header
        h.push('<div class="clf-header">');
        h.push('<h3 style="font-size:16px;font-weight:700;color:#1a1a2e;margin:0;">Transcription Classifiers</h3>');
        h.push('<button class="btn btn-success btn-sm" id="clf-add-btn">+ Add Pattern</button>');
        h.push('</div>');

        // Category pills
        h.push('<div class="clf-category-pills" id="clf-category-pills"></div>');

        // Stats bar
        h.push('<div class="clf-stats" id="clf-stats"></div>');

        // Main content area
        h.push('<div id="clf-content" class="clf-content"></div>');

        h.push('</div>');
        panel.innerHTML = h.join('\n');

        var self = this;
        document.getElementById('clf-add-btn').addEventListener('click', function() {
            self.showForm(null);
        });
    },

    // ─── Data Loading ────────────────────────────────────────

    loadClassifiers: function() {
        var self = this;
        API.get('/api/transcription/classifiers').then(function(result) {
            self.classifiers = result.data || [];
            self.categories = Object.keys(result.grouped || {});
            self.renderCategoryPills();
            self.renderStats();
            self.renderContent();
        }).catch(function(err) {
            document.getElementById('clf-content').innerHTML =
                '<div class="alert alert-danger">Failed to load classifiers</div>';
        });
    },

    // ─── Category Pills ─────────────────────────────────────

    renderCategoryPills: function() {
        var el = document.getElementById('clf-category-pills');
        var h = [];
        var self = this;

        // "All" pill
        var allActive = !this.activeCategory ? ' clf-pill-active' : '';
        h.push('<button class="clf-pill' + allActive + '" data-cat="">All (' + this.classifiers.length + ')</button>');

        // Category pills
        var catOrder = ['closing', 'opening', 'price', 'giveaway', 'false_positive', 'structural'];
        catOrder.forEach(function(cat) {
            var meta = self.CATEGORY_META[cat] || { label: cat, color: '#666' };
            var count = self.classifiers.filter(function(c) { return c.category === cat; }).length;
            if (count === 0) return;
            var active = self.activeCategory === cat ? ' clf-pill-active' : '';
            h.push('<button class="clf-pill' + active + '" data-cat="' + cat + '" style="--pill-color:' + meta.color + '">' +
                meta.label + ' (' + count + ')</button>');
        });

        el.innerHTML = h.join('');

        el.querySelectorAll('.clf-pill').forEach(function(btn) {
            btn.addEventListener('click', function() {
                self.activeCategory = btn.dataset.cat || null;
                self.renderCategoryPills();
                self.renderContent();
            });
        });
    },

    // ─── Stats Bar ──────────────────────────────────────────

    renderStats: function() {
        var total = this.classifiers.length;
        var active = this.classifiers.filter(function(c) { return c.is_active == 1; }).length;
        var inactive = total - active;
        var byType = { keyword: 0, regex: 0, phrase: 0 };
        this.classifiers.forEach(function(c) { byType[c.pattern_type] = (byType[c.pattern_type] || 0) + 1; });

        var el = document.getElementById('clf-stats');
        el.innerHTML = '<span class="clf-stat"><strong>' + total + '</strong> total</span>' +
            '<span class="clf-stat" style="color:#2e7d32"><strong>' + active + '</strong> active</span>' +
            (inactive > 0 ? '<span class="clf-stat" style="color:#c62828"><strong>' + inactive + '</strong> inactive</span>' : '') +
            '<span class="clf-stat-sep">|</span>' +
            '<span class="clf-stat">Phrases: ' + byType.phrase + '</span>' +
            '<span class="clf-stat">Regex: ' + byType.regex + '</span>' +
            '<span class="clf-stat">Keywords: ' + byType.keyword + '</span>';
    },

    // ─── Content Rendering ──────────────────────────────────

    renderContent: function() {
        var el = document.getElementById('clf-content');
        var self = this;
        var filtered = this.activeCategory
            ? this.classifiers.filter(function(c) { return c.category === self.activeCategory; })
            : this.classifiers;

        if (filtered.length === 0) {
            el.innerHTML = '<div style="text-align:center;padding:40px;color:#999;">No classifiers found</div>';
            return;
        }

        // Group by category for "All" view
        if (!this.activeCategory) {
            var h = [];
            var catOrder = ['closing', 'opening', 'price', 'giveaway', 'false_positive', 'structural'];
            catOrder.forEach(function(cat) {
                var items = filtered.filter(function(c) { return c.category === cat; });
                if (items.length === 0) return;
                var meta = self.CATEGORY_META[cat] || { label: cat, color: '#666', icon: '' };
                h.push('<div class="clf-category-group">');
                h.push('<div class="clf-category-header" style="border-left-color:' + meta.color + '">');
                h.push('<span class="clf-category-title">' + self.escHtml(meta.label) + '</span>');
                h.push('<span class="clf-category-desc">' + self.escHtml(meta.icon) + '</span>');
                h.push('</div>');
                h.push(self.renderTable(items));
                h.push('</div>');
            });
            el.innerHTML = h.join('');
        } else {
            el.innerHTML = this.renderTable(filtered);
        }

        // Wire up action buttons
        el.querySelectorAll('.clf-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                self.showForm(parseInt(btn.dataset.id));
            });
        });
        el.querySelectorAll('.clf-toggle-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                self.toggleActive(parseInt(btn.dataset.id));
            });
        });
        el.querySelectorAll('.clf-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                self.deleteClassifier(parseInt(btn.dataset.id));
            });
        });
    },

    renderTable: function(items) {
        var self = this;
        var h = [];
        h.push('<table class="clf-table">');
        h.push('<thead><tr>');
        h.push('<th style="width:40px">#</th>');
        h.push('<th>Pattern</th>');
        h.push('<th style="width:70px">Type</th>');
        h.push('<th>Label</th>');
        h.push('<th style="width:80px">Reliability</th>');
        h.push('<th>Description</th>');
        h.push('<th>Examples</th>');
        h.push('<th style="width:100px">Actions</th>');
        h.push('</tr></thead><tbody>');

        items.forEach(function(c) {
            var reliColor = self.RELIABILITY_COLORS[c.reliability] || '#666';
            var inactiveClass = c.is_active == 0 ? ' clf-row-inactive' : '';
            h.push('<tr class="clf-row' + inactiveClass + '">');
            h.push('<td class="clf-cell-order">' + c.sort_order + '</td>');
            h.push('<td class="clf-cell-pattern"><code>' + self.escHtml(c.pattern) + '</code></td>');
            h.push('<td><span class="clf-type-badge clf-type-' + c.pattern_type + '">' + c.pattern_type + '</span></td>');
            h.push('<td class="clf-cell-label">' + self.escHtml(c.label) + '</td>');
            h.push('<td><span class="clf-reliability" style="color:' + reliColor + '">' +
                c.reliability.replace('_', ' ') + '</span></td>');
            h.push('<td class="clf-cell-desc">' + self.escHtml(c.description || '') + '</td>');
            h.push('<td class="clf-cell-examples">' + self.escHtml(c.examples || '') + '</td>');
            h.push('<td class="clf-cell-actions">');
            h.push('<button class="clf-action-btn clf-edit-btn" data-id="' + c.classifier_id + '" title="Edit">&#9998;</button>');
            h.push('<button class="clf-action-btn clf-toggle-btn" data-id="' + c.classifier_id + '" title="' +
                (c.is_active == 1 ? 'Disable' : 'Enable') + '">' + (c.is_active == 1 ? '&#10004;' : '&#10006;') + '</button>');
            h.push('<button class="clf-action-btn clf-delete-btn" data-id="' + c.classifier_id + '" title="Delete">&#128465;</button>');
            h.push('</td>');
            h.push('</tr>');
        });

        h.push('</tbody></table>');
        return h.join('');
    },

    // ─── Add/Edit Form ──────────────────────────────────────

    showForm: function(classifierId) {
        var self = this;
        var existing = null;
        if (classifierId) {
            existing = this.classifiers.find(function(c) { return parseInt(c.classifier_id) === classifierId; });
        }

        var title = existing ? 'Edit Classifier' : 'Add New Classifier';
        var h = [];
        h.push('<div class="clf-modal-overlay" id="clf-modal-overlay">');
        h.push('<div class="clf-modal">');
        h.push('<div class="clf-modal-header">');
        h.push('<h4>' + title + '</h4>');
        h.push('<button class="clf-modal-close" id="clf-modal-close">&times;</button>');
        h.push('</div>');
        h.push('<div class="clf-modal-body">');

        // Category
        h.push('<div class="clf-form-row">');
        h.push('<label>Category</label>');
        h.push('<select id="clf-form-category" class="form-select">');
        var cats = ['closing', 'opening', 'price', 'giveaway', 'false_positive', 'structural'];
        cats.forEach(function(cat) {
            var meta = self.CATEGORY_META[cat] || { label: cat };
            var sel = (existing && existing.category === cat) ? ' selected' : '';
            h.push('<option value="' + cat + '"' + sel + '>' + meta.label + '</option>');
        });
        h.push('</select></div>');

        // Pattern
        h.push('<div class="clf-form-row">');
        h.push('<label>Pattern</label>');
        h.push('<input type="text" id="clf-form-pattern" class="form-control" value="' +
            self.escAttr(existing ? existing.pattern : '') + '" placeholder="e.g. great take">');
        h.push('</div>');

        // Pattern Type
        h.push('<div class="clf-form-row">');
        h.push('<label>Pattern Type</label>');
        h.push('<select id="clf-form-type" class="form-select">');
        ['phrase', 'keyword', 'regex'].forEach(function(t) {
            var sel = (existing && existing.pattern_type === t) ? ' selected' : '';
            h.push('<option value="' + t + '"' + sel + '>' + t + '</option>');
        });
        h.push('</select></div>');

        // Label
        h.push('<div class="clf-form-row">');
        h.push('<label>Label</label>');
        h.push('<input type="text" id="clf-form-label" class="form-control" value="' +
            self.escAttr(existing ? existing.label : '') + '" placeholder="e.g. Great Take">');
        h.push('</div>');

        // Reliability
        h.push('<div class="clf-form-row">');
        h.push('<label>Reliability</label>');
        h.push('<select id="clf-form-reliability" class="form-select">');
        ['very_high', 'high', 'medium', 'low'].forEach(function(r) {
            var sel = (existing && existing.reliability === r) ? ' selected' : '';
            h.push('<option value="' + r + '"' + sel + '>' + r.replace('_', ' ') + '</option>');
        });
        h.push('</select></div>');

        // Description
        h.push('<div class="clf-form-row">');
        h.push('<label>Description</label>');
        h.push('<input type="text" id="clf-form-desc" class="form-control" value="' +
            self.escAttr(existing ? (existing.description || '') : '') + '" placeholder="What this pattern detects">');
        h.push('</div>');

        // Examples
        h.push('<div class="clf-form-row">');
        h.push('<label>Examples</label>');
        h.push('<textarea id="clf-form-examples" class="form-control" rows="2" placeholder="Example phrases from transcripts">' +
            self.escHtml(existing ? (existing.examples || '') : '') + '</textarea>');
        h.push('</div>');

        // Sort Order
        h.push('<div class="clf-form-row">');
        h.push('<label>Sort Order</label>');
        h.push('<input type="number" id="clf-form-sort" class="form-control" style="width:100px;" value="' +
            (existing ? existing.sort_order : '0') + '">');
        h.push('</div>');

        h.push('</div>'); // end body
        h.push('<div class="clf-modal-footer">');
        h.push('<button class="btn btn-secondary btn-sm" id="clf-form-cancel">Cancel</button>');
        h.push('<button class="btn btn-primary btn-sm" id="clf-form-save">' + (existing ? 'Update' : 'Create') + '</button>');
        h.push('</div>');
        h.push('</div></div>');

        // Append to body
        var div = document.createElement('div');
        div.id = 'clf-modal-wrap';
        div.innerHTML = h.join('');
        document.body.appendChild(div);

        this.editingId = classifierId || null;

        // Wire events
        document.getElementById('clf-modal-close').addEventListener('click', function() { self.closeForm(); });
        document.getElementById('clf-form-cancel').addEventListener('click', function() { self.closeForm(); });
        document.getElementById('clf-modal-overlay').addEventListener('click', function(e) {
            if (e.target.id === 'clf-modal-overlay') self.closeForm();
        });
        document.getElementById('clf-form-save').addEventListener('click', function() { self.saveForm(); });
    },

    closeForm: function() {
        var wrap = document.getElementById('clf-modal-wrap');
        if (wrap) wrap.remove();
        this.editingId = null;
    },

    saveForm: function() {
        var data = {
            category:     document.getElementById('clf-form-category').value,
            pattern:      document.getElementById('clf-form-pattern').value.trim(),
            pattern_type: document.getElementById('clf-form-type').value,
            label:        document.getElementById('clf-form-label').value.trim(),
            reliability:  document.getElementById('clf-form-reliability').value,
            description:  document.getElementById('clf-form-desc').value.trim() || null,
            examples:     document.getElementById('clf-form-examples').value.trim() || null,
            sort_order:   parseInt(document.getElementById('clf-form-sort').value) || 0
        };

        if (!data.pattern || !data.label) {
            alert('Pattern and Label are required.');
            return;
        }

        var self = this;
        var saveBtn = document.getElementById('clf-form-save');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        var promise;
        if (this.editingId) {
            promise = API.put('/api/transcription/classifiers/' + this.editingId, data);
        } else {
            promise = API.post('/api/transcription/classifiers', data);
        }

        promise.then(function() {
            self.closeForm();
            self.loadClassifiers();
        }).catch(function(err) {
            alert('Save failed: ' + (err.message || 'Unknown error'));
            saveBtn.disabled = false;
            saveBtn.textContent = self.editingId ? 'Update' : 'Create';
        });
    },

    // ─── Actions ────────────────────────────────────────────

    toggleActive: function(id) {
        var c = this.classifiers.find(function(c) { return parseInt(c.classifier_id) === id; });
        if (!c) return;
        var newVal = c.is_active == 1 ? 0 : 1;
        var self = this;
        API.put('/api/transcription/classifiers/' + id, { is_active: newVal }).then(function() {
            self.loadClassifiers();
        });
    },

    deleteClassifier: function(id) {
        var c = this.classifiers.find(function(c) { return parseInt(c.classifier_id) === id; });
        if (!c) return;
        if (!confirm('Delete classifier "' + c.label + '"?')) return;
        var self = this;
        API.del('/api/transcription/classifiers/' + id).then(function() {
            self.loadClassifiers();
        });
    },

    // ─── Helpers ────────────────────────────────────────────

    escHtml: function(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },

    escAttr: function(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
};
