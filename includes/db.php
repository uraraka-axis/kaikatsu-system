<?php declare(strict_types=1);

/**
 * 快活システム - データベース接続・ヘルパー
 */

require_once __DIR__ . '/config.php';

/**
 * PDO接続をシングルトンで管理するクラス
 */
class Database
{
    private static ?PDO $instance = null;

    /**
     * PDOインスタンスを取得（シングルトン）
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci",
            ];

            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        }

        return self::$instance;
    }

    /**
     * コネクションをリセット（テスト用）
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // インスタンス化を禁止
    private function __construct() {}
    private function __clone() {}
}

/**
 * SELECT文を実行して全行を返す
 *
 * @param string $sql SQL文
 * @param array $params バインドパラメータ
 * @return array 結果の配列
 */
function query(string $sql, array $params = []): array
{
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Database query error: ' . $e->getMessage() . ' SQL: ' . $sql);
        throw $e;
    }
}

/**
 * INSERT/UPDATE/DELETE文を実行して影響行数を返す
 *
 * @param string $sql SQL文
 * @param array $params バインドパラメータ
 * @return int 影響行数
 */
function execute(string $sql, array $params = []): int
{
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('Database execute error: ' . $e->getMessage() . ' SQL: ' . $sql);
        throw $e;
    }
}

/**
 * SELECT文を実行して1行だけ返す
 *
 * @param string $sql SQL文
 * @param array $params バインドパラメータ
 * @return array|null 結果の連想配列またはnull
 */
function getOne(string $sql, array $params = []): ?array
{
    try {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    } catch (PDOException $e) {
        error_log('Database getOne error: ' . $e->getMessage() . ' SQL: ' . $sql);
        throw $e;
    }
}

/**
 * 最後に挿入したIDを取得
 *
 * @return string 最後のINSERT ID
 */
function lastInsertId(): string
{
    return Database::getInstance()->lastInsertId();
}

/**
 * トランザクション開始
 */
function beginTransaction(): void
{
    Database::getInstance()->beginTransaction();
}

/**
 * トランザクションコミット
 */
function commit(): void
{
    Database::getInstance()->commit();
}

/**
 * トランザクションロールバック
 */
function rollback(): void
{
    Database::getInstance()->rollBack();
}
