<?php
/**
 * Tag Network Visualization Admin Page
 * Full-page exploration of tag relationships at scale
 */

// Load configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die('Error: config.php not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/tags.php';

// Set timezone
if (isset($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Require admin authentication
require_admin($config);

$isLoggedIn = is_logged_in();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tag Network - <?= htmlspecialchars($config['site_title']) ?></title>
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <?php render_nav_styles(); ?>
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* Tag Network Page Styles */
        .network-page {
            display: flex;
            height: calc(100vh - 70px);
            overflow: hidden;
        }

        .network-controls {
            width: 280px;
            min-width: 280px;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .control-section {
            background: var(--bg-tertiary);
            border-radius: 12px;
            padding: 16px;
        }

        .control-section h3 {
            margin: 0 0 12px 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .control-group {
            margin-bottom: 12px;
        }

        .control-group:last-child {
            margin-bottom: 0;
        }

        .control-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .control-value {
            font-weight: 600;
            color: var(--primary);
        }

        .control-slider {
            width: 100%;
            height: 6px;
            -webkit-appearance: none;
            appearance: none;
            background: var(--border-color);
            border-radius: 3px;
            outline: none;
        }

        .control-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 16px;
            height: 16px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
            transition: transform 0.15s ease;
        }

        .control-slider::-webkit-slider-thumb:hover {
            transform: scale(1.2);
        }

        .control-slider::-moz-range-thumb {
            width: 16px;
            height: 16px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
            border: none;
        }

        .control-select {
            width: 100%;
            padding: 8px 12px;
            font-size: 13px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
            cursor: pointer;
        }

        .control-input {
            width: 100%;
            padding: 8px 12px;
            font-size: 13px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
        }

        .control-input::placeholder {
            color: var(--text-tertiary);
        }

        .control-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-primary);
            cursor: pointer;
        }

        .control-checkbox input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }

        .zoom-controls {
            display: flex;
            gap: 8px;
        }

        .zoom-btn {
            flex: 1;
            padding: 8px;
            font-size: 16px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .zoom-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .export-controls {
            display: flex;
            gap: 8px;
        }

        .export-btn {
            flex: 1;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .export-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .stats-panel {
            background: var(--bg-tertiary);
            border-radius: 12px;
            padding: 16px;
            margin-top: auto;
        }

        .stats-panel h3 {
            margin: 0 0 12px 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .stat-item {
            text-align: center;
            padding: 8px;
            background: var(--bg-secondary);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-tertiary);
            margin-top: 2px;
        }

        .selected-tag-info {
            padding: 12px;
            background: var(--bg-secondary);
            border-radius: 8px;
            display: none;
        }

        .selected-tag-info.visible {
            display: block;
        }

        .selected-tag-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .selected-tag-stats {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .selected-tag-actions {
            margin-top: 8px;
            display: flex;
            gap: 8px;
        }

        .selected-tag-actions a {
            flex: 1;
            padding: 6px 10px;
            font-size: 11px;
            text-align: center;
            text-decoration: none;
            border-radius: 6px;
            background: var(--primary);
            color: white;
            transition: opacity 0.15s ease;
        }

        .selected-tag-actions a:hover {
            opacity: 0.9;
        }

        /* Main visualization area */
        .network-main {
            flex: 1;
            position: relative;
            background: var(--bg-primary);
            overflow: hidden;
        }

        .network-svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        .network-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: var(--text-secondary);
        }

        .network-loading .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-color);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 12px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Network elements */
        .network-link {
            stroke: var(--border-color);
            stroke-opacity: 0.4;
            fill: none;
        }

        .network-link.highlighted {
            stroke: var(--primary);
            stroke-opacity: 0.8;
            stroke-width: 2px;
        }

        .network-node circle {
            cursor: pointer;
            stroke: var(--bg-secondary);
            stroke-width: 2px;
            transition: stroke-width 0.15s ease;
        }

        .network-node:hover circle {
            stroke-width: 4px;
        }

        .network-node.selected circle {
            stroke: var(--primary);
            stroke-width: 4px;
        }

        .network-node.dimmed circle {
            opacity: 0.3;
        }

        .network-node.dimmed text {
            opacity: 0.3;
        }

        .network-node text {
            font-size: 10px;
            font-weight: 600;
            fill: var(--text-primary);
            text-shadow: 0 1px 4px var(--bg-secondary), 0 0 10px var(--bg-secondary);
            pointer-events: none;
        }

        .network-node.searched text {
            fill: var(--accent-amber);
            font-weight: 700;
        }

        .network-node.searched circle {
            stroke: var(--accent-amber);
            stroke-width: 4px;
        }

        /* Group nodes */
        .group-node circle {
            stroke-dasharray: 4 2;
        }

        .group-node .group-count {
            font-size: 9px;
            fill: var(--text-secondary);
        }

        /* Cluster hulls */
        .cluster-hull {
            fill-opacity: 0.08;
            stroke-width: 2;
            stroke-opacity: 0.3;
            stroke-dasharray: 6 3;
        }

        /* Tooltip */
        .network-tooltip {
            position: absolute;
            background: var(--bg-secondary);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px;
            font-size: 12px;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s ease;
            z-index: 100;
            max-width: 250px;
            box-shadow: var(--shadow-lg);
        }

        .network-tooltip.visible {
            opacity: 1;
        }

        .network-tooltip strong {
            color: var(--text-primary);
        }

        .network-tooltip .tip-stats {
            margin-top: 6px;
            color: var(--text-secondary);
        }

        .network-tooltip .tip-hint {
            margin-top: 8px;
            font-size: 10px;
            color: var(--text-tertiary);
            font-style: italic;
        }

        /* Filter status bar */
        .filter-status {
            position: absolute;
            top: 16px;
            left: 16px;
            background: var(--bg-secondary);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            color: var(--text-secondary);
            z-index: 10;
        }

        .filter-status strong {
            color: var(--primary);
        }

        /* Legend */
        .network-legend {
            position: absolute;
            bottom: 16px;
            right: 16px;
            background: var(--bg-secondary);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px;
            font-size: 11px;
            z-index: 10;
        }

        .legend-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .legend-items {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .legend-label {
            color: var(--text-secondary);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .network-page {
                flex-direction: column;
            }

            .network-controls {
                width: 100%;
                min-width: auto;
                height: auto;
                max-height: 40vh;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }

            .stats-panel {
                margin-top: 0;
            }
        }
    </style>
</head>

<body>
    <?php render_nav($config, $isLoggedIn, 'tag-network', 'Tag Network'); ?>

    <div class="network-page">
        <!-- Controls Sidebar -->
        <aside class="network-controls">
            <!-- Filtering Controls -->
            <div class="control-section">
                <h3>Filters</h3>

                <div class="control-group">
                    <label class="control-label">
                        <span>Min Tag Count</span>
                        <span class="control-value" id="minCountValue">2</span>
                    </label>
                    <input type="range" class="control-slider" id="minCount" min="1" max="20" value="2">
                </div>

                <div class="control-group">
                    <label class="control-label">
                        <span>Min Co-occurrence</span>
                        <span class="control-value" id="minCooccurrenceValue">1</span>
                    </label>
                    <input type="range" class="control-slider" id="minCooccurrence" min="1" max="10" value="1">
                </div>

                <div class="control-group">
                    <label class="control-label">
                        <span>Max Tags</span>
                        <span class="control-value" id="maxTagsValue">75</span>
                    </label>
                    <input type="range" class="control-slider" id="maxTags" min="10" max="350" value="75">
                </div>

                <div class="control-group">
                    <label class="control-label">
                        <span>Tag Prefix</span>
                    </label>
                    <select class="control-select" id="prefixFilter">
                        <option value="all">All Tags</option>
                        <option value="tag">Regular Tags Only</option>
                        <option value="person:">person: Tags Only</option>
                        <option value="via:">via: Tags Only</option>
                    </select>
                </div>

                <div class="control-group">
                    <input type="text" class="control-input" id="searchBox" placeholder="Search tags...">
                </div>
            </div>

            <!-- Zoom Controls -->
            <div class="control-section">
                <h3>Navigation</h3>
                <div class="zoom-controls">
                    <button class="zoom-btn" id="zoomIn" title="Zoom In">+</button>
                    <button class="zoom-btn" id="zoomOut" title="Zoom Out">-</button>
                    <button class="zoom-btn" id="zoomReset" title="Reset Zoom">Reset</button>
                </div>
            </div>

            <!-- Clustering Controls -->
            <div class="control-section">
                <h3>Clustering</h3>

                <div class="control-group">
                    <label class="control-label">
                        <span>Cluster Strength</span>
                        <span class="control-value" id="clusterStrengthValue">0.5</span>
                    </label>
                    <input type="range" class="control-slider" id="clusterStrength" min="0" max="1" step="0.1" value="0.5">
                </div>

                <div class="control-group">
                    <label class="control-checkbox">
                        <input type="checkbox" id="showClusters">
                        Show Cluster Boundaries
                    </label>
                </div>

                <div class="control-group">
                    <label class="control-checkbox">
                        <input type="checkbox" id="showPrefixGroups">
                        Group by Prefix
                    </label>
                </div>
            </div>

            <!-- Export Controls -->
            <div class="control-section">
                <h3>Export</h3>
                <div class="export-controls">
                    <button class="export-btn" id="exportPng">PNG</button>
                    <button class="export-btn" id="exportSvg">SVG</button>
                </div>
            </div>

            <!-- Stats Panel -->
            <div class="stats-panel">
                <h3>Network Stats</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value" id="totalTagsStat">-</div>
                        <div class="stat-label">Total Tags</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="visibleTagsStat">-</div>
                        <div class="stat-label">Visible</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="linksStat">-</div>
                        <div class="stat-label">Links</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="clustersStat">-</div>
                        <div class="stat-label">Clusters</div>
                    </div>
                </div>

                <!-- Selected Tag Info -->
                <div class="selected-tag-info" id="selectedTagInfo">
                    <div class="selected-tag-name" id="selectedTagName"></div>
                    <div class="selected-tag-stats" id="selectedTagStats"></div>
                    <div class="selected-tag-actions">
                        <a href="#" id="viewBookmarksLink">View Bookmarks</a>
                        <a href="#" id="manageTagLink">Manage Tag</a>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Visualization Area -->
        <main class="network-main">
            <div class="network-loading" id="networkLoading">
                <div class="spinner"></div>
                <div>Loading tag network...</div>
            </div>

            <svg class="network-svg" id="networkSvg"></svg>

            <div class="filter-status" id="filterStatus">
                Showing <strong id="visibleCount">0</strong> of <strong id="totalCount">0</strong> tags
            </div>

            <div class="network-legend" id="networkLegend" style="display: none;">
                <div class="legend-title">Clusters</div>
                <div class="legend-items" id="legendItems"></div>
            </div>

            <div class="network-tooltip" id="networkTooltip"></div>
        </main>
    </div>

    <script>
        const BASE_PATH = <?= json_encode($config['base_path']) ?>;

        // Global state
        let rawData = null;
        let filteredData = null;
        let simulation = null;
        let svg = null;
        let container = null;
        let zoom = null;
        let selectedNode = null;

        // Filter state
        const filterState = {
            minCount: 2,
            minCooccurrence: 1,
            maxTags: 75,
            prefixFilter: 'all',
            searchQuery: '',
            clusterStrength: 0.5,
            showClusters: false,
            showPrefixGroups: false
        };

        // Cluster colors
        const clusterColors = d3.schemeTableau10;

        // Helper to get CSS variable value
        function getCSSVariable(name) {
            return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        }

        // Parse tag prefix
        function parseTagPrefix(tag) {
            if (tag.startsWith('person:')) return { prefix: 'person:', name: tag.slice(7) };
            if (tag.startsWith('via:')) return { prefix: 'via:', name: tag.slice(4) };
            return { prefix: 'tag', name: tag };
        }

        // Fetch network data from API
        async function fetchNetworkData() {
            try {
                const response = await fetch(`${BASE_PATH}/api.php?action=tag_network_data`);
                if (!response.ok) throw new Error('Failed to fetch data');
                rawData = await response.json();

                document.getElementById('totalTagsStat').textContent = rawData.tags.length;
                document.getElementById('totalCount').textContent = rawData.tags.length;

                applyFilters();
                document.getElementById('networkLoading').style.display = 'none';
            } catch (error) {
                console.error('Error fetching network data:', error);
                document.getElementById('networkLoading').innerHTML = `
                    <div style="color: var(--accent-red);">Error loading data</div>
                    <div style="margin-top: 8px; font-size: 12px;">${error.message}</div>
                `;
            }
        }

        // Apply filters and prepare data
        function applyFilters() {
            if (!rawData) return;

            // Filter tags
            let tags = rawData.tags.filter(t => t.count >= filterState.minCount);

            // Filter by prefix
            if (filterState.prefixFilter !== 'all') {
                if (filterState.prefixFilter === 'tag') {
                    tags = tags.filter(t => !t.tag.includes(':'));
                } else {
                    tags = tags.filter(t => t.tag.toLowerCase().startsWith(filterState.prefixFilter));
                }
            }

            // Sort by count and limit
            tags.sort((a, b) => b.count - a.count);
            tags = tags.slice(0, filterState.maxTags);

            // Create node map for filtering links
            const tagSet = new Set(tags.map(t => t.tag.toLowerCase()));

            // Filter links
            let links = rawData.cooccurrence.filter(l =>
                l.count >= filterState.minCooccurrence &&
                tagSet.has(l.source.toLowerCase()) &&
                tagSet.has(l.target.toLowerCase())
            );

            // Prepare nodes
            const nodes = tags.map(t => {
                const parsed = parseTagPrefix(t.tag);
                return {
                    id: t.tag.toLowerCase(),
                    label: t.tag,
                    count: t.count,
                    first_seen: t.first_seen,
                    prefix: parsed.prefix,
                    shortName: parsed.name,
                    cluster: 0
                };
            });

            // Detect clusters using simple community detection
            detectClusters(nodes, links);

            filteredData = { nodes, links };

            // Update stats
            document.getElementById('visibleTagsStat').textContent = nodes.length;
            document.getElementById('visibleCount').textContent = nodes.length;
            document.getElementById('linksStat').textContent = links.length;

            // Count unique clusters
            const clusters = new Set(nodes.map(n => n.cluster));
            document.getElementById('clustersStat').textContent = clusters.size;

            renderNetwork();
        }

        // Simple community detection based on co-occurrence
        function detectClusters(nodes, links) {
            // Build adjacency list
            const adjacency = new Map();
            nodes.forEach(n => adjacency.set(n.id, new Set()));

            links.forEach(l => {
                const source = l.source.toLowerCase();
                const target = l.target.toLowerCase();
                if (adjacency.has(source) && adjacency.has(target)) {
                    adjacency.get(source).add(target);
                    adjacency.get(target).add(source);
                }
            });

            // Simple greedy clustering
            const visited = new Set();
            let clusterId = 0;
            const nodeMap = new Map(nodes.map(n => [n.id, n]));

            // Start with highest-degree nodes
            const nodesByDegree = [...adjacency.entries()]
                .sort((a, b) => b[1].size - a[1].size)
                .map(([id]) => id);

            for (const startId of nodesByDegree) {
                if (visited.has(startId)) continue;

                // BFS to find connected component with high connectivity
                const queue = [startId];
                const cluster = [];

                while (queue.length > 0 && cluster.length < nodes.length / 3) {
                    const nodeId = queue.shift();
                    if (visited.has(nodeId)) continue;

                    visited.add(nodeId);
                    cluster.push(nodeId);

                    // Sort neighbors by connection strength to current cluster
                    const neighbors = [...adjacency.get(nodeId)]
                        .filter(n => !visited.has(n))
                        .map(n => {
                            const connections = cluster.filter(c => adjacency.get(n).has(c)).length;
                            return { id: n, connections };
                        })
                        .sort((a, b) => b.connections - a.connections);

                    // Add strongly connected neighbors
                    for (const neighbor of neighbors) {
                        if (neighbor.connections >= Math.min(2, cluster.length / 2)) {
                            queue.push(neighbor.id);
                        }
                    }
                }

                // Assign cluster ID
                cluster.forEach(nodeId => {
                    const node = nodeMap.get(nodeId);
                    if (node) node.cluster = clusterId;
                });

                if (cluster.length > 0) clusterId++;
            }

            // Assign remaining unvisited nodes
            nodes.forEach(n => {
                if (!visited.has(n.id)) {
                    n.cluster = clusterId++;
                }
            });
        }

        // Render the network visualization
        function renderNetwork() {
            if (!filteredData) return;

            const container_el = document.getElementById('networkSvg');
            const rect = container_el.getBoundingClientRect();
            const width = rect.width;
            const height = rect.height;

            // Clear existing
            d3.select(container_el).selectAll('*').remove();

            svg = d3.select(container_el)
                .attr('width', width)
                .attr('height', height);

            // Create container for zoom
            container = svg.append('g').attr('class', 'network-container');

            // Add cluster hulls layer
            const hullsLayer = container.append('g').attr('class', 'hulls-layer');

            // Add links layer
            const linksLayer = container.append('g').attr('class', 'links-layer');

            // Add nodes layer
            const nodesLayer = container.append('g').attr('class', 'nodes-layer');

            // Setup zoom
            zoom = d3.zoom()
                .scaleExtent([0.1, 5])
                .on('zoom', ({ transform }) => {
                    container.attr('transform', transform);
                });

            svg.call(zoom);

            // Prepare data
            const nodes = filteredData.nodes.map(d => ({ ...d }));
            const links = filteredData.links.map(d => ({
                source: d.source.toLowerCase(),
                target: d.target.toLowerCase(),
                value: d.count
            }));

            // Color scales
            const countExtent = d3.extent(nodes, d => d.count);
            const sizeScale = d3.scaleSqrt()
                .domain(countExtent)
                .range([6, 28]);

            // Get unique clusters for coloring
            const clusters = [...new Set(nodes.map(n => n.cluster))].sort((a, b) => a - b);

            // Update legend
            updateLegend(clusters);

            // Draw links
            const link = linksLayer.selectAll('line')
                .data(links)
                .join('line')
                .attr('class', 'network-link')
                .attr('stroke-width', d => Math.sqrt(d.value) * 0.8);

            // Draw nodes
            const node = nodesLayer.selectAll('g')
                .data(nodes)
                .join('g')
                .attr('class', 'network-node')
                .call(d3.drag()
                    .on('start', dragstarted)
                    .on('drag', dragged)
                    .on('end', dragended));

            node.append('circle')
                .attr('r', d => sizeScale(d.count))
                .attr('fill', d => clusterColors[d.cluster % clusterColors.length]);

            node.append('text')
                .text(d => d.label)
                .attr('x', 0)
                .attr('y', d => sizeScale(d.count) + 12)
                .attr('text-anchor', 'middle');

            // Interaction handlers
            const tooltip = document.getElementById('networkTooltip');

            node.on('mouseenter', function(event, d) {
                    // Show tooltip
                    tooltip.innerHTML = `
                        <strong>${d.label}</strong>
                        <div class="tip-stats">
                            Count: ${d.count} bookmarks<br>
                            First used: ${new Date(d.first_seen).toLocaleDateString()}<br>
                            Cluster: ${d.cluster + 1}
                        </div>
                        <div class="tip-hint">Click to select, double-click for bookmarks</div>
                    `;
                    tooltip.classList.add('visible');

                    // Highlight connected links
                    link.classed('highlighted', l =>
                        l.source.id === d.id || l.target.id === d.id
                    );

                    // Dim unconnected nodes
                    const connectedIds = new Set([d.id]);
                    links.forEach(l => {
                        if (l.source.id === d.id || l.source === d.id) connectedIds.add(l.target.id || l.target);
                        if (l.target.id === d.id || l.target === d.id) connectedIds.add(l.source.id || l.source);
                    });

                    node.classed('dimmed', n => !connectedIds.has(n.id));
                })
                .on('mousemove', function(event) {
                    const rect = container_el.getBoundingClientRect();
                    tooltip.style.left = (event.clientX - rect.left + 15) + 'px';
                    tooltip.style.top = (event.clientY - rect.top + 15) + 'px';
                })
                .on('mouseleave', function() {
                    tooltip.classList.remove('visible');
                    link.classed('highlighted', false);
                    node.classed('dimmed', false);
                })
                .on('click', function(event, d) {
                    event.stopPropagation();
                    selectNode(d, node);
                })
                .on('dblclick', function(event, d) {
                    event.stopPropagation();
                    window.location.href = `${BASE_PATH}/?tag=${encodeURIComponent(d.label)}`;
                });

            // Click on background to deselect
            svg.on('click', () => {
                selectNode(null, node);
            });

            // Apply search highlighting
            applySearchHighlight(node);

            // Calculate collision radii
            nodes.forEach(d => {
                d.collisionRadius = sizeScale(d.count) + 20;
            });

            // Create force simulation
            const clusterForce = filterState.clusterStrength;

            simulation = d3.forceSimulation(nodes)
                .force('link', d3.forceLink(links)
                    .id(d => d.id)
                    .distance(d => {
                        const source = nodes.find(n => n.id === (d.source.id || d.source));
                        const target = nodes.find(n => n.id === (d.target.id || d.target));
                        return (source?.collisionRadius || 30) + (target?.collisionRadius || 30) + 20;
                    })
                    .strength(0.3))
                .force('charge', d3.forceManyBody()
                    .strength(d => -Math.pow(d.collisionRadius, 2) * 0.5)
                    .distanceMax(400))
                .force('collision', d3.forceCollide()
                    .radius(d => d.collisionRadius)
                    .strength(0.9)
                    .iterations(3))
                .force('center', d3.forceCenter(width / 2, height / 2).strength(0.05))
                .force('cluster', clusterForceFunc(nodes, clusters, clusterForce, width, height))
                .velocityDecay(0.5)
                .alphaDecay(0.02)
                .on('tick', () => {
                    link
                        .attr('x1', d => d.source.x)
                        .attr('y1', d => d.source.y)
                        .attr('x2', d => d.target.x)
                        .attr('y2', d => d.target.y);

                    node.attr('transform', d => `translate(${d.x},${d.y})`);

                    // Update cluster hulls if enabled
                    if (filterState.showClusters) {
                        updateClusterHulls(hullsLayer, nodes, clusters);
                    }
                });

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

        // Cluster force function
        function clusterForceFunc(nodes, clusters, strength, width, height) {
            // Calculate cluster centers
            const clusterCenters = new Map();
            const numClusters = clusters.length;
            const radius = Math.min(width, height) * 0.3;

            clusters.forEach((clusterId, i) => {
                const angle = (2 * Math.PI * i) / numClusters;
                clusterCenters.set(clusterId, {
                    x: width / 2 + radius * Math.cos(angle),
                    y: height / 2 + radius * Math.sin(angle)
                });
            });

            return () => {
                nodes.forEach(node => {
                    const center = clusterCenters.get(node.cluster);
                    if (center) {
                        node.vx += (center.x - node.x) * strength * 0.05;
                        node.vy += (center.y - node.y) * strength * 0.05;
                    }
                });
            };
        }

        // Update cluster hulls
        function updateClusterHulls(hullsLayer, nodes, clusters) {
            hullsLayer.selectAll('.cluster-hull').remove();

            clusters.forEach(clusterId => {
                const clusterNodes = nodes.filter(n => n.cluster === clusterId);
                if (clusterNodes.length < 3) return;

                const points = clusterNodes.map(n => [n.x, n.y]);
                const hull = d3.polygonHull(points);

                if (hull) {
                    // Expand hull slightly
                    const centroid = d3.polygonCentroid(hull);
                    const expandedHull = hull.map(p => {
                        const dx = p[0] - centroid[0];
                        const dy = p[1] - centroid[1];
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        const expand = 30;
                        return [
                            p[0] + (dx / dist) * expand,
                            p[1] + (dy / dist) * expand
                        ];
                    });

                    hullsLayer.append('path')
                        .attr('class', 'cluster-hull')
                        .attr('d', 'M' + expandedHull.join('L') + 'Z')
                        .attr('fill', clusterColors[clusterId % clusterColors.length])
                        .attr('stroke', clusterColors[clusterId % clusterColors.length]);
                }
            });
        }

        // Update legend
        function updateLegend(clusters) {
            const legend = document.getElementById('networkLegend');
            const items = document.getElementById('legendItems');

            if (filterState.showClusters && clusters.length > 1) {
                legend.style.display = 'block';
                items.innerHTML = clusters.slice(0, 10).map(c => `
                    <div class="legend-item">
                        <div class="legend-color" style="background: ${clusterColors[c % clusterColors.length]}"></div>
                        <span class="legend-label">Cluster ${c + 1}</span>
                    </div>
                `).join('');
            } else {
                legend.style.display = 'none';
            }
        }

        // Select a node
        function selectNode(d, nodeSelection) {
            selectedNode = d;
            nodeSelection.classed('selected', false);

            const info = document.getElementById('selectedTagInfo');

            if (d) {
                nodeSelection.filter(n => n.id === d.id).classed('selected', true);

                document.getElementById('selectedTagName').textContent = d.label;
                document.getElementById('selectedTagStats').innerHTML = `
                    ${d.count} bookmarks<br>
                    First used: ${new Date(d.first_seen).toLocaleDateString()}<br>
                    Cluster: ${d.cluster + 1}
                `;
                document.getElementById('viewBookmarksLink').href = `${BASE_PATH}/?tag=${encodeURIComponent(d.label)}`;
                document.getElementById('manageTagLink').href = `${BASE_PATH}/tag-admin.php?search=${encodeURIComponent(d.label)}`;

                info.classList.add('visible');
            } else {
                info.classList.remove('visible');
            }
        }

        // Apply search highlighting
        function applySearchHighlight(nodeSelection) {
            const query = filterState.searchQuery.toLowerCase();

            if (query) {
                nodeSelection.classed('searched', d => d.label.toLowerCase().includes(query));
            } else {
                nodeSelection.classed('searched', false);
            }
        }

        // Zoom controls
        document.getElementById('zoomIn').addEventListener('click', () => {
            svg.transition().duration(300).call(zoom.scaleBy, 1.5);
        });

        document.getElementById('zoomOut').addEventListener('click', () => {
            svg.transition().duration(300).call(zoom.scaleBy, 0.67);
        });

        document.getElementById('zoomReset').addEventListener('click', () => {
            svg.transition().duration(300).call(zoom.transform, d3.zoomIdentity);
        });

        // Filter controls
        document.getElementById('minCount').addEventListener('input', function() {
            filterState.minCount = parseInt(this.value);
            document.getElementById('minCountValue').textContent = this.value;
            applyFilters();
        });

        document.getElementById('minCooccurrence').addEventListener('input', function() {
            filterState.minCooccurrence = parseInt(this.value);
            document.getElementById('minCooccurrenceValue').textContent = this.value;
            applyFilters();
        });

        document.getElementById('maxTags').addEventListener('input', function() {
            filterState.maxTags = parseInt(this.value);
            document.getElementById('maxTagsValue').textContent = this.value;
            applyFilters();
        });

        document.getElementById('prefixFilter').addEventListener('change', function() {
            filterState.prefixFilter = this.value;
            applyFilters();
        });

        let searchDebounce;
        document.getElementById('searchBox').addEventListener('input', function() {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                filterState.searchQuery = this.value;
                if (svg) {
                    applySearchHighlight(svg.selectAll('.network-node'));
                }
            }, 200);
        });

        // Clustering controls
        document.getElementById('clusterStrength').addEventListener('input', function() {
            filterState.clusterStrength = parseFloat(this.value);
            document.getElementById('clusterStrengthValue').textContent = this.value;
            applyFilters();
        });

        document.getElementById('showClusters').addEventListener('change', function() {
            filterState.showClusters = this.checked;
            if (filteredData) {
                const clusters = [...new Set(filteredData.nodes.map(n => n.cluster))];
                updateLegend(clusters);

                const hullsLayer = container.select('.hulls-layer');
                if (filterState.showClusters) {
                    updateClusterHulls(hullsLayer, filteredData.nodes, clusters);
                } else {
                    hullsLayer.selectAll('.cluster-hull').remove();
                }
            }
        });

        document.getElementById('showPrefixGroups').addEventListener('change', function() {
            filterState.showPrefixGroups = this.checked;
            // This would require more complex grouping logic - left for future enhancement
        });

        // Export functions
        document.getElementById('exportPng').addEventListener('click', exportAsPng);
        document.getElementById('exportSvg').addEventListener('click', exportAsSvg);

        function exportAsPng() {
            const svgEl = document.getElementById('networkSvg');
            const serializer = new XMLSerializer();
            let source = serializer.serializeToString(svgEl);

            // Add namespaces
            if (!source.match(/^<svg[^>]+xmlns="http:\/\/www\.w3\.org\/2000\/svg"/)) {
                source = source.replace(/^<svg/, '<svg xmlns="http://www.w3.org/2000/svg"');
            }

            // Add styles
            const style = `
                <style>
                    text { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; fill: ${getCSSVariable('--text-primary')}; }
                    .network-node text { font-weight: 600; }
                    .network-link { stroke: ${getCSSVariable('--border-color')}; stroke-opacity: 0.4; fill: none; }
                    .cluster-hull { fill-opacity: 0.08; stroke-width: 2; stroke-opacity: 0.3; }
                </style>
            `;
            source = source.replace('</svg>', style + '</svg>');

            const canvas = document.createElement('canvas');
            const rect = svgEl.getBoundingClientRect();
            canvas.width = rect.width * 2;
            canvas.height = rect.height * 2;
            const ctx = canvas.getContext('2d');
            ctx.scale(2, 2);

            ctx.fillStyle = getCSSVariable('--bg-primary');
            ctx.fillRect(0, 0, rect.width, rect.height);

            const img = new Image();
            const svgBlob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
            const url = URL.createObjectURL(svgBlob);

            img.onload = function() {
                ctx.drawImage(img, 0, 0);
                URL.revokeObjectURL(url);

                const a = document.createElement('a');
                a.download = 'tag-network-' + new Date().toISOString().slice(0, 10) + '.png';
                a.href = canvas.toDataURL('image/png');
                a.click();
            };

            img.src = url;
        }

        function exportAsSvg() {
            const svgEl = document.getElementById('networkSvg');
            const serializer = new XMLSerializer();
            let source = serializer.serializeToString(svgEl);

            // Add namespaces
            if (!source.match(/^<svg[^>]+xmlns="http:\/\/www\.w3\.org\/2000\/svg"/)) {
                source = source.replace(/^<svg/, '<svg xmlns="http://www.w3.org/2000/svg"');
            }

            // Add styles
            const style = `
                <style>
                    text { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; fill: ${getCSSVariable('--text-primary')}; }
                    .network-node text { font-weight: 600; }
                    .network-link { stroke: ${getCSSVariable('--border-color')}; stroke-opacity: 0.4; fill: none; }
                    .cluster-hull { fill-opacity: 0.08; stroke-width: 2; stroke-opacity: 0.3; }
                </style>
            `;
            source = source.replace('</svg>', style + '</svg>');

            const blob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');
            a.download = 'tag-network-' + new Date().toISOString().slice(0, 10) + '.svg';
            a.href = url;
            a.click();

            URL.revokeObjectURL(url);
        }

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (filteredData) {
                    renderNetwork();
                }
            }, 200);
        });

        // Initialize
        fetchNetworkData();
    </script>
    <?php render_nav_scripts(); ?>
</body>

</html>
