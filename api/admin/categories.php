<?php declare(strict_types=1);

/**
 * 快活システム - カテゴリ一覧（管理画面用）
 *
 * GET /api/admin/categories.php
 * - 締めルール（closing_type, closing_day）を含めて返す
 * - 非アクティブも含めるが is_active=1 のみで運用想定（必要に応じて拡張）
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAdmin();
requireMethod('GET');

$rows = query(
    'SELECT code, name, closing_type, closing_day, is_active, sort_order
     FROM categories
     ORDER BY sort_order, code'
);

// 数値型に正規化
foreach ($rows as &$r) {
    $r['closing_day'] = (int)$r['closing_day'];
    $r['is_active']   = (int)$r['is_active'];
    $r['sort_order']  = (int)$r['sort_order'];
}
unset($r);

jsonResponse([
    'success' => true,
    'data'    => $rows,
]);
