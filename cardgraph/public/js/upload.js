/**
 * Card Graph — CSV Upload Component
 */
const Upload = {
    /**
     * Show an upload modal.
     * @param {string} title - Modal title
     * @param {string} endpoint - API endpoint (e.g., '/api/uploads/earnings')
     * @param {string} hint - Helper text for accepted files
     * @param {Function} onSuccess - Callback with upload result
     */
    showModal(title, endpoint, hint, onSuccess) {
        const overlay = document.getElementById('modal-overlay');
        const content = document.getElementById('modal-content');

        content.innerHTML = `
            <div class="modal-header">
                <h2>${title}</h2>
                <button class="modal-close" onclick="Upload.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="upload-area" id="upload-drop-zone">
                    <div class="upload-icon">&#128194;</div>
                    <div class="upload-text">Drag & drop a CSV file here, or click to browse</div>
                    <div class="upload-hint">${hint}</div>
                    <input type="file" id="upload-file-input" accept=".csv" style="display:none">
                </div>
                <div class="upload-progress" id="upload-progress">
                    <div class="progress-bar"><div class="progress-bar-fill" id="upload-progress-fill"></div></div>
                    <div class="text-muted mt-2" id="upload-status">Processing...</div>
                </div>
                <div class="upload-result" id="upload-result"></div>
            </div>
        `;

        overlay.style.display = 'flex';

        const dropZone = document.getElementById('upload-drop-zone');
        const fileInput = document.getElementById('upload-file-input');

        // Click to browse
        dropZone.addEventListener('click', () => fileInput.click());

        // File selected
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                this.handleUpload(fileInput.files[0], endpoint, onSuccess);
            }
        });

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                this.handleUpload(e.dataTransfer.files[0], endpoint, onSuccess);
            }
        });

        // Close on overlay click
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.closeModal();
        });
    },

    async handleUpload(file, endpoint, onSuccess) {
        const progress = document.getElementById('upload-progress');
        const progressFill = document.getElementById('upload-progress-fill');
        const statusText = document.getElementById('upload-status');
        const resultDiv = document.getElementById('upload-result');
        const dropZone = document.getElementById('upload-drop-zone');

        // Validate extension
        if (!file.name.toLowerCase().endsWith('.csv')) {
            resultDiv.className = 'upload-result error';
            resultDiv.textContent = 'Only CSV files are accepted.';
            resultDiv.style.display = 'block';
            return;
        }

        // Show progress
        dropZone.style.display = 'none';
        progress.style.display = 'block';
        progressFill.style.width = '30%';
        statusText.textContent = 'Uploading...';

        const formData = new FormData();
        formData.append('file', file);

        try {
            progressFill.style.width = '60%';
            statusText.textContent = 'Processing CSV...';

            const result = await API.upload(endpoint, formData);

            progressFill.style.width = '100%';
            progress.style.display = 'none';

            resultDiv.className = 'upload-result success';
            resultDiv.innerHTML = `
                <strong>Upload successful!</strong><br>
                File: ${result.filename || file.name}<br>
                Rows inserted: ${result.rows_inserted || 0}<br>
                Rows skipped (duplicates): ${result.rows_skipped || 0}
            `;
            resultDiv.style.display = 'block';

            if (onSuccess) onSuccess(result);
        } catch (err) {
            progress.style.display = 'none';
            dropZone.style.display = 'block';
            resultDiv.className = 'upload-result error';
            resultDiv.textContent = err.message || 'Upload failed';
            resultDiv.style.display = 'block';
        }
    },

    closeModal() {
        document.getElementById('modal-overlay').style.display = 'none';
    }
};
