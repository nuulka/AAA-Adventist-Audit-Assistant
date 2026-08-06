<?php
date_default_timezone_set('Europe/Budapest');
$out = [];
$out[] = "=== PHP DIAG " . date('Y-m-d H:i:s') . " ===";
$out[] = "PHP_VERSION: " . PHP_VERSION;
$out[] = "PHP_BINARY: " . (PHP_BINARY ?: 'n/a');
$out[] = "SERVER_SOFTWARE: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI');

$exts = ['mysqli', 'pdo', 'pdo_mysql', 'mysqlnd'];
foreach ($exts as $e) {
    $out[] = "EXT_$e: " . (extension_loaded($e) ? 'loaded' : 'MISSING');
}

$out[] = "INI_mysqli.default_socket: '" . ini_get('mysqli.default_socket') . "'";
$out[] = "INI_pdo_mysql.default_socket: '" . ini_get('pdo_mysql.default_socket') . "'";
$out[] = "INI_mysqli.default_host: '" . ini_get('mysqli.default_host') . "'";
$out[] = "INI_mysqli.default_port: '" . ini_get('mysqli.default_port') . "'";
$out[] = "INI_pdo_mysql.default_port: '" . ini_get('pdo_mysql.default_port') . "'";
$out[] = "OPENSSL: " . (extension_loaded('openssl') ? 'loaded' : 'MISSING');

$args = array_slice($argv, 1);

if (count($args) === 4) {
    $targets = [
        'target1' => [
            'host' => $args[0],
            'name' => $args[1],
            'user' => $args[2],
            'pass' => $args[3],
        ],
    ];
    $out[] = "MODE: manual args (single target)";
} else {
    $out[] = "MODE: config file (or 4 CLI args: HOST DBNAME USER PASS)";
    $cfgDir = null;
    $dir = __DIR__;
    for ($i = 0; $i < 4; $i++) {
        if (file_exists($dir . '/config/app.php')) { $cfgDir = $dir; break; }
        $dir = dirname($dir);
    }
    $cfg = ['db' => ['revizor' => [], 'ots' => []]];
    if ($cfgDir) {
        $out[] = "CONFIG_DIR: $cfgDir";
        $a = include $cfgDir . '/config/app.php';
        if (is_array($a)) { $cfg = array_replace_recursive($cfg, $a); }
        $l = $cfgDir . '/config/app.local.php';
        if (file_exists($l)) {
            $lc = include $l;
            if (is_array($lc)) { $cfg = array_replace_recursive($cfg, $lc); }
        }
    } else {
        $out[] = "CONFIG_DIR: NOT FOUND (upload near config/ dir or use CLI args)";
    }

    $targets = [
        'revizor' => ['db' => $cfg['db']['revizor'] ?? [], 'name' => $cfg['db']['revizor']['name'] ?? 'revizor_db'],
        'ots'     => ['db' => $cfg['db']['ots'] ?? [], 'name' => $cfg['db']['ots']['name'] ?? 'ots'],
    ];
}

function diag_target(&$out, $label, $host, $dbname, $user, $pass) {
    $passStr = ($pass === '' ? '(empty)' : '(set)');
    $out[] = "--- [$label] host='$host' user='$user' pass$passStr db='$dbname' ---";
    if (class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers() ?: [], true)) {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $v = $pdo->query('SELECT VERSION()')->fetchColumn();
            $out[] = "[$label] PDO host=$host OK, server version: $v";
        } catch (Throwable $e) {
            $out[] = "[$label] PDO host=$host FAIL: " . $e->getMessage();
        }
    } else {
        $out[] = "[$label] PDO mysql driver NOT AVAILABLE";
    }
    if (function_exists('mysqli_connect')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = @mysqli_connect($host, $user, $pass, $dbname, 3306);
        if ($m) {
            $out[] = "[$label] MYSQLI host=$host OK, server version: " . mysqli_get_server_info($m);
            mysqli_close($m);
        } else {
            $out[] = "[$label] MYSQLI host=$host FAIL: " . (mysqli_connect_error() ?: 'unknown');
        }
    } else {
        $out[] = "[$label] mysqli NOT AVAILABLE";
    }
}

foreach ($targets as $label => $t) {
    if (isset($t['host'])) {
        $host = $t['host'];
        $user = $t['user'] ?? '';
        $pass = $t['pass'] ?? '';
        $name = $t['name'] ?? '';
    } else {
        $host = $t['db']['host'] ?? 'localhost';
        $user = $t['db']['user'] ?? ($label === 'revizor' ? 'revizor_rw' : 'ots_ro');
        $pass = $t['db']['pass'] ?? '';
        $name = $t['name'];
    }
    $hosts = [$host];
    if ($host === 'localhost' && !in_array('127.0.0.1', $hosts, true)) { $hosts[] = '127.0.0.1'; }
    foreach ($hosts as $h) {
        diag_target($out, $label, $h, $name, $user, $pass);
    }
}

$body = implode("\n", $out) . "\n";
echo $body;

$logFile = __DIR__ . '/php_diag_output.txt';
@file_put_contents($logFile, $body);
echo "=== LOG WRITTEN: $logFile ===\n";
