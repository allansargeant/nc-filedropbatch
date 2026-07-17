<?php

declare(strict_types=1);

// Deliberately minimal - no Nextcloud/OCP bootstrap. Only classes with zero
// constructor dependencies on OCP interfaces are covered by this test suite,
// so a couple of require_once calls are all the autoloading these tests need.

require_once __DIR__ . '/../lib/Service/PathSanitizer.php';
require_once __DIR__ . '/../lib/Service/CsvReader.php';
