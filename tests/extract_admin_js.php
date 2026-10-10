<?php
/**
 * tests/extract_admin_js.php — JS parse guard helper.
 *
 * Extracts the LARGEST inline <script> block from a given PHP page, strips
 * the inline `<?php ?>` echoes, and writes browser-ready JS to a temp file.
 * The suite then runs `node --check` on it.
 *
 * Why this exists: a syntax error anywhere in the admin page's main inline
 * script kills EVERY admin function (the page renders — it's server HTML —
 * but nothing responds), and regex source checks cannot see it. Found live
 * on 2026-10-09: a botched edit dropped a function body, the whole script
 * died with "Unexpected end of input", and only the browser console showed
 * it. Usage: php tests/extract_admin_js.php <target.php> — prints the temp
 * JS path, or exits 1 with a message when no script block is found.
 */
$target = $argv[1] ?? null;
if (!$target || !is_file($target)) {
    fwrite(STDERR, "usage: php extract_admin_js.php <path-to-php-page>\n");
    exit(1);
}
$src = file_get_contents($target);
if (!preg_match_all('/<script>([\s\S]*?)<\/script>/i', $src, $m) || empty($m[1])) {
    fwrite(STDERR, "no inline <script> blocks found in $target\n");
    exit(1);
}
$biggest = '';
foreach ($m[1] as $block) {
    if (strlen($block) > strlen($biggest)) $biggest = $block;
}
// Replace inline PHP echoes with harmless placeholders — the goal is to
// validate the STRUCTURE of the generated JS, not its data. Handles both
// tag styles: the admin page uses full "php echo" tags, the student
// dashboard uses short "echo" tags (each opens with the respective PHP
// open tag and closes with the PHP close tag).
$js = preg_replace('/<\?php[\s\S]*?\?>/i', '"PHP"', $biggest);
$js = preg_replace('/<\?=[\s\S]*?\?>/i', '"PHP"', $js);
$out = sys_get_temp_dir() . '/opencode_admin_js_check.js';
file_put_contents($out, $js);
echo $out;
