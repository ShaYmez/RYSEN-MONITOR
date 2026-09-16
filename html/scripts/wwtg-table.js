/**
 * Talkgroup list search and column sort for the WWTG dashboard page.
 */
(function () {
    var table = document.getElementById('wwtg-table');
    if (!table || !table.tBodies.length) {
        return;
    }

    var tbody = table.tBodies[0];
    var headers = table.tHead ? table.tHead.querySelectorAll('th.sortable') : [];
    var searchInput = document.getElementById('tbltgs_search');
    var countEl = document.getElementById('wwtg-visible-count');
    var emptyEl = document.getElementById('tbltgs_none');
    var total = tbody.rows.length;
    var sortColumn = 0;
    var sortDir = 'asc';

    function cellSortValue(row, colIndex, type) {
        var cell = row.cells[colIndex];
        if (!cell) {
            return type === 'number' ? 0 : '';
        }
        var raw = cell.getAttribute('data-sort');
        if (raw == null) {
            raw = cell.textContent || '';
        }
        raw = String(raw).trim();
        if (type === 'number') {
            var n = parseFloat(raw);
            return raw === '' || isNaN(n) ? null : n;
        }
        return raw.toLowerCase();
    }

    function compareRows(a, b, colIndex, type, dir) {
        var av = cellSortValue(a, colIndex, type);
        var bv = cellSortValue(b, colIndex, type);
        var emptyA = av === null || av === '';
        var emptyB = bv === null || bv === '';
        if (emptyA && emptyB) {
            return 0;
        }
        if (emptyA) {
            return 1;
        }
        if (emptyB) {
            return -1;
        }
        var result = 0;
        if (type === 'number') {
            result = av - bv;
        } else if (av < bv) {
            result = -1;
        } else if (av > bv) {
            result = 1;
        }
        return dir === 'desc' ? -result : result;
    }

    function restripe(visibleCount) {
        var shown = 0;
        Array.prototype.forEach.call(tbody.rows, function (row) {
            row.classList.remove('row-even', 'row-odd');
            if (row.classList.contains('is-hidden')) {
                return;
            }
            row.classList.add(shown % 2 === 0 ? 'row-odd' : 'row-even');
            shown += 1;
        });
        if (countEl) {
            countEl.textContent = visibleCount === total ? String(total) : visibleCount + ' / ' + total;
        }
        if (emptyEl) {
            emptyEl.hidden = visibleCount !== 0;
        }
    }

    function filterRows() {
        var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var visible = 0;
        Array.prototype.forEach.call(tbody.rows, function (row) {
            var haystack = row.getAttribute('data-search') || row.textContent.toLowerCase();
            var match = query === '' || haystack.indexOf(query) !== -1;
            row.classList.toggle('is-hidden', !match);
            if (match) {
                visible += 1;
            }
        });
        restripe(visible);
    }

    function sortBy(colIndex, type, dir) {
        var rows = Array.prototype.slice.call(tbody.rows);
        rows.sort(function (a, b) {
            return compareRows(a, b, colIndex, type, dir);
        });
        rows.forEach(function (row) {
            tbody.appendChild(row);
        });
        Array.prototype.forEach.call(headers, function (th, index) {
            th.classList.remove('sort-asc', 'sort-desc');
            if (index === colIndex) {
                th.classList.add(dir === 'desc' ? 'sort-desc' : 'sort-asc');
                th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
            } else {
                th.setAttribute('aria-sort', 'none');
            }
        });
        sortColumn = colIndex;
        sortDir = dir;
        filterRows();
    }

    function onHeaderActivate(th) {
        var colIndex = Array.prototype.indexOf.call(th.parentNode.children, th);
        var type = th.getAttribute('data-sort-type') === 'number' ? 'number' : 'text';
        var dir = (colIndex === sortColumn && sortDir === 'asc') ? 'desc' : 'asc';
        sortBy(colIndex, type, dir);
    }

    Array.prototype.forEach.call(headers, function (th) {
        th.addEventListener('click', function () {
            onHeaderActivate(th);
        });
        th.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                onHeaderActivate(th);
            }
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', filterRows);
    }

    restripe(total);
})();
