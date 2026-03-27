<?php
/**
 * Dashboard view - Visual analytics for bookmarks
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found. Please copy config-example.php to config.php and adjust settings.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/nav.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Check if logged in (but don't require it)
$isLoggedIn = is_logged_in();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($config['site_title']) ?></title>
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'dashboard', 'Dashboard'); ?>

    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="dashboard-stat-card">
                <div class="label">Total Bookmarks</div>
                <div class="value" id="stat-total">-</div>
                <div class="subtext" id="stat-total-sub"></div>
            </div>
            <div class="dashboard-stat-card">
                <div class="label">Unique Tags</div>
                <div class="value" id="stat-tags">-</div>
                <div class="subtext" id="stat-tags-sub"></div>
            </div>
            <div class="dashboard-stat-card">
                <div class="label">Archived</div>
                <div class="value" id="stat-archived">-</div>
                <div class="subtext" id="stat-archived-sub"></div>
            </div>
            <div class="dashboard-stat-card">
                <div class="label">With Descriptions</div>
                <div class="value" id="stat-descriptions">-</div>
                <div class="subtext" id="stat-descriptions-sub"></div>
            </div>
            <div class="dashboard-stat-card">
                <div class="label">30-Day Velocity</div>
                <div class="value" id="stat-velocity">-</div>
                <div class="subtext" id="stat-velocity-sub"></div>
            </div>
            <div class="dashboard-stat-card">
                <div class="label">Active Days</div>
                <div class="value" id="stat-active">-</div>
                <div class="subtext" id="stat-active-sub"></div>
            </div>
        </div>

        <div class="content-grid">
            <div class="panel" id="tagNetworkPanel">
                <div class="panel-title">
                    <span>Tag Co-occurrence Matrix</span>
                    <div class="panel-controls">
                        <button class="fullscreen-btn" onclick="toggleFullscreen('tagNetworkPanel')"
                            title="Toggle fullscreen">⛶</button>
                    </div>
                </div>
                <div class="panel-content">
                    <div class="loading">Loading tag matrix</div>
                    <svg class="network-container" id="tagNetwork"></svg>
                </div>
            </div>

            <div class="panel" id="velocityPanel">
                <div class="panel-title">
                    <span>Bookmarking Velocity (90 Days)</span>
                    <button class="fullscreen-btn" onclick="toggleFullscreen('velocityPanel')"
                        title="Toggle fullscreen">⛶</button>
                </div>
                <div class="panel-content">
                    <div class="loading">Loading velocity data</div>
                    <svg class="chart-container" id="velocityChart"></svg>
                </div>
            </div>

            <div class="panel" id="tagEvolutionPanel">
                <div class="panel-title">
                    <span>Tag Activity Trends (Daily)</span>
                    <button class="fullscreen-btn" onclick="toggleFullscreen('tagEvolutionPanel')"
                        title="Toggle fullscreen">⛶</button>
                </div>
                <div class="panel-content">
                    <div class="loading">Loading tag activity</div>
                    <svg class="chart-container" id="tagEvolutionChart"></svg>
                </div>
            </div>
        </div>
    </div>

    <div class="last-updated">
        <span class="dot"></span>
        <span id="lastUpdated">Loading...</span>
    </div>

    <div class="tooltip" id="tooltip"></div>

    <script>
        const BASE_PATH = <?= json_encode($config['base_path']) ?>;
        let dashboardData = null;

        // Helper to get CSS variable value
        function getCSSVariable(name) {
            return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        }

        // Fetch dashboard data
        async function fetchDashboardData() {
            try {
                const response = await fetch(`${BASE_PATH}/api.php?action=dashboard_stats`);
                const data = await response.json();
                dashboardData = data;
                updateDashboard(data);
                updateLastUpdated();
            } catch (error) {
                console.error('Error fetching dashboard data:', error);
            }
        }

        // Update all visualizations
        function updateDashboard(data) {
            updateStats(data);
            renderTagCooccurrenceMatrix(data);
            renderVelocityChart(data);
            renderTagEvolution(data);
        }

        // Update stat cards
        function updateStats(data) {
            const stats = data.basic_stats;
            const timeline = data.timeline;

            // Total bookmarks
            document.getElementById('stat-total').textContent = stats.total_bookmarks;
            document.getElementById('stat-total-sub').textContent =
                `${stats.public_bookmarks} public / ${stats.private_bookmarks} private`;

            // Unique tags
            document.getElementById('stat-tags').textContent = data.tag_stats.length;
            const topTag = data.tag_stats[0];
            document.getElementById('stat-tags-sub').textContent =
                topTag ? `Top: ${topTag.tag} (${topTag.count})` : '-';

            // Archived
            document.getElementById('stat-archived').textContent = stats.with_archives;
            const archivePercent = stats.total_bookmarks > 0 ?
                Math.round((stats.with_archives / stats.total_bookmarks) * 100) : 0;
            document.getElementById('stat-archived-sub').textContent = `${archivePercent}% coverage`;

            // With descriptions
            document.getElementById('stat-descriptions').textContent = stats.with_descriptions;
            const descPercent = stats.total_bookmarks > 0 ?
                Math.round((stats.with_descriptions / stats.total_bookmarks) * 100) : 0;
            document.getElementById('stat-descriptions-sub').textContent = `${descPercent}% coverage`;

            // 30-day velocity
            const last30Days = timeline.slice(-30);
            const velocity30 = last30Days.reduce((sum, d) => sum + parseInt(d.count, 10), 0);
            const avgPerDay = last30Days.length > 0 ? (velocity30 / last30Days.length).toFixed(1) : 0;
            document.getElementById('stat-velocity').textContent = velocity30;
            document.getElementById('stat-velocity-sub').textContent = `${avgPerDay} per day avg`;

            // Active days
            const activeDays = timeline.filter(d => parseInt(d.count, 10) > 0).length;
            const totalDays = timeline.length;
            const activePercent = totalDays > 0 ? Math.round((activeDays / totalDays) * 100) : 0;
            document.getElementById('stat-active').textContent = activeDays;
            document.getElementById('stat-active-sub').textContent = `${activePercent}% of ${totalDays} days`;
        }

        // Render tag co-occurrence matrix heatmap
        function renderTagCooccurrenceMatrix(data) {
            const container = document.getElementById('tagNetwork');
            const parent = container.parentElement;
            parent.querySelector('.loading').style.display = 'none';
            container.style.display = 'block';

            d3.select(container).selectAll('*').remove();

            const isFullscreen = parent.closest('.panel').classList.contains('fullscreen');
            const MAX_TAGS = isFullscreen ? 20 : 15;

            const topTags = data.tag_stats.slice(0, MAX_TAGS);
            if (topTags.length < 2) {
                d3.select(container).append('text')
                    .attr('x', 20).attr('y', 40)
                    .attr('fill', getCSSVariable('--text-secondary'))
                    .text('Not enough tag data');
                return;
            }

            const tagNames = topTags.map(t => t.tag);
            const tagAliases = topTags.map(t => t.aliases || []);
            const tagNamesLower = tagNames.map(t => t.toLowerCase());

            // Build symmetric co-occurrence lookup
            const coMap = new Map();
            data.tag_cooccurrence.forEach(c => {
                coMap.set(`${c.source}|${c.target}`, c.count);
                coMap.set(`${c.target}|${c.source}`, c.count);
            });

            // Build flat matrix (including diagonal as null)
            const matrix = [];
            tagNamesLower.forEach((row, ri) => {
                tagNamesLower.forEach((col, ci) => {
                    matrix.push({
                        row: ri, col: ci,
                        rowTag: tagNames[ri], colTag: tagNames[ci],
                        rowAliases: tagAliases[ri], colAliases: tagAliases[ci],
                        count: ri === ci ? null : (coMap.get(`${row}|${col}`) || 0)
                    });
                });
            });

            const maxCount = d3.max(matrix, d => d.count) || 1;

            const width = parent.clientWidth;
            const height = parent.clientHeight;

            const leftPad = 85;
            const topPad = 85;
            const rightPad = 10;
            const bottomPad = 28;

            const n = tagNames.length;
            const cellSize = Math.floor(Math.min(
                (width - leftPad - rightPad) / n,
                (height - topPad - bottomPad) / n
            ));

            const matrixW = cellSize * n;
            const matrixH = cellSize * n;

            const svg = d3.select(container)
                .attr('width', width)
                .attr('height', height);

            const g = svg.append('g').attr('transform', `translate(${leftPad}, ${topPad})`);

            const colorScale = d3.scaleSequential(d3.interpolateBlues).domain([0, maxCount]);
            const tooltip = document.getElementById('tooltip');

            // Cells
            g.selectAll('.co-cell')
                .data(matrix)
                .join('rect')
                .attr('class', 'co-cell')
                .attr('x', d => d.col * cellSize)
                .attr('y', d => d.row * cellSize)
                .attr('width', cellSize - 1)
                .attr('height', cellSize - 1)
                .attr('rx', 2)
                .attr('fill', d => {
                    if (d.count === null) return getCSSVariable('--border-color');
                    if (d.count === 0) return getCSSVariable('--bg-secondary');
                    return colorScale(d.count);
                })
                .style('cursor', d => d.count > 0 ? 'pointer' : 'default')
                .on('mouseenter', function (event, d) {
                    if (!d.count) return;
                    d3.select(this).attr('opacity', 0.75);
                    const aliases = d.rowAliases && d.rowAliases.length ? ` (also: ${d.rowAliases.join(', ')})` : '';
                    tooltip.innerHTML = `<strong>${d.rowTag}${aliases} + ${d.colTag}</strong><br>${d.count} shared bookmark${d.count !== 1 ? 's' : ''}`;
                    tooltip.classList.add('visible');
                })
                .on('mousemove', function (event) {
                    tooltip.style.left = (event.pageX + 10) + 'px';
                    tooltip.style.top = (event.pageY + 10) + 'px';
                })
                .on('mouseleave', function () {
                    d3.select(this).attr('opacity', 1);
                    tooltip.classList.remove('visible');
                })
                .on('click', function (event, d) {
                    if (d.count > 0) window.location.href = `${BASE_PATH}/?tag=${encodeURIComponent(d.rowTag)}`;
                });

            // Count labels inside cells (only if there's room)
            if (cellSize >= 18) {
                g.selectAll('.co-count')
                    .data(matrix.filter(d => d.count > 0))
                    .join('text')
                    .attr('class', 'co-count')
                    .attr('x', d => d.col * cellSize + cellSize / 2)
                    .attr('y', d => d.row * cellSize + cellSize / 2 + 4)
                    .attr('text-anchor', 'middle')
                    .attr('pointer-events', 'none')
                    .attr('font-size', Math.min(10, cellSize * 0.38) + 'px')
                    .attr('fill', d => d.count > maxCount * 0.6 ? 'rgba(255,255,255,0.9)' : getCSSVariable('--text-secondary'))
                    .text(d => d.count);
            }

            const labelFontSize = Math.max(9, Math.min(12, cellSize * 0.55)) + 'px';

            // Row labels (left)
            g.selectAll('.co-row-label')
                .data(topTags)
                .join('text')
                .attr('class', 'co-row-label')
                .attr('x', -6)
                .attr('y', (d, i) => i * cellSize + cellSize / 2 + 4)
                .attr('text-anchor', 'end')
                .attr('font-size', labelFontSize)
                .attr('fill', getCSSVariable('--text-primary'))
                .style('cursor', 'pointer')
                .text(d => d.tag)
                .each(function(d) {
                    if (d.aliases && d.aliases.length) {
                        d3.select(this).append('title').text(`also: ${d.aliases.join(', ')}`);
                    }
                })
                .on('click', (event, d) => window.location.href = `${BASE_PATH}/?tag=${encodeURIComponent(d.tag)}`);

            // Column labels (top, rotated -45°)
            g.selectAll('.co-col-label')
                .data(topTags)
                .join('text')
                .attr('class', 'co-col-label')
                .attr('transform', (d, i) => `translate(${i * cellSize + cellSize / 2}, -6) rotate(-45)`)
                .attr('text-anchor', 'start')
                .attr('font-size', labelFontSize)
                .attr('fill', getCSSVariable('--text-primary'))
                .style('cursor', 'pointer')
                .text(d => d.tag)
                .each(function(d) {
                    if (d.aliases && d.aliases.length) {
                        d3.select(this).append('title').text(`also: ${d.aliases.join(', ')}`);
                    }
                })
                .on('click', (event, d) => window.location.href = `${BASE_PATH}/?tag=${encodeURIComponent(d.tag)}`);

            // Color legend
            const legendW = Math.min(130, matrixW * 0.55);
            const legendX = matrixW - legendW;
            const legendY = matrixH + 12;

            const defs = svg.append('defs');
            const grad = defs.append('linearGradient').attr('id', 'co-legend-grad');
            grad.append('stop').attr('offset', '0%').attr('stop-color', d3.interpolateBlues(0.05));
            grad.append('stop').attr('offset', '100%').attr('stop-color', d3.interpolateBlues(1));

            const lgnd = g.append('g').attr('transform', `translate(${legendX}, ${legendY})`);
            lgnd.append('rect').attr('width', legendW).attr('height', 6).attr('rx', 2).attr('fill', 'url(#co-legend-grad)');
            lgnd.append('text').attr('y', -3).attr('font-size', '9px').attr('fill', getCSSVariable('--text-secondary')).text('fewer');
            lgnd.append('text').attr('x', legendW).attr('y', -3).attr('text-anchor', 'end').attr('font-size', '9px').attr('fill', getCSSVariable('--text-secondary')).text('more co-occurrences');
        }

        // Render velocity chart
        function renderVelocityChart(data) {
            const container = document.getElementById('velocityChart');
            const parent = container.parentElement;
            parent.querySelector('.loading').style.display = 'none';
            container.style.display = 'block';

            const margin = { top: 20, right: 20, bottom: 30, left: 40 };
            const width = parent.clientWidth - margin.left - margin.right;
            const height = parent.clientHeight - margin.top - margin.bottom;

            // Clear existing
            d3.select(container).selectAll('*').remove();

            const svg = d3.select(container)
                .attr('width', width + margin.left + margin.right)
                .attr('height', height + margin.top + margin.bottom)
                .append('g')
                .attr('transform', `translate(${margin.left},${margin.top})`);

            const timeline = data.timeline;

            // Parse dates
            timeline.forEach(d => {
                d.date = new Date(d.date);
            });

            // Scales
            const x = d3.scaleTime()
                .domain(d3.extent(timeline, d => d.date))
                .range([0, width]);

            const y = d3.scaleLinear()
                .domain([0, d3.max(timeline, d => parseInt(d.count, 10))])
                .nice()
                .range([height, 0]);

            // Axes
            svg.append('g')
                .attr('class', 'axis')
                .attr('transform', `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(6).tickFormat(d3.timeFormat('%b %d')));

            svg.append('g')
                .attr('class', 'axis')
                .call(d3.axisLeft(y).ticks(5));

            // Bars
            const barWidth = Math.max(2, width / timeline.length - 1);
            const tooltip = document.getElementById('tooltip');

            svg.selectAll('.velocity-bar')
                .data(timeline)
                .join('rect')
                .attr('class', 'velocity-bar')
                .attr('x', d => x(d.date) - barWidth / 2)
                .attr('y', d => y(parseInt(d.count, 10)))
                .attr('width', barWidth)
                .attr('height', d => height - y(parseInt(d.count, 10)))
                .on('mouseenter', function (event, d) {
                    const count = parseInt(d.count, 10);
                    tooltip.innerHTML = `
                        <strong>${d.date.toLocaleDateString()}</strong><br>
                        ${count} bookmark${count !== 1 ? 's' : ''} added
                    `;
                    tooltip.classList.add('visible');
                })
                .on('mousemove', function (event) {
                    tooltip.style.left = (event.pageX + 10) + 'px';
                    tooltip.style.top = (event.pageY + 10) + 'px';
                })
                .on('mouseleave', function () {
                    tooltip.classList.remove('visible');
                });

            // Moving average line (7-day)
            const movingAverage = [];
            for (let i = 0; i < timeline.length; i++) {
                const start = Math.max(0, i - 6);
                const slice = timeline.slice(start, i + 1);
                const avg = slice.reduce((sum, d) => sum + parseInt(d.count, 10), 0) / slice.length;
                movingAverage.push({
                    date: timeline[i].date,
                    avg: avg
                });
            }

            const line = d3.line()
                .x(d => x(d.date))
                .y(d => y(d.avg))
                .curve(d3.curveMonotoneX);

            svg.append('path')
                .datum(movingAverage)
                .attr('class', 'velocity-line')
                .attr('d', line);
        }

        // Render tag activity as stacked area chart
        function renderTagEvolution(data) {
            const container = document.getElementById('tagEvolutionChart');
            const parent = container.parentElement;
            parent.querySelector('.loading').style.display = 'none';
            container.style.display = 'block';

            const margin = { top: 30, right: 120, bottom: 40, left: 50 };
            const width = parent.clientWidth - margin.left - margin.right;
            const height = parent.clientHeight - margin.top - margin.bottom;

            // Clear existing
            d3.select(container).selectAll('*').remove();

            const svg = d3.select(container)
                .attr('width', width + margin.left + margin.right)
                .attr('height', height + margin.top + margin.bottom)
                .append('g')
                .attr('transform', `translate(${margin.left},${margin.top})`);

            const tagData = data.tag_activity_heatmap || [];

            if (tagData.length === 0) {
                svg.append('text')
                    .attr('x', width / 2)
                    .attr('y', height / 2)
                    .attr('text-anchor', 'middle')
                    .attr('text-anchor', 'middle')
                    .attr('fill', getCSSVariable('--text-secondary'))
                    .text('Not enough data to display chart');
                return;
            }

            // Get all unique dates and sort them
            const allDates = new Set();
            tagData.forEach(tag => {
                tag.days.forEach(d => allDates.add(d.date));
            });
            const dates = Array.from(allDates).sort();

            if (dates.length === 0) {
                svg.append('text')
                    .attr('x', width / 2)
                    .attr('y', height / 2)
                    .attr('text-anchor', 'middle')
                    .attr('text-anchor', 'middle')
                    .attr('fill', getCSSVariable('--text-secondary'))
                    .text('No recent activity data');
                return;
            }

            // Create complete data series for each tag (fill in missing dates with 0)
            const series = tagData.map(tag => {
                const dateMap = new Map(tag.days.map(d => [d.date, d.count]));
                return {
                    tag: tag.tag,
                    values: dates.map(date => ({
                        date: date,
                        count: dateMap.get(date) || 0
                    }))
                };
            });

            // Parse date strings
            const parseDate = d3.timeParse('%Y-%m-%d');

            // Scales
            const x = d3.scaleTime()
                .domain([parseDate(dates[0]), parseDate(dates[dates.length - 1])])
                .range([0, width]);

            // Find max value for y scale
            const maxValue = d3.max(dates, date => {
                return d3.sum(series, s => {
                    const dataPoint = s.values.find(v => v.date === date);
                    return dataPoint ? dataPoint.count : 0;
                });
            });

            const y = d3.scaleLinear()
                .domain([0, maxValue])
                .nice()
                .range([height, 0]);

            // Color scale
            const colorScale = d3.scaleOrdinal()
                .domain(tagData.map(d => d.tag))
                .range(d3.schemeCategory10);

            // Stack the data
            const stack = d3.stack()
                .keys(series.map(s => s.tag))
                .value((d, key) => {
                    const tagSeries = series.find(s => s.tag === key);
                    const dataPoint = tagSeries.values.find(v => v.date === d);
                    return dataPoint ? dataPoint.count : 0;
                });

            const stackedData = stack(dates);

            // Area generator
            const area = d3.area()
                .x(d => x(parseDate(d.data)))
                .y0(d => y(d[0]))
                .y1(d => y(d[1]))
                .curve(d3.curveMonotoneX);

            // Draw areas
            const tooltip = document.getElementById('tooltip');

            svg.selectAll('.tag-area')
                .data(stackedData)
                .join('path')
                .attr('class', 'tag-area')
                .attr('fill', d => colorScale(d.key))
                .attr('d', area)
                .attr('opacity', 0.7)
                .on('mouseenter', function (event, d) {
                    d3.select(this).attr('opacity', 0.9);
                    const tagInfo = tagData.find(t => t.tag === d.key);
                    tooltip.innerHTML = `
                        <strong>${d.key}</strong><br>
                        Total: ${tagInfo.total} bookmarks
                    `;
                    tooltip.classList.add('visible');
                })
                .on('mousemove', function (event) {
                    tooltip.style.left = (event.pageX + 10) + 'px';
                    tooltip.style.top = (event.pageY + 10) + 'px';
                })
                .on('mouseleave', function () {
                    d3.select(this).attr('opacity', 0.7);
                    tooltip.classList.remove('visible');
                });

            // Axes
            svg.append('g')
                .attr('class', 'axis')
                .attr('transform', `translate(0,${height})`)
                .call(d3.axisBottom(x).ticks(6).tickFormat(d3.timeFormat('%b %d')));

            svg.append('g')
                .attr('class', 'axis')
                .call(d3.axisLeft(y).ticks(5));

            // Legend
            const legend = svg.append('g')
                .attr('transform', `translate(${width + 15}, 0)`);

            const legendItems = legend.selectAll('.tag-legend-item')
                .data(series)
                .join('g')
                .attr('class', 'tag-legend-item')
                .attr('transform', (d, i) => `translate(0, ${i * 20})`);

            legendItems.append('rect')
                .attr('width', 12)
                .attr('height', 12)
                .attr('fill', d => colorScale(d.tag))
                .attr('opacity', 0.7);

            legendItems.append('text')
                .attr('x', 18)
                .attr('y', 10)
                .attr('font-size', '11px')
                .attr('font-size', '11px')
                .attr('fill', getCSSVariable('--text-primary'))
                .text(d => d.tag);
        }

        // Update last updated timestamp
        function updateLastUpdated() {
            const now = new Date();
            document.getElementById('lastUpdated').textContent =
                `Last updated: ${now.toLocaleTimeString()}`;
        }

        // Auto-refresh disabled - uncomment if you want automatic updates
        // setInterval(fetchDashboardData, 60000);

        // Initial load
        fetchDashboardData();

        // Fullscreen toggle functionality
        function toggleFullscreen(panelId) {
            const panel = document.getElementById(panelId);
            panel.classList.toggle('fullscreen');

            // Re-render the visualization after fullscreen toggle
            setTimeout(() => {
                if (dashboardData) {
                    if (panelId === 'tagNetworkPanel') {
                        renderTagCooccurrenceMatrix(dashboardData);
                    } else if (panelId === 'velocityPanel') {
                        renderVelocityChart(dashboardData);
                    } else if (panelId === 'tagEvolutionPanel') {
                        renderTagEvolution(dashboardData);
                    }
                }
            }, 100);
        }

        // Allow ESC key to exit fullscreen
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                const fullscreenPanel = document.querySelector('.panel.fullscreen');
                if (fullscreenPanel) {
                    fullscreenPanel.classList.remove('fullscreen');
                    // Re-render after exiting fullscreen
                    setTimeout(() => {
                        if (dashboardData) {
                            if (fullscreenPanel.id === 'tagNetworkPanel') {
                                renderTagCooccurrenceMatrix(dashboardData);
                            } else if (fullscreenPanel.id === 'velocityPanel') {
                                renderVelocityChart(dashboardData);
                            } else if (fullscreenPanel.id === 'tagEvolutionPanel') {
                                renderTagEvolution(dashboardData);
                            }
                        }
                    }, 100);
                }
            }
        });
    </script>
    <?php render_nav_scripts(); ?>
</body>

</html>