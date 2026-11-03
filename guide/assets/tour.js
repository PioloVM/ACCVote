/*
 * Civic Code — guided tour engine.
 *
 * The guide frames the real application same-origin. Markers are positioned
 * from live DOM rectangles inside the iframe; clicks in Inspect mode resolve
 * the closest annotated element; code is fetched from the source API by
 * file + anchor and rendered escaped.
 */
(function () {
    'use strict';

    var data = window.GUIDE_DATA || { appRoot: '/voting-app3/', groups: [], chapters: [], annotations: {}, system: {} };
    var app = document.getElementById('tourApp');
    if (!app) { return; }

    var frame = document.getElementById('app-frame');
    var viewport = document.getElementById('frameViewport');
    var markerLayer = document.getElementById('marker-layer');
    var drawer = document.getElementById('drawer');
    var drawerBody = document.getElementById('drawerBody');
    var drawerFile = document.getElementById('drawerFile');
    var drawerCategory = document.getElementById('drawerCategory');
    var drawerTitle = document.getElementById('drawerTitle');
    var drawerSummary = document.getElementById('drawerSummary');
    var drawerStep = document.getElementById('drawerStep');
    var drawerPrev = document.getElementById('drawerPrev');
    var drawerNext = document.getElementById('drawerNext');
    var frameBanner = document.getElementById('frameBanner');
    var fallback = document.getElementById('fallback');
    var fallbackImage = document.getElementById('fallbackImage');
    var fallbackList = document.getElementById('fallbackList');
    var palette = document.getElementById('palette');
    var paletteInput = document.getElementById('paletteInput');
    var paletteResults = document.getElementById('paletteResults');
    var rolePill = document.getElementById('rolePill');
    var stepPosition = document.getElementById('stepPosition');
    var stepTitle = document.getElementById('stepTitle');
    var railProgress = document.getElementById('railProgress');
    var stageTitle = document.getElementById('stageTitle');
    var urlPill = document.getElementById('urlPill');
    var routePill = document.getElementById('routePill');
    var openFrame = document.getElementById('openFrame');
    var prevStep = document.getElementById('prevStep');
    var nextStep = document.getElementById('nextStep');

    var annotations = data.annotations || {};
    var chapters = data.chapters || [];
    var groups = data.groups || [];
    var appRoot = data.appRoot || '/voting-app3/';
    var WELCOME_KEY = 'guide.welcome.dismissed.v1';
    var STATE_KEY = 'guide.state.v1';
    var INSPECT_KEY = 'guide.inspect.v1';
    var DEVICE_KEY = 'guide.device.v1';

    var state = {
        chapterId: null,
        stepIndex: 0,
        activeId: null,
        tab: 'behavior',
        drawerOpen: false,
        inspect: true,
        device: 'desktop',
        frameReady: false,
        boundDoc: null,
        pendingOpen: null,
        pendingPanel: '',
        pendingToken: 0,
        markers: [],
        loadWaiters: [],
        roles: { student: false, admin: false },
        roleToken: 0,
        drawerToken: 0,
        focusReturn: null,
        hover: null,
        synthetic: null,
        systemOpen: false,
        systemTab: 'overview',
        systemTable: '',
        paletteIndex: 0,
        paletteItems: [],
        tourActive: false,
        follow: true,
        navToken: 0
    };

    var snippetCache = {};
    var observer = null;
    var scanTimers = [];
    var rescanTimer = null;
    var repositionRaf = 0;
    var healthTimer = null;
    var toastTimer = null;

    /* ------------------------------------------------------------ base --- */

    function $(id) { return document.getElementById(id); }
    function all(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }
    function sleep(ms) { return new Promise(function (resolve) { window.setTimeout(resolve, ms); }); }
    function storeGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function storeSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
    }
    function storeRemove(key) {
        try { window.localStorage.removeItem(key); } catch (e) { /* private mode */ }
    }
    function toast(message) {
        var el = $('toast');
        if (!el) { return; }
        el.textContent = message;
        el.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () { el.classList.remove('is-visible'); }, 2800);
    }
    function titleCase(value) { return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''; }

    function chapterFor(id) {
        for (var i = 0; i < chapters.length; i++) {
            if (chapters[i].id === id) { return chapters[i]; }
        }
        return null;
    }
    function annotationById(id) { return annotations[id] || null; }
    function currentStep() {
        var chapter = chapterFor(state.chapterId);
        return chapter && chapter.steps ? chapter.steps[state.stepIndex] || null : null;
    }
    function currentStepAnnotation() {
        var step = currentStep();
        return step ? annotationById(step.annotation) : null;
    }

    /* ----------------------------------------------------------- frame --- */

    function frameWin() {
        try { return frame.contentWindow || null; } catch (e) { return null; }
    }
    function frameDoc() {
        try { return frame.contentDocument || null; } catch (e) { return null; }
    }
    function framePath() {
        var win = frameWin();
        if (!win) { return ''; }
        try { return win.location.pathname || ''; } catch (e) { return ''; }
    }
    function frameSearch() {
        var win = frameWin();
        if (!win) { return ''; }
        try { return win.location.search || ''; } catch (e) { return ''; }
    }
    function frameHref() {
        var win = frameWin();
        if (!win) { return ''; }
        try { return win.location.href || ''; } catch (e) { return ''; }
    }
    function pathFromLocation(path) {
        if (!path) { return '/'; }
        if (path.indexOf(appRoot) === 0) {
            var rest = path.slice(appRoot.length);
            return '/' + rest;
        }
        return path;
    }
    function normalizePath(path) {
        if (!path) { return '/'; }
        if (path.indexOf(appRoot) === 0) { return pathFromLocation(path); }
        return path.charAt(0) === '/' ? path : '/' + path;
    }
    function currentRoute() {
        var path = pathFromLocation(framePath());
        var params = new URLSearchParams(frameSearch());
        var panel = '';
        var doc = frameDoc();
        if (doc) {
            try {
                var active = doc.querySelector('.panel.active');
                if (active && active.id) { panel = active.id.replace(/^panel-/, ''); }
            } catch (e) { /* noop */ }
        }
        return {
            path: path,
            page: params.get('page') || '',
            panel: panel,
            preview: params.get('preview') === '1',
            electionId: params.get('election_id') || '',
            search: frameSearch()
        };
    }
    function appUrl(path, params) {
        var clean = path || '/';
        if (clean.indexOf(appRoot) === 0) { clean = '/' + clean.slice(appRoot.length); }
        if (clean.charAt(0) !== '/') { clean = '/' + clean; }
        var url = new URL(appRoot.replace(/\/$/, '') + clean, window.location.origin);
        if (params) {
            Object.keys(params).forEach(function (key) {
                if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                    url.searchParams.set(key, String(params[key]));
                }
            });
        }
        return url;
    }
    function sameFrameUrl(url) {
        var href = frameHref();
        if (!href) { return false; }
        try { return new URL(href).href === url.href; } catch (e) { return false; }
    }

    function annotationMatches(annotation, route) {
        var match = annotation.match || {};
        if (!match.path) { return false; }
        var target = normalizePath(match.path);
        if (target.charAt(target.length - 1) === '*') {
            if (route.path.indexOf(target.slice(0, -1)) !== 0) { return false; }
        } else if (target !== route.path) {
            return false;
        }
        if (match.page && match.page !== route.page) { return false; }
        if (match.panel && match.panel !== route.panel) { return false; }
        if (typeof match.preview === 'boolean' && match.preview !== route.preview) { return false; }
        if (typeof match.hasElection === 'boolean') {
            var hasElection = !!route.electionId;
            if (hasElection !== match.hasElection) { return false; }
        }
        return true;
    }
    function activeAnnotations(route) {
        var current = route || currentRoute();
        return Object.keys(annotations).map(function (key) { return annotations[key]; })
            .filter(function (annotation) { return annotation && annotationMatches(annotation, current); });
    }
    function annotationForElement(target) {
        var doc = frameDoc();
        if (!doc || !target) { return null; }
        var candidates = activeAnnotations();
        var best = null;
        var bestDepth = -1;
        candidates.forEach(function (annotation) {
            var element = null;
            try { element = doc.querySelector(annotation.selector); } catch (e) { element = null; }
            if (!element) { return; }
            if (element === target || (element.contains && element.contains(target))) {
                var depth = 0;
                var node = target;
                while (node && node !== element) { depth += 1; node = node.parentElement; }
                if (depth > bestDepth) { best = annotation; bestDepth = depth; }
            }
        });
        return best;
    }

    /* --------------------------------------------------------- markers --- */

    function renderMarkers() {
        if (!markerLayer) { return; }
        markerLayer.textContent = '';
        state.markers = [];
        var doc = frameDoc();
        if (!doc || !state.frameReady) { return; }
        var list = activeAnnotations();
        var index = 0;

        list.forEach(function (annotation) {
            var element = null;
            try { element = doc.querySelector(annotation.selector); } catch (e) { element = null; }
            if (!element) { return; }
            var rect = element.getBoundingClientRect();
            if (rect.width < 2 || rect.height < 2) { return; }

            index += 1;
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'marker';
            if (annotation.category === 'data') { button.className += ' is-cat-data'; }
            if (annotation.category === 'security') { button.className += ' is-cat-security'; }
            if (annotation.id === state.activeId) { button.className += ' is-active'; }
            button.textContent = String(index);
            button.title = annotation.title;
            button.setAttribute('aria-label', 'Inspect: ' + annotation.title);
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                state.focusReturn = button;
                openAnnotation(annotation.id);
            });

            markerLayer.appendChild(button);
            state.markers.push({ button: button, annotation: annotation });
        });

        positionMarkers();
    }

    function positionMarkers() {
        if (!state.markers.length || !state.frameReady) { return; }
        var doc = frameDoc();
        if (!doc) { return; }
        var iframeRect = frame.getBoundingClientRect();
        var viewportRect = viewport.getBoundingClientRect();
        var baseX = iframeRect.left - viewportRect.left;
        var baseY = iframeRect.top - viewportRect.top;
        var frameWidth = frame.clientWidth || iframeRect.width;
        var frameHeight = frame.clientHeight || iframeRect.height;

        state.markers.forEach(function (entry) {
            var element = null;
            try { element = doc.querySelector(entry.annotation.selector); } catch (e) { element = null; }
            if (!element) { entry.button.hidden = true; return; }
            var rect = element.getBoundingClientRect();
            var visible = rect.width > 1 && rect.height > 1
                && rect.bottom > 0 && rect.right > 0
                && rect.top < frameHeight && rect.left < frameWidth;
            if (!visible) { entry.button.hidden = true; return; }

            entry.button.hidden = false;
            var x = baseX + rect.left + 14;
            var y = baseY + rect.top + 14;
            x = Math.max(12, Math.min(viewport.clientWidth - 12, x));
            y = Math.max(12, Math.min(viewport.clientHeight - 12, y));
            entry.button.style.left = x + 'px';
            entry.button.style.top = y + 'px';
        });
    }

    function schedulePosition() {
        if (repositionRaf) { return; }
        repositionRaf = window.requestAnimationFrame(function () {
            repositionRaf = 0;
            positionMarkers();
        });
    }

    function scheduleRescan() {
        if (rescanTimer) { return; }
        rescanTimer = window.setTimeout(function () {
            rescanTimer = null;
            renderMarkers();
            updateChrome();
        }, 140);
    }

    function scheduleScans() {
        scanTimers.forEach(function (timer) { window.clearTimeout(timer); });
        scanTimers = [0, 300, 1000, 2500].map(function (delay) {
            return window.setTimeout(function () {
                renderMarkers();
                updateChrome();
            }, delay);
        });
    }

    /* ---------------------------------------------------------- drawer --- */

    var CATEGORY_LABELS = { ui: 'Interface', logic: 'Logic', data: 'Data', security: 'Security' };

    function setFileChip(file, line) {
        if (!drawerFile) { return; }
        drawerFile.textContent = file + (line ? ':' + line : ':…');
        drawerFile.dataset.path = file || '';
    }

    function copyText(value) {
        if (!value) { return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () { toast('Copied ' + value); })
                .catch(function () { legacyCopy(value); });
        } else {
            legacyCopy(value);
        }
    }
    function legacyCopy(value) {
        var textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try { document.execCommand('copy'); toast('Copied ' + value); }
        catch (e) { toast('Could not copy.'); }
        document.body.removeChild(textarea);
    }

    function addDrawerSection(title, content) {
        var section = make('div', 'drawer-section');
        if (title) { section.appendChild(make('h3', '', title)); }
        if (typeof content === 'string') {
            section.appendChild(make('p', '', content));
        } else if (content) {
            section.appendChild(content);
        }
        drawerBody.appendChild(section);
        return section;
    }

    function listBlock(items) {
        var list = make('ul', 'drawer-list');
        items.forEach(function (item) {
            var li = document.createElement('li');
            li.textContent = item;
            list.appendChild(li);
        });
        return list;
    }

    function chipsBlock(items) {
        var wrap = make('div', 'chip-row');
        items.forEach(function (item) { wrap.appendChild(make('span', 'chip', item)); });
        return wrap;
    }

    function renderBehaviorTab(annotation) {
        if (annotation.summary) { addDrawerSection('In plain terms', annotation.summary); }
        if (annotation.behavior) { addDrawerSection('What happens', annotation.behavior); }
        if (annotation.under_the_hood) { addDrawerSection('Under the hood', annotation.under_the_hood); }
        if (annotation.connections && annotation.connections.length) {
            addDrawerSection('Connections', listBlock(annotation.connections));
        }
        if (annotation.tables && annotation.tables.length) {
            addDrawerSection('Tables touched', chipsBlock(annotation.tables));
        }
        if (annotation.endpoints && annotation.endpoints.length) {
            addDrawerSection('Endpoints', chipsBlock(annotation.endpoints));
        }
        if (!drawerBody.childNodes.length) {
            drawerBody.appendChild(make('div', 'empty-state', 'No behavior notes yet.'));
        }
    }

    function renderConnectionsTab(annotation) {
        var connections = annotation.connections || [];
        if (connections.length) { addDrawerSection('How it connects', listBlock(connections)); }
        if (annotation.tables && annotation.tables.length) {
            addDrawerSection('Database tables', chipsBlock(annotation.tables));
        }
        if (annotation.endpoints && annotation.endpoints.length) {
            addDrawerSection('Endpoints', chipsBlock(annotation.endpoints));
        }
        var files = (annotation.code || []).map(function (ref) { return ref.file; });
        if (files.length) {
            addDrawerSection('Source files', chipsBlock(files.filter(function (file, index) {
                return files.indexOf(file) === index;
            })));
        }
        if (!drawerBody.childNodes.length) {
            drawerBody.appendChild(make('div', 'empty-state', 'No connections listed for this element.'));
        }
    }

    function renderDefenseTab(annotation) {
        if (annotation.defense) {
            var quote = make('blockquote', 'defense-quote', annotation.defense);
            addDrawerSection('Say it at defense', quote);
        }
        if (annotation.questions && annotation.questions.length) {
            addDrawerSection('If the panel asks', listBlock(annotation.questions));
        }
        if (!drawerBody.childNodes.length) {
            drawerBody.appendChild(make('div', 'empty-state', 'No defense notes yet.'));
        }
    }

    function renderCodeTab(annotation) {
        var refs = annotation.code || [];
        if (!refs.length) {
            drawerBody.appendChild(make('div', 'empty-state', 'No source reference for this element.'));
            return;
        }
        var token = ++state.drawerToken;

        refs.forEach(function (ref, index) {
            var block = make('div', 'code-block');
            var head = make('div', 'code-head');
            var label = make('span', '', ref.label || (ref.file + ' · ' + ref.anchor));
            var actions = make('span', 'code-actions');
            var copy = make('button', '', 'Copy path');
            copy.type = 'button';
            copy.title = 'Copy ' + ref.file;
            copy.addEventListener('click', function () { copyText(ref.file); });
            var expand = make('button', '', 'Expand');
            expand.type = 'button';
            expand.disabled = true;
            actions.appendChild(copy);
            actions.appendChild(expand);
            head.appendChild(label);
            head.appendChild(actions);

            var scroll = make('div', 'code-scroll');
            var loading = make('div', 'code-loading', 'Loading live source…');
            scroll.appendChild(loading);
            var pre = document.createElement('pre');
            var meta = make('div', 'code-meta', ref.file + ' · anchor “' + ref.anchor + '”');

            block.appendChild(head);
            block.appendChild(scroll);
            block.appendChild(meta);
            drawerBody.appendChild(block);

            expand.addEventListener('click', function () {
                if (expand.disabled) { return; }
                expand.disabled = true;
                expand.textContent = 'Expanding…';
                scroll.classList.add('is-expanded');
                loadSnippet(ref, true).then(function (payload) {
                    if (token !== state.drawerToken) { return; }
                    pre.innerHTML = window.GuideHighlight.renderCode(payload.code, payload.lang, payload.start);
                    meta.textContent = payload.file + ' · L' + payload.start + '–' + payload.end
                        + ' · anchor at L' + payload.anchor_line;
                    expand.textContent = 'Expanded';
                }).catch(function (error) {
                    if (token !== state.drawerToken) { return; }
                    expand.textContent = 'Expand';
                    expand.disabled = false;
                    toast(error.message || 'Could not expand that snippet.');
                });
            });

            loadSnippet(ref, false).then(function (payload) {
                if (token !== state.drawerToken) { return; }
                if (loading.parentNode) { loading.parentNode.removeChild(loading); }
                pre.innerHTML = window.GuideHighlight.renderCode(payload.code, payload.lang, payload.start);
                scroll.appendChild(pre);
                meta.textContent = payload.file + ' · L' + payload.start + '–' + payload.end
                    + ' · anchor at L' + payload.anchor_line;
                expand.disabled = false;
                if (index === 0) { setFileChip(payload.file, payload.anchor_line); }
            }).catch(function (error) {
                if (token !== state.drawerToken) { return; }
                if (loading.parentNode) { loading.parentNode.removeChild(loading); }
                var problem = make('div', 'code-error',
                    'Could not load ' + ref.file + ' (anchor “' + ref.anchor + '”): ' + (error.message || 'unknown error'));
                scroll.appendChild(problem);
                meta.textContent = 'The anchor may have moved. Run the validator to audit it.';
            });
        });
    }

    function renderTab() {
        if (!drawerOpenAnnotation()) { return; }
        var annotation = drawerOpenAnnotation();
        drawerBody.textContent = '';
        all('.drawer-tabs button').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-tab') === state.tab);
        });
        if (state.tab === 'code') { renderCodeTab(annotation); }
        else if (state.tab === 'connections') { renderConnectionsTab(annotation); }
        else if (state.tab === 'defense') { renderDefenseTab(annotation); }
        else { renderBehaviorTab(annotation); }
    }

    function drawerOpenAnnotation() {
        if (state.activeId === '__generic__') { return state.synthetic; }
        return annotationById(state.activeId);
    }

    function updateDrawerStep() {
        var chapter = chapterFor(state.chapterId);
        if (!chapter || !chapter.steps || !chapter.steps.length) {
            drawerStep.textContent = 'Not in a tour step';
            drawerPrev.disabled = true;
            drawerNext.disabled = true;
            return;
        }
        drawerStep.textContent = 'Step ' + (state.stepIndex + 1) + ' of ' + chapter.steps.length;
        drawerPrev.disabled = state.stepIndex <= 0;
        drawerNext.disabled = state.stepIndex >= chapter.steps.length - 1;
    }

    function openAnnotation(id) {
        var annotation = id === '__generic__' ? state.synthetic : annotationById(id);
        if (!annotation) { toast('That annotation is not in the tour data.'); return; }
        state.activeId = id;
        state.drawerOpen = true;
        state.tab = 'behavior';

        var category = annotation.category || 'ui';
        drawerCategory.className = 'category-badge cat-' + category;
        drawerCategory.textContent = CATEGORY_LABELS[category] || 'Interface';
        drawerTitle.textContent = annotation.title || 'Untitled element';
        drawerSummary.textContent = annotation.summary || '';
        var firstRef = annotation.code && annotation.code.length ? annotation.code[0] : null;
        setFileChip((firstRef && firstRef.file) || annotation.file || 'source', '');
        drawer.setAttribute('aria-hidden', 'false');
        updateDrawerStep();
        syncChapterForAnnotation(id);
        renderTab();
        renderMarkers();
        updateDrawerStep();

        var close = $('drawerClose');
        if (close) { close.focus({ preventScroll: true }); }
    }

    function closeDrawer(returnFocus) {
        if (!drawer) { return; }
        state.drawerOpen = false;
        state.activeId = null;
        state.drawerToken += 1;
        state.synthetic = null;
        drawer.setAttribute('aria-hidden', 'true');
        renderMarkers();
        if (returnFocus !== false && state.focusReturn && document.contains(state.focusReturn)) {
            try { state.focusReturn.focus({ preventScroll: true }); } catch (e) { /* noop */ }
        }
        state.focusReturn = null;
    }

    function syncChapterForAnnotation(id) {
        if (!state.follow) { return; }
        var i, j, steps;
        // Chapters reuse annotations, so prefer the chapter the tour is
        // already showing: a shared step must not drag the position back to
        // an earlier chapter that happens to contain the same annotation.
        var current = chapterFor(state.chapterId);
        steps = (current && current.steps) || [];
        for (j = 0; j < steps.length; j++) {
            if (steps[j].annotation === id) {
                if (state.stepIndex !== j) {
                    state.stepIndex = j;
                    updateChapterUI();
                    updateStepBar();
                    saveState();
                }
                return;
            }
        }
        for (i = 0; i < chapters.length; i++) {
            steps = chapters[i].steps || [];
            for (j = 0; j < steps.length; j++) {
                if (steps[j].annotation === id) {
                    state.chapterId = chapters[i].id;
                    state.stepIndex = j;
                    updateChapterUI();
                    updateStepBar();
                    saveState();
                    return;
                }
            }
        }
    }

    function loadSnippet(ref, expanded) {
        var before = expanded ? 80 : (typeof ref.before === 'number' ? ref.before : 6);
        var after = expanded ? 80 : (typeof ref.after === 'number' ? ref.after : 14);
        var params = new URLSearchParams({
            file: ref.file,
            anchor: ref.anchor,
            before: String(before),
            after: String(after)
        });
        var key = ref.file + '|' + ref.anchor + '|' + before + '|' + after;
        if (snippetCache[key]) { return snippetCache[key]; }

        var url = new URL('api/snippet.php?' + params.toString(), document.baseURI);
        var promise = fetch(url.href, { credentials: 'same-origin' })
            .then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || ('HTTP ' + response.status));
                    }
                    return payload;
                });
            })
            .catch(function (error) {
                delete snippetCache[key];
                throw error;
            });
        snippetCache[key] = promise;
        return promise;
    }

    /* ---------------------------------------------------------- source --- */

    function sourceFileForPath(path) {
        var clean = normalizePath(path).replace(/^\//, '');
        return clean || 'public/index.php';
    }

    function syntheticAnnotation(target) {
        var tag = target && target.tagName ? target.tagName.toLowerCase() : 'element';
        var descriptor = tag;
        if (target && target.id) { descriptor += '#' + target.id; }
        if (target && typeof target.className === 'string' && target.className.trim()) {
            descriptor += '.' + target.className.trim().split(/\s+/).slice(0, 2).join('.');
        }
        var file = sourceFileForPath(currentRoute().path);
        return {
            id: '__generic__',
            title: 'Unannotated <' + descriptor + '>',
            category: 'ui',
            summary: 'This element has no handwritten annotation yet.',
            behavior: 'It is part of the page you are looking at. The Code tab still shows the template around <body>, so you can point at the real file instead of guessing.',
            under_the_hood: 'Numbered markers and chapter steps are curated explanations. Use those when the panel asks about behavior rather than markup.',
            code: [{ file: file, anchor: '<body', before: 0, after: 26, label: 'Page template' }],
            tables: [],
            endpoints: [],
            connections: ['Open a numbered marker for a curated explanation of this screen.'],
            defense: 'Every screen in this system is rendered by the PHP file shown here. That file is the honest answer even when an element has no separate note.',
            questions: []
        };
    }

    function openSynthetic(target) {
        state.synthetic = syntheticAnnotation(target);
        state.focusReturn = null;
        openAnnotation('__generic__');
    }

    /* ---------------------------------------------------------- system --- */

    var system = data.system || {};
    var systemView = $('systemView');
    var systemBody = $('systemBody');
    var systemTabs = $('systemTabs');
    var SYSTEM_TABS = [
        { id: 'overview', label: 'Overview' },
        { id: 'architecture', label: 'Architecture' },
        { id: 'flows', label: 'Flows' },
        { id: 'database', label: 'Database' },
        { id: 'api', label: 'API' },
        { id: 'security', label: 'Security' },
        { id: 'functions', label: 'Functions' },
        { id: 'defense', label: 'Defense kit' },
        { id: 'quality', label: 'Testing & deployment' },
        { id: 'glossary', label: 'Glossary' }
    ];

    function systemTabAvailable(id) {
        if (id === 'quality') { return !!(system.testing || system.deployment); }
        if (id === 'database') { return !!(system.tables && system.tables.length); }
        if (id === 'api') { return !!(system.endpoints && system.endpoints.length); }
        if (id === 'defense') { return !!system.defense_kit; }
        return !!system[id];
    }

    function systemText(tag, className, text) {
        var node = make(tag, className || '', text);
        return node;
    }

    function systemParagraphs(container, value) {
        if (!value) { return; }
        var parts = Array.isArray(value) ? value : [value];
        parts.forEach(function (text) { container.appendChild(systemText('p', '', text)); });
    }

    function refFor(ref, label) {
        if (!ref || !ref.file || !ref.anchor) { return null; }
        var copy = {};
        Object.keys(ref).forEach(function (key) { copy[key] = ref[key]; });
        copy.label = label || (ref.file + ' · ' + ref.anchor);
        return copy;
    }

    function refsFor(entry) {
        if (entry && entry.refs) { return entry.refs; }
        return entry && entry.ref ? [entry.ref] : [];
    }

    function codeFor(entry, label) {
        return refsFor(entry).map(function (ref) {
            return refFor(ref, label);
        }).filter(function (ref) { return !!ref; });
    }

    function baseSystemAnnotation(entry) {
        return {
            id: '__generic__',
            title: entry.title || 'System entry',
            category: entry.category || 'logic',
            summary: entry.summary || '',
            behavior: entry.behavior || '',
            under_the_hood: entry.under_the_hood || '',
            code: entry.code || [],
            tables: entry.tables || [],
            endpoints: entry.endpoints || [],
            connections: entry.connections || [],
            defense: entry.defense || '',
            questions: entry.questions || []
        };
    }

    function openSystemEntry(entry) {
        state.synthetic = baseSystemAnnotation(entry);
        state.focusReturn = null;
        openAnnotation('__generic__');
    }

    function tableAnnotation(table) {
        var refs = table.ref ? [table.ref] : [];
        return {
            title: 'Table · ' + table.name,
            category: 'data',
            summary: table.purpose,
            behavior: (table.columns || []).length + ' columns. ' + (table.keys || []).join(' · '),
            under_the_hood: (table.relationships || []).join(' '),
            code: refs.map(function (ref) { return refFor(ref, 'CREATE TABLE ' + table.name); }),
            tables: [table.name],
            connections: table.relationships || [],
            defense: table.defense || ''
        };
    }

    function endpointAnnotation(entry) {
        return {
            title: entry.method + ' ' + entry.path,
            category: 'logic',
            summary: entry.purpose,
            behavior: 'Authentication: ' + entry.auth + '. Group: ' + entry.group + '.',
            under_the_hood: 'Open the Code tab for the guard or handler that anchors this endpoint.',
            code: codeFor(entry, entry.path),
            endpoints: [entry.path],
            connections: ['Grouped under ' + entry.group + ' in the system reference.'],
            defense: 'This endpoint is server-side. The browser can call it, but it cannot skip the session, CSRF, or validation checks inside it.'
        };
    }

    function securityAnnotation(entry) {
        return {
            title: entry.title,
            category: 'security',
            summary: entry.summary,
            behavior: (entry.checks || []).join(' '),
            under_the_hood: 'Each check is enforced in PHP before the request is allowed to change data.',
            code: codeFor(entry, entry.title),
            connections: entry.checks || [],
            defense: entry.defense || ''
        };
    }

    function flowAnnotation(flow, step, refs) {
        return {
            title: step.title,
            category: 'logic',
            summary: step.detail,
            behavior: flow.summary,
            under_the_hood: 'Step in the flow: ' + flow.title + '.',
            code: refs.map(function (ref) { return refFor(ref, step.title); }),
            connections: ['Part of ' + flow.title + '.'],
            defense: flow.defense || ''
        };
    }

    function architectureAnnotation(node) {
        var category = node.id === 'database' || node.id === 'images' ? 'data'
            : (node.id === 'router' || node.id === 'apache' ? 'security' : 'logic');
        return {
            title: node.title,
            category: category,
            summary: node.summary,
            behavior: 'Technology: ' + node.tech + '.',
            under_the_hood: 'Layer node in the architecture diagram. Click any connected node to keep following the request.',
            code: codeFor(node, node.title),
            connections: [],
            defense: 'The request path is visible end to end: browser, HTTP edge, PHP handler, shared helpers, database.'
        };
    }

    function focusSystemEntry(focus) {
        if (!focus || !systemBody) { return; }
        var nodes = all('[data-system-entry]', systemBody);
        var match = null;
        nodes.forEach(function (node) {
            if (node.getAttribute('data-system-entry') === String(focus)) { match = node; }
        });
        if (!match) { return; }
        try { match.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { match.scrollIntoView(); }
        match.classList.add('is-focus');
        window.setTimeout(function () { match.classList.remove('is-focus'); }, 1800);
    }

    function renderSystemTabs(active) {
        if (!systemTabs) { return; }
        systemTabs.textContent = '';
        SYSTEM_TABS.forEach(function (tab) {
            if (!systemTabAvailable(tab.id)) { return; }
            var button = make('button', tab.id === active ? 'active' : '', tab.label);
            button.type = 'button';
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', tab.id === active ? 'true' : 'false');
            button.setAttribute('data-system-tab', tab.id);
            systemTabs.appendChild(button);
        });
    }

    function renderSystem(view, focus) {
        if (!systemView || !systemBody) { return; }
        var known = SYSTEM_TABS.some(function (tab) { return tab.id === view && systemTabAvailable(tab.id); });
        var active = known ? view : 'overview';
        state.systemTab = active;
        renderSystemTabs(active);
        systemBody.textContent = '';

        if (active === 'overview') { renderSystemOverview(); }
        else if (active === 'architecture') { renderSystemArchitecture(); }
        else if (active === 'flows') { renderSystemFlows(); }
        else if (active === 'database') { renderSystemDatabase(focus); }
        else if (active === 'api') { renderSystemApi(); }
        else if (active === 'security') { renderSystemSecurity(); }
        else if (active === 'functions') { renderSystemFunctions(); }
        else if (active === 'defense') { renderSystemDefense(); }
        else if (active === 'quality') { renderSystemQuality(); }
        else if (active === 'glossary') { renderSystemGlossary(); }

        systemBody.scrollTop = 0;
        focusSystemEntry(focus);
    }

    function openSystemView(view, focus) {
        if (!systemView) { toast('System reference is unavailable.'); return; }
        closeDrawer(false);
        state.systemOpen = true;
        var stage = $('stage');
        if (stage) { stage.classList.add('is-system'); }
        systemView.hidden = false;
        renderSystem(view || 'overview', focus || '');
    }

    function closeSystemView() {
        if (!systemView) { return; }
        state.systemOpen = false;
        systemView.hidden = true;
        var stage = $('stage');
        if (stage) { stage.classList.remove('is-system'); }
    }

    function renderSystemOverview() {
        var overview = system.overview || {};
        var hero = make('div', 'system-hero');
        hero.appendChild(make('h3', '', overview.title || 'How this system works'));
        systemParagraphs(hero, overview.summary);
        if (overview.pitch) {
            var pitch = make('p', '', overview.pitch);
            pitch.className = 'flow-defense';
            hero.appendChild(pitch);
        }
        if (overview.facts && overview.facts.length) {
            var facts = make('div', 'system-facts');
            overview.facts.forEach(function (fact) {
                var chip = make('span', 'system-fact');
                chip.appendChild(make('strong', '', fact.value));
                chip.appendChild(document.createTextNode(' ' + fact.label));
                facts.appendChild(chip);
            });
            hero.appendChild(facts);
        }
        systemBody.appendChild(hero);

        if (overview.stack && overview.stack.length) {
            systemBody.appendChild(make('div', 'system-section-title', 'Stack'));
            var grid = make('div', 'system-grid');
            overview.stack.forEach(function (item) {
                var card = make('div', 'system-card');
                card.appendChild(make('h3', '', item.name));
                card.appendChild(make('p', '', item.role));
                grid.appendChild(card);
            });
            systemBody.appendChild(grid);
        }

        if (overview.repo && overview.repo.length) {
            systemBody.appendChild(make('div', 'system-section-title', 'Where things live'));
            var repo = make('div', 'repo-list');
            overview.repo.forEach(function (item) {
                var row = make('div', 'repo-row');
                row.appendChild(make('code', '', item.path));
                row.appendChild(make('span', '', item.purpose));
                repo.appendChild(row);
            });
            systemBody.appendChild(repo);
        }

        if (overview.run && overview.run.length) {
            systemBody.appendChild(make('div', 'system-section-title', 'Run it locally'));
            var list = make('ul', 'system-list');
            overview.run.forEach(function (text, index) {
                list.appendChild(make('li', '', (index + 1) + '. ' + text));
            });
            systemBody.appendChild(list);
        }

        if (overview.privacy) {
            systemBody.appendChild(make('div', 'system-section-title', 'Data note'));
            var note = make('div', 'system-hero');
            note.appendChild(make('p', '', overview.privacy));
            systemBody.appendChild(note);
        }
    }

    function renderSystemArchitecture() {
        var architecture = system.architecture || {};
        if (architecture.intro) {
            var intro = make('div', 'system-hero');
            intro.appendChild(make('p', '', architecture.intro));
            systemBody.appendChild(intro);
        }

        var layers = make('div', 'arch-layers');
        (architecture.layers || []).forEach(function (layer) {
            var column = make('div', 'arch-layer');
            column.appendChild(make('div', 'arch-layer-label', layer.title || ''));
            (layer.nodes || []).forEach(function (node) {
                var button = make('button', 'arch-node');
                button.type = 'button';
                button.setAttribute('data-system-entry', 'arch:' + node.id);
                button.appendChild(make('strong', '', node.title));
                button.appendChild(make('span', '', node.tech || ''));
                if (node.summary) { button.appendChild(make('small', '', node.summary)); }
                button.addEventListener('click', function () { openSystemEntry(architectureAnnotation(node)); });
                column.appendChild(button);
            });
            layers.appendChild(column);
        });
        systemBody.appendChild(layers);

        if (architecture.edges && architecture.edges.length) {
            systemBody.appendChild(make('div', 'system-section-title', 'Request paths'));
            var list = make('ul', 'arch-edges');
            architecture.edges.forEach(function (edge) {
                var item = document.createElement('li');
                item.appendChild(make('strong', '', edge.from));
                item.appendChild(make('em', '', '→'));
                item.appendChild(make('strong', '', edge.to));
                item.appendChild(make('span', '', '— ' + edge.label));
                list.appendChild(item);
            });
            systemBody.appendChild(list);
        }
    }

    function renderSystemFlows() {
        (system.flows || []).forEach(function (flow) {
            var card = make('article', 'flow-card');
            card.setAttribute('data-system-entry', 'flow:' + flow.id);
            card.appendChild(make('h3', '', flow.title));
            card.appendChild(make('p', '', flow.summary || ''));
            var list = make('ol', 'flow-steps');
            (flow.steps || []).forEach(function (step) {
                var item = make('li', 'flow-step');
                item.appendChild(make('strong', '', step.title));
                if (step.detail) { item.appendChild(make('p', '', step.detail)); }
                var refs = step.refs || (step.ref ? [step.ref] : []);
                if (refs.length) {
                    var source = make('button', 'source-link', 'Open source · ' + refs[0].file);
                    source.type = 'button';
                    source.addEventListener('click', function () {
                        openSystemEntry(flowAnnotation(flow, step, refs));
                    });
                    item.appendChild(source);
                }
                list.appendChild(item);
            });
            card.appendChild(list);
            if (flow.defense) {
                card.appendChild(make('div', 'flow-defense', flow.defense));
            }
            systemBody.appendChild(card);
        });
    }

    function renderSystemDatabase(focus) {
        var tables = system.tables || [];
        if (!tables.length) {
            systemBody.appendChild(make('div', 'empty-state', 'No tables are catalogued.'));
            return;
        }
        var focusName = String(focus || '').indexOf('table:') === 0 ? String(focus).slice(6) : '';
        var selected = null;
        tables.forEach(function (table) {
            if (!selected && (!focusName || table.name === focusName)) { selected = table; }
        });
        if (!selected) { selected = tables[0]; }
        if (focusName) { state.systemTable = focusName; }
        else if (!state.systemTable) { state.systemTable = selected.name; }
        tables.forEach(function (table) {
            if (table.name === state.systemTable) { selected = table; }
        });

        var layout = make('div', 'db-layout');
        var list = make('div', 'db-list');
        tables.forEach(function (table) {
            var button = make('button', table.name === selected.name ? 'is-active' : '', table.name);
            button.type = 'button';
            button.setAttribute('data-system-entry', 'table:' + table.name);
            button.addEventListener('click', function () {
                state.systemTable = table.name;
                renderSystem('database', 'table:' + table.name);
                openSystemEntry(tableAnnotation(table));
            });
            list.appendChild(button);
        });
        layout.appendChild(list);

        var detail = make('div', 'db-detail');
        detail.appendChild(make('h3', '', selected.name));
        detail.appendChild(make('p', '', selected.purpose || ''));

        var source = make('button', 'db-source', selected.ref ? selected.ref.file + ' · CREATE TABLE ' + selected.name : 'Open source');
        source.type = 'button';
        source.addEventListener('click', function () { openSystemEntry(tableAnnotation(selected)); });
        detail.appendChild(source);

        var wrap = make('div', 'db-table-wrap');
        var table = make('table', 'db-columns');
        var thead = document.createElement('thead');
        var headRow = document.createElement('tr');
        ['Column', 'Type', 'Note'].forEach(function (label) {
            headRow.appendChild(make('th', '', label));
        });
        thead.appendChild(headRow);
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        (selected.columns || []).forEach(function (column) {
            var row = document.createElement('tr');
            row.appendChild(make('td', '', '')).appendChild(make('code', '', column.name));
            row.appendChild(make('td', 'db-type', column.type || ''));
            row.appendChild(make('td', '', column.note || ''));
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        detail.appendChild(wrap);

        var meta = make('div', 'db-meta');
        if (selected.keys && selected.keys.length) {
            var keys = make('div');
            keys.appendChild(make('h4', '', 'Keys and indexes'));
            var keyList = make('ul');
            selected.keys.forEach(function (key) { keyList.appendChild(make('li', '', key)); });
            keys.appendChild(keyList);
            meta.appendChild(keys);
        }
        if (selected.relationships && selected.relationships.length) {
            var relationships = make('div');
            relationships.appendChild(make('h4', '', 'Relationships'));
            var relList = make('ul');
            selected.relationships.forEach(function (rel) { relList.appendChild(make('li', '', rel)); });
            relationships.appendChild(relList);
            meta.appendChild(relationships);
        }
        detail.appendChild(meta);
        if (selected.defense) { detail.appendChild(make('div', 'flow-defense', selected.defense)); }
        layout.appendChild(detail);
        systemBody.appendChild(layout);
    }

    function renderSystemApi() {
        var groups = {};
        (system.endpoints || []).forEach(function (entry) {
            var group = entry.group || 'Endpoints';
            (groups[group] = groups[group] || []).push(entry);
        });
        Object.keys(groups).forEach(function (group) {
            var section = make('section', 'api-group');
            section.appendChild(make('h3', '', group));
            groups[group].forEach(function (entry) {
                var row = make('button', 'api-row');
                row.type = 'button';
                row.setAttribute('data-system-entry', 'endpoint:' + entry.method + ':' + entry.path);
                var method = String(entry.method || 'GET');
                var badge = make('span', 'method-badge' + (method.indexOf('POST') !== -1 ? ' is-post' : ''), method);
                row.appendChild(badge);
                var main = make('span', 'api-main');
                main.appendChild(make('span', 'api-path', entry.path));
                main.appendChild(make('span', 'api-purpose', entry.purpose || ''));
                row.appendChild(main);
                row.appendChild(make('span', 'api-auth', entry.auth || ''));
                row.addEventListener('click', function () { openSystemEntry(endpointAnnotation(entry)); });
                section.appendChild(row);
            });
            systemBody.appendChild(section);
        });
    }

    function renderSystemSecurity() {
        var grid = make('div', 'security-grid');
        (system.security || []).forEach(function (entry) {
            var card = make('button', 'security-card');
            card.type = 'button';
            card.setAttribute('data-system-entry', 'security:' + entry.id);
            card.appendChild(make('h3', '', entry.title));
            card.appendChild(make('p', '', entry.summary || ''));
            if (entry.checks && entry.checks.length) {
                var list = make('ul');
                entry.checks.slice(0, 3).forEach(function (check) { list.appendChild(make('li', '', check)); });
                card.appendChild(list);
            }
            card.addEventListener('click', function () { openSystemEntry(securityAnnotation(entry)); });
            grid.appendChild(card);
        });
        systemBody.appendChild(grid);
    }

    function functionAnnotation(entry) {
        return {
            title: entry.name + '()',
            category: 'logic',
            summary: entry.purpose,
            behavior: entry.behavior || '',
            under_the_hood: 'Plain PHP helper in includes/functions.php — no framework layer between this code and the page that calls it.',
            code: codeFor(entry, entry.name + '()'),
            connections: entry.connections || [],
            defense: entry.defense || ''
        };
    }

    function renderSystemFunctions() {
        var grid = make('div', 'function-grid');
        (system.functions || []).forEach(function (entry) {
            var card = make('button', 'function-card');
            card.type = 'button';
            card.setAttribute('data-system-entry', 'function:' + entry.name);
            card.appendChild(make('h3', '', entry.name + '()'));
            card.appendChild(make('p', '', entry.purpose || ''));
            card.addEventListener('click', function () { openSystemEntry(functionAnnotation(entry)); });
            grid.appendChild(card);
        });
        systemBody.appendChild(grid);
    }

    function qualityRefAnnotation(title, summary, refs) {
        return {
            title: title,
            category: 'logic',
            summary: summary,
            behavior: summary,
            code: refs.map(function (ref) { return refFor(ref, title); }),
            connections: [],
            defense: 'This is a project check, not an application feature. It proves the claim instead of asking the panel to take it on faith.'
        };
    }

    function defenseEntry(entry, refs) {
        return {
            title: entry.q,
            category: 'defense',
            summary: '',
            behavior: (entry.a || []).join(' '),
            under_the_hood: 'This is the code that enforces the answer. Read the window, then explain the guard in your own words.',
            code: (refs || entry.refs || []).map(function (ref) {
                return refFor(ref, ref.file + ' · ' + ref.anchor);
            }),
            connections: [],
            defense: ''
        };
    }

    function renderSystemDefense() {
        var kit = system.defense_kit || {};
        var hero = make('div', 'system-hero');
        hero.appendChild(make('h3', '', 'Defense kit'));
        systemParagraphs(hero, kit.intro);
        systemBody.appendChild(hero);

        var pitch = kit.pitch || {};
        var pitchCard = make('section', 'quality-card defense-card');
        pitchCard.appendChild(make('h3', '', pitch.title || 'The pitch'));
        systemParagraphs(pitchCard, pitch.paragraphs);
        if (pitch.beats && pitch.beats.length) {
            var beats = make('div', 'defense-beats');
            pitch.beats.forEach(function (beat) { beats.appendChild(make('span', 'system-fact', beat)); });
            pitchCard.appendChild(beats);
        }
        systemBody.appendChild(pitchCard);

        var demo = kit.demo || {};
        systemBody.appendChild(make('div', 'system-section-title', demo.title || 'Demo'));
        var demoList = make('ol', 'defense-demo');
        (demo.steps || []).forEach(function (step) {
            var item = make('li');
            item.appendChild(make('strong', '', step.title));
            item.appendChild(make('p', '', step.detail || ''));
            var go = make('button', 'source-link', step.view ? 'Open ' + step.view : 'Open this step');
            go.type = 'button';
            go.addEventListener('click', function () {
                if (step.view) { renderSystem(step.view, step.focus || ''); return; }
                closeSystemView();
                selectChapter(step.chapter, { open: true, navigate: true, step: step.step || 0 });
            });
            item.appendChild(go);
            demoList.appendChild(item);
        });
        systemBody.appendChild(demoList);

        systemBody.appendChild(make('div', 'system-section-title', 'Likely panel questions'));
        var qaWrap = make('div', 'defense-qa');
        (kit.qa || []).forEach(function (entry, index) {
            var details = make('details', 'qa-item');
            var summary = make('summary', 'qa-q');
            summary.appendChild(make('span', 'qa-num', String(index + 1).padStart(2, '0')));
            summary.appendChild(make('span', '', entry.q));
            details.appendChild(summary);
            var answer = make('div', 'qa-a');
            systemParagraphs(answer, entry.a);
            if (entry.refs && entry.refs.length) {
                var sources = make('div', 'qa-sources');
                entry.refs.forEach(function (ref) {
                    var source = make('button', 'source-link', ref.file);
                    source.type = 'button';
                    source.title = ref.file + ' · ' + ref.anchor;
                    source.addEventListener('click', function () {
                        openSystemEntry(defenseEntry(entry, [ref]));
                    });
                    sources.appendChild(source);
                });
                answer.appendChild(sources);
            }
            details.appendChild(answer);
            qaWrap.appendChild(details);
        });
        systemBody.appendChild(qaWrap);

        var columns = make('div', 'quality-grid');
        var limits = make('section', 'quality-card');
        limits.appendChild(make('h3', '', 'Known limitations'));
        var limitList = make('ul', 'system-list');
        (kit.limits || []).forEach(function (item) { limitList.appendChild(make('li', '', item)); });
        limits.appendChild(limitList);
        columns.appendChild(limits);

        var future = make('section', 'quality-card');
        future.appendChild(make('h3', '', 'Future work'));
        var futureList = make('ul', 'system-list');
        (kit.future || []).forEach(function (item) { futureList.appendChild(make('li', '', item)); });
        future.appendChild(futureList);
        columns.appendChild(future);
        systemBody.appendChild(columns);
    }

    function renderSystemQuality() {
        var grid = make('div', 'quality-grid');
        var testing = system.testing || {};
        var testCard = make('section', 'quality-card');
        testCard.appendChild(make('h3', '', 'Testing'));
        testCard.appendChild(make('p', '', testing.intro || ''));
        (testing.commands || []).forEach(function (command) {
            var row = make('div', 'command-row');
            row.appendChild(make('strong', '', command.label));
            row.appendChild(make('code', '', command.command));
            if (command.note) { row.appendChild(make('small', '', command.note)); }
            if (command.ref) {
                var source = make('button', 'source-link', 'Open source · ' + command.ref.file);
                source.type = 'button';
                source.addEventListener('click', function () {
                    openSystemEntry(qualityRefAnnotation(command.label, command.note || command.label, [command.ref]));
                });
                row.appendChild(source);
            }
            testCard.appendChild(row);
        });
        if (testing.checks && testing.checks.length) {
            var checks = make('div', 'system-section-title', 'What the suite proves');
            testCard.appendChild(checks);
            var checkList = make('ul', 'system-list');
            testing.checks.forEach(function (check) { checkList.appendChild(make('li', '', check)); });
            testCard.appendChild(checkList);
        }
        grid.appendChild(testCard);

        var deployment = system.deployment || {};
        var deployCard = make('section', 'quality-card');
        deployCard.appendChild(make('h3', '', 'Deployment'));
        deployCard.appendChild(make('p', '', deployment.intro || ''));
        (deployment.targets || []).forEach(function (target) {
            var card = make('div', 'system-card');
            card.style.marginBottom = '8px';
            card.appendChild(make('h3', '', target.name));
            card.appendChild(make('p', '', target.summary || ''));
            if (target.notes && target.notes.length) {
                var list = make('ul');
                target.notes.forEach(function (note) { list.appendChild(make('li', '', note)); });
                card.appendChild(list);
            }
            if (target.ref) {
                var source = make('button', 'source-link', 'Open source · ' + target.ref.file);
                source.type = 'button';
                source.addEventListener('click', function () {
                    openSystemEntry(qualityRefAnnotation(target.name, target.summary || target.name, [target.ref]));
                });
                card.appendChild(source);
            }
            deployCard.appendChild(card);
        });
        if (deployment.guards && deployment.guards.length) {
            deployCard.appendChild(make('div', 'system-section-title', 'Guard rails'));
            var guardList = make('ul', 'system-list');
            deployment.guards.forEach(function (guard) { guardList.appendChild(make('li', '', guard)); });
            deployCard.appendChild(guardList);
        }
        grid.appendChild(deployCard);
        systemBody.appendChild(grid);

        if (testing.coverage && testing.coverage.length) {
            systemBody.appendChild(make('div', 'system-section-title', 'Coverage map'));
            var coverage = make('div', 'system-grid');
            testing.coverage.forEach(function (item) {
                var card = make('div', 'system-card');
                card.appendChild(make('h3', '', item.name));
                card.appendChild(make('p', '', item.detail));
                coverage.appendChild(card);
            });
            systemBody.appendChild(coverage);
        }

        if (deployment.steps && deployment.steps.length) {
            systemBody.appendChild(make('div', 'system-section-title', 'Deployment checklist'));
            var steps = make('ul', 'system-list');
            deployment.steps.forEach(function (step, index) {
                steps.appendChild(make('li', '', (index + 1) + '. ' + step));
            });
            systemBody.appendChild(steps);
        }
    }

    function renderSystemGlossary() {
        var list = make('dl', 'glossary-list');
        (system.glossary || []).forEach(function (entry) {
            var item = make('div', 'glossary-item');
            item.setAttribute('data-system-entry', 'glossary:' + entry.term);
            item.appendChild(make('dt', '', entry.term));
            item.appendChild(make('dd', '', entry.definition));
            list.appendChild(item);
        });
        systemBody.appendChild(list);
    }

    function setupSystem() {
        var launcher = $('systemBtn');
        if (launcher) {
            launcher.addEventListener('click', function () { openSystemView('overview'); });
        }
        var close = $('systemClose');
        if (close) { close.addEventListener('click', closeSystemView); }
        var print = $('systemPrint');
        if (print) { print.addEventListener('click', function () { window.print(); }); }
        var printDate = $('printDate');
        if (printDate) {
            printDate.textContent = new Date().toLocaleDateString(undefined, {
                year: 'numeric', month: 'long', day: 'numeric'
            }) + ' · printed from the local guide';
        }
        window.addEventListener('beforeprint', function () {
            if (!systemView || systemView.hidden) { return; }
            all('details', systemView).forEach(function (node) {
                if (!node.open) {
                    node.setAttribute('data-print-opened', '1');
                    node.open = true;
                }
            });
        });
        window.addEventListener('afterprint', function () {
            all('details[data-print-opened="1"]', systemView || document).forEach(function (node) {
                node.open = false;
                node.removeAttribute('data-print-opened');
            });
        });
        if (systemTabs) {
            systemTabs.addEventListener('click', function (event) {
                var button = event.target.closest ? event.target.closest('button[data-system-tab]') : null;
                if (!button) { return; }
                renderSystem(button.getAttribute('data-system-tab') || 'overview');
            });
        }
    }

    /* ------------------------------------------------------ navigation --- */

    function targetUrl(target) {
        var params = {};
        if (target.page) { params.page = target.page; }
        if (target.panel && !target.page) { params.page = target.panel; }
        if (target.preview) {
            params.page = 'vote';
            params.preview = '1';
            if (target.electionId) { params.election_id = target.electionId; }
        }
        if (target.query && typeof target.query === 'object') {
            Object.keys(target.query).forEach(function (key) { params[key] = target.query[key]; });
        }
        return appUrl(target.path || '/', params);
    }

    function callPanel(panel) {
        if (!panel) { return; }
        var win = frameWin();
        if (!win || typeof win.showPanel !== 'function') { return; }
        try { win.showPanel(panel); } catch (e) { /* page may still be loading */ }
    }

    function armHealth() {
        window.clearTimeout(healthTimer);
        healthTimer = window.setTimeout(function () {
            showFallback('The live app did not finish loading. It may be offline.');
        }, 12000);
    }
    function clearHealth() { window.clearTimeout(healthTimer); healthTimer = null; }

    function resolvePreviewElection() {
        if (state.previewId) { return Promise.resolve(state.previewId); }
        function getJson(url) {
            return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    var type = response.headers.get('content-type') || '';
                    if (type.indexOf('json') === -1) { throw new Error('Not JSON'); }
                    return response.json();
                });
        }
        var listUrl = new URL('../admin/api/elections.php', document.baseURI).href;
        return getJson(listUrl).then(function (list) {
            if (!Array.isArray(list)) { throw new Error('Unexpected election list'); }
            var candidates = list.filter(function (election) { return election.status !== 'archived'; })
                .sort(function (a, b) { return String(b.start_date || '').localeCompare(String(a.start_date || '')); });
            var index = 0;
            function next() {
                if (index >= candidates.length || index > 8) {
                    return candidates.length ? candidates[0].id : 143;
                }
                var election = candidates[index++];
                return getJson(listUrl + '?id=' + encodeURIComponent(election.id)).then(function (detail) {
                    if (detail && Array.isArray(detail.positions) && detail.positions.length) {
                        return election.id;
                    }
                    return next();
                }).catch(function () { return next(); });
            }
            return next();
        }).then(function (id) {
            state.previewId = id;
            return id;
        }).catch(function () {
            state.previewId = 143;
            return 143;
        });
    }

    function navigateToAnnotation(annotation, openId) {
        closeSystemView();
        var token = ++state.navToken;
        var match = annotation.match || {};
        var target = {
            path: match.path || '/',
            page: match.page || '',
            panel: match.panel || '',
            preview: match.preview === true,
            electionId: ''
        };
        var preparing = Promise.resolve();
        if (target.preview) {
            preparing = resolvePreviewElection().then(function (id) { target.electionId = id; });
        }
        return preparing.then(function () {
            var url = targetUrl(target);
            if (sameFrameUrl(url)) {
                state.pendingOpen = null;
                if (target.panel) { callPanel(target.panel); }
                window.setTimeout(function () {
                    // A newer step may have replaced this one while the timer
                    // waited; opening the stale drawer would also drag the
                    // tour position back through syncChapterForAnnotation.
                    if (token !== state.navToken) { return; }
                    renderMarkers();
                    if (openId) { openAnnotation(openId); }
                }, target.panel ? 500 : 60);
                return true;
            }
            state.pendingOpen = openId || null;
            state.pendingPanel = target.panel || '';
            state.pendingToken = token;
            state.frameReady = false;
            armHealth();
            frame.src = url.href;
            return true;
        });
    }

    function waitForFrameLoad(timeout) {
        return new Promise(function (resolve) {
            var done = false;
            function finish() {
                if (done) { return; }
                done = true;
                resolve(true);
            }
            state.loadWaiters.push(finish);
            window.setTimeout(finish, timeout || 12000);
        });
    }
    function resolveLoadWaiters() {
        var waiters = state.loadWaiters;
        state.loadWaiters = [];
        waiters.forEach(function (finish) { finish(); });
    }

    function navigateApp(path) {
        closeSystemView();
        var url = appUrl(path, null);
        if (sameFrameUrl(url)) {
            renderMarkers();
            updateChrome();
            return Promise.resolve(true);
        }
        state.frameReady = false;
        armHealth();
        var wait = waitForFrameLoad(12000);
        frame.src = url.href;
        return wait;
    }

    function navigate(target) {
        if (!target) { return Promise.resolve(false); }
        closeSystemView();
        if (target.annotation) {
            var annotation = annotationById(target.annotation);
            if (!annotation) { return Promise.resolve(false); }
            return navigateToAnnotation(annotation, target.open === false ? null : annotation.id);
        }
        var preparing = Promise.resolve();
        if (target.previewElection) {
            preparing = resolvePreviewElection().then(function (id) {
                target.electionId = target.electionId || id;
            });
        }
        return preparing.then(function () {
            var url = targetUrl(target);
            var token = ++state.navToken;
            state.pendingPanel = target.panel || '';
            state.pendingOpen = null;
            state.pendingToken = token;
            state.frameReady = false;
            armHealth();
            var wait = waitForFrameLoad(12000);
            frame.src = url.href;
            return wait;
        });
    }

    /* --------------------------------------------------------- chrome --- */

    var PAGE_TITLES = {
        '/public/landingpage.php': 'Landing page',
        '/student/login.php': 'Student login',
        '/student/register.php': 'Student registration',
        '/public/results.php': 'Public results',
        '/student/dashboard.php': 'Student dashboard',
        '/student/update-account.php': 'Account update',
        '/forgot-password.php': 'Password recovery',
        '/admin/login.php': 'Admin login',
        '/admin/dashboard.php': 'Admin panel'
    };

    function updateChrome() {
        var route = currentRoute();
        var shown = route.path + (route.search || '');
        if (urlPill) {
            urlPill.textContent = 'localhost' + appRoot.replace(/\/$/, '') + shown;
        }
        if (routePill) { routePill.textContent = shown; }
        if (openFrame) {
            openFrame.href = appUrl(route.path, null).href + (route.search || '');
        }
        if (stageTitle) {
            var title = PAGE_TITLES[route.path] || titleCase(route.path.replace(/^\//, '').replace(/\.php$/, '') || 'Landing page');
            if (route.path === '/student/dashboard.php' && route.page) { title += ' · ' + titleCase(route.page); }
            if (route.path === '/admin/dashboard.php' && route.panel) { title += ' · ' + titleCase(route.panel); }
            stageTitle.textContent = title;
        }
        updateRolePill();
    }

    /* ------------------------------------------------------- chapters --- */

    function renderChapters() {
        var list = $('chapterList');
        if (!list) { return; }
        list.textContent = '';
        var byGroup = {};
        chapters.forEach(function (chapter) {
            var key = chapter.group || 'start';
            (byGroup[key] = byGroup[key] || []).push(chapter);
        });

        var number = 0;
        groups.forEach(function (group) {
            var items = byGroup[group.id] || [];
            if (!items.length) { return; }
            var wrap = make('div', 'chapter-group');
            wrap.appendChild(make('div', 'chapter-group-label', group.title || group.id));
            items.forEach(function (chapter) {
                number += 1;
                var button = make('button', 'chapter-btn');
                button.type = 'button';
                button.setAttribute('data-chapter', chapter.id);
                var num = make('span', 'chapter-num', String(number).padStart(2, '0'));
                var name = make('span', 'chapter-name');
                name.appendChild(make('strong', '', chapter.title));
                name.appendChild(make('small', '', chapter.summary || ((chapter.steps || []).length + ' steps')));
                button.appendChild(num);
                button.appendChild(name);
                button.addEventListener('click', function () {
                    selectChapter(chapter.id, { open: true, navigate: true });
                });
                wrap.appendChild(button);
            });
            list.appendChild(wrap);
        });

        if (railProgress) {
            railProgress.textContent = chapters.length ? '0 / ' + chapters.length : '0 / 0';
        }
    }

    function updateChapterUI() {
        all('.chapter-btn').forEach(function (button) {
            var active = button.getAttribute('data-chapter') === state.chapterId;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-current', active ? 'true' : 'false');
        });
        var chapter = chapterFor(state.chapterId);
        if (chapter) {
            var title = $('chapterCurrentTitle');
            if (title) { title.textContent = chapter.title; }
            if (railProgress) {
                railProgress.textContent = (chapters.indexOf(chapter) + 1) + ' / ' + chapters.length;
            }
        }
    }

    function tourSteps() {
        var list = [];
        chapters.forEach(function (chapter) {
            (chapter.steps || []).forEach(function (step, index) {
                list.push({ chapterId: chapter.id, stepIndex: index, annotation: step.annotation });
            });
        });
        return list;
    }

    function tourPosition() {
        var list = tourSteps();
        for (var i = 0; i < list.length; i++) {
            if (list[i].chapterId === state.chapterId && list[i].stepIndex === state.stepIndex) { return i; }
        }
        return 0;
    }

    function updateFollowUI() {
        var button = $('followToggle');
        if (!button) { return; }
        button.classList.toggle('is-active', !!state.follow);
        button.setAttribute('aria-pressed', state.follow ? 'true' : 'false');
        button.textContent = state.follow ? 'Following' : 'Follow';
    }

    function updateHash() {
        if (!state.chapterId) { return; }
        var step = currentStep();
        var hash = '#chapter=' + encodeURIComponent(state.chapterId);
        if (step && step.annotation) { hash += '&step=' + encodeURIComponent(step.annotation); }
        try { window.history.replaceState(null, '', hash); } catch (e) { /* file:// */ }
    }

    function parseHash() {
        var raw = window.location.hash.replace(/^#/, '');
        if (!raw) { return null; }
        var params = new URLSearchParams(raw);
        var chapterId = params.get('chapter') || '';
        var chapter = chapterFor(chapterId);
        if (!chapter) { return null; }
        var token = params.get('step') || '';
        var index = 0;
        (chapter.steps || []).forEach(function (step, i) {
            if (step.annotation === token || step.id === token || String(i) === token) { index = i; }
        });
        return { chapterId: chapterId, stepIndex: index };
    }

    function updateStepBar() {
        var chapter = chapterFor(state.chapterId);
        var steps = chapter ? chapter.steps || [] : [];
        var step = steps[state.stepIndex] || null;
        var total = steps.length;
        var position = state.stepIndex + 1;
        if (state.tourActive) {
            total = tourSteps().length;
            position = tourPosition() + 1;
        }
        if (stepPosition) {
            stepPosition.textContent = total ? position + ' of ' + total : '1 of 1';
        }
        if (stepTitle) {
            stepTitle.textContent = step ? step.title : (chapter ? chapter.summary || chapter.title : 'Choose a chapter');
        }
        if (prevStep) {
            prevStep.disabled = state.tourActive
                ? tourPosition() <= 0
                : (!steps.length || state.stepIndex <= 0);
        }
        if (nextStep) {
            nextStep.disabled = state.tourActive
                ? tourPosition() >= tourSteps().length - 1
                : (!steps.length || state.stepIndex >= steps.length - 1);
        }
        updateDrawerStep();
    }

    function saveState() {
        storeSet(STATE_KEY, JSON.stringify({
            chapterId: state.chapterId,
            stepIndex: state.stepIndex,
            device: state.device,
            tourActive: state.tourActive,
            follow: state.follow
        }));
    }

    function restoreState() {
        var saved = null;
        var raw = storeGet(STATE_KEY);
        if (raw) {
            try { saved = JSON.parse(raw); } catch (e) { saved = null; }
        }
        if (saved) {
            if (typeof saved.follow === 'boolean') { state.follow = saved.follow; }
            state.tourActive = !!saved.tourActive;
        }
        updateFollowUI();

        var linked = parseHash();
        if (linked) {
            state.tourActive = true;
            selectChapter(linked.chapterId, { open: true, navigate: true, step: linked.stepIndex });
            return;
        }
        if (saved && chapterFor(saved.chapterId)) {
            selectChapter(saved.chapterId, {
                open: false,
                navigate: true,
                step: saved.stepIndex
            });
            return;
        }
        var first = $('chapterList') ? $('chapterList').querySelector('.chapter-btn') : null;
        if (first) { first.classList.add('is-active'); }
        updateStepBar();
    }

    function selectChapter(id, options) {
        var chapter = chapterFor(id);
        if (!chapter) { return; }
        var opts = options || {};
        var steps = chapter.steps || [];
        state.chapterId = id;
        state.stepIndex = Math.max(0, Math.min(opts.step || 0, Math.max(0, steps.length - 1)));
        updateChapterUI();
        updateStepBar();
        saveState();
        updateHash();

        if (!steps.length) {
            toast('This chapter has no steps yet.');
            return;
        }
        var annotation = annotationById(steps[state.stepIndex].annotation);
        if (!annotation) {
            toast('That step points to a missing annotation.');
            return;
        }
        if (opts.navigate === false) {
            if (opts.open) { openAnnotation(annotation.id); }
            return;
        }
        navigateToAnnotation(annotation, opts.open ? annotation.id : null);
    }

    function gotoStep(index) {
        var chapter = chapterFor(state.chapterId);
        if (!chapter || !chapter.steps || !chapter.steps.length) { return; }
        var next = Math.max(0, Math.min(index, chapter.steps.length - 1));
        state.stepIndex = next;
        var annotation = annotationById(chapter.steps[next].annotation);
        updateChapterUI();
        updateStepBar();
        saveState();
        updateHash();
        if (!annotation) { toast('That step points to a missing annotation.'); return; }
        navigateToAnnotation(annotation, annotation.id);
    }

    function stepTour(delta) {
        var list = tourSteps();
        if (!list.length) { return; }
        var index = Math.max(0, Math.min(tourPosition() + delta, list.length - 1));
        var target = list[index];
        selectChapter(target.chapterId, { open: true, navigate: true, step: target.stepIndex });
    }

    function startTour(step) {
        state.tourActive = true;
        updateFollowUI();
        updateStepBar();
        saveState();
        var target = step || tourSteps()[0];
        if (!target) { toast('There are no tour steps yet.'); return; }
        selectChapter(target.chapterId, { open: true, navigate: true, step: target.stepIndex });
    }

    function stopTour() {
        state.tourActive = false;
        saveState();
        updateStepBar();
        toast('Tour mode off. Chapters and markers still work.');
    }

    function nextStepAction() {
        if (state.tourActive) { stepTour(1); return; }
        var chapter = chapterFor(state.chapterId);
        if (chapter && chapter.steps && state.stepIndex < chapter.steps.length - 1) {
            gotoStep(state.stepIndex + 1);
        }
    }
    function prevStepAction() {
        if (state.tourActive) { stepTour(-1); return; }
        if (state.stepIndex > 0) { gotoStep(state.stepIndex - 1); }
    }

    function setupTour() {
        updateFollowUI();
        var follow = $('followToggle');
        if (follow) {
            follow.addEventListener('click', function () {
                state.follow = !state.follow;
                saveState();
                updateFollowUI();
                toast(state.follow
                    ? 'Following your clicks. The tour keeps its place in sync.'
                    : 'Follow off. Clicks no longer move the tour position.');
            });
        }
        window.addEventListener('hashchange', function () {
            var linked = parseHash();
            if (!linked) { return; }
            if (linked.chapterId === state.chapterId && linked.stepIndex === state.stepIndex) { return; }
            state.tourActive = true;
            selectChapter(linked.chapterId, { open: true, navigate: true, step: linked.stepIndex });
        });
    }

    /* ------------------------------------------------------------ roles --- */

    function detectRoles() {
        if (!state.frameReady) { return Promise.resolve(); }
        var token = ++state.roleToken;
        var url = new URL('api/roles.php', document.baseURI).href;
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) { return null; }
                return response.json();
            })
            .then(function (payload) {
                if (token !== state.roleToken) { return; }
                state.roles = {
                    student: !!(payload && payload.student),
                    admin: !!(payload && payload.admin)
                };
                updateRolePill();
            })
            .catch(function () {
                if (token !== state.roleToken) { return; }
                state.roles = { student: false, admin: false };
                updateRolePill();
            });
    }

    function updateRolePill() {
        if (!rolePill) { return; }
        var student = state.roles.student;
        var admin = state.roles.admin;
        if (student && admin) {
            rolePill.hidden = false;
            rolePill.dataset.role = 'both';
            rolePill.textContent = 'Student + admin';
            rolePill.title = 'Both sessions are active; actions use the role of the page you open.';
        } else if (admin) {
            rolePill.hidden = false;
            rolePill.dataset.role = 'admin';
            rolePill.textContent = 'Admin session';
            rolePill.title = 'Admin demo session is active.';
        } else if (student) {
            rolePill.hidden = false;
            rolePill.dataset.role = 'student';
            rolePill.textContent = 'Student session';
            rolePill.title = 'Student demo session is active.';
        } else {
            rolePill.hidden = true;
            rolePill.textContent = '';
            rolePill.removeAttribute('data-role');
        }
    }

    function demoRequest(path, body) {
        var headers = { Accept: 'application/json', 'X-Guide-Demo': '1' };
        var options = { method: 'POST', credentials: 'same-origin', headers: headers };
        if (body) {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            options.body = new URLSearchParams(body).toString();
        }
        return fetch(new URL(path, document.baseURI).href, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || !payload.ok) {
                    throw new Error((payload && payload.error) || 'The demo session could not be opened.');
                }
                return payload;
            });
        });
    }

    function loginAs(role, options) {
        var isAdmin = role === 'admin';
        var dashboardPath = isAdmin ? '/admin/dashboard.php' : '/student/dashboard.php';
        var label = isAdmin ? 'Admin' : 'Student';
        var opts = options || {};

        return demoRequest('api/demo-login.php', { role: role }).then(function () {
            state.roles = { student: !isAdmin, admin: isAdmin };
            updateRolePill();
            toast(label + ' demo session opened.');
            return detectRoles();
        }).then(function () {
            var returnAnnotation = opts.returnAnnotation && annotationById(opts.returnAnnotation);
            if (returnAnnotation) {
                return navigateToAnnotation(returnAnnotation, returnAnnotation.id);
            }
            return navigateApp(dashboardPath);
        }).catch(function (error) {
            toast(error && error.message ? error.message : 'Demo login failed.');
            return false;
        });
    }

    function logout() {
        if (frame) {
            try { frame.src = 'about:blank'; } catch (e) { /* noop */ }
            state.frameReady = false;
        }
        return demoRequest('api/logout.php').catch(function () {
            return { ok: true };
        }).then(function () {
            state.roles = { student: false, admin: false };
            state.previewId = null;
            updateRolePill();
            toast('Demo sessions cleared.');
            return navigateApp('/public/landingpage.php');
        });
    }

    /* --------------------------------------------------------- banners --- */

    function showBanner(message, actionLabel, action) {
        if (!frameBanner) { return; }
        frameBanner.textContent = '';
        frameBanner.appendChild(make('span', '', message));
        if (actionLabel && action) {
            var button = make('button', '', actionLabel);
            button.type = 'button';
            button.addEventListener('click', action);
            frameBanner.appendChild(button);
        }
        frameBanner.hidden = false;
    }

    function updateBanner() {
        if (!frameBanner) { return; }
        var route = currentRoute();
        var stepAnnotation = currentStepAnnotation();
        var stepMatch = stepAnnotation && stepAnnotation.match ? stepAnnotation.match : {};
        var stepWantsAdmin = stepMatch.preview === true
            || normalizePath(stepMatch.path || '').indexOf('/admin/') === 0;

        if (route.path === '/student/login.php' && stepWantsAdmin) {
            showBanner('This ballot preview needs an admin session.', 'Log in as demo admin', function () {
                loginAs('admin', { returnAnnotation: stepAnnotation ? stepAnnotation.id : null });
            });
            return;
        }
        if (route.path === '/student/login.php') {
            showBanner('This screen needs a student session.', 'Log in as demo student', function () {
                loginAs('student', { returnAnnotation: stepAnnotation ? stepAnnotation.id : null });
            });
            return;
        }
        if (route.path === '/admin/login.php') {
            showBanner('This screen needs an admin session.', 'Log in as demo admin', function () {
                loginAs('admin', { returnAnnotation: stepAnnotation ? stepAnnotation.id : null });
            });
            return;
        }
        var win = frameWin();
        if (route.path === '/admin/dashboard.php' && win && !win.Chart) {
            showBanner('Chart.js did not load from the CDN. Charts may be blank; the rest of the panel still works.', 'View recorded screen', function () {
                showFallback('Chart.js did not load. This is a recorded view of the same screen.');
            });
            return;
        }
        frameBanner.hidden = true;
    }

    /* -------------------------------------------------------- fallback --- */

    /* Routes moved during the folder restructure. Recorded fallback screens
       keep their original keys, so the old key is mapped from the new path. */
    var LEGACY_ROUTE = {
        '/public/landingpage.php': 'landingpage',
        '/public/index.php': 'index',
        '/public/results.php': 'results',
        '/public/health.php': 'health',
        '/public/404.php': '404',
        '/student/login.php': 'login',
        '/student/dashboard.php': 'dashboard',
        '/student/vote.php': 'vote',
        '/student/draft.php': 'draft',
        '/student/dashboard-data.php': 'dashboard-data',
        '/student/update-account.php': 'update-account',
        '/student/change-password.php': 'change-password',
        '/student/logout.php': 'logout',
        '/student/photo.php': 'photo',
        '/student/icon.php': 'icon',
        '/config/router.php': 'router'
    };

    function screenKeyForRoute(route) {
        var legacy = LEGACY_ROUTE[route.path];
        if (legacy) {
            if (route.path === '/student/dashboard.php' && route.page && route.page !== 'home') { return legacy + '-' + route.page; }
            return legacy;
        }
        var path = (route.path || '/').replace(/^\/|\/$/g, '');
        if (!path) { path = 'landingpage'; }
        path = path.replace(/\.php$/, '').replace(/\//g, '-');
        if (route.path === '/admin/dashboard.php' && route.panel && route.panel !== 'dashboard') { path += '-' + route.panel; }
        return path;
    }

    function renderFallbackList() {
        if (!fallbackList) { return; }
        fallbackList.textContent = '';
        var list = activeAnnotations();
        if (!list.length) {
            var chapter = chapterFor(state.chapterId);
            (chapter && chapter.steps ? chapter.steps : []).forEach(function (step) {
                var annotation = annotationById(step.annotation);
                if (annotation) { list.push(annotation); }
            });
        }
        if (!list.length && chapters.length) {
            var first = chapterFor(chapters[0].id);
            (first && first.steps ? first.steps : []).forEach(function (step) {
                var annotation = annotationById(step.annotation);
                if (annotation) { list.push(annotation); }
            });
        }
        list.slice(0, 8).forEach(function (annotation) {
            var button = make('button');
            button.type = 'button';
            var text = make('span', '', annotation.title);
            var meta = make('small', '', (annotation.code && annotation.code[0] ? annotation.code[0].file : 'source'));
            button.appendChild(text);
            button.appendChild(meta);
            button.addEventListener('click', function () { openAnnotation(annotation.id); });
            fallbackList.appendChild(button);
        });
        if (!fallbackList.childNodes.length) {
            fallbackList.appendChild(make('div', 'empty-state', 'No annotations for this screen yet.'));
        }
    }

    function showFallback(message) {
        if (!fallback) { return; }
        window.clearTimeout(healthTimer);
        var paragraph = fallback.querySelector('p');
        if (paragraph && message) { paragraph.textContent = message; }
        var route = currentRoute();
        var screens = data.screens || {};
        var source = screens[route.path] || ('screens/' + screenKeyForRoute(route) + '.webp');
        if (fallbackImage) {
            fallbackImage.onload = function () { fallbackImage.hidden = false; };
            fallbackImage.onerror = function () { fallbackImage.hidden = true; };
            fallbackImage.hidden = false;
            fallbackImage.src = source;
        }
        renderFallbackList();
        fallback.hidden = false;
    }

    function retryLiveView() {
        if (fallback) { fallback.hidden = true; }
        armHealth();
        try {
            var win = frameWin();
            if (win) { win.location.reload(); }
            else { frame.src = appUrl('/public/landingpage.php').href; }
        } catch (e) {
            frame.src = appUrl('/public/landingpage.php').href;
        }
    }

    function checkHealth(doc) {
        if (!doc || !doc.body) {
            showFallback('The live page could not be read. It may be offline.');
            return;
        }
        var text = '';
        try { text = (doc.body.innerText || '').slice(0, 240); } catch (e) { text = ''; }
        if (/ERR_CONNECTION|ERR_NAME_NOT_RESOLVED|This site can.t be reached|Fatal error|Parse error|Page Not Found|isn.t on the ballot/.test(text)) {
            showFallback('The live app returned an error. This recorded view keeps the code and notes available.');
        }
    }

    /* ----------------------------------------------------------- frame --- */

    function injectFrameStyle(doc) {
        if (!doc || doc.getElementById('guide-frame-style')) { return; }
        var style = doc.createElement('style');
        style.id = 'guide-frame-style';
        style.textContent = 'html.guide-inspect, html.guide-inspect *{cursor:crosshair !important;}';
        (doc.head || doc.documentElement).appendChild(style);
    }

    function applyInspectClass() {
        var doc = frameDoc();
        if (doc && doc.documentElement) {
            doc.documentElement.classList.toggle('guide-inspect', state.inspect);
        }
    }

    function clearHover() {
        if (state.hover && state.hover.element) {
            state.hover.element.style.outline = state.hover.outline;
            state.hover.element.style.outlineOffset = state.hover.offset;
        }
        state.hover = null;
    }

    function onFrameMove(event) {
        if (!state.inspect) { clearHover(); return; }
        var target = event.target;
        if (!target || target.nodeType !== 1) { return; }
        if (state.hover && state.hover.target === target) { return; }
        clearHover();
        var annotation = annotationForElement(target);
        if (!annotation) { return; }
        var doc = frameDoc();
        var element = null;
        try { element = doc.querySelector(annotation.selector); } catch (e) { element = null; }
        if (!element) { return; }
        state.hover = {
            target: target,
            element: element,
            outline: element.style.outline,
            offset: element.style.outlineOffset
        };
        element.style.outline = '2px solid #84cc16';
        element.style.outlineOffset = '-2px';
    }

    function onFrameClick(event) {
        if (!state.inspect) { return; }
        var target = event.target;
        if (!target || target.nodeType !== 1) { return; }
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        var annotation = annotationForElement(target);
        if (annotation) {
            state.focusReturn = null;
            openAnnotation(annotation.id);
        } else {
            openSynthetic(target);
        }
    }

    function bindFrame(doc, win) {
        if (!doc || !win || doc.__guideBound) { return; }
        doc.__guideBound = true;
        injectFrameStyle(doc);
        applyInspectClass();
        try {
            observer = new win.MutationObserver(function () { scheduleRescan(); });
            observer.observe(doc.body || doc.documentElement, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class']
            });
        } catch (e) { /* older engines */ }
        win.addEventListener('scroll', schedulePosition, { passive: true });
        win.addEventListener('resize', schedulePosition, { passive: true });
        doc.addEventListener('click', onFrameClick, true);
        doc.addEventListener('pointermove', onFrameMove, { passive: true });
    }

    function onFrameLoad() {
        state.frameReady = true;
        clearHealth();
        if (fallback) { fallback.hidden = true; }
        var doc = frameDoc();
        var win = frameWin();
        if (!doc) {
            showFallback('The live page could not be read. It may be offline.');
            resolveLoadWaiters();
            return;
        }
        state.boundDoc = doc;
        bindFrame(doc, win);
        updateChrome();
        renderMarkers();
        scheduleScans();
        detectRoles();
        updateBanner();
        checkHealth(doc);

        var panel = state.pendingPanel;
        state.pendingPanel = '';
        if (panel) { callPanel(panel); }

        var openId = state.pendingOpen;
        var loadToken = state.pendingToken;
        state.pendingOpen = null;
        window.setTimeout(function () {
            renderMarkers();
            updateChrome();
            updateBanner();
            // Only the navigation that is still current may open its drawer.
            // A slower load can land after a newer step already advanced.
            if (openId && loadToken === state.navToken) { openAnnotation(openId); }
        }, panel ? 700 : 260);

        resolveLoadWaiters();
    }

    /* --------------------------------------------------------- welcome --- */

    function dismissWelcome(remember) {
        var welcome = $('welcome');
        if (welcome) {
            welcome.hidden = true;
            welcome.setAttribute('aria-hidden', 'true');
        }
        if (remember) { storeSet(WELCOME_KEY, '1'); }
    }

    function setupWelcome() {
        var welcome = $('welcome');
        if (!welcome) { return; }
        var chapterCount = $('welcomeChapters');
        var annotationCount = $('welcomeAnnotations');
        if (chapterCount) { chapterCount.textContent = String(chapters.length); }
        if (annotationCount) { annotationCount.textContent = String(Object.keys(annotations).length); }

        if (storeGet(WELCOME_KEY) === '1') {
            welcome.hidden = true;
            welcome.setAttribute('aria-hidden', 'true');
        }

        var skip = $('welcomeSkip');
        if (skip) {
            skip.checked = storeGet(WELCOME_KEY) === '1';
            skip.addEventListener('change', function () {
                if (skip.checked) { storeSet(WELCOME_KEY, '1'); }
                else { storeRemove(WELCOME_KEY); }
            });
        }

        var start = $('welcomeStart');
        if (start) {
            start.addEventListener('click', function () {
                dismissWelcome(skip && skip.checked);
                startTour();
            });
        }
        var explore = $('welcomeExplore');
        if (explore) {
            explore.addEventListener('click', function () {
                dismissWelcome(skip && skip.checked);
                if (state.tourActive) { stopTour(); }
                toast('Inspect mode is on. Click anything inside the frame.');
            });
        }
    }

    /* ---------------------------------------------------------- toggles --- */

    function setInspect(mode, announce) {
        var inspect = mode !== false && mode !== 'browse';
        state.inspect = inspect;
        app.setAttribute('data-inspect', inspect ? 'true' : 'false');
        all('#inspectToggle button').forEach(function (button) {
            button.classList.toggle('active', (button.getAttribute('data-mode') === 'inspect') === inspect);
        });
        applyInspectClass();
        storeSet(INSPECT_KEY, inspect ? 'inspect' : 'browse');
        if (!inspect) { clearHover(); }
        if (announce) {
            toast(inspect
                ? 'Inspect mode: clicks open code instead of navigating.'
                : 'Browse mode: the app behaves normally.');
        }
    }

    function setupInspect() {
        var wrap = $('inspectToggle');
        if (!wrap) { return; }
        var saved = storeGet(INSPECT_KEY);
        setInspect(saved !== 'browse', false);
        all('button', wrap).forEach(function (button) {
            button.addEventListener('click', function () {
                setInspect(button.getAttribute('data-mode'), true);
            });
        });
    }

    function setDevice(device) {
        var value = device === 'tablet' || device === 'mobile' ? device : 'desktop';
        state.device = value;
        var shell = $('frame-shell');
        if (shell) { shell.setAttribute('data-device', value); }
        app.setAttribute('data-device', value);
        all('#deviceToggle button').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-device') === value);
        });
        storeSet(DEVICE_KEY, value);
        window.setTimeout(schedulePosition, 280);
    }

    function setupDevice() {
        var wrap = $('deviceToggle');
        if (!wrap) { return; }
        var saved = storeGet(DEVICE_KEY);
        setDevice(saved || 'desktop');
        all('button', wrap).forEach(function (button) {
            button.addEventListener('click', function () {
                setDevice(button.getAttribute('data-device'));
            });
        });
    }

    function setupRail() {
        var toggle = $('railToggle');
        var rail = $('tour-rail');
        if (!toggle || !rail) { return; }
        toggle.addEventListener('click', function () {
            var open = rail.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function setupDemoMenu() {
        var button = $('demoBtn');
        var menu = $('demoMenu');
        if (!button || !menu) { return; }

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = menu.hidden;
            menu.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        all('button[data-demo]', menu).forEach(function (item) {
            item.addEventListener('click', function () {
                var action = item.getAttribute('data-demo');
                menu.hidden = true;
                button.setAttribute('aria-expanded', 'false');
                if (action === 'logout') { logout(); }
                else { loginAs(action); }
            });
        });

        document.addEventListener('click', function (event) {
            if (!menu.hidden && !menu.contains(event.target) && event.target !== button) {
                menu.hidden = true;
                button.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* ---------------------------------------------------------- palette --- */

    function flattenSystemText(node, path, out) {
        if (!node) { return; }
        if (typeof node === 'string') { out.push(node); return; }
        if (Array.isArray(node)) {
            node.forEach(function (item, index) { flattenSystemText(item, path + '.' + index, out); });
            return;
        }
        if (typeof node === 'object') {
            Object.keys(node).forEach(function (key) {
                out.push(key);
                flattenSystemText(node[key], path + '.' + key, out);
            });
        }
    }

    function paletteItems(term) {
        var needle = String(term || '').trim().toLowerCase();
        var items = [];
        var systemItems = [];
        chapters.forEach(function (chapter, chapterIndex) {
            var haystack = [chapter.title, chapter.summary, chapter.group].join(' ').toLowerCase();
            if (!needle || haystack.indexOf(needle) !== -1) {
                items.push({
                    kind: 'Chapter',
                    title: chapter.title,
                    sub: chapter.summary || '',
                    chapter: chapter.id,
                    chapterIndex: chapterIndex
                });
            }
            (chapter.steps || []).forEach(function (step, stepIndex) {
                var annotation = annotationById(step.annotation) || {};
                var text = [step.title, step.intro, annotation.title, annotation.summary].join(' ').toLowerCase();
                if (needle && text.indexOf(needle) === -1) { return; }
                items.push({
                    kind: 'Step',
                    title: step.title,
                    sub: annotation.title || step.annotation,
                    chapter: chapter.id,
                    stepIndex: stepIndex
                });
            });
        });
        function pushSystem(kind, title, sub, view, focus) {
            if (!needle) { return; }
            var haystack = [title, sub].join(' ').toLowerCase();
            if (haystack.indexOf(needle) === -1) { return; }
            systemItems.push({
                kind: kind,
                title: title,
                sub: sub || '',
                system: true,
                systemView: view,
                systemFocus: focus || ''
            });        }

        (system.tables || []).forEach(function (table) {
            pushSystem('Table', 'Table · ' + table.name, table.purpose, 'database', 'table:' + table.name);
        });
        (system.endpoints || []).forEach(function (entry) {
            pushSystem('Endpoint', entry.method + ' ' + entry.path, entry.purpose, 'api', 'endpoint:' + entry.method + ':' + entry.path);
        });
        (system.security || []).forEach(function (entry) {
            pushSystem('Security', entry.title, entry.summary, 'security', 'security:' + entry.id);
        });
        (system.functions || []).forEach(function (entry) {
            pushSystem('Function', entry.name + '()', entry.purpose, 'functions', 'function:' + entry.name);
        });
        (system.flows || []).forEach(function (flow) {
            pushSystem('Flow', flow.title, flow.summary, 'flows', 'flow:' + flow.id);
        });
        ((system.architecture && system.architecture.layers) || []).forEach(function (layer) {
            (layer.nodes || []).forEach(function (node) {
                pushSystem('Architecture', node.title, node.summary, 'architecture', 'arch:' + node.id);
            });
        });
        (system.glossary || []).forEach(function (entry) {
            pushSystem('Glossary', entry.term, entry.definition, 'glossary', 'glossary:' + entry.term);
        });

        Object.keys(annotations).forEach(function (id) {
            var annotation = annotations[id];
            var haystack = [
                annotation.title, annotation.summary, annotation.behavior, annotation.under_the_hood,
                annotation.defense, (annotation.questions || []).join(' '),
                (annotation.tables || []).join(' '), (annotation.endpoints || []).join(' '),
                (annotation.code || []).map(function (ref) { return ref.file + ' ' + ref.anchor; }).join(' ')
            ].join(' ').toLowerCase();
            if (needle && haystack.indexOf(needle) === -1) { return; }
            items.push({
                kind: 'Annotation',
                title: annotation.title || id,
                sub: (annotation.code && annotation.code[0] ? annotation.code[0].file : '') + ' · ' + (annotation.summary || ''),
                annotation: id
            });
        });
        var systemText = [];
        flattenSystemText(data.system, 'system', systemText);
        var hasSystemMatch = systemItems.length > 0;
        if (needle && !hasSystemMatch && systemText.join(' ').toLowerCase().indexOf(needle) !== -1) {
            systemItems.push({
                kind: 'System',
                title: 'System reference',
                sub: 'Architecture, database, APIs, security, and flows',
                system: true,
                systemView: 'overview',
                systemFocus: ''
            });
        }
        return systemItems.concat(items);
    }

    function renderPalette(term) {
        if (!paletteResults) { return; }
        paletteResults.textContent = '';
        state.paletteItems = paletteItems(term).slice(0, 30);
        state.paletteIndex = Math.max(0, Math.min(state.paletteIndex, state.paletteItems.length - 1));
        if (!state.paletteItems.length) {
            paletteResults.appendChild(make('div', 'empty-state', 'No matches. Search chapters, annotations, tables, or endpoints.'));
            return;
        }
        state.paletteItems.forEach(function (item, index) {
            var button = make('button', 'palette-item' + (index === state.paletteIndex ? ' is-selected' : ''));
            button.type = 'button';
            var main = make('span', 'pi-main');
            main.appendChild(make('strong', '', item.title));
            main.appendChild(make('small', '', item.sub || ''));
            button.appendChild(main);
            button.appendChild(make('span', 'pi-kind', item.kind));
            button.addEventListener('click', function () { choosePaletteItem(item); });
            paletteResults.appendChild(button);
        });
        var selected = paletteResults.querySelector('.is-selected');
        if (selected && selected.scrollIntoView) { selected.scrollIntoView({ block: 'nearest' }); }
    }

    function choosePaletteItem(item) {
        closePalette();
        if (item.annotation) {
            var annotation = annotationById(item.annotation);
            if (!annotation) { return; }
            state.focusReturn = null;
            navigateToAnnotation(annotation, annotation.id);
            return;
        }
        if (item.system) {
            openSystemView(item.systemView || 'overview', item.systemFocus || '');
            return;
        }
        if (item.chapter) {
            selectChapter(item.chapter, {
                open: true,
                navigate: true,
                step: item.stepIndex || 0
            });
        }
    }

    function openPalette() {
        if (!palette) { return; }
        palette.hidden = false;
        state.paletteIndex = 0;
        if (paletteInput) {
            paletteInput.value = '';
            renderPalette('');
            window.setTimeout(function () { paletteInput.focus(); }, 20);
        }
    }
    function closePalette() {
        if (palette) { palette.hidden = true; }
    }
    function setupPalette() {
        var search = $('searchBtn');
        if (search) { search.addEventListener('click', openPalette); }
        if (paletteInput) {
            paletteInput.addEventListener('input', function () {
                state.paletteIndex = 0;
                renderPalette(paletteInput.value);
            });
            paletteInput.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    state.paletteIndex = Math.min(state.paletteIndex + 1, state.paletteItems.length - 1);
                    renderPalette(paletteInput.value);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    state.paletteIndex = Math.max(state.paletteIndex - 1, 0);
                    renderPalette(paletteInput.value);
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    var item = state.paletteItems[state.paletteIndex];
                    if (item) { choosePaletteItem(item); }
                }
            });
        }
        if (palette) {
            palette.addEventListener('click', function (event) {
                if (event.target === palette) { closePalette(); }
            });
        }
        renderPalette('');
    }

    /* ----------------------------------------------------------- drawer --- */

    function setupDrawer() {
        var close = $('drawerClose');
        if (close) { close.addEventListener('click', function () { closeDrawer(true); }); }
        if (drawerFile) {
            drawerFile.addEventListener('click', function () {
                if (drawerFile.dataset.path) { copyText(drawerFile.dataset.path); }
            });
        }
        all('.drawer-tabs button').forEach(function (button) {
            button.addEventListener('click', function () {
                state.tab = button.getAttribute('data-tab') || 'behavior';
                renderTab();
            });
        });
        if (drawerPrev) { drawerPrev.addEventListener('click', prevStepAction); }
        if (drawerNext) { drawerNext.addEventListener('click', nextStepAction); }
        if (prevStep) { prevStep.addEventListener('click', prevStepAction); }
        if (nextStep) { nextStep.addEventListener('click', nextStepAction); }
        if (drawer) {
            drawer.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') { event.preventDefault(); closeDrawer(true); return; }
                if (event.key !== 'Tab') { return; }
                var focusable = all('button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])', drawer)
                    .filter(function (node) { return node.offsetParent !== null; });
                if (!focusable.length) { return; }
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
        }
    }

    function setupKeys() {
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (palette && !palette.hidden) { closePalette(); return; }
                closePalette();
                var menu = $('demoMenu');
                if (menu) { menu.hidden = true; }
                var rail = $('tour-rail');
                if (rail) { rail.classList.remove('is-open'); }
                if (state.drawerOpen) { closeDrawer(true); return; }
                if (state.systemOpen) { closeSystemView(); }
            }
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                openPalette();
            }
        });
    }

    function setupHelp() {
        var help = $('helpBtn');
        if (help) {
            help.addEventListener('click', function () {
                toast('Pick a chapter, then use Inspect mode to click anything in the running app.');
            });
        }
    }

    function setupFrame() {
        if (!frame) { return; }
        frame.addEventListener('load', onFrameLoad);
        var reload = $('reloadFrame');
        var frameReload = $('frameReload');
        function reloadFrame() {
            armHealth();
            try { frameWin().location.reload(); }
            catch (e) { frame.src = appUrl('/public/landingpage.php').href; }
        }
        if (reload) { reload.addEventListener('click', reloadFrame); }
        if (frameReload) { frameReload.addEventListener('click', reloadFrame); }
        var retry = $('fallbackRetry');
        if (retry) { retry.addEventListener('click', retryLiveView); }
        window.addEventListener('resize', schedulePosition);
        window.addEventListener('scroll', schedulePosition, true);
    }

    /* ------------------------------------------------------------- init --- */

    function init() {
        renderChapters();
        setupWelcome();
        setupTour();
        setupRail();
        setupInspect();
        setupDevice();
        setupDemoMenu();
        setupPalette();
        setupDrawer();
        setupSystem();
        setupKeys();
        setupHelp();
        setupFrame();
        restoreState();
        updateStepBar();

        if (frame && frame.contentDocument && frame.contentDocument.readyState === 'complete' && !state.frameReady) {
            window.setTimeout(function () {
                if (!state.frameReady) { onFrameLoad(); }
            }, 30);
        }
    }

    window.Guide = {
        init: init,
        navigate: navigate,
        openAnnotation: openAnnotation,
        closeDrawer: closeDrawer,
        setInspect: function (mode) { setInspect(mode, true); },
        loginAs: loginAs,
        logout: logout,
        setDevice: setDevice,
        nextStep: nextStepAction,
        prevStep: prevStepAction,
        startTour: startTour,
        stopTour: stopTour,
        setFollow: function (on) { state.follow = !!on; saveState(); updateFollowUI(); },
        search: function (term) {
            openPalette();
            if (paletteInput) {
                paletteInput.value = term || '';
                renderPalette(term || '');
            }
        },
        refresh: function () {
            renderMarkers();
            updateChrome();
            detectRoles();
        },
        renderSystem: function (view, focus) { openSystemView(view, focus); },
        closeSystem: closeSystemView,
        state: state
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
