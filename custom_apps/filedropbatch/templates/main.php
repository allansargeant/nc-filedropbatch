<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="filedropbatch" class="filedropbatch">
    <h2>File Drop Batch</h2>
    <p class="fdb-intro">
        Upload a CSV with columns <code>Date, Theatre, Start Time, presenter name, presenter email</code>.
        A folder and an upload-only file drop link will be created for each row, and the link will be
        emailed to the presenter. Optionally, shared root folders and a Nextcloud account per theatre
        can also be created below.
    </p>

    <form id="filedropbatch-form" enctype="multipart/form-data">
        <div class="fdb-field">
            <label for="fdb-csv-file">CSV file</label>
            <input type="file" id="fdb-csv-file" name="csv_file" accept=".csv,text/csv" required>
        </div>

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

        <button type="submit" class="primary" id="fdb-submit">Process CSV</button>
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
