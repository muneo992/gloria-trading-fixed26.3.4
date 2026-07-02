<?php
/**
 * Fallback entry point when DirectoryIndex / mod_rewrite is unavailable.
 */
$indexFile = __DIR__ . '/frontend/index.html';

if (!is_readable($indexFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Site configuration error: frontend/index.html is missing.\n";
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($indexFile);
