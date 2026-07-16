<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="filedropbatch" class="filedropbatch">
    <h2>File Drop Batch</h2>
    <p class="fdb-intro">
        Build a show from a CSV (columns <code>Date, Theatre, Start Time, presenter name, presenter email</code>)
        or by entering sessions directly below. A folder and an upload-only file drop link will be created
        for each session, and the link will be emailed to the presenter. Optionally, shared root folders and
        a Nextcloud account per theatre can also be created below.
    </p>

    <form id="filedropbatch-form" enctype="multipart/form-data">
        <div class="fdb-field">
            <label>How do you want to build this show?</label>
            <div class="fdb-mode-switch">
                <label class="fdb-checkbox"><input type="radio" name="fdb_mode" value="csv" id="fdb-mode-csv" checked> Upload CSV</label>
                <label class="fdb-checkbox"><input type="radio" name="fdb_mode" value="manual" id="fdb-mode-manual"> Enter manually</label>
                <label class="fdb-checkbox"><input type="radio" name="fdb_mode" value="sheet" id="fdb-mode-sheet"> Link Google Sheet</label>
            </div>
        </div>

        <div class="fdb-field" id="fdb-csv-mode">
            <label for="fdb-csv-file">CSV file</label>
            <input type="file" id="fdb-csv-file" name="csv_file" accept=".csv,text/csv" required>
        </div>

        <div id="fdb-manual-mode" class="fdb-field" hidden>
            <label>Sessions</label>
            <table id="fdb-manual-rows-table">
                <thead>
                    <tr>
                        <th>Theatre</th>
                        <th>Date</th>
                        <th>Start time</th>
                        <th>Presenter name</th>
                        <th>Presenter email</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <button type="button" id="fdb-manual-add-row">Add row</button>
        </div>

        <div id="fdb-sheet-mode" class="fdb-field" hidden>
            <?php if (!$_['googleConnected']): ?>
                <p class="fdb-hint">
                    Google isn't connected yet - ask an admin to connect a Google account in
                    File Drop Batch's admin settings before a sheet can be linked here.
                </p>
            <?php else: ?>
                <p class="fdb-hint">
                    Rows are matched across syncs by Theatre + Date + Start time. Removing a row from
                    the sheet automatically closes that session's file drop link immediately, with no
                    confirmation and no undo - the same as clicking "Close now" on it.
                </p>

                <form id="fdb-sheet-link-form" class="fdb-add-session-form">
                    <div class="fdb-field">
                        <label for="fdb-sheet-name">Name</label>
                        <input type="text" id="fdb-sheet-name" name="name" placeholder="Autumn tour schedule" required>
                    </div>
                    <div class="fdb-field">
                        <label for="fdb-sheet-url">Google Sheet URL</label>
                        <input type="url" id="fdb-sheet-url" name="sheet_url" placeholder="https://docs.google.com/spreadsheets/d/..." required>
                    </div>
                    <div class="fdb-field">
                        <label for="fdb-sheet-base-folder">Base folder</label>
                        <input type="text" id="fdb-sheet-base-folder" name="base_folder" value="<?php p($_['baseFolder']); ?>">
                    </div>
                    <div class="fdb-field">
                        <label for="fdb-sheet-expiry">Link expiry date</label>
                        <input type="date" id="fdb-sheet-expiry" name="expiry_date"
                               min="<?php p(date('Y-m-d', strtotime('+1 day'))); ?>" required>
                    </div>
                    <fieldset class="fdb-field">
                        <legend>Root folders</legend>
                        <?php foreach ($_['predefinedRootFolders'] as $folderName): ?>
                            <label class="fdb-checkbox">
                                <input type="checkbox" name="root_folders[]" value="<?php p($folderName); ?>" checked>
                                <?php p($folderName); ?>
                            </label>
                        <?php endforeach; ?>
                        <label for="fdb-sheet-custom-folders">Custom folders (comma-separated)</label>
                        <input type="text" id="fdb-sheet-custom-folders" name="custom_folders" placeholder="Costumes, Props">
                    </fieldset>
                    <div class="fdb-field">
                        <label class="fdb-checkbox">
                            <input type="checkbox" name="create_users" value="1" <?php if (!$_['canCreateUsers']) { p('disabled'); } ?>>
                            Create a Nextcloud account per theatre
                        </label>
                    </div>
                    <div class="fdb-field">
                        <label class="fdb-checkbox">
                            <input type="checkbox" name="sync_enabled" value="1" checked>
                            Keep this sheet synced automatically
                        </label>
                    </div>
                    <button type="submit" class="primary">Link sheet</button>
                </form>

                <div id="fdb-sheets-error" class="fdb-error" hidden></div>

                <table id="fdb-sheets-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Base folder</th>
                            <th>Expiry</th>
                            <th>Auto-sync</th>
                            <th>Last sync</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            <?php endif; ?>
        </div>

        <div id="fdb-batch-options">
        <div class="fdb-field">
            <label for="fdb-expiry">Link expiry date</label>
            <input type="date" id="fdb-expiry" name="expiry_date"
                   min="<?php p(date('Y-m-d', strtotime('+1 day'))); ?>" required>
            <p class="fdb-hint">
                Links expire at the start of this date. Nextcloud only supports whole-day expiry
                for share links, so the earliest option is tomorrow.
            </p>
        </div>

        <div class="fdb-field">
            <label for="fdb-base-folder">Base folder</label>
            <input type="text" id="fdb-base-folder" name="base_folder"
                   value="<?php p($_['baseFolder']); ?>" placeholder="File Drops">
        </div>

        <fieldset class="fdb-field">
            <legend>Root folders for this batch</legend>
            <p class="fdb-hint">
                Created once at the top of the base folder and shared with every theatre account below.
            </p>
            <?php foreach ($_['predefinedRootFolders'] as $folderName): ?>
                <label class="fdb-checkbox">
                    <input type="checkbox" name="root_folders[]" value="<?php p($folderName); ?>" checked>
                    <?php p($folderName); ?>
                </label>
            <?php endforeach; ?>
            <label for="fdb-custom-folders">Custom folders (comma-separated)</label>
            <input type="text" id="fdb-custom-folders" name="custom_folders" placeholder="Costumes, Props">
        </fieldset>

        <fieldset class="fdb-field">
            <legend>Theatre accounts</legend>
            <label class="fdb-checkbox">
                <input type="checkbox" name="create_users" value="1" <?php if (!$_['canCreateUsers']) { p('disabled'); } ?>>
                Create a Nextcloud account for each distinct theatre in the CSV
            </label>
            <p class="fdb-hint">
                <?php if ($_['canCreateUsers']): ?>
                    Each account gets a generated password and access to its own theatre folder plus
                    the root folders above (not other theatres). Passwords are shown once and saved
                    to a downloadable CSV - store it securely.
                <?php else: ?>
                    Only admins or subadmins can create theatre accounts, so this is disabled for your account.
                <?php endif; ?>
            </p>
        </fieldset>

        <button type="submit" class="primary" id="fdb-submit">Create show</button>
        </div>
    </form>

    <div id="fdb-error" class="fdb-error" hidden></div>

    <div id="fdb-results" class="fdb-results" hidden>
        <h3>Results</h3>
        <p id="fdb-summary"></p>
        <a id="fdb-download" class="button" download hidden>Download results CSV</a>
        <table id="fdb-table">
            <thead>
                <tr>
                    <th>Row</th>
                    <th>Theatre</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Presenter</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Link</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div id="fdb-users-results" class="fdb-results" hidden>
        <h3>Theatre accounts</h3>
        <p id="fdb-users-summary"></p>
        <p class="fdb-hint">Passwords are shown once and cannot be retrieved again - save the CSV securely.</p>
        <a id="fdb-users-download" class="button" download hidden>Download theatre accounts CSV</a>
        <table id="fdb-users-table">
            <thead>
                <tr>
                    <th>Theatre</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div id="fdb-sessions-section" class="fdb-results">
        <h3>Sessions</h3>
        <p class="fdb-hint">
            Every session created via CSV or added manually. Editing moves the folder to match the new
            details (the link keeps working); Close revokes the upload link immediately; Delete only
            stops tracking a session here - the folder, its files, and its link (if still open) are left alone.
        </p>

        <button type="button" class="primary" id="fdb-add-session-toggle">Add session</button>

        <form id="fdb-add-session-form" class="fdb-add-session-form" hidden>
            <div class="fdb-field">
                <label for="fdb-new-theatre">Theatre</label>
                <input type="text" id="fdb-new-theatre" name="theatre" required>
            </div>
            <div class="fdb-field">
                <label for="fdb-new-date">Date</label>
                <input type="text" id="fdb-new-date" name="date" placeholder="2026-08-01" required>
            </div>
            <div class="fdb-field">
                <label for="fdb-new-start-time">Start time</label>
                <input type="text" id="fdb-new-start-time" name="start_time" placeholder="17:00" required>
            </div>
            <div class="fdb-field">
                <label for="fdb-new-presenter-name">Presenter name</label>
                <input type="text" id="fdb-new-presenter-name" name="presenter_name" required>
            </div>
            <div class="fdb-field">
                <label for="fdb-new-presenter-email">Presenter email</label>
                <input type="email" id="fdb-new-presenter-email" name="presenter_email" required>
            </div>
            <div class="fdb-field">
                <label for="fdb-new-base-folder">Base folder</label>
                <input type="text" id="fdb-new-base-folder" name="base_folder" value="<?php p($_['baseFolder']); ?>">
            </div>
            <div class="fdb-field">
                <label for="fdb-new-expiry">Link expiry date</label>
                <input type="date" id="fdb-new-expiry" name="expiry_date"
                       min="<?php p(date('Y-m-d', strtotime('+1 day'))); ?>" required>
            </div>
            <button type="submit" class="primary">Create session</button>
            <button type="button" id="fdb-add-session-cancel">Cancel</button>
        </form>

        <div id="fdb-sessions-error" class="fdb-error" hidden></div>

        <table id="fdb-sessions-table">
            <thead>
                <tr>
                    <th>Theatre</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Presenter</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
