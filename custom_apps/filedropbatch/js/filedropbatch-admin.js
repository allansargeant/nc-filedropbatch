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

    const googleForm = document.getElementById('fdb-google-form');
    const googleSaveButton = document.getElementById('fdb-google-save');
    const googleMessageBox = document.getElementById('fdb-google-message');
    const googleDisconnectButton = document.getElementById('fdb-google-disconnect');

    function showGoogleMessage(text, isError) {
        googleMessageBox.textContent = text;
        googleMessageBox.hidden = !text;
        googleMessageBox.className = isError ? 'fdb-error' : 'fdb-status-success';
    }

    (function showRedirectFlags() {
        const params = new URLSearchParams(window.location.search);
        if (params.has('google_error')) {
            showGoogleMessage('Google connection failed: ' + params.get('google_error'), true);
        } else if (params.has('google_connected')) {
            showGoogleMessage('Google account connected.', false);
        } else if (params.has('google_disconnected')) {
            showGoogleMessage('Google account disconnected.', false);
        }
    })();

    if (googleForm) {
        googleForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            showGoogleMessage('', false);
            googleSaveButton.disabled = true;

            try {
                const formData = new FormData(googleForm);
                const response = await fetch(OC.generateUrl('/apps/filedropbatch/admin/google-settings'), {
                    method: 'POST',
                    headers: { requesttoken: OC.requestToken },
                    body: formData,
                });
                const data = await response.json();
                if (!response.ok) {
                    showGoogleMessage(data.error || 'Could not save Google settings', true);
                    return;
                }
                showGoogleMessage('Google settings saved.', false);
                document.getElementById('fdb-google-client-secret').value = '';
            } catch (e) {
                showGoogleMessage('Network error: ' + e.message, true);
            } finally {
                googleSaveButton.disabled = false;
            }
        });
    }

    if (googleDisconnectButton) {
        googleDisconnectButton.addEventListener('click', async () => {
            googleDisconnectButton.disabled = true;
            try {
                const response = await fetch(OC.generateUrl('/apps/filedropbatch/google/disconnect'), {
                    method: 'POST',
                    headers: { requesttoken: OC.requestToken },
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    const data = await response.json();
                    showGoogleMessage(data.error || 'Could not disconnect', true);
                    googleDisconnectButton.disabled = false;
                }
            } catch (e) {
                showGoogleMessage('Network error: ' + e.message, true);
                googleDisconnectButton.disabled = false;
            }
        });
    }

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
