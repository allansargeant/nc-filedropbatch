(function () {
    'use strict';

    const form = document.getElementById('fdb-admin-form');
    const saveButton = document.getElementById('fdb-admin-save');
    const syncButton = document.getElementById('fdb-admin-sync-now');
    const statusBox = document.getElementById('fdb-admin-status');
    const messageBox = document.getElementById('fdb-admin-message');

    function showMessage(text) {
        messageBox.textContent = text;
        messageBox.hidden = !text;
    }

    function renderStatus(settings) {
        if (!settings || !settings.lastSyncAt) {
            statusBox.textContent = 'No sync has run yet.';
            return;
        }

        statusBox.replaceChildren();
        statusBox.append(`Last sync: ${settings.lastSyncAt} - `);

        const statusSpan = document.createElement('span');
        statusSpan.className = 'fdb-status-' + (settings.lastSyncStatus === 'success' ? 'success' : 'error');
        statusSpan.textContent = settings.lastSyncStatus;
        statusBox.appendChild(statusSpan);

        if (settings.lastSyncMessage) {
            statusBox.append(` - ${settings.lastSyncMessage}`);
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        showMessage('');
        saveButton.disabled = true;

        try {
            const formData = new FormData(form);
            const response = await fetch(OC.generateUrl('/apps/filedropbatch/admin/settings'), {
                method: 'POST',
                headers: { requesttoken: OC.requestToken },
                body: formData,
            });
            const data = await response.json();
            if (!response.ok) {
                showMessage(data.error || 'Could not save settings');
                return;
            }
            renderStatus(data.settings);
            document.getElementById('fdb-remote-password').value = '';
        } catch (e) {
            showMessage('Network error: ' + e.message);
        } finally {
            saveButton.disabled = false;
        }
    });

    syncButton.addEventListener('click', async () => {
        showMessage('');
        syncButton.disabled = true;
        syncButton.textContent = 'Syncing...';

        try {
            const response = await fetch(OC.generateUrl('/apps/filedropbatch/admin/sync-now'), {
                method: 'POST',
                headers: { requesttoken: OC.requestToken },
            });
            const data = await response.json();
            renderStatus(data.settings);
            if (!response.ok) {
                showMessage(data.error || 'Sync failed');
            }
        } catch (e) {
            showMessage('Network error: ' + e.message);
        } finally {
            syncButton.disabled = false;
            syncButton.textContent = 'Sync now';
        }
    });
})();
