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
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            overflow: hidden;
            height: 100vh;
            width: 100vw;
        }

        .dashboard-container {
            width: 1920px;
            height: 1080px;
            margin: 0 auto;
            padding: 20px;
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 20px;
        }

        /* Header with stats cards */
        .header {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 15px;
            margin-bottom: 10px;
        }

        .stat-card {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .stat-card .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #7f8c8d;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1;
        }

        .stat-card .subtext {
            font-size: 10px;
            color: #95a5a6;
            margin-top: 5px;
        }

        /* Main content grid */
        .content-grid {
            display: grid;
            grid-template-columns: 60% 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 20px;
            height: calc(1080px - 200px);
        }

        .panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 20px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .fullscreen-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 5px 10px;
            color: #7f8c8d;
            transition: all 0.2s;
            border-radius: 4px;
        }

        .fullscreen-btn:hover {
            background: #ecf0f1;
            color: #2c3e50;
        }

        .panel.fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            border-radius: 0;
            margin: 0;
        }

        .panel.fullscreen .panel-content {
            height: calc(100vh - 80px);
        }

        .panel-content {
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        /* Tag co-occurrence network - takes up full left side */
        #tagNetworkPanel {
            grid-column: 1;
            grid-row: 1 / span 2;
        }

        /* Velocity chart - top right */
        #velocityPanel {
            grid-column: 2;
            grid-row: 1;
        }

        /* Tag evolution - bottom right */
        #tagEvolutionPanel {
            grid-column: 2;
            grid-row: 2;
        }

        /* Network visualization styles */
        .network-container {
            width: 100%;
            height: 100%;
        }

        .network-node {
            cursor: pointer;
        }

        .network-node:hover circle {
            stroke: #3498db;
            stroke-width: 4px;
            filter: brightness(1.1);
        }

        .network-node:hover text {
            font-weight: 700;
            fill: #1a1a1a;
        }

        .network-node text {
            font-size: 11px;
            font-weight: 600;
            pointer-events: none;
            text-shadow: 0 1px 4px white, 0 0 10px white;
        }

        .network-link {
            stroke: #bdc3c7;
            stroke-opacity: 0.4;
        }

        .network-link.highlighted {
            stroke: #3498db;
            stroke-opacity: 0.8;
            stroke-width: 3px;
        }

        /* Chart styles */
        .chart-container {
            width: 100%;
            height: 100%;
        }

        .axis text {
            font-size: 10px;
            fill: #7f8c8d;
        }

        .axis line,
        .axis path {
            stroke: #ecf0f1;
        }

        .velocity-bar {
            fill: #3498db;
            transition: fill 0.2s;
        }

        .velocity-bar:hover {
            fill: #2980b9;
        }

        .velocity-line {
            fill: none;
            stroke: #e74c3c;
            stroke-width: 2;
        }

        .tag-area {
            transition: opacity 0.3s;
        }

        .tag-area:hover {
            opacity: 0.8;
            stroke: #2c3e50;
            stroke-width: 2px;
        }

        .tag-legend-item {
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .tag-legend-item:hover {
            opacity: 0.7;
        }

        .tag-legend-item.inactive {
            opacity: 0.3;
        }

        .tag-line {
            fill: none;
            stroke-width: 2;
            transition: all 0.3s;
        }

        .tag-line:hover {
            stroke-width: 3;
        }

        /* Loading state */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #95a5a6;
            font-size: 14px;
        }

        .loading::after {
            content: '...';
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes dots {
            0%, 20% { content: '.'; }
            40% { content: '..'; }
            60%, 100% { content: '...'; }
        }

        /* Tooltip */
        .tooltip {
            position: absolute;
            background: rgba(0, 0, 0, 0.85);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s;
            z-index: 1000;
            max-width: 250px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        .tooltip.visible {
            opacity: 1;
        }

        /* Nav link */
        .nav-link {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: #2c3e50;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: all 0.2s;
            z-index: 1000;
        }

        .nav-link:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        /* Last updated indicator */
        .last-updated {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.95);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 11px;
            color: #7f8c8d;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            z-index: 1000;
        }

        .last-updated .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #27ae60;
            margin-right: 6px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <a href="<?= $config['base_path'] ?>/" class="nav-link">← Back to Bookmarks</a>

    <div class="dashboard-container">
        <div class="header">
            <div class="stat-card">
                <div class="label">Total Bookmarks</div>
                <div class="value" id="stat-total">-</div>
                <div class="subtext" id="stat-total-sub"></div>
            </div>
            <div class="stat-card">
                <div class="label">Unique Tags</div>
                <div class="value" id="stat-tags">-</div>
                <div class="subtext" id="stat-tags-sub"></div>
            </div>
            <div class="stat-card">
                <div class="label">Archived</div>
                <div class="value" id="stat-archived">-</div>
                <div class="subtext" id="stat-archived-sub"></div>
            </div>
            <div class="stat-card">
                <div class="label">With Descriptions</div>
                <div class="value" id="stat-descriptions">-</div>
                <div class="subtext" id="stat-descriptions-sub"></div>
            </div>
            <div class="stat-card">
                <div class="label">30-Day Velocity</div>
                <div class="value" id="stat-velocity">-</div>
                <div class="subtext" id="stat-velocity-sub"></div>
            </div>
            <div class="stat-card">
                <div class="label">Active Days</div>
                <div class="value" id="stat-active">-</div>
                <div class="subtext" id="stat-active-sub"></div>
            </div>
        </div>

        <div class="content-grid">
            <div class="panel" id="tagNetworkPanel">
                <div class="panel-title">
                    <span>Tag Co-occurrence Network</span>
                    <button class="fullscreen-btn" onclick="toggleFullscreen('tagNetworkPanel')" title="Toggle fullscreen">⛶</button>
                </div>
                <div class="panel-content">
                    <div class="loading">Loading tag network</div>
                    <svg class="network-container" id="tagNetwork"></svg>
                </div>
            </div>

            <div class="panel" id="velocityPanel">
                <div class="panel-title">
                    <span>Bookmarking Velocity (90 Days)</span>
                    <button class="fullscreen-btn" onclick="toggleFullscreen('velocityPanel')" title="Toggle fullscreen">⛶</button>
                </div>
                <div class="panel-content">
                    <div class="loading">Loading velocity data</div>
                    <svg class="chart-container" id="velocityChart"></svg>
                </div>
            </div>

            <div class="panel" id="tagEvolutionPanel">
                <div class="panel-title">
                    <span>Tag Activity Trends (Daily)</span>
                    <button class="fullscreen-btn" onclick="toggleFullscreen('tagEvolutionPanel')" title="Toggle fullscreen">⛶</button>
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
            renderTagNetwork(data);
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
            const velocity30 = last30Days.reduce((sum, d) => sum + d.count, 0);
            const avgPerDay = last30Days.length > 0 ? (velocity30 / last30Days.length).toFixed(1) : 0;
            document.getElementById('stat-velocity').textContent = velocity30;
            document.getElementById('stat-velocity-sub').textContent = `${avgPerDay} per day avg`;

            // Active days
            const activeDays = timeline.filter(d => d.count > 0).length;
            const totalDays = timeline.length;
            const activePercent = totalDays > 0 ? Math.round((activeDays / totalDays) * 100) : 0;
            document.getElementById('stat-active').textContent = activeDays;
            document.getElementById('stat-active-sub').textContent = `${activePercent}% of ${totalDays} days`;
        }

        // Render tag co-occurrence network
        function renderTagNetwork(data) {
            const container = document.getElementById('tagNetwork');
            const parent = container.parentElement;
            parent.querySelector('.loading').style.display = 'none';
            container.style.display = 'block';

            const width = parent.clientWidth;
            const height = parent.clientHeight;

            // Clear existing
            d3.select(container).selectAll('*').remove();

            const svg = d3.select(container)
                .attr('width', width)
                .attr('height', height);

            // Detect if we're in fullscreen mode
            const isFullscreen = parent.closest('.panel').classList.contains('fullscreen');
            const area = width * height;

            // Prepare nodes and links
            const tagStats = data.tag_stats; // Use all tags from API
            const nodes = tagStats.map(t => ({
                id: t.tag.toLowerCase(),
                label: t.tag,
                count: t.count,
                first_seen: t.first_seen
            }));

            const links = data.tag_cooccurrence
                .map(c => ({
                    source: c.source,
                    target: c.target,
                    value: c.count
                }));

            // Color scale based on count
            const countExtent = d3.extent(nodes, d => d.count);
            const colorScale = d3.scaleSequential(d3.interpolateBlues)
                .domain(countExtent);

            // Adaptive size scale based on space available
            const nodeSizeRange = isFullscreen ? [8, 30] : [6, 20];
            const sizeScale = d3.scaleSqrt()
                .domain(countExtent)
                .range(nodeSizeRange);

            // Adaptive margins and sizing based on mode
            const margin = isFullscreen ? 60 : 30;
            const textPadding = isFullscreen ? 15 : 10;
            const fontSize = isFullscreen ? '11px' : '9px';

            // Draw links
            const link = svg.append('g')
                .selectAll('line')
                .data(links)
                .join('line')
                .attr('class', 'network-link')
                .attr('stroke-width', d => Math.sqrt(d.value));

            // Draw nodes
            const node = svg.append('g')
                .selectAll('g')
                .data(nodes)
                .join('g')
                .attr('class', 'network-node')
                .call(d3.drag()
                    .on('start', dragstarted)
                    .on('drag', dragged)
                    .on('end', dragended));

            node.append('circle')
                .attr('r', d => sizeScale(d.count))
                .attr('fill', d => colorScale(d.count))
                .attr('stroke', '#fff')
                .attr('stroke-width', 2);

            node.append('text')
                .text(d => d.label)
                .attr('x', 0)
                .attr('y', d => sizeScale(d.count) + (isFullscreen ? 15 : 12))
                .attr('text-anchor', 'middle')
                .attr('fill', '#2c3e50')
                .attr('font-size', fontSize);

            // Tooltip and click handling
            const tooltip = document.getElementById('tooltip');
            node.on('mouseenter', function(event, d) {
                tooltip.innerHTML = `
                    <strong>${d.label}</strong><br>
                    Count: ${d.count} bookmarks<br>
                    First used: ${new Date(d.first_seen).toLocaleDateString()}<br>
                    <em style="font-size: 10px; color: #95a5a6;">Click to view bookmarks</em>
                `;
                tooltip.classList.add('visible');

                // Highlight connected links
                link.classed('highlighted', l =>
                    l.source.id === d.id || l.target.id === d.id
                );
            })
            .on('mousemove', function(event) {
                tooltip.style.left = (event.pageX + 10) + 'px';
                tooltip.style.top = (event.pageY + 10) + 'px';
            })
            .on('mouseleave', function() {
                tooltip.classList.remove('visible');
                link.classed('highlighted', false);
            })
            .on('click', function(event, d) {
                // Navigate to tag page
                window.location.href = `${BASE_PATH}/?tag=${encodeURIComponent(d.label)}`;
            });

            // Measure actual text widths for collision detection
            const tempSvg = svg.append('g').attr('class', 'temp-measure');
            nodes.forEach(d => {
                const text = tempSvg.append('text')
                    .text(d.label)
                    .attr('font-size', fontSize)
                    .attr('font-weight', '600');
                d.textWidth = text.node().getComputedTextLength();
                text.remove();
            });
            tempSvg.remove();

            // Calculate collision radius for each node (node radius + text space)
            nodes.forEach(d => {
                d.collisionRadius = Math.max(
                    sizeScale(d.count) + textPadding,
                    (d.textWidth / 2) + textPadding
                );
            });

            // Adaptive force parameters based on mode
            const linkDistance = isFullscreen ? 40 : 25;
            const chargeStrength = isFullscreen ? 0.8 : 0.5;
            const centerStrength = isFullscreen ? 0.05 : 0.15;
            const collisionStrength = isFullscreen ? 1 : 0.9;

            // Create force simulation with very strong collision prevention
            const simulation = d3.forceSimulation(nodes)
                .force('link', d3.forceLink(links)
                    .id(d => d.id)
                    .distance(d => {
                        // Adaptive distance based on node sizes and mode
                        const source = nodes.find(n => n.id === d.source.id || n.id === d.source);
                        const target = nodes.find(n => n.id === d.target.id || n.id === d.target);
                        const baseDistance = (source?.collisionRadius || 30) + (target?.collisionRadius || 30);
                        return baseDistance + linkDistance;
                    })
                    .strength(isFullscreen ? 0.5 : 0.4))
                .force('charge', d3.forceManyBody()
                    .strength(d => -Math.pow(d.collisionRadius, 2) * chargeStrength)
                    .distanceMax(isFullscreen ? 400 : 250))
                .force('collision', d3.forceCollide()
                    .radius(d => d.collisionRadius)
                    .strength(collisionStrength)
                    .iterations(5))
                .force('center', d3.forceCenter(width / 2, height / 2).strength(centerStrength))
                .force('boundary', () => {
                    // Soft boundary force to prevent edge-hugging in widget mode
                    if (!isFullscreen) {
                        nodes.forEach(d => {
                            const edgeBuffer = 40;
                            const xPush = 0.5; // Strength of push
                            const yPush = 0.5;

                            // Push away from left edge
                            if (d.x < edgeBuffer) d.vx += xPush;
                            // Push away from right edge
                            if (d.x > width - edgeBuffer) d.vx -= xPush;
                            // Push away from top edge
                            if (d.y < edgeBuffer) d.vy += yPush;
                            // Push away from bottom edge
                            if (d.y > height - edgeBuffer) d.vy -= yPush;
                        });
                    }
                })
                .velocityDecay(0.6)
                .alphaDecay(0.015)
                .on('tick', ticked)
                .on('end', () => {
                    console.log('Simulation complete');
                });

            // Track last update time to prevent constant re-rendering
            let lastUpdate = Date.now();
            let tickCount = 0;

            function ticked() {
                tickCount++;

                // Only update DOM every 2 ticks for performance
                if (tickCount % 2 !== 0 && simulation.alpha() > 0.1) return;

                // Gentle boundary constraints - keep nodes mostly in view
                nodes.forEach(d => {
                    const buffer = 20; // Small buffer to keep nodes visible
                    d.x = Math.max(buffer, Math.min(width - buffer, d.x));
                    d.y = Math.max(buffer, Math.min(height - buffer, d.y));
                });

                link
                    .attr('x1', d => d.source.x)
                    .attr('y1', d => d.source.y)
                    .attr('x2', d => d.target.x)
                    .attr('y2', d => d.target.y);

                node.attr('transform', d => `translate(${d.x},${d.y})`);

                // Force stop after settling completely
                if (simulation.alpha() < 0.005) {
                    simulation.stop();
                    console.log('Force stopped at alpha:', simulation.alpha());
                }
            }

            // Drag functions
            function dragstarted(event, d) {
                if (!event.active) simulation.alphaTarget(0.3).restart();
                d.fx = d.x;
                d.fy = d.y;
            }

            function dragged(event, d) {
                d.fx = event.x;
                d.fy = event.y;
            }

            function dragended(event, d) {
                if (!event.active) simulation.alphaTarget(0);
                d.fx = null;
                d.fy = null;
            }
        }

        // Render velocity chart
        function renderVelocityChart(data) {
            const container = document.getElementById('velocityChart');
            const parent = container.parentElement;
            parent.querySelector('.loading').style.display = 'none';
            container.style.display = 'block';

            const margin = {top: 20, right: 20, bottom: 30, left: 40};
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
                .domain([0, d3.max(timeline, d => d.count)])
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
                .attr('y', d => y(d.count))
                .attr('width', barWidth)
                .attr('height', d => height - y(d.count))
                .on('mouseenter', function(event, d) {
                    tooltip.innerHTML = `
                        <strong>${d.date.toLocaleDateString()}</strong><br>
                        ${d.count} bookmark${d.count !== 1 ? 's' : ''} added
                    `;
                    tooltip.classList.add('visible');
                })
                .on('mousemove', function(event) {
                    tooltip.style.left = (event.pageX + 10) + 'px';
                    tooltip.style.top = (event.pageY + 10) + 'px';
                })
                .on('mouseleave', function() {
                    tooltip.classList.remove('visible');
                });

            // Moving average line (7-day)
            const movingAverage = [];
            for (let i = 0; i < timeline.length; i++) {
                const start = Math.max(0, i - 6);
                const slice = timeline.slice(start, i + 1);
                const avg = slice.reduce((sum, d) => sum + d.count, 0) / slice.length;
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

            const margin = {top: 30, right: 120, bottom: 40, left: 50};
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
                    .attr('fill', '#95a5a6')
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
                    .attr('fill', '#95a5a6')
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
                .on('mouseenter', function(event, d) {
                    d3.select(this).attr('opacity', 0.9);
                    const tagInfo = tagData.find(t => t.tag === d.key);
                    tooltip.innerHTML = `
                        <strong>${d.key}</strong><br>
                        Total: ${tagInfo.total} bookmarks
                    `;
                    tooltip.classList.add('visible');
                })
                .on('mousemove', function(event) {
                    tooltip.style.left = (event.pageX + 10) + 'px';
                    tooltip.style.top = (event.pageY + 10) + 'px';
                })
                .on('mouseleave', function() {
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
                .attr('fill', '#2c3e50')
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
                        renderTagNetwork(dashboardData);
                    } else if (panelId === 'velocityPanel') {
                        renderVelocityChart(dashboardData);
                    } else if (panelId === 'tagEvolutionPanel') {
                        renderTagEvolution(dashboardData);
                    }
                }
            }, 100);
        }

        // Allow ESC key to exit fullscreen
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const fullscreenPanel = document.querySelector('.panel.fullscreen');
                if (fullscreenPanel) {
                    fullscreenPanel.classList.remove('fullscreen');
                    // Re-render after exiting fullscreen
                    setTimeout(() => {
                        if (dashboardData) {
                            if (fullscreenPanel.id === 'tagNetworkPanel') {
                                renderTagNetwork(dashboardData);
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
</body>
</html>
