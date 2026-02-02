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

        .network-canvas {
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

            <canvas class="network-canvas" id="networkCanvas"></canvas>

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
        let canvas = null;
        let ctx = null;
        let transform = d3.zoomIdentity;
        let selectedNode = null;
        let hoveredNode = null;
        let draggedNode = null;
        let width = 0;
        let height = 0;
        let dpr = 1;
        let nodes = [];
        let links = [];
        let clusters = [];
        let sizeScale = null;
        let needsRedraw = true;
        let animationId = null;

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

        // Cached CSS colors
        let cssColors = {};
        function updateCSSColors() {
            const style = getComputedStyle(document.documentElement);
            cssColors = {
                bgPrimary: style.getPropertyValue('--bg-primary').trim(),
                bgSecondary: style.getPropertyValue('--bg-secondary').trim(),
                textPrimary: style.getPropertyValue('--text-primary').trim(),
                textSecondary: style.getPropertyValue('--text-secondary').trim(),
                borderColor: style.getPropertyValue('--border-color').trim(),
                primary: style.getPropertyValue('--primary').trim(),
                accentAmber: style.getPropertyValue('--accent-amber').trim()
            };
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
            let filteredLinks = rawData.cooccurrence.filter(l =>
                l.count >= filterState.minCooccurrence &&
                tagSet.has(l.source.toLowerCase()) &&
                tagSet.has(l.target.toLowerCase())
            );

            // Prepare nodes
            const filteredNodes = tags.map(t => {
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
            detectClusters(filteredNodes, filteredLinks);

            filteredData = { nodes: filteredNodes, links: filteredLinks };

            // Update stats
            document.getElementById('visibleTagsStat').textContent = filteredNodes.length;
            document.getElementById('visibleCount').textContent = filteredNodes.length;
            document.getElementById('linksStat').textContent = filteredLinks.length;

            // Count unique clusters
            const uniqueClusters = new Set(filteredNodes.map(n => n.cluster));
            document.getElementById('clustersStat').textContent = uniqueClusters.size;

            initNetwork();
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

        // Initialize canvas and simulation
        function initNetwork() {
            if (!filteredData) return;

            // Stop existing simulation
            if (simulation) {
                simulation.stop();
            }
            if (animationId) {
                cancelAnimationFrame(animationId);
            }

            updateCSSColors();

            canvas = document.getElementById('networkCanvas');
            const rect = canvas.getBoundingClientRect();
            width = rect.width;
            height = rect.height;

            // Handle high-DPI displays
            dpr = window.devicePixelRatio || 1;
            canvas.width = width * dpr;
            canvas.height = height * dpr;
            canvas.style.width = width + 'px';
            canvas.style.height = height + 'px';

            ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);

            // Reset transform
            transform = d3.zoomIdentity;

            // Prepare data (create copies to avoid mutation issues)
            nodes = filteredData.nodes.map(d => ({ ...d }));
            links = filteredData.links.map(d => ({
                source: d.source.toLowerCase(),
                target: d.target.toLowerCase(),
                value: d.count
            }));

            // Size scale
            const countExtent = d3.extent(nodes, d => d.count);
            sizeScale = d3.scaleSqrt()
                .domain(countExtent)
                .range([6, 28]);

            // Get unique clusters
            clusters = [...new Set(nodes.map(n => n.cluster))].sort((a, b) => a - b);

            // Update legend
            updateLegend(clusters);

            // Calculate collision radii
            nodes.forEach(d => {
                d.radius = sizeScale(d.count);
                d.collisionRadius = d.radius + 20;
            });

            // Create force simulation
            const clusterForce = filterState.clusterStrength;

            simulation = d3.forceSimulation(nodes)
                .force('link', d3.forceLink(links)
                    .id(d => d.id)
                    .distance(d => {
                        const source = typeof d.source === 'object' ? d.source : nodes.find(n => n.id === d.source);
                        const target = typeof d.target === 'object' ? d.target : nodes.find(n => n.id === d.target);
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
                    needsRedraw = true;
                });

            // D3 drag behavior for nodes
            const drag = d3.drag()
                .subject((event) => {
                    const [x, y] = transform.invert([event.x, event.y]);
                    const node = findNodeAtPoint(x, y);
                    return node;
                })
                .on('start', (event) => {
                    if (!event.active) simulation.alphaTarget(0.3).restart();
                    event.subject.fx = event.subject.x;
                    event.subject.fy = event.subject.y;
                    draggedNode = event.subject;
                    canvas.style.cursor = 'grabbing';
                })
                .on('drag', (event) => {
                    const [x, y] = transform.invert([event.x, event.y]);
                    event.subject.fx = x;
                    event.subject.fy = y;
                    needsRedraw = true;
                })
                .on('end', (event) => {
                    if (!event.active) simulation.alphaTarget(0);
                    event.subject.fx = null;
                    event.subject.fy = null;
                    draggedNode = null;
                    canvas.style.cursor = 'grab';
                    needsRedraw = true;
                });

            // Setup zoom
            const zoom = d3.zoom()
                .scaleExtent([0.1, 5])
                .filter((event) => {
                    // Don't zoom if dragging a node
                    if (event.type === 'mousedown' || event.type === 'touchstart') {
                        const rect = canvas.getBoundingClientRect();
                        const mouseX = (event.touches ? event.touches[0].clientX : event.clientX) - rect.left;
                        const mouseY = (event.touches ? event.touches[0].clientY : event.clientY) - rect.top;
                        const [x, y] = transform.invert([mouseX, mouseY]);
                        if (findNodeAtPoint(x, y)) return false;
                    }
                    return !event.ctrlKey && !event.button;
                })
                .on('zoom', ({ transform: t }) => {
                    transform = t;
                    needsRedraw = true;
                });

            // Apply both behaviors - drag first, then zoom
            d3.select(canvas).call(drag).call(zoom);

            // Store zoom for external controls
            canvas._zoom = zoom;

            // Setup mouse interactions (hover, click, tooltip)
            setupMouseHandlers();

            // Start render loop
            startRenderLoop();
        }

        // Cluster force function
        function clusterForceFunc(nodes, clusters, strength, width, height) {
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

        // Setup mouse event handlers (hover, tooltip, click - drag is handled by D3)
        function setupMouseHandlers() {
            const tooltip = document.getElementById('networkTooltip');
            let lastClickTime = 0;

            canvas.addEventListener('mousemove', (event) => {
                // Skip hover detection while dragging
                if (draggedNode) return;

                const rect = canvas.getBoundingClientRect();
                const mouseX = event.clientX - rect.left;
                const mouseY = event.clientY - rect.top;
                const [x, y] = transform.invert([mouseX, mouseY]);

                const node = findNodeAtPoint(x, y);

                if (node !== hoveredNode) {
                    hoveredNode = node;
                    needsRedraw = true;

                    if (node) {
                        tooltip.innerHTML = `
                            <strong>${node.label}</strong>
                            <div class="tip-stats">
                                Count: ${node.count} bookmarks<br>
                                First used: ${new Date(node.first_seen).toLocaleDateString()}<br>
                                Cluster: ${node.cluster + 1}
                            </div>
                            <div class="tip-hint">Click to select, double-click for bookmarks</div>
                        `;
                        tooltip.classList.add('visible');
                    } else {
                        tooltip.classList.remove('visible');
                    }
                }

                if (hoveredNode) {
                    tooltip.style.left = (mouseX + 15) + 'px';
                    tooltip.style.top = (mouseY + 15) + 'px';
                }

                canvas.style.cursor = hoveredNode ? 'pointer' : 'grab';
            });

            canvas.addEventListener('mouseleave', () => {
                tooltip.classList.remove('visible');
                hoveredNode = null;
                needsRedraw = true;
            });

            canvas.addEventListener('click', (event) => {
                const now = Date.now();
                const rect = canvas.getBoundingClientRect();
                const mouseX = event.clientX - rect.left;
                const mouseY = event.clientY - rect.top;
                const [x, y] = transform.invert([mouseX, mouseY]);

                const node = findNodeAtPoint(x, y);

                // Double-click detection
                if (now - lastClickTime < 300 && node) {
                    window.location.href = `${BASE_PATH}/?tag=${encodeURIComponent(node.label)}`;
                    return;
                }
                lastClickTime = now;

                selectNode(node);
                needsRedraw = true;
            });
        }

        // Find node at point
        function findNodeAtPoint(x, y) {
            // Search in reverse order (top nodes first)
            for (let i = nodes.length - 1; i >= 0; i--) {
                const node = nodes[i];
                const dx = x - node.x;
                const dy = y - node.y;
                if (dx * dx + dy * dy < node.radius * node.radius) {
                    return node;
                }
            }
            return null;
        }

        // Get connected node IDs
        function getConnectedIds(node) {
            const connected = new Set([node.id]);
            links.forEach(l => {
                const sourceId = typeof l.source === 'object' ? l.source.id : l.source;
                const targetId = typeof l.target === 'object' ? l.target.id : l.target;
                if (sourceId === node.id) connected.add(targetId);
                if (targetId === node.id) connected.add(sourceId);
            });
            return connected;
        }

        // Render loop
        function startRenderLoop() {
            function render() {
                if (needsRedraw) {
                    draw();
                    needsRedraw = false;
                }
                animationId = requestAnimationFrame(render);
            }
            render();
        }

        // Main draw function
        function draw() {
            ctx.save();
            ctx.clearRect(0, 0, width, height);

            // Fill background
            ctx.fillStyle = cssColors.bgPrimary;
            ctx.fillRect(0, 0, width, height);

            // Apply zoom transform
            ctx.translate(transform.x, transform.y);
            ctx.scale(transform.k, transform.k);

            // Get connected nodes for highlighting
            const connectedIds = hoveredNode ? getConnectedIds(hoveredNode) : null;
            const searchQuery = filterState.searchQuery.toLowerCase();

            // Draw cluster hulls if enabled
            if (filterState.showClusters) {
                drawClusterHulls();
            }

            // Draw links
            ctx.lineWidth = 1;
            links.forEach(link => {
                const source = typeof link.source === 'object' ? link.source : nodes.find(n => n.id === link.source);
                const target = typeof link.target === 'object' ? link.target : nodes.find(n => n.id === link.target);
                if (!source || !target) return;

                const isHighlighted = hoveredNode && (
                    source.id === hoveredNode.id || target.id === hoveredNode.id
                );

                ctx.beginPath();
                ctx.moveTo(source.x, source.y);
                ctx.lineTo(target.x, target.y);

                if (isHighlighted) {
                    ctx.strokeStyle = cssColors.primary;
                    ctx.globalAlpha = 0.8;
                    ctx.lineWidth = 2;
                } else {
                    ctx.strokeStyle = cssColors.borderColor;
                    ctx.globalAlpha = hoveredNode ? 0.1 : 0.4;
                    ctx.lineWidth = Math.sqrt(link.value) * 0.8;
                }
                ctx.stroke();
                ctx.globalAlpha = 1;
            });

            // Draw nodes
            nodes.forEach(node => {
                const isDimmed = hoveredNode && !connectedIds.has(node.id);
                const isSearched = searchQuery && node.label.toLowerCase().includes(searchQuery);
                const isSelected = selectedNode && selectedNode.id === node.id;
                const isHovered = hoveredNode && hoveredNode.id === node.id;

                ctx.globalAlpha = isDimmed ? 0.3 : 1;

                // Draw circle
                ctx.beginPath();
                ctx.arc(node.x, node.y, node.radius, 0, Math.PI * 2);
                ctx.fillStyle = clusterColors[node.cluster % clusterColors.length];
                ctx.fill();

                // Draw stroke
                if (isSelected || isSearched) {
                    ctx.strokeStyle = isSearched ? cssColors.accentAmber : cssColors.primary;
                    ctx.lineWidth = 4;
                } else {
                    ctx.strokeStyle = cssColors.bgSecondary;
                    ctx.lineWidth = isHovered ? 4 : 2;
                }
                ctx.stroke();

                // Draw label
                ctx.font = `600 10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'top';

                // Text shadow for readability
                ctx.fillStyle = cssColors.bgSecondary;
                for (let ox = -1; ox <= 1; ox++) {
                    for (let oy = -1; oy <= 1; oy++) {
                        if (ox !== 0 || oy !== 0) {
                            ctx.fillText(node.label, node.x + ox, node.y + node.radius + 4 + oy);
                        }
                    }
                }

                ctx.fillStyle = isSearched ? cssColors.accentAmber : cssColors.textPrimary;
                ctx.fillText(node.label, node.x, node.y + node.radius + 4);

                ctx.globalAlpha = 1;
            });

            ctx.restore();
        }

        // Draw cluster hulls
        function drawClusterHulls() {
            clusters.forEach(clusterId => {
                const clusterNodes = nodes.filter(n => n.cluster === clusterId);
                if (clusterNodes.length < 3) return;

                const points = clusterNodes.map(n => [n.x, n.y]);
                const hull = d3.polygonHull(points);

                if (hull) {
                    // Expand hull
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

                    const color = clusterColors[clusterId % clusterColors.length];

                    ctx.beginPath();
                    ctx.moveTo(expandedHull[0][0], expandedHull[0][1]);
                    expandedHull.slice(1).forEach(p => ctx.lineTo(p[0], p[1]));
                    ctx.closePath();

                    ctx.fillStyle = color;
                    ctx.globalAlpha = 0.08;
                    ctx.fill();

                    ctx.strokeStyle = color;
                    ctx.globalAlpha = 0.3;
                    ctx.lineWidth = 2;
                    ctx.setLineDash([6, 3]);
                    ctx.stroke();
                    ctx.setLineDash([]);
                    ctx.globalAlpha = 1;
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
        function selectNode(node) {
            selectedNode = node;

            const info = document.getElementById('selectedTagInfo');

            if (node) {
                document.getElementById('selectedTagName').textContent = node.label;
                document.getElementById('selectedTagStats').innerHTML = `
                    ${node.count} bookmarks<br>
                    First used: ${new Date(node.first_seen).toLocaleDateString()}<br>
                    Cluster: ${node.cluster + 1}
                `;
                document.getElementById('viewBookmarksLink').href = `${BASE_PATH}/?tag=${encodeURIComponent(node.label)}`;
                document.getElementById('manageTagLink').href = `${BASE_PATH}/tag-admin.php?search=${encodeURIComponent(node.label)}`;

                info.classList.add('visible');
            } else {
                info.classList.remove('visible');
            }
        }

        // Zoom controls
        document.getElementById('zoomIn').addEventListener('click', () => {
            if (canvas && canvas._zoom) {
                d3.select(canvas).transition().duration(300).call(canvas._zoom.scaleBy, 1.5);
            }
        });

        document.getElementById('zoomOut').addEventListener('click', () => {
            if (canvas && canvas._zoom) {
                d3.select(canvas).transition().duration(300).call(canvas._zoom.scaleBy, 0.67);
            }
        });

        document.getElementById('zoomReset').addEventListener('click', () => {
            if (canvas && canvas._zoom) {
                d3.select(canvas).transition().duration(300).call(canvas._zoom.transform, d3.zoomIdentity);
            }
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
                needsRedraw = true;
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
            updateLegend(clusters);
            needsRedraw = true;
        });

        document.getElementById('showPrefixGroups').addEventListener('change', function() {
            filterState.showPrefixGroups = this.checked;
            // Future enhancement
        });

        // Export functions
        document.getElementById('exportPng').addEventListener('click', exportAsPng);
        document.getElementById('exportSvg').addEventListener('click', exportAsSvg);

        function exportAsPng() {
            // Create a temporary canvas at higher resolution
            const exportCanvas = document.createElement('canvas');
            const scale = 2;
            exportCanvas.width = width * scale;
            exportCanvas.height = height * scale;
            const exportCtx = exportCanvas.getContext('2d');
            exportCtx.scale(scale, scale);

            // Draw background
            exportCtx.fillStyle = cssColors.bgPrimary;
            exportCtx.fillRect(0, 0, width, height);

            // Apply current transform
            exportCtx.translate(transform.x, transform.y);
            exportCtx.scale(transform.k, transform.k);

            // Draw cluster hulls
            if (filterState.showClusters) {
                clusters.forEach(clusterId => {
                    const clusterNodes = nodes.filter(n => n.cluster === clusterId);
                    if (clusterNodes.length < 3) return;

                    const points = clusterNodes.map(n => [n.x, n.y]);
                    const hull = d3.polygonHull(points);

                    if (hull) {
                        const centroid = d3.polygonCentroid(hull);
                        const expandedHull = hull.map(p => {
                            const dx = p[0] - centroid[0];
                            const dy = p[1] - centroid[1];
                            const dist = Math.sqrt(dx * dx + dy * dy);
                            return [p[0] + (dx / dist) * 30, p[1] + (dy / dist) * 30];
                        });

                        const color = clusterColors[clusterId % clusterColors.length];
                        exportCtx.beginPath();
                        exportCtx.moveTo(expandedHull[0][0], expandedHull[0][1]);
                        expandedHull.slice(1).forEach(p => exportCtx.lineTo(p[0], p[1]));
                        exportCtx.closePath();

                        exportCtx.fillStyle = color;
                        exportCtx.globalAlpha = 0.08;
                        exportCtx.fill();
                        exportCtx.globalAlpha = 1;
                    }
                });
            }

            // Draw links
            links.forEach(link => {
                const source = typeof link.source === 'object' ? link.source : nodes.find(n => n.id === link.source);
                const target = typeof link.target === 'object' ? link.target : nodes.find(n => n.id === link.target);
                if (!source || !target) return;

                exportCtx.beginPath();
                exportCtx.moveTo(source.x, source.y);
                exportCtx.lineTo(target.x, target.y);
                exportCtx.strokeStyle = cssColors.borderColor;
                exportCtx.globalAlpha = 0.4;
                exportCtx.lineWidth = Math.sqrt(link.value) * 0.8;
                exportCtx.stroke();
                exportCtx.globalAlpha = 1;
            });

            // Draw nodes
            nodes.forEach(node => {
                exportCtx.beginPath();
                exportCtx.arc(node.x, node.y, node.radius, 0, Math.PI * 2);
                exportCtx.fillStyle = clusterColors[node.cluster % clusterColors.length];
                exportCtx.fill();
                exportCtx.strokeStyle = cssColors.bgSecondary;
                exportCtx.lineWidth = 2;
                exportCtx.stroke();

                exportCtx.font = `600 10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`;
                exportCtx.textAlign = 'center';
                exportCtx.textBaseline = 'top';
                exportCtx.fillStyle = cssColors.textPrimary;
                exportCtx.fillText(node.label, node.x, node.y + node.radius + 4);
            });

            // Download
            const a = document.createElement('a');
            a.download = 'tag-network-' + new Date().toISOString().slice(0, 10) + '.png';
            a.href = exportCanvas.toDataURL('image/png');
            a.click();
        }

        function exportAsSvg() {
            // Build SVG manually
            let svgContent = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">`;
            svgContent += `<rect width="100%" height="100%" fill="${cssColors.bgPrimary}"/>`;
            svgContent += `<g transform="translate(${transform.x},${transform.y}) scale(${transform.k})">`;

            // Links
            links.forEach(link => {
                const source = typeof link.source === 'object' ? link.source : nodes.find(n => n.id === link.source);
                const target = typeof link.target === 'object' ? link.target : nodes.find(n => n.id === link.target);
                if (!source || !target) return;

                svgContent += `<line x1="${source.x}" y1="${source.y}" x2="${target.x}" y2="${target.y}"
                    stroke="${cssColors.borderColor}" stroke-opacity="0.4" stroke-width="${Math.sqrt(link.value) * 0.8}"/>`;
            });

            // Nodes
            nodes.forEach(node => {
                const color = clusterColors[node.cluster % clusterColors.length];
                svgContent += `<circle cx="${node.x}" cy="${node.y}" r="${node.radius}"
                    fill="${color}" stroke="${cssColors.bgSecondary}" stroke-width="2"/>`;
                svgContent += `<text x="${node.x}" y="${node.y + node.radius + 14}"
                    text-anchor="middle" font-family="-apple-system, sans-serif" font-size="10" font-weight="600"
                    fill="${cssColors.textPrimary}">${escapeHtml(node.label)}</text>`;
            });

            svgContent += '</g></svg>';

            const blob = new Blob([svgContent], { type: 'image/svg+xml;charset=utf-8' });
            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');
            a.download = 'tag-network-' + new Date().toISOString().slice(0, 10) + '.svg';
            a.href = url;
            a.click();

            URL.revokeObjectURL(url);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (filteredData) {
                    initNetwork();
                }
            }, 200);
        });

        // Initialize
        fetchNetworkData();
    </script>
    <?php render_nav_scripts(); ?>
</body>

</html>
