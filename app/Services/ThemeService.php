<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Http\UploadedFile;
use Exception;
use ZipArchive;

class ThemeService
{
    private const SYSTEM_THEME_DIR = 'theme/';
    private const USER_THEME_DIR = '/storage/theme/';
    private const CONFIG_FILE = 'config.json';
    private const SETTING_PREFIX = 'theme_';
    private const SYSTEM_THEMES = ['Xboard', 'v2board'];

    public function __construct()
    {
        $this->registerThemeViewPaths();
    }

    /**
     * Register theme view paths
     */
    private function registerThemeViewPaths(): void
    {
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            View::addNamespace('theme', $systemPath);
        }

        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            View::prependNamespace('theme', $userPath);
        }
    }

    /**
     * Get theme view path
     */
    public function getThemeViewPath(string $theme): ?string
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }
        return $themePath . '/dashboard.blade.php';
    }

    /**
     * Get all available themes
     */
    public function getList(): array
    {
        $themes = [];

        // 获取系统主题
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            $themes = $this->getThemesFromPath($systemPath, false);
        }

        // 获取用户主题
        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            $themes = array_merge($themes, $this->getThemesFromPath($userPath, true));
        }

        return $themes;
    }

    /**
     * Get themes from specified path
     */
    private function getThemesFromPath(string $path, bool $canDelete): array
    {
        return collect(File::directories($path))
            ->mapWithKeys(function ($dir) use ($canDelete) {
                $name = basename($dir);
                if (
                    !File::exists($dir . '/' . self::CONFIG_FILE) ||
                    !File::exists($dir . '/dashboard.blade.php')
                ) {
                    return [];
                }
                $config = $this->readConfigFile($name);
                if (!$config) {
                    return [];
                }

                $config['can_delete'] = $canDelete && $name !== admin_setting('frontend_theme', admin_setting('current_theme'));
                $config['is_system'] = !$canDelete;
                return [$name => $config];
            })->toArray();
    }

    /**
     * Upload new theme
     */
    public function upload(UploadedFile $file): bool
    {
        $zip = new ZipArchive;
        $tmpPath = storage_path('tmp/' . uniqid());

        try {
            if ($zip->open($file->path()) !== true) {
                throw new Exception('Invalid theme package');
            }

            SafeArchive::validate($zip);

            $configEntry = collect(range(0, $zip->numFiles - 1))
                ->map(fn($i) => $zip->getNameIndex($i))
                ->first(fn($name) => basename($name) === self::CONFIG_FILE);

            if (!$configEntry) {
                throw new Exception('Theme config file not found');
            }

            if (!$zip->extractTo($tmpPath)) throw new Exception('主题包解压失败');
            $zip->close();

            $sourcePath = $tmpPath . '/' . rtrim(dirname($configEntry), '.');
            $configFile = $sourcePath . '/' . self::CONFIG_FILE;

            if (!File::exists($configFile)) {
                throw new Exception('Theme config file not found');
            }

            $config = json_decode(File::get($configFile), true);
            if (empty($config['name'])) {
                throw new Exception('Theme name not configured');
            }
            $this->validateName($config['name']);

            if (in_array($config['name'], self::SYSTEM_THEMES)) {
                throw new Exception('Cannot upload theme with same name as system theme');
            }

            if (!File::exists($sourcePath . '/dashboard.blade.php')) {
                throw new Exception('Missing required theme file: dashboard.blade.php');
            }

            $userThemePath = base_path(self::USER_THEME_DIR);
            if (!File::exists($userThemePath)) {
                File::makeDirectory($userThemePath, 0755, true);
            }

            $targetPath = $userThemePath . $config['name'];
            if (is_link($targetPath)) throw new Exception('主题目录不能是软链接');
            if (File::exists($targetPath)) {
                $this->assertWithin($targetPath, $userThemePath);
                $oldConfigFile = $targetPath . '/config.json';
                if (!File::exists($oldConfigFile)) {
                    throw new Exception('Existing theme missing config file');
                }
                $oldConfig = json_decode(File::get($oldConfigFile), true);
                $oldVersion = $oldConfig['version'] ?? '0.0.0';
                $newVersion = $config['version'] ?? '0.0.0';
                if (version_compare($newVersion, $oldVersion, '>')) {
                    $this->cleanupThemeFiles($config['name']);
                    File::deleteDirectory($targetPath);
                    if (!File::copyDirectory($sourcePath, $targetPath)) throw new Exception('主题文件复制失败');
                    // 更新主题时保留用户配置
                    $this->initConfig($config['name'], true);
                    return true;
                } else {
                    throw new Exception('Theme exists and not a newer version');
                }
            }

            if (!File::copyDirectory($sourcePath, $targetPath)) throw new Exception('主题文件复制失败');
            $this->initConfig($config['name']);

            return true;

        } catch (Exception $e) {
            throw $e;
        } finally {
            if (File::exists($tmpPath)) {
                File::deleteDirectory($tmpPath);
            }
        }
    }

    /**
     * Switch theme
     */
    public function switch(string|null $theme): bool
    {
        if ($theme === null) {
            return true;
        }

        $this->validateName($theme);
        $currentTheme = admin_setting('frontend_theme', admin_setting('current_theme'));

        try {
            $themePath = $this->getThemePath($theme);
            if (!$themePath) {
                throw new Exception('Theme not found');
            }

            if (!File::exists($this->getThemeViewPath($theme))) {
                throw new Exception('Theme view file not found');
            }

            $this->publish($theme);
            if ($currentTheme && $currentTheme !== $theme && $this->validName($currentTheme)) $this->cleanupThemeFiles($currentTheme);

            admin_setting(['frontend_theme' => $theme, 'current_theme' => $theme]);
            return true;

        } catch (Exception $e) {
            Log::error('Theme switch failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete theme
     */
    public function delete(string $theme): bool
    {
        $this->validateName($theme);
        try {
            if (in_array($theme, self::SYSTEM_THEMES)) {
                throw new Exception('System theme cannot be deleted');
            }

            if ($theme === admin_setting('frontend_theme', admin_setting('current_theme'))) {
                throw new Exception('Current theme cannot be deleted');
            }

            $themePath = base_path(self::USER_THEME_DIR . $theme);
            $this->assertWithin($themePath, base_path(self::USER_THEME_DIR));
            if (!File::exists($themePath)) {
                throw new Exception('Theme not found');
            }

            $this->cleanupThemeFiles($theme);
            File::deleteDirectory($themePath);
            admin_setting([self::SETTING_PREFIX . $theme => null]);
            return true;

        } catch (Exception $e) {
            Log::error('Theme deletion failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if theme exists
     */
    public function exists(string $theme): bool
    {
        return $this->getThemePath($theme) !== null;
    }

    /**
     * Get theme path
     */
    public function getThemePath(string $theme): ?string
    {
        if (!$this->validName($theme)) return null;
        $systemPath = base_path(self::SYSTEM_THEME_DIR . $theme);
        if (File::exists($systemPath)) {
            $this->assertWithin($systemPath, base_path(self::SYSTEM_THEME_DIR));
            return $systemPath;
        }

        $userPath = base_path(self::USER_THEME_DIR . $theme);
        if (File::exists($userPath)) {
            $this->assertWithin($userPath, base_path(self::USER_THEME_DIR));
            return $userPath;
        }

        return null;
    }

    /**
     * Get theme config
     */
    public function getConfig(string $theme): ?array
    {
        $config = admin_setting(self::SETTING_PREFIX . $theme);
        if ($config === null) {
            $this->initConfig($theme);
            $config = admin_setting(self::SETTING_PREFIX . $theme);
        }
        return $config;
    }

    /**
     * Update theme config
     */
    public function updateConfig(string $theme, array $config): bool
    {
        $this->validateName($theme);
        try {
            if (!$this->getThemePath($theme)) {
                throw new Exception('Theme not found');
            }

            $schema = $this->readConfigFile($theme);
            if (!$schema) {
                throw new Exception('Invalid theme config file');
            }

            $validFields = collect($schema['configs'] ?? [])->pluck('field_name')->toArray();
            $validConfig = collect($config)
                ->only($validFields)
                ->toArray();

            $currentConfig = $this->getConfig($theme) ?? [];
            $newConfig = array_merge($currentConfig, $validConfig);

            admin_setting([self::SETTING_PREFIX . $theme => $newConfig]);
            return true;

        } catch (Exception $e) {
            Log::error('Config update failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Read theme config file
     */
    private function readConfigFile(string $theme): ?array
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }

        $file = $themePath . '/' . self::CONFIG_FILE;
        return File::exists($file) ? json_decode(File::get($file), true) : null;
    }

    /**
     * Clean up theme files including public directory
     */
    public function cleanupThemeFiles(string $theme): void
    {
        $this->validateName($theme);
        try {
            $publicThemePath = public_path('theme/' . $theme);
            if (File::exists($publicThemePath)) {
                $this->assertWithin($publicThemePath, public_path('theme'));
                File::deleteDirectory($publicThemePath);
                Log::info('Cleaned up public theme files', ['theme' => $theme, 'path' => $publicThemePath]);
            }

            $cacheKey = "theme_{$theme}_assets";
            if (cache()->has($cacheKey)) {
                cache()->forget($cacheKey);
                Log::info('Cleaned up theme cache', ['theme' => $theme, 'cache_key' => $cacheKey]);
            }

        } catch (Exception $e) {
            Log::warning('Failed to cleanup theme files', [
                'theme' => $theme,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Force refresh current theme public files
     */
    public function refreshCurrentTheme(): bool
    {
        try {
            $currentTheme = admin_setting('frontend_theme', admin_setting('current_theme', 'Xboard'));
            if (!$currentTheme) {
                return false;
            }

            $themePath = $this->getThemePath($currentTheme);
            if (!$themePath) {
                throw new Exception('Current theme path not found');
            }

            $this->publish($currentTheme);

            Log::info('Refreshed current theme files', ['theme' => $currentTheme]);
            return true;

        } catch (Exception $e) {
            Log::error('Failed to refresh current theme', [
                'theme' => $currentTheme,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function validName(mixed $theme): bool
    {
        return is_string($theme) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $theme) === 1;
    }

    private function validateName(mixed $theme): void
    {
        if (!$this->validName($theme)) throw new \App\Exceptions\ApiException('主题名称不正确', 422);
    }

    private function assertWithin(string $path, string $root): void
    {
        $real = realpath($path);
        $base = realpath($root);
        if (is_link($path) || !$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new \App\Exceptions\ApiException('主题目录超出允许范围', 422);
        }
    }

    /** 模板只在私有目录渲染；公开目录仅发布静态文件，整体替换避免残留旧脚本。 */
    public function publish(string $theme): bool
    {
        $this->validateName($theme);
        $source = $this->getThemePath($theme);
        if (!$source) throw new Exception('Theme not found');
        $extensions = ['js', 'css', 'json', 'html', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'wasm', 'webmanifest', 'mp4', 'webm'];
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) throw new \App\Exceptions\ApiException('主题不能包含软链接', 422);
            if (!$file->isFile()) continue;
            $relative = substr($file->getPathname(), strlen($source) + 1);
            if (preg_match('#(^|/)\.#', $relative) || strtolower($file->getBasename()) === 'config.json'
                || preg_match('/\.(php|phtml|phar|blade)(\.|$)/i', $file->getBasename())
                || !in_array(strtolower($file->getExtension()), $extensions, true)) continue;
            $files[$relative] = $file->getPathname();
        }
        $root = public_path('theme');
        if (is_link($root)) throw new Exception('静态资源根目录不能是软链接');
        File::ensureDirectoryExists($root, 0755);
        $target = $root . '/' . $theme;
        if (is_link($target)) throw new Exception('静态主题目录不能是软链接');
        if (File::exists($target)) $this->assertWithin($target, $root);
        $id = bin2hex(random_bytes(8));
        $stage = $root . '/.publish-' . $id;
        $backup = $root . '/.previous-' . $id;
        $moved = false;
        try {
            File::makeDirectory($stage, 0755);
            foreach ($files as $relative => $from) {
                $to = $stage . '/' . $relative;
                File::ensureDirectoryExists(dirname($to), 0755);
                if (!File::copy($from, $to)) throw new Exception('主题静态资源发布失败');
            }
            if (File::exists($target)) {
                if (!rename($target, $backup)) throw new Exception('主题旧资源无法替换');
                $moved = true;
            }
            if (!rename($stage, $target)) throw new Exception('主题静态资源切换失败');
            File::deleteDirectory($backup);
            return true;
        } catch (\Throwable $exception) {
            if ($moved && !File::exists($target)) rename($backup, $target);
            throw $exception;
        } finally {
            File::deleteDirectory($stage);
        }
    }

    public function removeLegacyPublicScripts(): void
    {
        $root = public_path('theme');
        if (is_link($root)) throw new Exception('静态资源根目录不能是软链接');
        if (!is_dir($root)) return;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = strtolower($file->getBasename());
            if ($file->isLink() || in_array($name, ['.htaccess', '.user.ini', 'config.json'], true)
                || preg_match('/\.(php[0-9]*|phtml|phar|blade)(\.|$)/i', $name)) {
                if (!File::delete($file->getPathname())) throw new Exception('无法清理旧主题公开脚本');
            }
        }
    }

    private function initConfig(string $theme, bool $preserveExisting = false): void
    {
        $config = $this->readConfigFile($theme);
        if (!$config) {
            return;
        }

        $defaults = collect($config['configs'] ?? [])
            ->mapWithKeys(fn($col) => [$col['field_name'] => $col['default_value'] ?? ''])
            ->toArray();

        if ($preserveExisting) {
            $existingConfig = admin_setting(self::SETTING_PREFIX . $theme) ?? [];
            $mergedConfig = array_merge($defaults, $existingConfig);
            admin_setting([self::SETTING_PREFIX . $theme => $mergedConfig]);
        } else {
            admin_setting([self::SETTING_PREFIX . $theme => $defaults]);
        }
    }
}
