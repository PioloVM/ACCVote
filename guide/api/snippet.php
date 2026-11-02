<?php
/**
 * GET /guide/api/snippet.php?file=login.php&anchor=password_verify&before=4&after=8
 *
 * Resolves a file + anchor reference to real, current source. Line numbers are
 * computed from the file at request time; they are never stored in the tour.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/source.php';

guide_require_local();

$file = isset($_GET['file']) && is_string($_GET['file']) ? $_GET['file'] : '';
$anchor = isset($_GET['anchor']) && is_string($_GET['anchor']) ? $_GET['anchor'] : '';
$before = isset($_GET['before']) && is_scalar($_GET['before']) ? (int) $_GET['before'] : 6;
$after = isset($_GET['after']) && is_scalar($_GET['after']) ? (int) $_GET['after'] : 14;

try {
    $snippet = guide_resolve_snippet([
        'file' => $file,
        'anchor' => $anchor,
        'before' => $before,
        'after' => $after,
    ]);

    guide_json($snippet);
} catch (GuideSourceError $error) {
    guide_json([
        'ok' => false,
        'error' => $error->getKind(),
        'message' => $error->getMessage(),
    ], $error->getStatus());
} catch (Throwable $error) {
    error_log('Guide snippet API failed: ' . $error->getMessage());
    guide_json([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'The source could not be read.',
    ], 500);
}
