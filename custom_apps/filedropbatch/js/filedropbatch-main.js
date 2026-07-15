(function () {
    'use strict';

    const form = document.getElementById('filedropbatch-form');
    const submitButton = document.getElementById('fdb-submit');
    const errorBox = document.getElementById('fdb-error');
    const resultsBox = document.getElementById('fdb-results');
    const summaryEl = document.getElementById('fdb-summary');
    const downloadLink = document.getElementById('fdb-download');
    const tableBody = document.querySelector('#fdb-table tbody');
    const usersResultsBox = document.getElementById('fdb-users-results');
    const usersSummaryEl = document.getElementById('fdb-users-summary');
    const usersDownloadLink = document.getElementById('fdb-users-download');
    const usersTableBody = document.querySelector('#fdb-users-table tbody');

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    function clearError() {
        errorBox.hidden = true;
        errorBox.textContent = '';
    }

    function cell(text) {
        const td = document.createElement('td');
        td.textContent = text;
        return td;
    }

    function linkCell(url) {
        const td = document.createElement('td');
        if (url) {
            const a = document.createElement('a');
            a.href = url;
            a.textContent = url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            td.appendChild(a);
        }
        return td;
    }

    function wireCsvDownload(linkEl, base64, filename) {
        if (!base64) {
            return;
        }
        const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
        const blob = new Blob([bytes], { type: 'text/csv' });
        linkEl.href = URL.createObjectURL(blob);
        linkEl.download = filename || 'export.csv';
        linkEl.hidden = false;
    }

    function renderResults(data) {
        const s = data.summary;
        summaryEl.textContent =
            `Total: ${s.total} - Success: ${s.success} - Partial: ${s.partial} - Error: ${s.error}`;

        tableBody.replaceChildren();
        data.rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.appendChild(cell(row.rowNumber));
            tr.appendChild(cell(row.theatre));
            tr.appendChild(cell(row.date));
            tr.appendChild(cell(row.startTime));
            tr.appendChild(cell(row.presenterName));
            tr.appendChild(cell(row.presenterEmail));

            const statusTd = cell(row.status);
            statusTd.classList.add('fdb-status-' + row.status);
            tr.appendChild(statusTd);

            tr.appendChild(linkCell(row.shareLink));
            tr.appendChild(cell(row.message));
            tableBody.appendChild(tr);
        });

        wireCsvDownload(downloadLink, data.csvBase64, data.csvFilename);
        resultsBox.hidden = false;
    }

    function renderUserResults(data) {
        if (!data.users || data.users.length === 0) {
            usersResultsBox.hidden = true;
            return;
        }

        const s = data.userSummary;
        usersSummaryEl.textContent =
            `Total: ${s.total} - Created: ${s.created} - Existing: ${s.existing} - Error: ${s.error}`;

        usersTableBody.replaceChildren();
        data.users.forEach((row) => {
            const tr = document.createElement('tr');
            tr.appendChild(cell(row.theatre));
            tr.appendChild(cell(row.username));
            tr.appendChild(cell(row.password));

            const statusTd = cell(row.status);
            statusTd.classList.add('fdb-status-' + row.status);
            tr.appendChild(statusTd);

            tr.appendChild(cell(row.message));
            usersTableBody.appendChild(tr);
        });

        wireCsvDownload(usersDownloadLink, data.usersCsvBase64, data.usersCsvFilename);
        usersResultsBox.hidden = false;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        resultsBox.hidden = true;
        usersResultsBox.hidden = true;
        submitButton.disabled = true;

        try {
            const formData = new FormData(form);
            const response = await fetch(OC.generateUrl('/apps/filedropbatch/batch'), {
                method: 'POST',
                headers: { requesttoken: OC.requestToken },
                body: formData,
            });

            const data = await response.json();
            if (!response.ok) {
                showError(data.error || 'The request failed');
                return;
            }

            renderResults(data);
            renderUserResults(data);
        } catch (e) {
            showError('Network error: ' + e.message);
        } finally {
            submitButton.disabled = false;
        }
    });
})();
