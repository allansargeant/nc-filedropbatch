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

    const sessionsTableBody = document.querySelector('#fdb-sessions-table tbody');
    const sessionsErrorBox = document.getElementById('fdb-sessions-error');
    const addSessionToggle = document.getElementById('fdb-add-session-toggle');
    const addSessionForm = document.getElementById('fdb-add-session-form');
    const addSessionCancel = document.getElementById('fdb-add-session-cancel');

    const modeCsvRadio = document.getElementById('fdb-mode-csv');
    const modeManualRadio = document.getElementById('fdb-mode-manual');
    const modeSheetRadio = document.getElementById('fdb-mode-sheet');
    const csvModeBox = document.getElementById('fdb-csv-mode');
    const manualModeBox = document.getElementById('fdb-manual-mode');
    const sheetModeBox = document.getElementById('fdb-sheet-mode');
    const batchOptionsBox = document.getElementById('fdb-batch-options');
    const csvFileInput = document.getElementById('fdb-csv-file');
    const manualRowsTableBody = document.querySelector('#fdb-manual-rows-table tbody');
    const manualAddRowButton = document.getElementById('fdb-manual-add-row');

    const sheetLinkForm = document.getElementById('fdb-sheet-link-form');
    const sheetsErrorBox = document.getElementById('fdb-sheets-error');
    const sheetsTableBody = document.querySelector('#fdb-sheets-table tbody');

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

    // --- Mode switcher (Upload CSV / Enter manually) --------------------------

    function buildManualRow() {
        const tr = document.createElement('tr');
        const fields = ['theatre', 'date', 'startTime', 'presenterName', 'presenterEmail'];
        const placeholders = { theatre: 'Globe', date: '2026-08-01', startTime: '17:00', presenterName: 'A. Smith', presenterEmail: 'a.smith@example.com' };
        fields.forEach((field) => {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = field === 'presenterEmail' ? 'email' : 'text';
            input.dataset.field = field;
            input.placeholder = placeholders[field];
            td.appendChild(input);
            tr.appendChild(td);
        });

        const actionsTd = document.createElement('td');
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = 'Remove';
        removeBtn.addEventListener('click', () => tr.remove());
        actionsTd.appendChild(removeBtn);
        tr.appendChild(actionsTd);

        return tr;
    }

    function collectManualRows() {
        return Array.from(manualRowsTableBody.querySelectorAll('tr')).map((tr) => {
            const row = {};
            tr.querySelectorAll('input[data-field]').forEach((input) => {
                row[input.dataset.field] = input.value.trim();
            });
            return row;
        }).filter((row) => Object.values(row).some((v) => v !== ''));
    }

    function setMode(mode) {
        const isManual = mode === 'manual';
        const isSheet = mode === 'sheet';
        csvModeBox.hidden = isManual || isSheet;
        manualModeBox.hidden = !isManual;
        sheetModeBox.hidden = !isSheet;
        batchOptionsBox.hidden = isSheet;
        csvFileInput.required = !isManual && !isSheet;
        if (isManual && manualRowsTableBody.children.length === 0) {
            manualRowsTableBody.appendChild(buildManualRow());
        }
    }

    modeCsvRadio.addEventListener('change', () => setMode('csv'));
    modeManualRadio.addEventListener('change', () => setMode('manual'));
    if (modeSheetRadio) {
        modeSheetRadio.addEventListener('change', () => setMode('sheet'));
    }
    manualAddRowButton.addEventListener('click', () => {
        manualRowsTableBody.appendChild(buildManualRow());
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        resultsBox.hidden = true;
        usersResultsBox.hidden = true;
        submitButton.disabled = true;

        const isManual = modeManualRadio.checked;

        try {
            let response;
            if (isManual) {
                const rows = collectManualRows();
                if (rows.length === 0) {
                    showError('Add at least one session row');
                    return;
                }
                const formData = new FormData(form);
                formData.set('rows', JSON.stringify(rows));
                response = await fetch(OC.generateUrl('/apps/filedropbatch/batch/manual'), {
                    method: 'POST',
                    headers: { requesttoken: OC.requestToken },
                    body: formData,
                });
            } else {
                const formData = new FormData(form);
                response = await fetch(OC.generateUrl('/apps/filedropbatch/batch'), {
                    method: 'POST',
                    headers: { requesttoken: OC.requestToken },
                    body: formData,
                });
            }

            const data = await response.json();
            if (!response.ok) {
                showError(data.error || 'The request failed');
                return;
            }

            renderResults(data);
            renderUserResults(data);
            loadSessions();
        } catch (e) {
            showError('Network error: ' + e.message);
        } finally {
            submitButton.disabled = false;
        }
    });

    // --- Sessions management -------------------------------------------------

    function showSessionsError(message) {
        sessionsErrorBox.textContent = message;
        sessionsErrorBox.hidden = !message;
    }

    async function apiCall(url, method, body) {
        const options = { method, headers: { requesttoken: OC.requestToken } };
        if (body) {
            options.body = body;
        }
        const response = await fetch(url, options);
        const data = response.status === 204 ? {} : await response.json();
        if (!response.ok) {
            throw new Error(data.error || `Request failed (${response.status})`);
        }
        return data;
    }

    async function loadSessions() {
        try {
            const data = await apiCall(OC.generateUrl('/apps/filedropbatch/sessions'), 'GET');
            renderSessionsTable(data.sessions || []);
        } catch (e) {
            showSessionsError('Could not load sessions: ' + e.message);
        }
    }

    function renderSessionsTable(sessions) {
        sessionsTableBody.replaceChildren();
        sessions.forEach((session) => {
            sessionsTableBody.appendChild(buildDisplayRow(session));
        });
    }

    function buildDisplayRow(session) {
        const tr = document.createElement('tr');
        tr.dataset.id = session.id;

        tr.appendChild(cell(session.theatre));
        tr.appendChild(cell(session.date));
        tr.appendChild(cell(session.startTime));
        tr.appendChild(cell(session.presenterName));
        tr.appendChild(cell(session.presenterEmail));

        const statusTd = cell(session.status);
        statusTd.classList.add('fdb-status-' + session.status);
        tr.appendChild(statusTd);

        tr.appendChild(linkCell(session.shareLink));

        const actionsTd = document.createElement('td');
        actionsTd.classList.add('fdb-actions');

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', () => {
            tr.replaceWith(buildEditRow(session));
        });
        actionsTd.appendChild(editBtn);

        if (session.status === 'open') {
            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.textContent = 'Close now';
            closeBtn.addEventListener('click', async () => {
                if (!confirm(`Close the file drop link for "${session.presenterName}" (${session.theatre})? This revokes it immediately - it can't be reopened.`)) {
                    return;
                }
                try {
                    await apiCall(OC.generateUrl(`/apps/filedropbatch/sessions/${session.id}/close`), 'POST');
                    loadSessions();
                } catch (e) {
                    showSessionsError('Could not close: ' + e.message);
                }
            });
            actionsTd.appendChild(closeBtn);
        }

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.textContent = 'Delete';
        deleteBtn.addEventListener('click', async () => {
            if (!confirm(`Stop tracking "${session.presenterName}" (${session.theatre})? The folder and its files are left untouched.`)) {
                return;
            }
            try {
                await apiCall(OC.generateUrl(`/apps/filedropbatch/sessions/${session.id}`), 'DELETE');
                loadSessions();
            } catch (e) {
                showSessionsError('Could not delete: ' + e.message);
            }
        });
        actionsTd.appendChild(deleteBtn);

        tr.appendChild(actionsTd);
        return tr;
    }

    function editableCell(value) {
        const td = document.createElement('td');
        const input = document.createElement('input');
        input.type = 'text';
        input.value = value;
        td.appendChild(input);
        return { td, input };
    }

    function buildEditRow(session) {
        const tr = document.createElement('tr');
        tr.dataset.id = session.id;

        const theatre = editableCell(session.theatre);
        const date = editableCell(session.date);
        const startTime = editableCell(session.startTime);
        const presenterName = editableCell(session.presenterName);

        tr.appendChild(theatre.td);
        tr.appendChild(date.td);
        tr.appendChild(startTime.td);
        tr.appendChild(presenterName.td);
        tr.appendChild(cell(session.presenterEmail));

        const statusTd = cell(session.status);
        statusTd.classList.add('fdb-status-' + session.status);
        tr.appendChild(statusTd);
        tr.appendChild(linkCell(session.shareLink));

        const actionsTd = document.createElement('td');
        actionsTd.classList.add('fdb-actions');

        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.classList.add('primary');
        saveBtn.textContent = 'Save';
        saveBtn.addEventListener('click', async () => {
            showSessionsError('');
            const body = new URLSearchParams({
                theatre: theatre.input.value,
                date: date.input.value,
                start_time: startTime.input.value,
                presenter_name: presenterName.input.value,
            });
            try {
                await apiCall(OC.generateUrl(`/apps/filedropbatch/sessions/${session.id}`), 'PUT', body);
                loadSessions();
            } catch (e) {
                showSessionsError('Could not save: ' + e.message);
            }
        });
        actionsTd.appendChild(saveBtn);

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.addEventListener('click', () => {
            tr.replaceWith(buildDisplayRow(session));
        });
        actionsTd.appendChild(cancelBtn);

        tr.appendChild(actionsTd);
        return tr;
    }

    addSessionToggle.addEventListener('click', () => {
        addSessionForm.hidden = !addSessionForm.hidden;
    });

    addSessionCancel.addEventListener('click', () => {
        addSessionForm.hidden = true;
        addSessionForm.reset();
    });

    addSessionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        showSessionsError('');

        const formData = new FormData(addSessionForm);
        const body = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
            body.append(key, value);
        }

        try {
            await apiCall(OC.generateUrl('/apps/filedropbatch/sessions'), 'POST', body);
            addSessionForm.hidden = true;
            addSessionForm.reset();
            loadSessions();
        } catch (e) {
            showSessionsError('Could not create session: ' + e.message);
        }
    });

    // --- Linked Google Sheets -------------------------------------------------

    function showSheetsError(message) {
        if (!sheetsErrorBox) {
            return;
        }
        sheetsErrorBox.textContent = message;
        sheetsErrorBox.hidden = !message;
    }

    async function loadSheets() {
        if (!sheetsTableBody) {
            return;
        }
        try {
            const data = await apiCall(OC.generateUrl('/apps/filedropbatch/sheets'), 'GET');
            renderSheetsTable(data.sheets || []);
        } catch (e) {
            showSheetsError('Could not load linked sheets: ' + e.message);
        }
    }

    function renderSheetsTable(sheets) {
        sheetsTableBody.replaceChildren();
        sheets.forEach((sheet) => {
            sheetsTableBody.appendChild(buildSheetDisplayRow(sheet));
        });
    }

    function lastSyncCell(sheet) {
        const td = document.createElement('td');
        if (!sheet.lastSyncedAt) {
            td.textContent = 'Never synced';
            return td;
        }
        const span = document.createElement('span');
        span.className = 'fdb-status-' + (sheet.lastSyncStatus === 'success' ? 'success' : (sheet.lastSyncStatus === 'partial' ? 'partial' : 'error'));
        span.textContent = `${sheet.lastSyncedAt} - ${sheet.lastSyncStatus}`;
        td.appendChild(span);
        if (sheet.lastSyncMessage) {
            td.appendChild(document.createElement('br'));
            td.appendChild(document.createTextNode(sheet.lastSyncMessage));
        }
        return td;
    }

    function buildSheetDisplayRow(sheet) {
        const tr = document.createElement('tr');
        tr.dataset.id = sheet.id;

        tr.appendChild(cell(sheet.name));
        tr.appendChild(cell(sheet.baseFolder));
        tr.appendChild(cell(sheet.expiryDate));
        tr.appendChild(cell(sheet.syncEnabled ? 'On' : 'Off'));
        tr.appendChild(lastSyncCell(sheet));

        const actionsTd = document.createElement('td');
        actionsTd.classList.add('fdb-actions');

        const syncBtn = document.createElement('button');
        syncBtn.type = 'button';
        syncBtn.textContent = 'Sync now';
        syncBtn.addEventListener('click', async () => {
            showSheetsError('');
            syncBtn.disabled = true;
            syncBtn.textContent = 'Syncing...';
            try {
                await apiCall(OC.generateUrl(`/apps/filedropbatch/sheets/${sheet.id}/sync-now`), 'POST');
                loadSheets();
                loadSessions();
            } catch (e) {
                showSheetsError('Sync failed: ' + e.message);
                syncBtn.disabled = false;
                syncBtn.textContent = 'Sync now';
            }
        });
        actionsTd.appendChild(syncBtn);

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', () => {
            tr.replaceWith(buildSheetEditRow(sheet));
        });
        actionsTd.appendChild(editBtn);

        const unlinkBtn = document.createElement('button');
        unlinkBtn.type = 'button';
        unlinkBtn.textContent = 'Unlink';
        unlinkBtn.addEventListener('click', async () => {
            if (!confirm(`Unlink "${sheet.name}"? Sessions it already created are left untouched - this only stops future syncing.`)) {
                return;
            }
            try {
                await apiCall(OC.generateUrl(`/apps/filedropbatch/sheets/${sheet.id}`), 'DELETE');
                loadSheets();
            } catch (e) {
                showSheetsError('Could not unlink: ' + e.message);
            }
        });
        actionsTd.appendChild(unlinkBtn);

        tr.appendChild(actionsTd);
        return tr;
    }

    function buildSheetEditRow(sheet) {
        const tr = document.createElement('tr');
        tr.dataset.id = sheet.id;

        const name = editableCell(sheet.name);
        const baseFolder = editableCell(sheet.baseFolder);
        const expiry = editableCell(sheet.expiryDate);

        tr.appendChild(name.td);
        tr.appendChild(baseFolder.td);
        tr.appendChild(expiry.td);

        const syncTd = document.createElement('td');
        const syncCheckbox = document.createElement('input');
        syncCheckbox.type = 'checkbox';
        syncCheckbox.checked = !!sheet.syncEnabled;
        syncTd.appendChild(syncCheckbox);
        tr.appendChild(syncTd);

        tr.appendChild(cell(''));

        const actionsTd = document.createElement('td');
        actionsTd.classList.add('fdb-actions');

        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.classList.add('primary');
        saveBtn.textContent = 'Save';
        saveBtn.addEventListener('click', async () => {
            showSheetsError('');
            const body = new URLSearchParams({
                name: name.input.value,
                base_folder: baseFolder.input.value,
                expiry_date: expiry.input.value,
                sync_enabled: syncCheckbox.checked ? '1' : '',
            });
            try {
                await apiCall(OC.generateUrl(`/apps/filedropbatch/sheets/${sheet.id}`), 'PUT', body);
                loadSheets();
            } catch (e) {
                showSheetsError('Could not save: ' + e.message);
            }
        });
        actionsTd.appendChild(saveBtn);

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.addEventListener('click', () => {
            tr.replaceWith(buildSheetDisplayRow(sheet));
        });
        actionsTd.appendChild(cancelBtn);

        tr.appendChild(actionsTd);
        return tr;
    }

    if (sheetLinkForm) {
        sheetLinkForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            showSheetsError('');

            const formData = new FormData(sheetLinkForm);
            const body = new URLSearchParams();
            for (const [key, value] of formData.entries()) {
                body.append(key, value);
            }

            try {
                await apiCall(OC.generateUrl('/apps/filedropbatch/sheets'), 'POST', body);
                sheetLinkForm.reset();
                loadSheets();
            } catch (e) {
                showSheetsError('Could not link sheet: ' + e.message);
            }
        });
    }

    loadSessions();
    loadSheets();
})();
