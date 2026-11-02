<?php
/**
 * Dev-only audit: every file + anchor reference in the tour content must
 * resolve against the current repository, and structural references must line
 * up. Returns 200 with ok=false when an audit fails, so it is safe to script.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/source.php';

guide_require_local();

/**
 * @param mixed $node
 * @param list<array{where:string,file:string,anchor:string}> $refs
 */
function guide_collect_source_refs(mixed $node, string $where, array &$refs): void
{
    if (!is_array($node)) {
        return;
    }

    if (isset($node['file'], $node['anchor']) && is_string($node['file']) && is_string($node['anchor'])) {
        $refs[] = ['where' => $where, 'file' => $node['file'], 'anchor' => $node['anchor']];
        return;
    }

    foreach ($node as $key => $value) {
        guide_collect_source_refs($value, $where . '.' . (string) $key, $refs);
    }
}

$annotationsData = guide_annotations();
$systemData = guide_system();
$annotations = $annotationsData['annotations'] ?? [];
$chapters = $annotationsData['chapters'] ?? [];

$refs = [];
guide_collect_source_refs($annotationsData, 'annotations', $refs);
guide_collect_source_refs($systemData, 'system', $refs);

$unresolved = [];
$checked = 0;

foreach ($refs as $ref) {
    $checked++;
    try {
        guide_resolve_snippet($ref);
    } catch (GuideSourceError $error) {
        $unresolved[] = [
            'where' => $ref['where'],
            'file' => $ref['file'],
            'anchor' => $ref['anchor'],
            'error' => $error->getKind(),
            'message' => $error->getMessage(),
        ];
    } catch (Throwable $error) {
        $unresolved[] = [
            'where' => $ref['where'],
            'file' => $ref['file'],
            'anchor' => $ref['anchor'],
            'error' => 'server_error',
            'message' => $error->getMessage(),
        ];
    }
}

$issues = [];
$seenIds = [];
$stepIds = [];

foreach ($annotations as $key => $annotation) {
    if (!is_array($annotation)) {
        $issues[] = ['where' => 'annotations.' . $key, 'error' => 'invalid_annotation'];
        continue;
    }

    $id = $annotation['id'] ?? null;
    if ($id !== $key) {
        $issues[] = [
            'where' => 'annotations.' . $key,
            'error' => 'id_mismatch',
            'message' => 'The annotation key and id must match.',
        ];
    }
    if (is_string($id)) {
        if (isset($seenIds[$id])) {
            $issues[] = ['where' => 'annotations.' . $key, 'error' => 'duplicate_id', 'message' => $id];
        }
        $seenIds[$id] = true;
    }

    $selector = $annotation['selector'] ?? null;
    if (!is_string($selector) || trim($selector) === '') {
        $issues[] = [
            'where' => 'annotations.' . $key,
            'error' => 'missing_selector',
            'message' => 'Every annotation needs a CSS selector.',
        ];
    }
}

foreach ($chapters as $chapterIndex => $chapter) {
    $steps = is_array($chapter) ? ($chapter['steps'] ?? []) : [];
    if (!is_array($steps)) {
        $issues[] = ['where' => 'chapters.' . $chapterIndex, 'error' => 'invalid_steps'];
        continue;
    }

    foreach ($steps as $stepIndex => $step) {
        $where = 'chapters.' . $chapterIndex . '.steps.' . $stepIndex;
        if (!is_array($step)) {
            $issues[] = ['where' => $where, 'error' => 'invalid_step'];
            continue;
        }

        $stepId = $step['id'] ?? '';
        if (!is_string($stepId) || $stepId === '') {
            $issues[] = ['where' => $where, 'error' => 'missing_step_id'];
        } elseif (isset($stepIds[$stepId])) {
            $issues[] = ['where' => $where, 'error' => 'duplicate_step_id', 'message' => $stepId];
        } else {
            $stepIds[$stepId] = true;
        }

        $annotationId = $step['annotation'] ?? '';
        if (!is_string($annotationId) || $annotationId === '') {
            $issues[] = ['where' => $where, 'error' => 'missing_step_annotation'];
        } elseif (!isset($annotations[$annotationId])) {
            $issues[] = [
                'where' => $where,
                'error' => 'unknown_step_annotation',
                'message' => $annotationId,
            ];
        }
    }
}

guide_json([
    'ok' => $unresolved === [] && $issues === [],
    'unresolved' => $unresolved,
    'issues' => $issues,
    'checked' => $checked,
    'annotations' => count($annotations),
    'chapters' => count($chapters),
]);
