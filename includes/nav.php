<?php
/**
 * Shared navigation component
 * Provides consistent navigation across all pages
 */

// Determine current page
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Define navigation structure
$nav_items = [
    'primary' => [
        [
            'id' => 'index',
            'label' => 'Bookmarks',
            'url' => $config['base_path'] . '/',
            'show_always' => true
        ],
        [
            'id' => 'gallery',
            'label' => 'Gallery',
            'url' => $config['base_path'] . '/gallery.php',
            'show_always' => true
        ],
        [
            'id' => 'archive',
            'label' => 'Archive',
            'url' => $config['base_path'] . '/archive.php',
            'show_always' => true
        ],
        [
            'id' => 'dashboard',
            'label' => 'Dashboard',
            'url' => $config['base_path'] . '/dashboard.php',
            'auth_required' => true
        ],
        [
            'id' => 'tags',
            'label' => 'Tags',
            'url' => $config['base_path'] . '/tags.php',
            'auth_required' => true
        ]
    ],
    'secondary' => [
        [
            'label' => 'Bookmarklet',
            'url' => $config['base_path'] . '/bookmarklet-setup.php',
            'auth_required' => true
        ],
        [
            'label' => 'RSS Feed',
            'url' => $config['base_path'] . '/rss.php'
        ],
        [
            'type' => 'divider'
        ],
        [
            'label' => 'Check Bookmarks',
            'url' => $config['base_path'] . '/check-bookmarks.php',
            'auth_required' => true
        ],
        [
            'type' => 'divider'
        ],
        [
            'label' => 'Import',
            'url' => $config['base_path'] . '/import.php',
            'auth_required' => true
        ],
        [
            'label' => 'Export',
            'url' => $config['base_path'] . '/export.php',
            'auth_required' => true
        ]
    ]
];

/**
 * Render the navigation header
 */
function render_nav($config, $isLoggedIn, $current_page, $page_title = null)
{
    global $nav_items;

    $title = $page_title ?? $config['site_title'];
    ?>
    <nav class="app-nav">
        <div class="nav-container">
            <div class="nav-brand">
                <a href="<?= $config['base_path'] ?>/" class="brand-link">
                    <span class="brand-title"><?= htmlspecialchars($title) ?></span>
                </a>
            </div>

            <div class="nav-content">
                <!-- Primary Navigation -->
                <div class="nav-primary">
                    <?php foreach ($nav_items['primary'] as $item): ?>
                        <?php if (isset($item['auth_required']) && $item['auth_required'] && !$isLoggedIn)
                            continue; ?>
                        <?php if (isset($item['show_always']) || $isLoggedIn): ?>
                            <?php
                            $is_active = ($current_page === $item['id']) ||
                                ($current_page === 'index' && $item['id'] === 'index');
                            $active_class = $is_active ? ' active' : '';
                            ?>
                            <a href="<?= $item['url'] ?>" class="nav-link<?= $active_class ?>"
                                aria-current="<?= $is_active ? 'page' : 'false' ?>">
                                <?= htmlspecialchars($item['label']) ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <!-- Secondary Navigation (Dropdown) -->
                <div class="nav-secondary">
                    <?php if ($isLoggedIn): ?>
                        <a href="<?= $config['base_path'] ?>/add-bookmark.php" class="nav-btn nav-btn-primary">
                            <span class="btn-label">Add Bookmark</span>
                        </a>
                    <?php endif; ?>

                    <div class="nav-dropdown">
                        <button class="nav-dropdown-trigger" onclick="toggleNavDropdown(event)" aria-haspopup="true"
                            aria-expanded="false" id="navMenuButton">
                            <span class="dropdown-label">Menu</span>
                        </button>
                        <div class="nav-dropdown-content" id="navDropdownMenu" role="menu" aria-labelledby="navMenuButton">
                            <?php foreach ($nav_items['secondary'] as $item): ?>
                                <?php
                                if (isset($item['auth_required']) && $item['auth_required'] && !$isLoggedIn) {
                                    continue;
                                }
                                ?>
                                <?php if (isset($item['type']) && $item['type'] === 'divider'): ?>
                                    <div class="dropdown-divider" role="separator"></div>
                                <?php else: ?>
                                    <a href="<?= $item['url'] ?>" class="dropdown-item"
                                        role="menuitem"><?= htmlspecialchars($item['label']) ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php if ($isLoggedIn): ?>
                                <div class="dropdown-divider" role="separator"></div>
                                <?php if (function_exists('is_admin') && is_admin()): ?>
                                    <a href="<?= $config['base_path'] ?>/admin.php" class="dropdown-item" role="menuitem">User
                                        Management</a>
                                <?php endif; ?>
                                <a href="<?= $config['base_path'] ?>/account.php" class="dropdown-item"
                                    role="menuitem">Account</a>
                                <a href="<?= $config['base_path'] ?>/logout.php" class="dropdown-item"
                                    role="menuitem">Logout</a>
                            <?php else: ?>
                                <div class="dropdown-divider" role="separator"></div>
                                <a href="<?= $config['base_path'] ?>/login.php" class="dropdown-item dropdown-item-highlight"
                                    role="menuitem">Login</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile Menu Toggle -->
            <button class="nav-mobile-toggle" onclick="toggleMobileNav()" aria-label="Toggle navigation menu"
                aria-expanded="false" id="mobileNavToggle">
                <span class="hamburger-icon"></span>
            </button>
        </div>
    </nav>
    <?php
}

/**
 * Render navigation styles
 */
function render_nav_styles()
{
    global $config;
    ?>
    <link rel="stylesheet" href="<?= $config['base_path'] ?>/css/dark-mode.css">
    <style>
        /* Navigation Container */
        .app-nav {
            background: var(--bg-secondary);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-subtle);
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 1000;
            margin-bottom: 20px;
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        @supports (backdrop-filter: blur(10px)) {
            .app-nav {
                background: rgba(255, 255, 255, 0.8);
            }

            @media (prefers-color-scheme: dark) {
                .app-nav {
                    background: rgba(45, 55, 72, 0.8);
                }
            }
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 200px 1fr auto;
            align-items: center;
            gap: 20px;
            min-height: 64px;
        }

        /* Brand */
        .nav-brand {
            display: flex;
            align-items: center;
        }

        .brand-link {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 18px;
            transition: opacity 0.2s, color 0.3s ease;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .brand-link:hover {
            opacity: 0.8;
        }

        .brand-title {
            display: inline-block;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Navigation Content */
        .nav-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        /* Primary Navigation */
        .nav-primary {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s;
            position: relative;
        }

        .nav-link:hover {
            background: rgba(52, 152, 219, 0.1);
            color: var(--accent-blue);
        }

        .nav-link.active {
            background: var(--accent-blue);
            color: white;
        }

        .nav-link.active:hover {
            background: var(--accent-blue-hover);
        }

        /* Secondary Navigation */
        .nav-secondary {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .nav-btn:hover {
            background: var(--bg-tertiary);
            border-color: var(--text-tertiary);
        }

        .nav-btn-primary {
            background: var(--accent-green);
            color: white;
            border-color: var(--accent-green);
        }

        .nav-btn-primary:hover {
            background: var(--accent-green-hover);
            border-color: var(--accent-green-hover);
        }

        /* Dropdown */
        .nav-dropdown {
            position: relative;
        }

        .nav-dropdown-trigger {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .nav-dropdown-trigger:hover {
            background: var(--bg-tertiary);
            border-color: var(--text-tertiary);
        }

        .nav-dropdown-trigger[aria-expanded="true"] {
            background: var(--bg-tertiary);
        }

        .nav-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            min-width: 200px;
            background: var(--bg-secondary);
            border-radius: 8px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-subtle);
            overflow: hidden;
            animation: dropdownFadeIn 0.15s ease-out;
        }

        .nav-dropdown-content.active {
            display: block;
        }

        @keyframes dropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown-item {
            display: block;
            padding: 10px 16px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 14px;
            transition: background 0.15s;
        }

        .dropdown-item:hover {
            background: rgba(52, 152, 219, 0.08);
            color: var(--accent-blue);
        }

        .dropdown-item-highlight {
            font-weight: 600;
            color: var(--accent-blue);
        }

        .dropdown-divider {
            height: 1px;
            background: var(--border-subtle);
            margin: 4px 0;
        }

        /* Mobile Toggle */
        .nav-mobile-toggle {
            display: none;
            background: transparent;
            border: none;
            padding: 8px;
            cursor: pointer;
            margin-left: 12px;
        }

        .hamburger-icon {
            display: block;
            width: 24px;
            height: 2px;
            background: var(--text-primary);
            position: relative;
            transition: background 0.3s;
        }

        .hamburger-icon::before,
        .hamburger-icon::after {
            content: '';
            display: block;
            width: 24px;
            height: 2px;
            background: var(--text-primary);
            position: absolute;
            left: 0;
            transition: all 0.3s;
        }

        .hamburger-icon::before {
            top: -8px;
        }

        .hamburger-icon::after {
            top: 8px;
        }

        .nav-mobile-toggle[aria-expanded="true"] .hamburger-icon {
            background: transparent;
        }

        .nav-mobile-toggle[aria-expanded="true"] .hamburger-icon::before {
            top: 0;
            transform: rotate(45deg);
        }

        .nav-mobile-toggle[aria-expanded="true"] .hamburger-icon::after {
            top: 0;
            transform: rotate(-45deg);
        }

        /* Responsive Design */
        @media (max-width: 900px) {
            .nav-container {
                grid-template-columns: 150px 1fr auto;
                gap: 10px;
            }
        }

        @media (max-width: 640px) {
            .nav-container {
                display: flex;
                flex-wrap: wrap;
                padding: 12px 16px;
            }

            .nav-brand {
                order: 1;
            }

            .nav-mobile-toggle {
                display: block;
                order: 2;
            }

            .nav-content {
                order: 3;
                width: 100%;
                margin-left: 0;
                margin-top: 12px;
                display: none;
                flex-direction: column;
                gap: 12px;
            }

            .nav-content.mobile-active {
                display: flex;
            }

            .nav-primary {
                width: 100%;
                flex-direction: column;
                gap: 4px;
            }

            .nav-link {
                width: 100%;
                justify-content: flex-start;
                padding: 10px 14px;
            }

            .nav-secondary {
                width: 100%;
                flex-direction: column;
            }

            .nav-btn,
            .nav-dropdown-trigger {
                width: 100%;
                justify-content: flex-start;
                padding: 10px 14px;
            }

            .nav-dropdown-content {
                position: static;
                box-shadow: none;
                border: 1px solid var(--border-subtle);
                margin-top: 4px;
            }
        }
    </style>
    <?php
}

/**
 * Render navigation JavaScript
 */
function render_nav_scripts()
{
    ?>
    <script>
        // Dropdown toggle
        function toggleNavDropdown(event) {
            event.stopPropagation();
            const trigger = document.getElementById('navMenuButton');
            const menu = document.getElementById('navDropdownMenu');
            const isActive = menu.classList.contains('active');

            menu.classList.toggle('active');
            trigger.setAttribute('aria-expanded', !isActive);
        }

        // Mobile navigation toggle
        function toggleMobileNav() {
            const navContent = document.querySelector('.nav-content');
            const toggle = document.getElementById('mobileNavToggle');
            const isActive = navContent.classList.contains('mobile-active');

            navContent.classList.toggle('mobile-active');
            toggle.setAttribute('aria-expanded', !isActive);
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function (event) {
            const dropdown = document.querySelector('.nav-dropdown');
            const menu = document.getElementById('navDropdownMenu');
            const trigger = document.getElementById('navMenuButton');

            if (menu && !dropdown.contains(event.target)) {
                menu.classList.remove('active');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            }
        });

        // Close mobile nav when clicking a link
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function () {
                const navContent = document.querySelector('.nav-content');
                const toggle = document.getElementById('mobileNavToggle');
                if (navContent.classList.contains('mobile-active')) {
                    navContent.classList.remove('mobile-active');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        });

        // Close mobile nav on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const navContent = document.querySelector('.nav-content');
                const toggle = document.getElementById('mobileNavToggle');
                const menu = document.getElementById('navDropdownMenu');
                const trigger = document.getElementById('navMenuButton');

                if (navContent.classList.contains('mobile-active')) {
                    navContent.classList.remove('mobile-active');
                    toggle.setAttribute('aria-expanded', 'false');
                }

                if (menu && menu.classList.contains('active')) {
                    menu.classList.remove('active');
                    trigger.setAttribute('aria-expanded', 'false');
                }
            }
        });
    </script>
    <?php
}
