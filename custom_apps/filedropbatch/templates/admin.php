<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
use OCP\Util;

Util::addScript('filedropbatch', 'filedropbatch-admin');
Util::addStyle('filedropbatch', 'style');
?>
<div id="filedropbatch-admin" class="filedropbatch section">
    <h2>File Drop Batch - site-server sync</h2>
    <p class="fdb-intro">
        Once a batch's file-drop links pass their expiry date, this can automatically mirror the whole
        base folder (via <code>rclone</code> over WebDAV) to a separate Nextcloud instance - e.g. a server
        taken to the event site with no reliable link back here. Requires <code>rclone</code> installed on
        this server and PHP allowed to run external processes.
    </p>

    <form id="fdb-admin-form">
        <div class="fdb-field">
            <label for="fdb-remote-url">Remote Nextcloud URL</label>
            <input type="url" id="fdb-remote-url" name="remote_url" placeholder="https://site-server.example"
                   value="<?php p($_['remoteUrl']); ?>">
        </div>

        <div class="fdb-field">
            <label for="fdb-remote-user">Remote username</label>
            <input type="text" id="fdb-remote-user" name="remote_user" value="<?php p($_['remoteUser']); ?>">
        </div>

        <div class="fdb-field">
            <label for="fdb-remote-password">Remote app password</label>
            <input type="password" id="fdb-remote-password" name="remote_password"
                   placeholder="<?php p($_['hasPassword'] ? '(unchanged)' : ''); ?>" autocomplete="new-password">
            <p class="fdb-hint">
                Create a dedicated app password on the remote instance (Settings &rsaquo; Security) rather
                than using that account's real login password. Stored encrypted; leave blank to keep the
                current one.
            </p>
        </div>

        <div class="fdb-field">
            <label for="fdb-remote-base-path">Remote base path (optional)</label>
            <input type="text" id="fdb-remote-base-path" name="remote_base_path" placeholder="e.g. Event Mirror"
                   value="<?php p($_['remoteBasePath']); ?>">
            <p class="fdb-hint">The base folder is created under this path on the remote instance (its own Files root if left blank).</p>
        </div>

        <div class="fdb-field">
            <label for="fdb-rclone-binary">rclone binary path</label>
            <input type="text" id="fdb-rclone-binary" name="rclone_binary" placeholder="rclone"
                   value="<?php p($_['rcloneBinary']); ?>">
        </div>

        <div class="fdb-field">
            <label for="fdb-local-base-url">Local WebDAV base URL override (optional)</label>
            <input type="url" id="fdb-local-base-url" name="local_base_url" placeholder="auto-detected if blank"
                   value="<?php p($_['localBaseUrl']); ?>">
            <p class="fdb-hint">Set this if the scheduled background job can't reliably detect this instance's own URL.</p>
        </div>

        <div class="fdb-field">
            <label class="fdb-checkbox">
                <input type="checkbox" name="sync_enabled" value="1" <?php if ($_['syncEnabled']) { p('checked'); } ?>>
                Automatically sync once a batch's links have expired
            </label>
            <p class="fdb-hint">Runs on Nextcloud's own background job schedule (roughly every 15 minutes). The button below always works regardless of this setting.</p>
        </div>

        <button type="submit" class="primary" id="fdb-admin-save">Save settings</button>
        <button type="button" id="fdb-admin-sync-now">Sync now</button>
    </form>

    <div id="fdb-admin-status" class="fdb-hint" style="margin-top: 12px;">
        <?php if ($_['lastSyncAt'] !== ''): ?>
            Last sync: <?php p($_['lastSyncAt']); ?> -
            <span class="fdb-status-<?php p($_['lastSyncStatus'] === 'success' ? 'success' : 'error'); ?>">
                <?php p($_['lastSyncStatus']); ?>
            </span>
            <?php if ($_['lastSyncMessage'] !== ''): ?>
                - <?php p($_['lastSyncMessage']); ?>
            <?php endif; ?>
        <?php else: ?>
            No sync has run yet.
        <?php endif; ?>
    </div>

    <div id="fdb-admin-message" class="fdb-error" hidden></div>
</div>
