<?php
// includes/backup_handler.php

function runBackup() {
    $backupDir = __DIR__ . '/../backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $timestamp  = date('Y-m-d_H-i-s');
    $zipName    = "backup_{$timestamp}.zip";
    $zipPath    = "{$backupDir}/{$zipName}";
    $sqlFile    = sys_get_temp_dir() . "/mctbs_dump_{$timestamp}.sql";

    // ── 1. DUMP DATABASE (pure PHP, no exec) ──
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'DB connection failed: ' . $e->getMessage()];
    }

    $sql  = "-- MCTBS Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: " . DB_NAME . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Table structure
        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $sql .= "-- ────────────────────────────────────────\n";
        $sql .= "-- Table: `{$table}`\n";
        $sql .= "-- ────────────────────────────────────────\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= array_values($createStmt)[1] . ";\n\n";

        // Table data
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $sql .= "INSERT INTO `{$table}` VALUES\n";
            $inserts = [];
            foreach ($rows as $row) {
                $vals = array_map(function($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote($v);
                }, array_values($row));
                $inserts[] = '(' . implode(', ', $vals) . ')';
            }
            $sql .= implode(",\n", $inserts) . ";\n\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($sqlFile, $sql);

    // ── 2. ZIP: SQL + uploads folder ──
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'message' => 'Could not create zip file.'];
    }

    // Add SQL dump
    $zip->addFile($sqlFile, "db_backup_{$timestamp}.sql");

    // Add uploads folder
    $uploadsDir = __DIR__ . '/../uploads';
    if (is_dir($uploadsDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath   = $file->getRealPath();
                $relativePath = 'uploads/' . substr($filePath, strlen($uploadsDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    $zip->close();
    @unlink($sqlFile); // clean up temp sql

    $sizeMB = round(filesize($zipPath) / 1024 / 1024, 2);

    // ── 3. LOG to backup_logs table ──
    try {
        $pdo->prepare("INSERT INTO backup_logs (filename, size_mb, created_by, created_at) VALUES (?, ?, ?, NOW())")
            ->execute([$zipName, $sizeMB, $_SESSION['user_id'] ?? 0]);
    } catch (PDOException $e) {
        // Table might not exist yet — silently continue
    }

    // ── 4. Auto-delete backups older than 30 days ──
    $loggedFiles = $pdo->query("SELECT filename FROM backup_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")
                       ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($loggedFiles as $old) {
        $oldPath = "{$backupDir}/{$old}";
        if (file_exists($oldPath)) @unlink($oldPath);
        $pdo->prepare("DELETE FROM backup_logs WHERE filename=?")->execute([$old]);
    }

    return [
        'success'  => true,
        'filename' => $zipName,
        'size'     => $sizeMB,
        'path'     => $zipPath,
    ];
}