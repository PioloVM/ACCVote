<?php
/**
 * Live source resolution.
 *
 * Code references are file + anchor pairs, never stored line numbers. The
 * anchor is located in the current file at request time, so the guide shows
 * the code that actually exists instead of a stale copy.
 *
 * Containment is defence in depth: an allowlist of roots, a deny on dot
 * segments, realpath verification, an extension allowlist, and a size cap.
 */

declare(strict_types=1);

final class GuideSourceError extends RuntimeException
{
    public string $kind;
    public int $status;

    public function __construct(string $kind, string $message, int $status)
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->status = $status;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}

const GUIDE_SOURCE_MAX_BYTES = 524288;
const GUIDE_SOURCE_MAX_WINDOW = 400;
const GUIDE_SOURCE_MAX_CONTEXT = 80;

function guide_lang_for(string $relativePath): string
{
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

    return [
        'php' => 'php',
        'js' => 'js',
        'css' => 'css',
        'sql' => 'sql',
        'md' => 'md',
        'json' => 'json',
        'html' => 'html',
        'htm' => 'html',
        'mjs' => 'js',
    ][$extension] ?? 'text';
}

/**
 * Normalise and vet the requested path before it ever touches the disk.
 */
function guide_source_path(string $file): string
{
    $file = trim($file);

    if ($file === '' || str_contains($file, "\0")) {
        throw new GuideSourceError('invalid_file', 'A file path is required.', 400);
    }

    if ($file[0] === '/' || str_contains($file, '\\') || preg_match('/^[A-Za-z]:/', $file) === 1) {
        throw new GuideSourceError('invalid_file', 'Use a project-relative path with forward slashes.', 400);
    }

    $segments = explode('/', $file);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || $segment[0] === '.') {
            throw new GuideSourceError('denied', 'That path is not available to the tour.', 400);
        }
    }

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($extension, ['php', 'js', 'mjs', 'css', 'sql', 'md', 'json', 'html', 'htm'], true)) {
        throw new GuideSourceError('denied', 'Only text source files can be read.', 400);
    }

    $count = count($segments);
    $first = $segments[0];
    $topLevel = ['admin', 'assets', 'config', 'database', 'includes', 'public', 'student', 'docs', 'tests', 'tools'];
    $guideRoots = ['data', 'assets', 'lib', 'tools'];

    $allowed = false;
    if ($count === 1) {
        $allowed = true;
    } elseif (in_array($first, $topLevel, true)) {
        $allowed = true;
    } elseif ($first === 'guide' && $count >= 2) {
        $allowed = in_array($segments[1], $guideRoots, true) || $file === 'guide/README.md';
    }

    if (!$allowed) {
        throw new GuideSourceError('denied', 'That path is not available to the tour.', 400);
    }

    $root = realpath(guide_root());
    if ($root === false) {
        throw new GuideSourceError('not_found', 'The application root could not be resolved.', 404);
    }

    $real = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
    if ($real === false || !is_file($real)) {
        throw new GuideSourceError('not_found', 'That file does not exist.', 404);
    }

    if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        throw new GuideSourceError('denied', 'That path is outside the application.', 400);
    }

    $size = filesize($real);
    if ($size === false || $size > GUIDE_SOURCE_MAX_BYTES) {
        throw new GuideSourceError('too_large', 'That file is too large for the tour.', 413);
    }

    return $real;
}

/**
 * @param array{file?:mixed,anchor?:mixed,before?:mixed,after?:mixed} $ref
 * @return array{ok:true,file:string,lang:string,start:int,end:int,anchor_line:int,total:int,code:string}
 */
function guide_resolve_snippet(array $ref): array
{
    $file = isset($ref['file']) && is_string($ref['file']) ? $ref['file'] : '';
    $anchor = isset($ref['anchor']) && is_string($ref['anchor']) ? trim($ref['anchor']) : '';

    if ($anchor === '') {
        throw new GuideSourceError('no_anchor', 'This reference has no anchor string.', 400);
    }

    $relative = trim($file);
    $real = guide_source_path($relative);

    $lines = file($real, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new GuideSourceError('not_found', 'That file could not be read.', 404);
    }

    $anchorLine = null;
    foreach ($lines as $index => $line) {
        if (str_contains($line, $anchor)) {
            $anchorLine = $index;
            break;
        }
    }

    if ($anchorLine === null) {
        throw new GuideSourceError(
            'anchor_not_found',
            'The anchor "' . $anchor . '" was not found in ' . $relative . '.',
            404
        );
    }

    $before = isset($ref['before']) && is_scalar($ref['before']) ? (int) $ref['before'] : 6;
    $after = isset($ref['after']) && is_scalar($ref['after']) ? (int) $ref['after'] : 14;
    $before = max(0, min(GUIDE_SOURCE_MAX_CONTEXT, $before));
    $after = max(0, min(GUIDE_SOURCE_MAX_CONTEXT, $after));

    $total = count($lines);
    $startIndex = max(0, $anchorLine - $before);
    $endIndex = min($total, $anchorLine + $after + 1);

    if ($endIndex - $startIndex > GUIDE_SOURCE_MAX_WINDOW) {
        $endIndex = $startIndex + GUIDE_SOURCE_MAX_WINDOW;
    }

    $code = implode("\n", array_slice($lines, $startIndex, $endIndex - $startIndex));

    return [
        'ok' => true,
        'file' => ltrim(str_replace('\\', '/', $relative), '/'),
        'lang' => guide_lang_for($relative),
        'start' => $startIndex + 1,
        'end' => $endIndex,
        'anchor_line' => $anchorLine + 1,
        'total' => $total,
        'code' => $code,
    ];
}
