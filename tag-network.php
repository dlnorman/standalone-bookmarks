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
            max-height: 300px;
            overflow-y: auto;
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
            flex-shrink: 0;
        }

        .legend-label {
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .network-page {
                flex-direction: column;
                height: auto;
                min-height: calc(100vh - 70px);
            }

            .network-controls {
                width: 100%;
                min-width: auto;
                height: auto;
                max-height: none;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
                overflow-y: visible;
            }

            .network-viewport {
                min-height: 50vh;
            }

            .stats-panel {
                margin-top: 0;
            }

            .control-section {
                padding: 12px;
            }

            .zoom-controls {
                flex-wrap: wrap;
            }

            .export-controls {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .network-controls {
                padding: 12px;
                gap: 12px;
            }

            .control-section h3 {
                font-size: 11px;
                margin-bottom: 10px;
            }

            .control-label {
                font-size: 12px;
            }

            .control-select,
            .control-input {
                font-size: 16px; /* Prevents zoom on iOS */
                padding: 10px 12px;
            }

            .zoom-btn,
            .export-btn {
                min-height: 44px;
                font-size: 14px;
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
                        <span id="maxTagsLabel">Max Tags</span>
                        <span class="control-value" id="maxTagsValue">75</span>
                    </label>
                    <input type="range" class="control-slider" id="maxTags" min="10" max="1000" value="75">
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
                    <button class="zoom-btn" id="zoomFit" title="Fit All">Fit</button>
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
        let clusterMeta = [];  // {id, representativeTag, size}
        let sizeScale = null;
        let needsRedraw = true;
        let animationId = null;
        let quadtree = null;
        let userHasPanned = false;
        let hasAutoFitted = false;

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

        // Cluster color scale: first 10 use Tableau10, beyond that use golden-angle HSL
        function clusterColorScale(index) {
            if (index < 10) return d3.schemeTableau10[index];
            const hue = (index * 137.508) % 360;
            return d3.hsl(hue, 0.65, 0.55).formatHex();
        }

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

                // Dynamic slider max: set to actual tag count
                const slider = document.getElementById('maxTags');
                slider.max = rawData.tags.length;
                document.getElementById('maxTagsLabel').textContent = `Max Tags (of ${rawData.tags.length})`;

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

            // Detect clusters using label propagation
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

        // Label Propagation community detection
        function detectClusters(nodes, links) {
            if (nodes.length === 0) return;

            // Build adjacency list with weights
            const adjacency = new Map();
            nodes.forEach(n => adjacency.set(n.id, []));

            links.forEach(l => {
                const source = l.source.toLowerCase();
                const target = l.target.toLowerCase();
                if (adjacency.has(source) && adjacency.has(target)) {
                    adjacency.get(source).push({ neighbor: target, weight: l.count });
                    adjacency.get(target).push({ neighbor: source, weight: l.count });
                }
            });

            // Initialize: each node is its own community
            const labels = new Map();
            nodes.forEach((n, i) => labels.set(n.id, i));

            // Iterate until convergence or max iterations
            const maxIter = 15;
            const nodeIds = nodes.map(n => n.id);

            for (let iter = 0; iter < maxIter; iter++) {
                let changed = false;

                // Shuffle node order each iteration for better convergence
                const shuffled = [...nodeIds];
                for (let i = shuffled.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
                }

                for (const nodeId of shuffled) {
                    const neighbors = adjacency.get(nodeId);
                    if (neighbors.length === 0) continue;

                    // Sum weights for each neighbor label
                    const labelWeights = new Map();
                    for (const { neighbor, weight } of neighbors) {
                        const nLabel = labels.get(neighbor);
                        labelWeights.set(nLabel, (labelWeights.get(nLabel) || 0) + weight);
                    }

                    // Find label with maximum weight
                    let bestLabel = labels.get(nodeId);
                    let bestWeight = 0;
                    for (const [label, weight] of labelWeights) {
                        if (weight > bestWeight) {
                            bestWeight = weight;
                            bestLabel = label;
                        }
                    }

                    if (bestLabel !== labels.get(nodeId)) {
                        labels.set(nodeId, bestLabel);
                        changed = true;
                    }
                }

                if (!changed) break;
            }

            // Remap labels to sequential IDs sorted by cluster size (largest first)
            const clusterSizes = new Map();
            for (const label of labels.values()) {
                clusterSizes.set(label, (clusterSizes.get(label) || 0) + 1);
            }
            const sortedLabels = [...clusterSizes.entries()]
                .sort((a, b) => b[1] - a[1])
                .map(e => e[0]);
            const labelRemap = new Map();
            sortedLabels.forEach((label, idx) => labelRemap.set(label, idx));

            // Assign remapped cluster IDs
            const nodeMap = new Map(nodes.map(n => [n.id, n]));
            for (const [nodeId, label] of labels) {
                const node = nodeMap.get(nodeId);
                if (node) node.cluster = labelRemap.get(label);
            }

            // Build cluster metadata (representative tag = highest count node in cluster)
            const clusterNodesMap = new Map();
            nodes.forEach(n => {
                if (!clusterNodesMap.has(n.cluster)) clusterNodesMap.set(n.cluster, []);
                clusterNodesMap.get(n.cluster).push(n);
            });

            clusterMeta = [];
            for (const [id, members] of clusterNodesMap) {
                members.sort((a, b) => b.count - a.count);
                clusterMeta.push({
                    id,
                    representativeTag: members[0].label,
                    size: members.length
                });
            }
            clusterMeta.sort((a, b) => a.id - b.id);
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

            // Reset transform and panning state
            transform = d3.zoomIdentity;
            userHasPanned = false;
            hasAutoFitted = false;

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
            updateLegend();

            // Calculate collision radii
            nodes.forEach(d => {
                d.radius = sizeScale(d.count);
                d.collisionRadius = d.radius + 20;
            });

            // Adaptive force parameters for large graphs
            const n = nodes.length;
            const isLarge = n >= 300;
            const linkStrength = isLarge ? 0.15 : 0.3;
            const chargeMax = isLarge ? 250 : 400;
            const collisionIter = isLarge ? 1 : 3;
            const velDecay = isLarge ? 0.6 : 0.5;
            const alphaDecay = isLarge ? 0.035 : 0.02;

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
                    .strength(linkStrength))
                .force('charge', d3.forceManyBody()
                    .strength(d => -Math.pow(d.collisionRadius, 2) * 0.5)
                    .distanceMax(chargeMax))
                .force('collision', d3.forceCollide()
                    .radius(d => d.collisionRadius)
                    .strength(0.9)
                    .iterations(collisionIter))
                .force('center', d3.forceCenter(width / 2, height / 2).strength(0.05))
                .force('cluster', clusterForceFunc(nodes, clusters, clusterForce, width, height))
                .velocityDecay(velDecay)
                .alphaDecay(alphaDecay)
                .on('tick', () => {
                    // Update quadtree each tick
                    quadtree = d3.quadtree()
                        .x(d => d.x)
                        .y(d => d.y)
                        .addAll(nodes);
                    needsRedraw = true;
                })
                .on('end', () => {
                    // Auto-fit on first stabilization if user hasn't panned
                    if (!userHasPanned && !hasAutoFitted) {
                        hasAutoFitted = true;
                        zoomToFit(600);
                    }
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
                    userHasPanned = true;
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

        // Zoom to fit all nodes in viewport
        function zoomToFit(duration) {
            if (!nodes.length || !canvas || !canvas._zoom) return;

            const padding = 60;
            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            nodes.forEach(n => {
                minX = Math.min(minX, n.x - n.radius);
                minY = Math.min(minY, n.y - n.radius);
                maxX = Math.max(maxX, n.x + n.radius);
                maxY = Math.max(maxY, n.y + n.radius);
            });

            const bw = maxX - minX;
            const bh = maxY - minY;
            if (bw <= 0 || bh <= 0) return;

            const scale = Math.min((width - padding * 2) / bw, (height - padding * 2) / bh, 2);
            const cx = (minX + maxX) / 2;
            const cy = (minY + maxY) / 2;

            const t = d3.zoomIdentity
                .translate(width / 2, height / 2)
                .scale(scale)
                .translate(-cx, -cy);

            if (duration > 0) {
                d3.select(canvas).transition().duration(duration).call(canvas._zoom.transform, t);
            } else {
                d3.select(canvas).call(canvas._zoom.transform, t);
            }
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

            const force = () => {
                nodes.forEach(node => {
                    const center = clusterCenters.get(node.cluster);
                    if (center) {
                        node.vx += (center.x - node.x) * force._strength * 0.05;
                        node.vy += (center.y - node.y) * force._strength * 0.05;
                    }
                });
            };

            force._strength = strength;

            // Allow live strength updates
            force.strength = function(s) {
                if (s === undefined) return force._strength;
                force._strength = s;
                return force;
            };

            return force;
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
                        const cm = clusterMeta.find(c => c.id === node.cluster);
                        const clusterLabel = cm ? `${cm.representativeTag} (${cm.size})` : `Cluster ${node.cluster + 1}`;
                        tooltip.innerHTML = `
                            <strong>${node.label}</strong>
                            <div class="tip-stats">
                                Count: ${node.count} bookmarks<br>
                                First used: ${new Date(node.first_seen).toLocaleDateString()}<br>
                                Cluster: ${clusterLabel}
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

        // Find node at point using quadtree (O(log n))
        function findNodeAtPoint(x, y) {
            if (!quadtree || nodes.length === 0) return null;

            // Find nearest node within max radius
            const maxRadius = 28; // max node radius
            const nearest = quadtree.find(x, y, maxRadius + 5);
            if (!nearest) return null;

            // Check if point is actually inside the node circle
            const dx = x - nearest.x;
            const dy = y - nearest.y;
            if (dx * dx + dy * dy <= nearest.radius * nearest.radius) {
                return nearest;
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

        // Catmull-Rom smooth closed curve through points, rendered to canvas context
        function drawSmoothHull(ctx, points) {
            if (points.length < 3) return;

            const n = points.length;
            const tension = 0.5;

            ctx.beginPath();

            for (let i = 0; i < n; i++) {
                const p0 = points[(i - 1 + n) % n];
                const p1 = points[i];
                const p2 = points[(i + 1) % n];
                const p3 = points[(i + 2) % n];

                if (i === 0) {
                    ctx.moveTo(p1[0], p1[1]);
                }

                const cp1x = p1[0] + (p2[0] - p0[0]) / 6 * tension * 3;
                const cp1y = p1[1] + (p2[1] - p0[1]) / 6 * tension * 3;
                const cp2x = p2[0] - (p3[0] - p1[0]) / 6 * tension * 3;
                const cp2y = p2[1] - (p3[1] - p1[1]) / 6 * tension * 3;

                ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, p2[0], p2[1]);
            }

            ctx.closePath();
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
                drawClusterHulls(ctx);
            }

            // Draw links - batch non-highlighted links into a single path
            const highlightedLinks = [];
            ctx.beginPath();
            links.forEach(link => {
                const source = typeof link.source === 'object' ? link.source : nodes.find(n => n.id === link.source);
                const target = typeof link.target === 'object' ? link.target : nodes.find(n => n.id === link.target);
                if (!source || !target) return;

                const isHighlighted = hoveredNode && (
                    source.id === hoveredNode.id || target.id === hoveredNode.id
                );

                if (isHighlighted) {
                    highlightedLinks.push({ source, target, value: link.value });
                } else {
                    ctx.moveTo(source.x, source.y);
                    ctx.lineTo(target.x, target.y);
                }
            });
            // Stroke all non-highlighted links in one call
            ctx.strokeStyle = cssColors.borderColor;
            ctx.globalAlpha = hoveredNode ? 0.1 : 0.4;
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.globalAlpha = 1;

            // Draw highlighted links individually
            highlightedLinks.forEach(({ source, target }) => {
                ctx.beginPath();
                ctx.moveTo(source.x, source.y);
                ctx.lineTo(target.x, target.y);
                ctx.strokeStyle = cssColors.primary;
                ctx.globalAlpha = 0.8;
                ctx.lineWidth = 2;
                ctx.stroke();
            });
            ctx.globalAlpha = 1;

            // Label culling: compute radius threshold based on zoom and node count
            const labelRadiusThreshold = computeLabelThreshold(transform.k, nodes.length);

            // Draw nodes
            const fontStr = `600 10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`;
            ctx.font = fontStr;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

            nodes.forEach(node => {
                const isDimmed = hoveredNode && !connectedIds.has(node.id);
                const isSearched = searchQuery && node.label.toLowerCase().includes(searchQuery);
                const isSelected = selectedNode && selectedNode.id === node.id;
                const isHovered = hoveredNode && hoveredNode.id === node.id;

                ctx.globalAlpha = isDimmed ? 0.3 : 1;

                // Draw circle
                ctx.beginPath();
                ctx.arc(node.x, node.y, node.radius, 0, Math.PI * 2);
                ctx.fillStyle = clusterColorScale(node.cluster);
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

                // Draw label - use culling
                const showLabel = node.radius >= labelRadiusThreshold ||
                    isHovered || isSelected || isSearched;

                if (showLabel) {
                    // strokeText halo (replaces 8-pass shadow)
                    ctx.lineWidth = 3;
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = cssColors.bgSecondary;
                    ctx.strokeText(node.label, node.x, node.y + node.radius + 4);

                    ctx.fillStyle = isSearched ? cssColors.accentAmber : cssColors.textPrimary;
                    ctx.fillText(node.label, node.x, node.y + node.radius + 4);
                }

                ctx.globalAlpha = 1;
            });

            ctx.restore();
        }

        // Compute label visibility threshold
        function computeLabelThreshold(zoomLevel, nodeCount) {
            // At high zoom, show more labels; at low zoom or many nodes, show fewer
            if (nodeCount <= 50) return 0; // show all labels for small graphs
            const base = Math.max(4, 12 - zoomLevel * 6);
            // For very large graphs, raise the threshold
            if (nodeCount > 300) return base + 4;
            if (nodeCount > 150) return base + 2;
            return base;
        }

        // Draw cluster hulls with smooth curves and labels
        function drawClusterHulls(renderCtx) {
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

                    const color = clusterColorScale(clusterId);

                    // Draw smooth hull
                    drawSmoothHull(renderCtx, expandedHull);

                    renderCtx.fillStyle = color;
                    renderCtx.globalAlpha = 0.08;
                    renderCtx.fill();

                    renderCtx.strokeStyle = color;
                    renderCtx.globalAlpha = 0.3;
                    renderCtx.lineWidth = 2;
                    renderCtx.setLineDash([6, 3]);
                    renderCtx.stroke();
                    renderCtx.setLineDash([]);
                    renderCtx.globalAlpha = 1;

                    // Draw cluster label at centroid
                    const meta = clusterMeta.find(c => c.id === clusterId);
                    if (meta) {
                        const labelText = `${meta.representativeTag} (${meta.size})`;
                        renderCtx.font = '600 12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                        renderCtx.textAlign = 'center';
                        renderCtx.textBaseline = 'middle';
                        renderCtx.lineWidth = 3;
                        renderCtx.lineJoin = 'round';
                        renderCtx.strokeStyle = cssColors.bgPrimary;
                        renderCtx.globalAlpha = 0.8;
                        renderCtx.strokeText(labelText, centroid[0], centroid[1]);
                        renderCtx.fillStyle = color;
                        renderCtx.globalAlpha = 0.7;
                        renderCtx.fillText(labelText, centroid[0], centroid[1]);
                        renderCtx.globalAlpha = 1;
                    }
                }
            });
        }

        // Update legend with representative tag names
        function updateLegend() {
            const legend = document.getElementById('networkLegend');
            const items = document.getElementById('legendItems');

            if (filterState.showClusters && clusterMeta.length > 1) {
                legend.style.display = 'block';
                items.innerHTML = clusterMeta.map(cm => `
                    <div class="legend-item">
                        <div class="legend-color" style="background: ${clusterColorScale(cm.id)}"></div>
                        <span class="legend-label">${escapeHtml(cm.representativeTag)} (${cm.size})</span>
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
                const cm = clusterMeta.find(c => c.id === node.cluster);
                const clusterLabel = cm ? `${cm.representativeTag} (${cm.size})` : `Cluster ${node.cluster + 1}`;
                document.getElementById('selectedTagStats').innerHTML = `
                    ${node.count} bookmarks<br>
                    First used: ${new Date(node.first_seen).toLocaleDateString()}<br>
                    Cluster: ${clusterLabel}
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

        document.getElementById('zoomFit').addEventListener('click', () => {
            zoomToFit(300);
        });

        document.getElementById('zoomReset').addEventListener('click', () => {
            if (canvas && canvas._zoom) {
                d3.select(canvas).transition().duration(300).call(canvas._zoom.transform, d3.zoomIdentity);
            }
        });

        // Filter controls - heavy sliders use 'change' for applyFilters, 'input' for display only
        document.getElementById('minCount').addEventListener('input', function() {
            document.getElementById('minCountValue').textContent = this.value;
        });
        document.getElementById('minCount').addEventListener('change', function() {
            filterState.minCount = parseInt(this.value);
            applyFilters();
        });

        document.getElementById('minCooccurrence').addEventListener('input', function() {
            document.getElementById('minCooccurrenceValue').textContent = this.value;
        });
        document.getElementById('minCooccurrence').addEventListener('change', function() {
            filterState.minCooccurrence = parseInt(this.value);
            applyFilters();
        });

        document.getElementById('maxTags').addEventListener('input', function() {
            document.getElementById('maxTagsValue').textContent = this.value;
        });
        document.getElementById('maxTags').addEventListener('change', function() {
            filterState.maxTags = parseInt(this.value);
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

        // Clustering controls - live update strength without rebuilding
        document.getElementById('clusterStrength').addEventListener('input', function() {
            filterState.clusterStrength = parseFloat(this.value);
            document.getElementById('clusterStrengthValue').textContent = this.value;

            // Update force in-place and restart alpha
            if (simulation) {
                const force = simulation.force('cluster');
                if (force && force.strength) {
                    force.strength(filterState.clusterStrength);
                    simulation.alpha(0.3).restart();
                }
            }
        });

        document.getElementById('showClusters').addEventListener('change', function() {
            filterState.showClusters = this.checked;
            updateLegend();
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
                drawClusterHulls(exportCtx);
            }

            // Draw links
            exportCtx.beginPath();
            links.forEach(link => {
                const source = typeof link.source === 'object' ? link.source : nodes.find(n => n.id === link.source);
                const target = typeof link.target === 'object' ? link.target : nodes.find(n => n.id === link.target);
                if (!source || !target) return;
                exportCtx.moveTo(source.x, source.y);
                exportCtx.lineTo(target.x, target.y);
            });
            exportCtx.strokeStyle = cssColors.borderColor;
            exportCtx.globalAlpha = 0.4;
            exportCtx.lineWidth = 1;
            exportCtx.stroke();
            exportCtx.globalAlpha = 1;

            // Draw nodes
            const fontStr = '600 10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            exportCtx.font = fontStr;
            exportCtx.textAlign = 'center';
            exportCtx.textBaseline = 'top';

            nodes.forEach(node => {
                exportCtx.beginPath();
                exportCtx.arc(node.x, node.y, node.radius, 0, Math.PI * 2);
                exportCtx.fillStyle = clusterColorScale(node.cluster);
                exportCtx.fill();
                exportCtx.strokeStyle = cssColors.bgSecondary;
                exportCtx.lineWidth = 2;
                exportCtx.stroke();

                // Label with strokeText halo
                exportCtx.lineWidth = 3;
                exportCtx.lineJoin = 'round';
                exportCtx.strokeStyle = cssColors.bgSecondary;
                exportCtx.strokeText(node.label, node.x, node.y + node.radius + 4);
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

            // Cluster hulls
            if (filterState.showClusters) {
                clusters.forEach(clusterId => {
                    const clusterNodes = nodes.filter(n => n.cluster === clusterId);
                    if (clusterNodes.length < 3) return;

                    const points = clusterNodes.map(n => [n.x, n.y]);
                    const hull = d3.polygonHull(points);
                    if (!hull) return;

                    const centroid = d3.polygonCentroid(hull);
                    const expandedHull = hull.map(p => {
                        const dx = p[0] - centroid[0];
                        const dy = p[1] - centroid[1];
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        return [p[0] + (dx / dist) * 30, p[1] + (dy / dist) * 30];
                    });

                    const color = clusterColorScale(clusterId);

                    // Generate smooth SVG path using cubic bezier (Catmull-Rom)
                    const pathD = catmullRomSvgPath(expandedHull);
                    svgContent += `<path d="${pathD}" fill="${color}" fill-opacity="0.08"
                        stroke="${color}" stroke-opacity="0.3" stroke-width="2" stroke-dasharray="6 3"/>`;

                    // Cluster label
                    const meta = clusterMeta.find(c => c.id === clusterId);
                    if (meta) {
                        svgContent += `<text x="${centroid[0]}" y="${centroid[1]}"
                            text-anchor="middle" dominant-baseline="central"
                            font-family="-apple-system, sans-serif" font-size="12" font-weight="600"
                            fill="${color}" fill-opacity="0.7"
                            stroke="${cssColors.bgPrimary}" stroke-width="3" stroke-linejoin="round" stroke-opacity="0.8"
                            paint-order="stroke fill">${escapeHtml(meta.representativeTag)} (${meta.size})</text>`;
                    }
                });
            }

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
                const color = clusterColorScale(node.cluster);
                svgContent += `<circle cx="${node.x}" cy="${node.y}" r="${node.radius}"
                    fill="${color}" stroke="${cssColors.bgSecondary}" stroke-width="2"/>`;
                svgContent += `<text x="${node.x}" y="${node.y + node.radius + 14}"
                    text-anchor="middle" font-family="-apple-system, sans-serif" font-size="10" font-weight="600"
                    fill="${cssColors.textPrimary}"
                    stroke="${cssColors.bgSecondary}" stroke-width="3" stroke-linejoin="round"
                    paint-order="stroke fill">${escapeHtml(node.label)}</text>`;
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

        // Generate SVG Catmull-Rom closed path
        function catmullRomSvgPath(points) {
            const n = points.length;
            if (n < 3) return '';
            const tension = 0.5;
            let d = `M ${points[0][0]},${points[0][1]} `;

            for (let i = 0; i < n; i++) {
                const p0 = points[(i - 1 + n) % n];
                const p1 = points[i];
                const p2 = points[(i + 1) % n];
                const p3 = points[(i + 2) % n];

                const cp1x = p1[0] + (p2[0] - p0[0]) / 6 * tension * 3;
                const cp1y = p1[1] + (p2[1] - p0[1]) / 6 * tension * 3;
                const cp2x = p2[0] - (p3[0] - p1[0]) / 6 * tension * 3;
                const cp2y = p2[1] - (p3[1] - p1[1]) / 6 * tension * 3;

                d += `C ${cp1x},${cp1y} ${cp2x},${cp2y} ${p2[0]},${p2[1]} `;
            }

            d += 'Z';
            return d;
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
