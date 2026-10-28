document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', () => {
        const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        try {
            localStorage.setItem('theme', theme);
        } catch {
        }
    });
});

const crawlSection = document.querySelector('[data-crawl]');
if (crawlSection) {
    startCrawl(crawlSection);
}

// Le navigateur pilote le crawl : il garde la file des pages, envoie des lots au serveur et calcule ce qui
// demande de connaître tout le site (profondeur, liens entrants, doublons, pages orphelines).
function startCrawl(section) {
    const config = JSON.parse(section.querySelector('#crawl-config').textContent);
    const t = (key, params = {}) => (config.i18n[key] ?? key).replace(/%(\w+)%/g, (_, name) => params[name] ?? '');
    const $ = (selector) => section.querySelector(selector);

    const DEEP_CLICKS = 3;
    const DUPLICATE_MIN_WORDS = 50;
    const SEVERITY_ORDER = { error: 0, warning: 1, notice: 2 };

    const pages = new Map();
    const depths = new Map([[config.root, 0]]);
    const incoming = new Map();
    const sitemap = new Set(config.sitemap);
    const queue = [config.root];
    let sitemapQueued = false;
    let state = 'running';
    let sort = { key: 'order', direction: 1 };
    let siteIssues = new Map();
    const opened = new Set();
    const openDrawers = new Set();

    const bareHost = (url) => new URL(url).host.replace(/^www\./, '');
    const rootHost = bareHost(config.root);
    const isInternal = (url) => {
        try {
            return bareHost(url) === rootHost;
        } catch {
            return false;
        }
    };

    const discover = (url, depth) => {
        if (!depths.has(url)) {
            if (depths.size >= config.maxPages) {
                return;
            }
            depths.set(url, depth);
            queue.push(url);
        } else if (depth !== null && (depths.get(url) === null || depth < depths.get(url))) {
            depths.set(url, depth);
        }
    };

    // Formats
    const number = new Intl.NumberFormat(document.documentElement.lang);
    const bytes = (value) => (value >= 1e6
        ? `${number.format(Math.round(value / 1e5) / 10)} ${t('unit.mb')}`
        : value >= 1e3 ? `${number.format(Math.round(value / 1e3))} ${t('unit.kb')}` : `${number.format(value)} ${t('unit.b')}`);
    const duration = (ms) => (ms === null || ms === undefined ? '—' : ms >= 1000
        ? `${number.format(Math.round(ms / 100) / 10)} ${t('unit.s')}`
        : `${number.format(ms)} ${t('unit.ms')}`);
    const percent = (part, total) => (total ? Math.round((part / total) * 100) : 0);
    const median = (values) => {
        const sorted = values.filter((v) => v !== null && v !== undefined).sort((a, b) => a - b);

        return sorted.length ? sorted[Math.floor((sorted.length - 1) / 2)] : null;
    };
    const el = (tag, attributes = {}, ...children) => {
        const node = document.createElement(tag);
        Object.entries(attributes).forEach(([name, value]) => {
            if (value !== null && value !== undefined && value !== false) {
                node.setAttribute(name, value === true ? '' : value);
            }
        });
        node.append(...children.flat().filter((child) => child !== null && child !== undefined && child !== false));

        return node;
    };
    // Chemin de la page ; le domaine n'est ajouté que s'il diffère de celui de l'accueil (www ou non).
    const rootHostname = new URL(config.root).host;
    const path = (url) => {
        const parsed = new URL(url);
        const local = decodeURI(parsed.pathname + parsed.search);

        return parsed.host === rootHostname ? local : `${parsed.host}${local}`;
    };

    const issuesOf = (page) => [...page.issues, ...(siteIssues.get(page.url) ?? [])]
        .sort((a, b) => SEVERITY_ORDER[config.issues[a].severity] - SEVERITY_ORDER[config.issues[b].severity]);
    // Pages déjà analysées vers lesquelles pointent les liens d'une page.
    const linkedPages = (page) => page.links.map((link) => pages.get(link)).filter(Boolean);
    const brokenTargets = (page) => linkedPages(page)
        .filter((target) => (target.status === 0 ? target.error !== 'robots' : target.status >= 400));
    const redirectTargets = (page) => linkedPages(page).filter((target) => target.redirect);
    const hasSeverity = (page, severity) => issuesOf(page).some((code) => config.issues[code].severity === severity);

    // Colonnes du tableau : seule la page a une largeur libre, le tableau tient en 1000 px sans défilement.
    // Les seuils (couleurs, jauges) sont ceux de PageAudit, transmis par le serveur.
    const TYPES = ['image', 'html', 'css', 'js', 'font', 'other'];
    const limits = config.thresholds;
    const lengthOf = (text) => [...(text ?? '')].length;
    const rangeClass = (value, [min, max]) => (!value ? 'is-bad' : value < min || value > max ? 'is-warn' : 'is-good');
    const metric = (value, className = null, title = null) => el('span', { class: `metric ${className ?? ''}`, title }, String(value));
    const severityCounts = (page) => {
        const counts = { error: 0, warning: 0, notice: 0 };
        issuesOf(page).forEach((code) => counts[config.issues[code].severity]++);

        return counts;
    };
    const breakdown = (page) => TYPES.filter((type) => page.bytes[type])
        .map((type) => `${t(`type.${type}`)} ${percent(page.bytes[type], page.totalBytes)} %`).join(' · ');

    const columns = [
        { key: 'url', sort: (p) => p.url, cell: pageCell, className: 'is-page' },
        { key: 'status', sort: (p) => p.status, cell: statusCell },
        { key: 'depth', hint: true, sort: (p) => depths.get(p.url) ?? 99, cell: (p) => metric(depths.get(p.url) ?? '—', (depths.get(p.url) ?? 0) > DEEP_CLICKS ? 'is-warn' : null), className: 'is-number' },
        { key: 'weight', hint: true, sort: (p) => p.totalBytes, cell: weightCell, className: 'is-number' },
        { key: 'ttfb', hint: true, sort: (p) => p.ttfb ?? -1, cell: (p) => timeCell(p.ttfb, limits.ttfb), className: 'is-number' },
        { key: 'load', hint: true, sort: (p) => p.loadMs ?? -1, cell: (p) => timeCell(p.loadMs, limits.load), className: 'is-number' },
        { key: 'requests', hint: true, sort: (p) => p.requests, cell: (p) => (p.html ? metric(p.requests, p.requests > limits.requests ? 'is-warn' : null) : ''), className: 'is-number' },
        { key: 'title', hint: { min: limits.title[0], max: limits.title[1] }, sort: (p) => lengthOf(p.title), cell: (p) => lengthCell(p, p.title, limits.title), className: 'is-number' },
        { key: 'meta', hint: { min: limits.meta[0], max: limits.meta[1] }, sort: (p) => lengthOf(p.metaDescription), cell: (p) => lengthCell(p, p.metaDescription, limits.meta), className: 'is-number' },
        { key: 'hn', hint: true, sort: (p) => p.h1.length * 1e4 + p.h2.length * 100 + p.h3.length, cell: headingsCell, className: 'is-number' },
        { key: 'words', hint: { min: limits.words }, sort: (p) => p.words ?? -1, cell: (p) => (p.html ? metric(number.format(p.words), p.words < limits.words ? 'is-warn' : null) : ''), className: 'is-number' },
        { key: 'incoming', hint: true, sort: (p) => incoming.get(p.url)?.size ?? 0, cell: (p) => metric(incoming.get(p.url)?.size ?? 0), className: 'is-number' },
        { key: 'issues', hint: true, sort: (p) => { const c = severityCounts(p); return c.error * 1e4 + c.warning * 100 + c.notice; }, cell: issuesCell },
    ];

    function pageCell(page) {
        const sub = page.html ? page.title || '—'
            : page.redirect ? `→ ${isInternal(page.redirect) ? path(page.redirect) : page.redirect}`
                : page.contentType ?? t(`error.${page.error}`);

        return el('button', { type: 'button', class: 'page-toggle', 'aria-expanded': String(opened.has(page.url)) },
            el('span', { class: 'chevron', 'aria-hidden': 'true' }),
            el('span', { class: 'page-text' },
                el('span', { class: 'page-path', title: page.url }, path(page.url)),
                el('span', { class: 'page-sub', title: sub }, sub)));
    }

    function statusCell(page) {
        const label = page.status || t(`error.${page.error}`);
        const kind = page.status >= 400 || page.status === 0 ? (page.error === 'robots' ? 'is-file' : 'is-error')
            : page.redirect ? 'is-redirect' : page.html ? 'is-ok' : 'is-file';

        return el('span', { class: `status ${kind}`, title: page.redirect ?? page.contentType ?? '' }, String(label));
    }

    function weightCell(page) {
        if (!page.totalBytes) {
            return '';
        }

        return el('span', { class: 'weight', title: breakdown(page) },
            metric(bytes(page.totalBytes), page.totalBytes > limits.weight ? 'is-warn' : null),
            el('span', { class: 'stack is-mini', 'aria-hidden': 'true' }, TYPES.map((type) => {
                const width = percent(page.bytes[type] ?? 0, page.totalBytes);

                return width ? el('span', { class: `is-${type}`, style: `width:${width}%` }) : null;
            })));
    }

    function timeCell(ms, slow) {
        return ms === null || ms === undefined ? '' : metric(duration(ms), ms > slow ? 'is-warn' : null);
    }

    function lengthCell(page, text, range) {
        return page.html ? metric(lengthOf(text) || '—', rangeClass(lengthOf(text), range), text ?? '') : '';
    }

    function headingsCell(page) {
        if (!page.html) {
            return '';
        }
        const h1 = page.h1.length;

        return el('span', { class: 'hn', title: `H1 ${h1} · H2 ${page.h2.length} · H3 ${page.h3.length}` },
            metric(h1, h1 === 1 ? 'is-good' : h1 === 0 ? 'is-bad' : 'is-warn'), metric(page.h2.length), metric(page.h3.length));
    }

    function issuesCell(page) {
        const codes = issuesOf(page);

        return el('span', { class: 'issue-counts', title: codes.map((code) => config.issues[code].label).join('\n') },
            ...Object.entries(severityCounts(page)).filter(([, n]) => n).map(([severity, n]) => el('span', { class: `pill is-${severity}` }, String(n))));
    }

    // Problèmes qui demandent de connaître tout le site.
    function computeSiteIssues() {
        const found = new Map();
        const add = (url, code) => found.set(url, [...(found.get(url) ?? []), code]);
        const indexable = [...pages.values()].filter((p) => p.html && p.status === 200
            && !p.issues.includes('noindex') && !p.issues.includes('canonical_elsewhere'));

        const groupBy = (list, key) => {
            const groups = new Map();
            list.forEach((page) => {
                const value = key(page);
                if (value) {
                    groups.set(value, [...(groups.get(value) ?? []), page]);
                }
            });

            return [...groups.values()].filter((group) => group.length > 1);
        };
        groupBy(indexable, (p) => p.title?.trim().toLowerCase()).flat().forEach((p) => add(p.url, 'duplicate_title'));
        groupBy(indexable, (p) => p.metaDescription?.trim().toLowerCase()).flat().forEach((p) => add(p.url, 'duplicate_meta'));
        groupBy(indexable, (p) => (p.words >= DUPLICATE_MIN_WORDS ? p.textHash : null)).flat().forEach((p) => add(p.url, 'duplicate_content'));

        pages.forEach((page) => {
            if (brokenTargets(page).length) {
                add(page.url, 'broken_links');
            }
            if (redirectTargets(page).length) {
                add(page.url, 'redirect_links');
            }
            if (!page.html || page.status !== 200) {
                return;
            }
            if (sitemap.has(page.url) && page.url !== config.root && !incoming.get(page.url)?.size) {
                add(page.url, 'orphan');
            }
            if (sitemap.size && !sitemap.has(page.url) && indexable.includes(page)) {
                add(page.url, 'not_in_sitemap');
            }
            if ((depths.get(page.url) ?? 0) > DEEP_CLICKS) {
                add(page.url, 'deep');
            }
        });

        return found;
    }

    // Synthèse au-dessus du tableau.
    function renderSynthesis() {
        const all = [...pages.values()];
        const html = all.filter((p) => p.html);
        const withError = all.filter((p) => hasSeverity(p, 'error')).length;
        const totals = {};
        html.forEach((p) => Object.entries(p.bytes).forEach(([type, n]) => { totals[type] = (totals[type] ?? 0) + n; }));
        const grandTotal = Object.values(totals).reduce((a, b) => a + b, 0);

        const counts = new Map();
        all.forEach((p) => issuesOf(p).forEach((code) => counts.set(code, (counts.get(code) ?? 0) + 1)));
        const top = [...counts.entries()]
            .sort((a, b) => SEVERITY_ORDER[config.issues[a[0]].severity] - SEVERITY_ORDER[config.issues[b[0]].severity] || b[1] - a[1])
            .slice(0, 8);

        const stat = (label, value, className = null) => el('div', { class: `stat ${className ?? ''}` }, el('dt', {}, label), el('dd', {}, value));
        const weightMedian = median(html.map((p) => p.totalBytes));

        $('[data-synthesis]').replaceChildren(
            el('dl', { class: 'stats' },
                stat(t('synth.pages'), number.format(all.length)),
                stat(t('synth.weight'), weightMedian === null ? '—' : bytes(weightMedian)),
                stat(t('synth.ttfb'), duration(median(html.map((p) => p.ttfb)))),
                stat(t('synth.load'), duration(median(html.map((p) => p.loadMs)))),
                stat(t('synth.errors'), number.format(withError), withError ? 'is-error' : null)),
            el('div', { class: 'synthesis-card' },
                el('h2', {}, t('synth.composition')),
                el('div', { class: 'composition' }, ['image', 'html', 'css', 'js', 'font', 'other'].map((type) => {
                    const share = percent(totals[type] ?? 0, grandTotal);

                    return el('div', { class: 'composition-row' },
                        el('span', {}, el('i', { class: `swatch is-${type}` }), t(`type.${type}`)),
                        el('span', { class: 'composition-track' }, el('span', { class: `is-${type}`, style: `width:${share}%` })),
                        el('b', {}, `${share} %`));
                }))),
            el('div', { class: 'synthesis-card' },
                el('h2', {}, t('synth.issues')),
                top.length ? el('ul', { class: 'top-issues' }, top.map(([code, n]) => el('li', {},
                    el('button', { type: 'button', 'data-issue': code },
                        el('span', { class: `dot is-${config.issues[code].severity}` }), config.issues[code].label, el('b', {}, number.format(n))))))
                    : el('p', { class: 'muted' }, t('synth.no_issue'))),
        );
    }

    // Tableau
    const head = $('[data-head]');
    const body = $('[data-body]');
    const search = $('[data-search]');
    const filter = $('[data-filter]');

    head.append(el('tr', {}, columns.map((column) => el('th', {
        scope: 'col',
        class: column.className ?? null,
        'data-key': column.key,
        title: column.hint ? t(`hint.${column.key}`, column.hint === true ? {} : column.hint) : null,
    }, el('button', { type: 'button', 'data-sort': column.key }, t(`col.${column.key}`))))));

    head.addEventListener('click', (event) => {
        const key = event.target.closest('[data-sort]')?.dataset.sort;
        if (!key) {
            return;
        }
        sort = { key, direction: sort.key === key ? -sort.direction : (key === 'url' || key === 'depth' ? 1 : -1) };
        head.querySelectorAll('[data-sort]').forEach((button) => {
            button.closest('th').setAttribute('aria-sort', button.dataset.sort === key ? (sort.direction > 0 ? 'ascending' : 'descending') : 'none');
        });
        renderTable();
    });

    function renderFilter() {
        const selected = filter.value;
        const counts = new Map();
        pages.forEach((page) => issuesOf(page).forEach((code) => counts.set(code, (counts.get(code) ?? 0) + 1)));
        filter.replaceChildren(
            el('option', { value: '' }, t('filter.all')),
            el('option', { value: 'severity:error' }, t('filter.errors')),
            el('option', { value: 'severity:warning' }, t('filter.warnings')),
            ...Object.entries(config.issues).filter(([code]) => counts.has(code))
                .map(([code, issue]) => el('option', { value: code }, `${issue.label} (${counts.get(code)})`)),
        );
        filter.value = [...filter.options].some((option) => option.value === selected) ? selected : '';
    }

    function visiblePages() {
        const text = search.value.trim().toLowerCase();
        const chosen = filter.value;
        const column = columns.find((c) => c.key === sort.key);

        return [...pages.values()]
            .filter((page) => !text || page.url.toLowerCase().includes(text))
            .filter((page) => !chosen || (chosen.startsWith('severity:')
                ? hasSeverity(page, chosen.slice(9))
                : issuesOf(page).includes(chosen)))
            .sort((a, b) => {
                const [x, y] = column ? [column.sort(a), column.sort(b)] : [a.order, b.order];

                return (typeof x === 'string' ? x.localeCompare(y) : x - y) * (column ? sort.direction : 1);
            });
    }

    // Le détail s'étend sur les seules colonnes affichées : certaines sont masquées sur les écrans étroits.
    const visibleColumns = () => [...head.querySelectorAll('th')].filter((th) => getComputedStyle(th).display !== 'none').length || columns.length;
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(renderTable, 150);
    });

    function renderTable() {
        const rows = [];
        visiblePages().forEach((page) => {
            const isOpen = opened.has(page.url);
            const row = el('tr', { class: isOpen ? 'is-open' : null, 'data-url': page.url },
                columns.map((column) => el('td', { class: column.className ?? null, 'data-key': column.key }, column.cell(page))));
            rows.push(row);
            if (isOpen) {
                rows.push(el('tr', { class: 'detail-row' }, el('td', { colspan: visibleColumns() }, detail(page))));
            }
        });
        body.replaceChildren(...(rows.length ? rows : [el('tr', {}, el('td', { colspan: visibleColumns(), class: 'muted' }, t('empty')))]));
    }

    body.addEventListener('click', (event) => {
        if (event.target.closest('a, .detail-row')) {
            return;
        }
        const url = event.target.closest('tr[data-url]')?.dataset.url;
        if (url) {
            opened.has(url) ? opened.delete(url) : opened.add(url);
            renderTable();
        }
    });
    // Le tableau est redessiné à chaque lot : les listes ouvertes du détail le restent.
    body.addEventListener('toggle', (event) => {
        const drawer = event.target.closest?.('[data-drawer]');
        if (drawer) {
            drawer.open ? openDrawers.add(drawer.dataset.drawer) : openDrawers.delete(drawer.dataset.drawer);
        }
    }, true);
    search.addEventListener('input', renderTable);
    filter.addEventListener('change', renderTable);
    $('[data-synthesis]').addEventListener('click', (event) => {
        const code = event.target.closest('[data-issue]')?.dataset.issue;
        if (code) {
            filter.value = code;
            renderTable();
            filter.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // Détail d'une page : contenu, performance et diagnostic côte à côte, puis les listes repliées.
    function detail(page) {
        const link = (url, label = url) => el('a', { href: url, target: '_blank', rel: 'noopener nofollow' }, label);
        const section = (key, ...children) => el('section', { class: 'detail-section' }, el('h3', {}, t(`section.${key}`)), ...children);
        const facts = (rows) => el('dl', { class: 'facts' }, rows.filter(Boolean).map(([key, value, className]) => el('div', { class: className ?? null },
            el('dt', {}, t(`detail.${key}`)), el('dd', {}, value ?? '—'))));
        const yesNo = (value) => t(value ? 'detail.yes' : 'detail.no');
        const codes = issuesOf(page);
        const issues = codes.length
            ? el('ul', { class: 'issue-list' }, codes.map((code) => el('li', {},
                el('span', { class: `dot is-${config.issues[code].severity}` }), config.issues[code].label)))
            : el('p', { class: 'muted' }, t('detail.no_issue'));
        const drawer = (key, items) => (items.length ? el('details', {
            class: 'drawer',
            'data-drawer': `${page.url}|${key}`,
            open: openDrawers.has(`${page.url}|${key}`),
        },
            el('summary', {}, t(`detail.${key}`), el('b', {}, number.format(items.length))),
            el('ul', {}, items.slice(0, 100).map((item) => el('li', {}, item)),
                items.length > 100 ? el('li', { class: 'muted' }, t('detail.more', { n: items.length - 100 })) : null)) : null);
        const drawers = el('div', { class: 'drawers' },
            drawer('broken_links', brokenTargets(page).map((target) => [link(target.url, path(target.url)), el('span', { class: 'status is-error' }, String(target.status || t(`error.${target.error}`)))])),
            drawer('redirect_links', redirectTargets(page).map((target) => [link(target.url, path(target.url)), ' → ', link(target.redirect)])),
            drawer('broken', (page.brokenResources ?? []).map((resource) => [link(resource.url), el('span', { class: 'status is-error' }, String(resource.status || t('error.network')))])),
            drawer('heavy_images', (page.heavyImages ?? []).map((image) => [link(image.url), el('span', { class: 'metric is-warn' }, bytes(image.bytes))])),
            drawer('images_without_alt', (page.imagesWithoutAlt ?? []).map((url) => link(url))),
            drawer('incoming', [...(incoming.get(page.url) ?? [])].map((url) => link(url, path(url)))));

        if (!page.html) {
            return el('div', { class: 'detail' },
                el('div', { class: 'detail-grid' }, section('diagnosis',
                    facts([
                        ['status', String(page.status || t(`error.${page.error}`))],
                        page.redirect ? ['redirect', link(page.redirect)] : null,
                        ['content_type', page.contentType],
                    ]),
                    issues)),
                drawers);
        }

        const field = (key, text, range) => {
            const n = lengthOf(text);

            return el('div', { class: 'field' },
                el('div', { class: 'field-head' },
                    el('span', { class: 'field-label' }, t(`detail.${key}`)),
                    gauge(n, range),
                    metric(t('detail.chars', { n }), rangeClass(n, range), t('detail.ideal', { min: range[0], max: range[1] }))),
                el('p', { class: `field-value${text ? '' : ' is-empty'}` }, text || '—'));
        };
        const kpi = (key, value, warn) => el('div', { class: `kpi${warn ? ' is-warn' : ''}` },
            el('span', { class: 'kpi-label' }, t(`detail.${key}`)), el('span', { class: 'kpi-value' }, value));

        return el('div', { class: 'detail' },
            el('div', { class: 'detail-grid' },
                section('content',
                    field('title', page.title, limits.title),
                    field('meta', page.metaDescription, limits.meta),
                    el('div', { class: 'field' },
                        el('div', { class: 'field-head' }, el('span', { class: 'field-label' }, t('detail.outline'))),
                        page.outline.length
                            ? el('ol', { class: 'outline' }, page.outline.map(({ level, text }) => el('li', { style: `--level:${level}` },
                                el('span', { class: `outline-tag is-h${Math.min(level, 4)}` }, `H${level}`), text || '—')))
                            : el('p', { class: 'field-value is-empty' }, t('detail.no_heading')))),
                section('performance',
                    el('div', { class: 'kpis' },
                        kpi('ttfb', duration(page.ttfb), page.ttfb > limits.ttfb),
                        kpi('load', duration(page.loadMs), page.loadMs > limits.load),
                        kpi('weight', bytes(page.totalBytes), page.totalBytes > limits.weight),
                        kpi('requests', number.format(page.requests), page.requests > limits.requests)),
                    el('div', { class: 'stack' }, TYPES.map((type) => {
                        const width = percent(page.bytes[type] ?? 0, page.totalBytes);

                        return width ? el('span', { class: `is-${type}`, style: `width:${width}%` }) : null;
                    })),
                    el('table', { class: 'breakdown' }, el('tbody', {}, TYPES.filter((type) => page.bytes[type]).map((type) => el('tr', {},
                        el('th', { scope: 'row' }, el('i', { class: `swatch is-${type}` }), t(`type.${type}`)),
                        el('td', {}, bytes(page.bytes[type])),
                        el('td', {}, `${percent(page.bytes[type], page.totalBytes)} %`))))),
                    facts([['compression', yesNo(page.compressed), page.compressed ? null : 'is-warn']])),
                section('diagnosis',
                    issues,
                    facts([
                        ['canonical', !page.canonical ? null : page.canonical === page.url ? t('detail.self') : link(page.canonical), page.canonical && page.canonical !== page.url ? 'is-warn' : null],
                        ['robots', page.robots, page.robots?.includes('noindex') ? 'is-warn' : null],
                        ['lang', page.lang, page.lang ? null : 'is-warn'],
                        ['hreflang', number.format(page.hreflang)],
                        ['open_graph', yesNo(page.openGraph)],
                        ['structured_data', page.structuredData.join(', ') || null],
                        ['words', number.format(page.words), page.words < limits.words ? 'is-warn' : null],
                        ['images', number.format(page.images)],
                        ['links_out', number.format(page.links.length)],
                        ['links_external', number.format(page.externalLinks)],
                    ]))),
            drawers);
    }

    // Jauge de longueur : zone idéale en fond, valeur en repère.
    function gauge(value, [min, max]) {
        const scale = Math.max(max * 1.4, value);

        return el('span', { class: 'gauge', 'aria-hidden': 'true' },
            el('span', { class: 'gauge-zone', style: `left:${(min / scale) * 100}%;width:${((max - min) / scale) * 100}%` }),
            el('span', { class: `gauge-mark ${rangeClass(value, [min, max])}`, style: `left:${(value / scale) * 100}%` }));
    }

    // Export CSV : toutes les pages, toutes les valeurs, y compris la part de chaque type dans le poids.
    $('[data-csv]').addEventListener('click', () => {
        const share = (types) => (p) => (p.html ? percent(types.reduce((sum, type) => sum + (p.bytes[type] ?? 0), 0), p.totalBytes) : '');
        const csvColumns = [
            ['col.url', (p) => p.url],
            ['col.status', (p) => p.status || t(`error.${p.error}`)],
            ['col.depth', (p) => depths.get(p.url) ?? ''],
            ['col.weight', (p) => p.totalBytes],
            ['col.ttfb', (p) => p.ttfb],
            ['col.load', (p) => p.loadMs],
            ['col.image', share(['image'])],
            ['col.html', share(['html'])],
            ['col.css', share(['css'])],
            ['col.js', share(['js'])],
            ['col.other', share(['font', 'other'])],
            ['col.requests', (p) => p.requests],
            ['detail.title', (p) => p.title],
            ['csv.title_length', (p) => (p.html ? lengthOf(p.title) : '')],
            ['detail.meta', (p) => p.metaDescription],
            ['csv.meta_length', (p) => (p.html ? lengthOf(p.metaDescription) : '')],
            ['detail.h1', (p) => p.h1.join(' | ')],
            ['detail.h2', (p) => p.h2.join(' | ')],
            ['detail.h3', (p) => p.h3.join(' | ')],
            ['col.words', (p) => p.words],
            ['col.incoming', (p) => incoming.get(p.url)?.size ?? 0],
            ['col.issues', (p) => issuesOf(p).map((code) => config.issues[code].label).join(' | ')],
            ['detail.canonical', (p) => p.canonical],
            ['detail.robots', (p) => p.robots],
            ['detail.broken_links', (p) => brokenTargets(p).map((target) => `${target.url} (${target.status || t(`error.${target.error}`)})`).join(' | ')],
            ['detail.redirect_links', (p) => redirectTargets(p).map((target) => `${target.url} → ${target.redirect}`).join(' | ')],
        ];
        const rows = [...pages.values()].sort((a, b) => a.order - b.order).map((page) => csvColumns.map(([, value]) => value(page)));
        const cell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
        const lines = [csvColumns.map(([key]) => t(key)), ...rows].map((row) => row.map(cell).join(';'));
        const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: section.dataset.filename });
        link.click();
        URL.revokeObjectURL(link.href);
    });

    // Boucle du crawl
    const pauseButton = $('[data-pause]');
    const stopButton = $('[data-stop]');
    pauseButton.hidden = false;
    stopButton.hidden = false;

    function renderStatus() {
        const params = { done: number.format(pages.size), found: number.format(depths.size), max: number.format(config.maxPages) };
        let status = t(`status.${state}`, params);
        if (depths.size >= config.maxPages && state !== 'running') {
            status += ` (${t('status.limit', params)})`;
        }
        $('[data-status]').textContent = status;
        $('[data-progress]').style.width = `${percent(pages.size, depths.size)}%`;
        section.classList.toggle('is-running', state === 'running');
        pauseButton.querySelector('span').textContent = t(state === 'paused' ? 'resume' : 'pause');
        pauseButton.classList.toggle('is-paused', state === 'paused');
        pauseButton.hidden = stopButton.hidden = state === 'done' || state === 'stopped';
    }

    function render() {
        siteIssues = computeSiteIssues();
        renderStatus();
        renderSynthesis();
        renderFilter();
        renderTable();
    }

    async function fetchBatch(urls) {
        const response = await fetch(config.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ root: config.root, urls }),
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return (await response.json()).pages;
    }

    const failed = (url) => ({
        url, status: 0, error: 'network', bytes: {}, totalBytes: 0, requests: 0, html: false,
        h1: [], h2: [], h3: [], links: [], structuredData: [], imagesWithoutAlt: [], heavyImages: [], brokenResources: [], issues: ['http_error'],
    });

    function record(page) {
        page.order = pages.size;
        pages.set(page.url, page);
        const depth = depths.get(page.url);
        if (page.redirect && isInternal(page.redirect)) {
            discover(page.redirect, depth);
        }
        page.links.forEach((link) => {
            if (!incoming.has(link)) {
                incoming.set(link, new Set());
            }
            incoming.get(link).add(page.url);
            discover(link, depth === null ? null : depth + 1);
        });
    }

    // Une seule boucle à la fois : une reprise pendant qu'un lot est en route ne doit pas en lancer une seconde.
    let looping = false;

    async function run() {
        if (looping) {
            return;
        }
        looping = true;
        while (state === 'running') {
            if (!queue.length) {
                if (sitemapQueued) {
                    state = 'done';
                    break;
                }
                // Les liens sont épuisés : les pages du sitemap jamais rencontrées sont orphelines.
                sitemapQueued = true;
                config.sitemap.forEach((url) => discover(url, null));
                continue;
            }
            const batch = queue.splice(0, config.batchSize);
            let reports;
            try {
                reports = await fetchBatch(batch);
            } catch {
                try {
                    reports = await fetchBatch(batch);
                } catch {
                    reports = batch.map(failed);
                }
            }
            reports.forEach(record);
            render();
        }
        looping = false;
        render();
    }

    pauseButton.addEventListener('click', () => {
        if (state === 'paused') {
            state = 'running';
            run();
        } else if (state === 'running') {
            state = 'paused';
            renderStatus();
        }
    });
    stopButton.addEventListener('click', () => {
        state = 'stopped';
        queue.length = 0;
        render();
    });

    render();
    run();
}
