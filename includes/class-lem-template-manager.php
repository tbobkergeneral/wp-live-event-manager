<?php
/**
 * LEM Template Manager
 *
 * Manages custom template pack discovery, installation, activation, and path
 * resolution. Template packs are stored in wp-content/lem-templates/{slug}/
 * so they survive plugin updates.
 *
 * Usage (from anywhere in the plugin):
 *   LEM_Template_Manager::resolve_template_file('single-event.php')
 *   LEM_Template_Manager::get_installed_templates()
 */
if (!defined('ABSPATH')) exit;

class LEM_Template_Manager {

    /** Base directory for all user-installed template packs. */
    const TEMPLATES_BASE_DIR = WP_CONTENT_DIR . '/lem-templates/';
    const TEMPLATES_BASE_URL = WP_CONTENT_URL . '/lem-templates/';

    /** Reserved slug — always points to the built-in plugin templates. */
    const DEFAULT_SLUG = 'default';

    /** Maximum ZIP upload size: 5 MB */
    const MAX_ZIP_SIZE = 5242880;

    /**
     * Returns the slug of the currently active template pack.
     * Falls back to 'default' if nothing is set.
     */
    public static function get_active_slug(): string {
        $settings = get_option('lem_settings', []);
        $slug     = sanitize_key($settings['active_event_template'] ?? '');
        return ($slug !== '') ? $slug : self::DEFAULT_SLUG;
    }

    /**
     * Returns the filesystem path to a template pack directory (with trailing slash).
     * 'default' resolves to the plugin's own /templates/ directory.
     */
    public static function get_template_dir(string $slug): string {
        if ($slug === self::DEFAULT_SLUG) {
            return LEM_PLUGIN_DIR . 'templates/';
        }
        return self::TEMPLATES_BASE_DIR . trailingslashit(sanitize_file_name($slug));
    }

    /**
     * Returns the public URL for a template pack directory (with trailing slash).
     */
    public static function get_template_url(string $slug): string {
        if ($slug === self::DEFAULT_SLUG) {
            return LEM_PLUGIN_URL . 'templates/';
        }
        return self::TEMPLATES_BASE_URL . trailingslashit(sanitize_file_name($slug));
    }

    /**
     * Core path resolver.
     *
     * Checks the active template pack for $filename first; falls back to the
     * plugin's built-in template. This means a pack only needs to ship the
     * files it actually overrides.
     *
     * @param  string $filename  e.g. 'single-event.php' or 'event-ticket-block.php'
     * @return string            Absolute path to the template file to load
     */
    public static function resolve_template_file(string $filename): string {
        $slug = self::get_active_slug();

        if ($slug !== self::DEFAULT_SLUG) {
            // 1. User-installed copy in wp-content/lem-templates/{slug}/
            $installed_path = self::get_template_dir($slug) . $filename;
            if (file_exists($installed_path)) {
                return $installed_path;
            }

            // 2. Bundled copy shipped with the plugin in template-packs/{slug}/
            //    This covers files added to a pack after the user already installed it,
            //    so they don't need to re-download and re-upload the ZIP.
            $bundled_path = LEM_PLUGIN_DIR . 'template-packs/'
                          . sanitize_file_name($slug) . '/' . $filename;
            if (file_exists($bundled_path)) {
                return $bundled_path;
            }
        }

        // 3. Plugin built-in default template
        return LEM_PLUGIN_DIR . 'templates/' . $filename;
    }

    /**
     * Returns the URL for a custom template asset (style.css or script.js).
     * Returns an empty string if the active template is default, or the asset
     * file doesn't exist in the active pack.
     *
     * @param  string $asset  e.g. 'assets/style.css'
     * @return string         URL or empty string
     */
    public static function get_active_asset_url(string $asset): string {
        $slug = self::get_active_slug();
        if ($slug === self::DEFAULT_SLUG) {
            return '';
        }

        // 1. User-installed copy
        $installed_dir = self::TEMPLATES_BASE_DIR . trailingslashit(sanitize_file_name($slug));
        if (file_exists($installed_dir . $asset)) {
            return self::TEMPLATES_BASE_URL . trailingslashit(sanitize_file_name($slug)) . $asset;
        }

        // 2. Bundled copy shipped with the plugin
        $bundled_dir = LEM_PLUGIN_DIR . 'template-packs/' . sanitize_file_name($slug) . '/';
        if (file_exists($bundled_dir . $asset)) {
            return LEM_PLUGIN_URL . 'template-packs/' . sanitize_file_name($slug) . '/' . $asset;
        }

        return '';
    }

    /**
     * Returns an array of all installed template packs.
     * The built-in 'default' entry is always the first item.
     *
     * @return array[]  Each item: ['slug', 'name', 'version', 'description', 'author', 'author_url', 'type', 'built_in']
     */
    public static function get_installed_templates(): array {
        $templates = [
            [
                'slug'        => self::DEFAULT_SLUG,
                'name'        => 'Default',
                'description' => 'Built-in plugin template.',
                'author'      => 'LEM',
                'author_url'  => '',
                'version'     => LEM_VERSION,
                'type'        => ['event_page', 'paywall'],
                'built_in'    => true,
            ],
        ];

        $base = self::TEMPLATES_BASE_DIR;
        if (!is_dir($base)) {
            return $templates;
        }

        foreach ((array) glob($base . '*', GLOB_ONLYDIR) as $dir) {
            $json_path = trailingslashit($dir) . 'template.json';
            if (!file_exists($json_path)) {
                continue;
            }
            $raw  = file_get_contents($json_path);
            $meta = json_decode($raw, true);
            if (!is_array($meta) || empty($meta['slug']) || empty($meta['name'])) {
                continue;
            }
            $meta['slug']     = sanitize_key($meta['slug']);
            $meta['built_in'] = false;
            $templates[]      = $meta;
        }

        return $templates;
    }

    /**
     * Activates a template pack by slug.
     * Validates that the slug is either 'default' or an installed pack.
     *
     * @param  string $slug
     * @return true|WP_Error
     */
    public static function activate_template(string $slug) {
        $slug = sanitize_key($slug);

        if ($slug !== self::DEFAULT_SLUG) {
            $dir = self::get_template_dir($slug);
            if (!is_dir($dir) || !file_exists($dir . 'template.json')) {
                return new WP_Error('not_found', 'Template pack not found.');
            }
        }

        $settings = get_option('lem_settings', []);
        $settings['active_event_template'] = $slug;
        update_option('lem_settings', $settings);
        return true;
    }

    /**
     * Deletes an installed template pack.
     * Refuses to delete 'default'. If the deleted pack is currently active,
     * the active template is reverted to 'default' first.
     *
     * @param  string $slug
     * @return true|WP_Error
     */
    public static function delete_template(string $slug) {
        $slug = sanitize_key($slug);

        if ($slug === self::DEFAULT_SLUG) {
            return new WP_Error('protected', 'The built-in default template cannot be deleted.');
        }

        $dir = self::get_template_dir($slug);

        // Confirm the path is genuinely inside TEMPLATES_BASE_DIR (guard against
        // slugs that might escape the base directory via sanitise_file_name edge cases).
        $real_base = realpath(self::TEMPLATES_BASE_DIR);
        $real_dir  = realpath($dir);
        if ($real_base === false || $real_dir === false || strpos($real_dir, $real_base) !== 0) {
            return new WP_Error('bad_path', 'Resolved path is outside the templates directory.');
        }

        if (!is_dir($real_dir)) {
            return new WP_Error('not_found', 'Template pack not found.');
        }

        // Revert to default before deleting if this pack is active.
        if (self::get_active_slug() === $slug) {
            self::activate_template(self::DEFAULT_SLUG);
        }

        $result = self::recursive_rmdir($real_dir);
        if (!$result) {
            return new WP_Error('delete_failed', 'Could not remove template directory.');
        }
        return true;
    }

    /**
     * Validates and installs a template pack from an uploaded ZIP file.
     *
     * Security enforced:
     *  - 5 MB size cap
     *  - Path traversal check on every ZIP entry
     *  - Explicit whitelist of allowed filenames
     *  - slug must match the top-level folder name
     *  - 'default' slug rejected
     *
     * @param  string $tmp_path  Path to the uploaded tmp file ($_FILES[...]['tmp_name'])
     * @return array|WP_Error    Parsed template.json metadata on success
     */
    public static function install_from_zip(string $tmp_path) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_ziparchive', 'The ZipArchive PHP extension is required to install template packs.');
        }
        if (!file_exists($tmp_path) || filesize($tmp_path) === 0) {
            return new WP_Error('empty_file', 'The uploaded file appears to be empty.');
        }
        if (filesize($tmp_path) > self::MAX_ZIP_SIZE) {
            return new WP_Error('too_large', 'The ZIP file exceeds the 5 MB size limit.');
        }

        $zip = new ZipArchive();
        $res = $zip->open($tmp_path);
        if ($res !== true) {
            return new WP_Error('bad_zip', 'Could not open the ZIP file (error code: ' . $res . ').');
        }

        // ── Discover the top-level folder name ────────────────────────────────────
        $folder_name = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Guard against path traversal
            if (strpos($name, '..') !== false || strpos($name, './') !== false) {
                $zip->close();
                return new WP_Error('path_traversal', 'The ZIP contains unsafe file paths.');
            }

            // First path component = top-level folder
            if ($folder_name === '') {
                $parts = explode('/', $name, 2);
                if (!empty($parts[0])) {
                    $folder_name = $parts[0];
                }
            }
        }

        $candidate_slug = sanitize_key($folder_name);
        if ($candidate_slug === '') {
            $zip->close();
            return new WP_Error('no_folder', 'The ZIP must contain a single top-level folder named after the template slug.');
        }
        if ($candidate_slug === self::DEFAULT_SLUG) {
            $zip->close();
            return new WP_Error('reserved_slug', '"default" is a reserved slug and cannot be used for a template pack.');
        }

        // ── Validate template.json ──────────────────────────────────────────────
        $json_content = $zip->getFromName($folder_name . '/template.json');
        if ($json_content === false) {
            $zip->close();
            return new WP_Error('missing_manifest', 'template.json was not found in the ZIP.');
        }

        $meta = json_decode($json_content, true);
        foreach (['name', 'slug', 'version'] as $required_key) {
            if (empty($meta[$required_key])) {
                $zip->close();
                return new WP_Error('invalid_manifest', 'template.json is missing the required "' . $required_key . '" field.');
            }
        }

        if (sanitize_key($meta['slug']) !== $candidate_slug) {
            $zip->close();
            return new WP_Error('slug_mismatch', 'The "slug" in template.json must match the ZIP\'s top-level folder name.');
        }

        // ── Allowed relative paths inside the pack ─────────────────────────────
        $allowed = [
            $folder_name . '/template.json',
            $folder_name . '/single-event.php',
            $folder_name . '/event-ticket-block.php',
            $folder_name . '/page-events.php',
            $folder_name . '/assets/style.css',
            $folder_name . '/assets/script.js',
            $folder_name . '/',
            $folder_name . '/assets/',
        ];

        // ── Create destination directories ────────────────────────────────────
        $dest_dir   = self::TEMPLATES_BASE_DIR . $candidate_slug . '/';
        $assets_dir = $dest_dir . 'assets/';
        wp_mkdir_p($dest_dir);
        wp_mkdir_p($assets_dir);

        // ── Extract whitelisted files ─────────────────────────────────────────
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if (!in_array($entry, $allowed, true)) {
                continue; // Silently skip anything not on the whitelist
            }
            if (substr($entry, -1) === '/') {
                continue; // Skip directory entries — already created above
            }

            $relative = substr($entry, strlen($folder_name) + 1);
            $out_path = $dest_dir . $relative;

            // Final safety: ensure resolved output path is inside $dest_dir
            $real_dest = realpath($dest_dir);
            $out_real  = realpath(dirname($out_path));
            if ($real_dest !== false && $out_real !== false && strpos($out_real, $real_dest) !== 0) {
                $zip->close();
                return new WP_Error('path_traversal', 'Extraction path escapes the template directory.');
            }

            file_put_contents($out_path, $zip->getFromIndex($i));
        }

        $zip->close();

        return $meta; // Success — return parsed metadata to caller
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Recursively removes a directory and all its contents.
     */
    private static function recursive_rmdir(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        return rmdir($dir);
    }
}
