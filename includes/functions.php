<?php declare(strict_types=1);

/**
 * 快活システム - 共通ユーティリティ関数
 */

require_once __DIR__ . '/config.php';

/**
 * HTMLエスケープのショートカット（XSS対策）
 *
 * @param string|null $str エスケープ対象の文字列
 * @return string エスケープ済み文字列
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * JSON APIレスポンスを返す
 *
 * @param mixed $data レスポンスデータ
 * @param int $status HTTPステータスコード
 */
function jsonResponse(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * JSONエラーレスポンスを返す
 *
 * @param string $message エラーメッセージ
 * @param int $status HTTPステータスコード
 */
function jsonError(string $message, int $status = 400): void
{
    jsonResponse([
        'success' => false,
        'error'   => $message,
    ], $status);
}

/**
 * 発注番号の採番
 * 種別×店舗×日付ごとに連番を管理
 *
 * @param string $type 発注種別（repair, equipment, parts, procurement）
 * @param string $shopCode 店舗コード
 * @param string|null $date 日付（Y-m-d形式、nullなら今日）
 * @return string 発注番号（例: REP-S01-20260301-0001）
 */
function generateOrderNumber(string $type, string $shopCode, ?string $date = null): string
{
    require_once __DIR__ . '/db.php';

    // 種別→プレフィクス変換
    $prefixMap = [
        'repair'      => ORDER_PREFIX_REPAIR,
        'equipment'   => ORDER_PREFIX_EQUIPMENT,
        'parts'       => ORDER_PREFIX_PARTS,
        'procurement' => ORDER_PREFIX_PROCUREMENT,
    ];
    $prefix = $prefixMap[$type] ?? throw new InvalidArgumentException("不正な発注種別: {$type}");

    // 店舗の短縮コードを取得
    $shop = getOne('SELECT short_code FROM shops WHERE code = :code', [':code' => $shopCode]);
    if ($shop === null) {
        throw new InvalidArgumentException("店舗が見つかりません: {$shopCode}");
    }
    $shortCode = $shop['short_code'];

    $targetDate = $date ?? date('Y-m-d');
    $dateStr = str_replace('-', '', $targetDate);

    $pdo = Database::getInstance();

    // 採番テーブルを更新（INSERT ON DUPLICATE KEY UPDATE で原子的に連番取得）
    $sql = 'INSERT INTO order_sequences (prefix, shop_code, date, current_seq)
            VALUES (:prefix, :shop_code, :date, 1)
            ON DUPLICATE KEY UPDATE current_seq = current_seq + 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':prefix'    => $prefix,
        ':shop_code' => $shopCode,
        ':date'      => $targetDate,
    ]);

    // 現在の連番を取得
    $seq = getOne(
        'SELECT current_seq FROM order_sequences WHERE prefix = :prefix AND shop_code = :shop_code AND date = :date',
        [':prefix' => $prefix, ':shop_code' => $shopCode, ':date' => $targetDate]
    );
    $seqNum = (int)$seq['current_seq'];

    return sprintf('%s-%s-%s-%04d', $prefix, $shortCode, $dateStr, $seqNum);
}

/**
 * 金額フォーマット
 *
 * @param int|null $amount 金額
 * @return string フォーマット済み文字列（例: ¥1,234）
 */
function formatCurrency(?int $amount): string
{
    if ($amount === null) {
        return '¥0';
    }
    return '¥' . number_format($amount);
}

/**
 * 現在の年度を取得（4月始まり）
 * 例: 2026年4月 → 2026年度（2026年4月〜2027年3月）
 *     2027年3月 → 2026年度（2026年4月〜2027年3月）
 *
 * @param string|null $date 基準日（Y-m-d形式、nullなら今日）
 * @return int 年度
 */
function getCurrentFiscalYear(?string $date = null): int
{
    $dt = $date ? new DateTime($date) : new DateTime();
    $year = (int)$dt->format('Y');
    $month = (int)$dt->format('n');

    // 4月以降は当年が年度、1〜3月は前年が年度
    return $month >= 4 ? $year : $year - 1;
}

/**
 * 現在の四半期を取得
 * Q1=4〜6月, Q2=7〜9月, Q3=10〜12月, Q4=1〜3月
 *
 * @param string|null $date 基準日（Y-m-d形式、nullなら今日）
 * @return string 四半期（Q1〜Q4）
 */
function getCurrentQuarter(?string $date = null): string
{
    $dt = $date ? new DateTime($date) : new DateTime();
    $month = (int)$dt->format('n');

    return match (true) {
        $month >= 4 && $month <= 6  => 'Q1',
        $month >= 7 && $month <= 9  => 'Q2',
        $month >= 10 && $month <= 12 => 'Q3',
        default                      => 'Q4', // 1〜3月
    };
}

/**
 * リダイレクト
 *
 * @param string $url リダイレクト先URL
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * POSTリクエストのJSONボディを取得
 *
 * @return array パース済みデータ
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

/**
 * リクエストメソッドを検証
 *
 * @param string $method 許可するメソッド（GET, POST, PUT, DELETE）
 */
function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        jsonError('Method Not Allowed', 405);
    }
}
