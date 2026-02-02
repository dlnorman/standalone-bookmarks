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
                                    <a href="<?= $config['base_path'] ?>/tag-admin.php" class="dropdown-item" role="menuitem">Tag
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
 * Note: Navigation styles are now in css/main.css
 * This function is kept for backward compatibility but does nothing
 */
function render_nav_styles()
{
    // Styles have been moved to css/main.css
    // This function is kept empty for backward compatibility
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
