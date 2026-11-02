/*
 * Dependency-free syntax highlighter.
 *
 * Every token is escaped before it is wrapped, so source can never inject
 * markup. The tokenizer is deliberately small: comments, strings, numbers,
 * PHP variables, keywords, CSS properties, SQL keywords, and HTML tags.
 */
(function () {
    'use strict';

    var KEYWORDS = {
        php: toSet([
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
            'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
            'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'exit',
            'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
            'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset',
            'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public',
            'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait',
            'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'true', 'false', 'null', 'self',
            'parent', 'int', 'float', 'string', 'bool', 'void', 'mixed', 'never', 'object', 'iterable'
        ]),
        js: toSet([
            'async', 'await', 'break', 'case', 'catch', 'class', 'const', 'continue', 'debugger',
            'default', 'delete', 'do', 'else', 'export', 'extends', 'finally', 'for', 'function',
            'get', 'if', 'import', 'in', 'instanceof', 'let', 'new', 'of', 'return', 'set', 'static',
            'super', 'switch', 'this', 'throw', 'try', 'typeof', 'var', 'void', 'while', 'with',
            'yield', 'true', 'false', 'null', 'undefined'
        ]),
        sql: toSet([
            'add', 'alter', 'and', 'as', 'asc', 'begin', 'between', 'by', 'case', 'check', 'column',
            'commit', 'constraint', 'create', 'database', 'default', 'delete', 'desc', 'distinct',
            'drop', 'else', 'end', 'engine', 'exists', 'foreign', 'from', 'full', 'group', 'having',
            'if', 'in', 'index', 'inner', 'insert', 'into', 'is', 'join', 'key', 'left', 'like',
            'limit', 'not', 'null', 'offset', 'on', 'or', 'order', 'outer', 'primary', 'references',
            'replace', 'right', 'rollback', 'select', 'set', 'table', 'then', 'transaction', 'union',
            'unique', 'update', 'using', 'values', 'view', 'when', 'where', 'with', 'int', 'bigint',
            'varchar', 'text', 'datetime', 'timestamp', 'decimal', 'tinyint', 'enum', 'boolean'
        ]),
        css: toSet([
            'important', 'media', 'supports', 'keyframes', 'import', 'from', 'to', 'and', 'not',
            'only', 'screen', 'print', 'root'
        ])
    };

    function toSet(values) {
        var set = {};
        values.forEach(function (value) { set[value] = true; });
        return set;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function span(kind, text) {
        return '<span class="tok-' + kind + '">' + text + '</span>';
    }

    function isSpace(ch) {
        return ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r' || ch === '\f';
    }

    function isWordStart(ch) {
        return (ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') || ch === '_';
    }

    function isWord(ch) {
        return isWordStart(ch) || (ch >= '0' && ch <= '9');
    }

    function startsWith(code, index, token) {
        return code.slice(index, index + token.length) === token;
    }

    function normalizeLang(lang) {
        var value = String(lang || '').toLowerCase();
        if (value === 'htm') { return 'html'; }
        return value;
    }

    function highlight(code, lang) {
        var text = String(code);
        var language = normalizeLang(lang);
        var keywords = KEYWORDS[language] || null;
        var out = '';
        var i = 0;
        var length = text.length;

        while (i < length) {
            var ch = text[i];
            var next = text[i + 1] || '';

            if ((language === 'html' || language === 'php') && startsWith(text, i, '<!--')) {
                var commentEnd = text.indexOf('-->', i + 4);
                var htmlEnd = commentEnd === -1 ? length : commentEnd + 3;
                out += span('com', escapeHtml(text.slice(i, htmlEnd)));
                i = htmlEnd;
                continue;
            }

            if (startsWith(text, i, '/*') && (language === 'php' || language === 'js' || language === 'css' || language === 'sql')) {
                var blockEnd = text.indexOf('*/', i + 2);
                var blockStop = blockEnd === -1 ? length : blockEnd + 2;
                out += span('com', escapeHtml(text.slice(i, blockStop)));
                i = blockStop;
                continue;
            }

            var lineComment = (language === 'php' || language === 'js') && startsWith(text, i, '//');
            var hashComment = (language === 'php' || language === 'sql') && ch === '#';
            var dashComment = language === 'sql' && startsWith(text, i, '--');
            if (lineComment || hashComment || dashComment) {
                var lineEnd = text.indexOf('\n', i);
                if (lineEnd === -1) { lineEnd = length; }
                out += span('com', escapeHtml(text.slice(i, lineEnd)));
                i = lineEnd;
                continue;
            }

            if (language === 'php' && startsWith(text, i, '<?')) {
                var openEnd = startsWith(text, i, '<?php') ? i + 5 : (startsWith(text, i, '<?=') ? i + 3 : i + 2);
                out += span('tag', escapeHtml(text.slice(i, openEnd)));
                i = openEnd;
                continue;
            }

            if (language === 'html' && ch === '<') {
                var tagEnd = text.indexOf('>', i + 1);
                var tagStop = tagEnd === -1 ? length : tagEnd + 1;
                out += span('tag', escapeHtml(text.slice(i, tagStop)));
                i = tagStop;
                continue;
            }

            if (ch === '"' || ch === "'" || (ch === '`' && (language === 'js' || language === 'php'))) {
                var quote = ch;
                var j = i + 1;
                while (j < length) {
                    if (text[j] === '\\') { j += 2; continue; }
                    if (text[j] === quote) {
                        if (language === 'sql' && text[j + 1] === quote) { j += 2; continue; }
                        j += 1;
                        break;
                    }
                    j += 1;
                }
                out += span('str', escapeHtml(text.slice(i, Math.min(j, length))));
                i = Math.min(j, length);
                continue;
            }

            if (language === 'php' && ch === '$' && isWordStart(next)) {
                var variableEnd = i + 2;
                while (variableEnd < length && isWord(text[variableEnd])) { variableEnd += 1; }
                out += span('var', escapeHtml(text.slice(i, variableEnd)));
                i = variableEnd;
                continue;
            }

            if ((ch >= '0' && ch <= '9') || (ch === '.' && next >= '0' && next <= '9')) {
                var numberEnd = i + 1;
                while (numberEnd < length && /[0-9a-fA-FxX._]/.test(text[numberEnd])) { numberEnd += 1; }
                out += span('num', escapeHtml(text.slice(i, numberEnd)));
                i = numberEnd;
                continue;
            }

            if (isWordStart(ch)) {
                var wordEnd = i + 1;
                while (wordEnd < length && isWord(text[wordEnd])) { wordEnd += 1; }
                var word = text.slice(i, wordEnd);
                var lookup = language === 'sql' ? word.toLowerCase() : word;
                var after = wordEnd;
                while (after < length && isSpace(text[after])) { after += 1; }

                if (keywords && keywords[lookup]) {
                    out += span('key', escapeHtml(word));
                } else if (language === 'css' && text[after] === ':') {
                    out += span('prop', escapeHtml(word));
                } else if (text[after] === '(') {
                    out += span('fn', escapeHtml(word));
                } else {
                    out += escapeHtml(word);
                }
                i = wordEnd;
                continue;
            }

            out += escapeHtml(ch);
            i += 1;
        }

        return out;
    }

    function renderCode(code, lang, startLine) {
        var start = Number(startLine) || 1;
        var lines = String(code).replace(/\r\n?/g, '\n').split('\n');
        var html = '';
        for (var i = 0; i < lines.length; i++) {
            var content = highlight(lines[i], lang);
            html += '<span class="line"><span class="ln">' + (start + i) + '</span>'
                + (content || ' ')
                + '</span>';
        }
        return html;
    }

    window.GuideHighlight = window.GuideHighlight || {};
    window.GuideHighlight.highlight = highlight;
    window.GuideHighlight.renderCode = renderCode;
    window.GuideHighlight.escape = escapeHtml;
}());
