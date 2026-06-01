<?php declare(strict_types=1);

/**
 * 快活システム - マスタ最終更新日時 取得API (admin 専用)
 *
 * 監査ログ(master_change_log)から target_table ごとの最新変更日時を返す。
 * 専用カラムは持たず、マスタアップロード適用のたびに記録される監査ログから
 * その場で MAX(changed_at) を算出するため、常に最新の状態を反映する。
 *
 * GET /api/admin/master/last-updated.php
 * レスポンス: { success, data: { zones: 'YYYY-MM-DD HH:MM:SS', areas: ..., ... } }
 *   一度も変更が無いテーブルはキーごと存在しない（フロントで「—」表示）。
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

requireAdmin();
requireMethod('GET');

$rows = query(
    'SELECT target_table, MAX(changed_at) AS last_at
       FROM master_change_log
      GROUP BY target_table'
);

$map = [];
foreach ($rows as $r) {
    if ($r['target_table'] !== null && $r['last_at'] !== null) {
        $map[$r['target_table']] = $r['last_at'];
    }
}

jsonResponse(['success' => true, 'data' => $map]);
