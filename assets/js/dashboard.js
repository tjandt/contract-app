(function () {
    const dataEl = document.getElementById('chart-data');
    if (!dataEl || typeof Chart === 'undefined') return;
    const data = JSON.parse(dataEl.textContent);

    const isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const textColor = isDark ? '#98989D' : '#6E6E73';
    const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';
    const accent = '#0A84FF';

    Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'SF Pro Text', sans-serif";
    Chart.defaults.color = textColor;

    function euro(value) {
        return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value);
    }

    // ---------- Kostenverlauf (Monate / Jahre) ----------
    const ctxSpend = document.getElementById('spendChart');
    let spendChart = null;
    let currentView = 'monthly';

    const hintEl = document.getElementById('spendChartHint');
    const breakdownEl = document.getElementById('monthBreakdown');
    const breakdownTitleEl = document.getElementById('monthBreakdownTitle');
    const breakdownListEl = document.getElementById('monthBreakdownList');
    const breakdownTotalEl = document.getElementById('monthBreakdownTotal');
    const breakdownCloseBtn = document.getElementById('monthBreakdownClose');

    function closeBreakdown() {
        if (breakdownEl) breakdownEl.hidden = true;
    }
    if (breakdownCloseBtn) breakdownCloseBtn.addEventListener('click', closeBreakdown);

    async function showBreakdown(kind, key, label) {
        if (!breakdownEl) return;
        breakdownEl.hidden = false;
        breakdownTitleEl.textContent = (kind === 'month' ? 'Kosten im ' : 'Kosten ') + label;
        breakdownListEl.innerHTML = '<div class="month-breakdown-loading">Lädt…</div>';
        breakdownTotalEl.textContent = '';

        const url = kind === 'month'
            ? 'month_breakdown.php?ym=' + encodeURIComponent(key)
            : 'year_breakdown.php?year=' + encodeURIComponent(key);

        try {
            const res = await fetch(url);
            if (!res.ok) throw new Error('request failed');
            const json = await res.json();

            if (!json.items || json.items.length === 0) {
                breakdownListEl.innerHTML = '<div class="month-breakdown-empty">Keine Verträge in diesem Zeitraum.</div>';
            } else {
                breakdownListEl.innerHTML = json.items.map(item => `
                    <div class="month-breakdown-item">
                        <span class="cat-dot" style="background:${item.color_hex || '#8E8E93'}"></span>
                        <span class="mb-name">${escapeHtml(item.name)}</span>
                        <span class="mb-cat">${escapeHtml(item.category_name || '')}</span>
                        <span class="mb-amount">${euro(item.amount)}</span>
                    </div>
                `).join('');
            }
            breakdownTotalEl.innerHTML = `<span>Gesamt</span><span>${euro(json.total)}</span>`;
        } catch (e) {
            breakdownListEl.innerHTML = '<div class="month-breakdown-empty">Konnte Daten nicht laden.</div>';
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function buildSpendChart(view) {
        const d = data[view];
        const isMonthly = view === 'monthly';
        currentView = view;

        if (spendChart) spendChart.destroy();
        closeBreakdown();
        if (hintEl) {
            hintEl.style.display = '';
            hintEl.textContent = isMonthly
                ? 'Klicke auf einen Monat für die Aufstellung nach Vertrag.'
                : 'Klicke auf ein Jahr für die Aufstellung nach Vertrag.';
        }

        spendChart = new Chart(ctxSpend, {
            type: isMonthly ? 'line' : 'bar',
            data: {
                labels: d.labels,
                datasets: [{
                    label: 'Kosten',
                    data: d.values,
                    borderColor: accent,
                    backgroundColor: isMonthly ? 'rgba(10,132,255,0.10)' : accent,
                    pointRadius: isMonthly ? 0 : undefined,
                    pointHoverRadius: isMonthly ? 5 : undefined,
                    pointHitRadius: isMonthly ? 12 : undefined,
                    borderWidth: isMonthly ? 2.5 : 0,
                    borderRadius: isMonthly ? 0 : 8,
                    maxBarThickness: 46,
                    fill: isMonthly,
                    tension: 0.35,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                onClick: (evt, elements) => {
                    if (!elements || elements.length === 0) return;
                    const idx = elements[0].index;
                    if (isMonthly) {
                        const ym = data.monthly.keys[idx];
                        const label = data.monthly.labels[idx];
                        if (ym) showBreakdown('month', ym, label);
                    } else {
                        const year = data.yearly.keys[idx];
                        if (year) showBreakdown('year', year, data.yearly.labels[idx]);
                    }
                },
                onHover: (evt, elements) => {
                    evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#2C2C2E' : '#1D1D1F',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => euro(ctx.parsed.y)
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        border: { display: false },
                        ticks: { callback: (v) => euro(v) }
                    }
                }
            }
        });
    }

    buildSpendChart('monthly');

    document.querySelectorAll('.chart-tabs button').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.chart-tabs button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            buildSpendChart(btn.dataset.view);
        });
    });

    // ---------- Kategorie-Donut ----------
    const ctxCat = document.getElementById('categoryChart');
    if (ctxCat && data.category.values.length > 0) {
        new Chart(ctxCat, {
            type: 'doughnut',
            data: {
                labels: data.category.labels,
                datasets: [{
                    data: data.category.values,
                    backgroundColor: data.category.colors,
                    borderColor: isDark ? '#1C1C1E' : '#FFFFFF',
                    borderWidth: 3,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#2C2C2E' : '#1D1D1F',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => `${ctx.label}: ${euro(ctx.parsed)}`
                        }
                    }
                }
            }
        });
    } else if (ctxCat) {
        ctxCat.style.display = 'none';
    }
})();
