<?php declare(strict_types=1);

/**
 * 快活システム - パスワードハッシュ化スクリプト
 *
 * seed.sql でプレーンテキスト 'password' として登録されたパスワードを
 * password_hash() でハッシュ化して更新する。
 *
 * 実行方法:
 *   php setup/hash_passwords.php
 *
 * ※ 一度だけ実行すること。既にハッシュ化済みの場合は再実行しても安全（スキップされる）。
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "=== パスワードハッシュ化スクリプト ===" . PHP_EOL;
echo PHP_EOL;

try {
    $users = query('SELECT id, login_id, password FROM users');

    if (empty($users)) {
        echo "ユーザーが見つかりません。seed.sql を先に実行してください。" . PHP_EOL;
        exit(1);
    }

    $updated = 0;
    $skipped = 0;

    foreach ($users as $user) {
        // 既にハッシュ化済み（$2y$ で始まる）ならスキップ
        if (str_starts_with($user['password'], '$2y$')) {
            echo "  [{$user['login_id']}] 既にハッシュ化済み → スキップ" . PHP_EOL;
            $skipped++;
            continue;
        }

        // プレーンテキストパスワードをハッシュ化
        $hashed = password_hash($user['password'], PASSWORD_BCRYPT);

        execute(
            'UPDATE users SET password = :password WHERE id = :id',
            [':password' => $hashed, ':id' => $user['id']]
        );

        echo "  [{$user['login_id']}] ハッシュ化完了" . PHP_EOL;
        $updated++;
    }

    echo PHP_EOL;
    echo "完了: {$updated}件を更新、{$skipped}件をスキップしました。" . PHP_EOL;
} catch (Throwable $e) {
    echo "エラー: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
