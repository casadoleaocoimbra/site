<?php
// Prune old files from the server
$files = glob('payments/*'); // Get all files in the payments directory
$now = time();

foreach ($files as $file) {
    if (is_file($file) && $now - filemtime($file) > 30 * 86400) {
        unlink($file); // Delete the file if it's older than 30 days
    }
}
exit;
?>