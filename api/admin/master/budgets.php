<?php declare(strict_types=1);

/**
 * 快活システム - 予算マスタ アップロードAPI（ピボット形式）
 *
 * POST /api/admin/master/budgets.php          確定実行
 * POST /api/admin/master/budgets.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=budgets.xlsx
 * master-crud-spec.md §2-7 参照
 *
 * 特殊仕様:
 *   - ピボット形式（1行=1店舗×1部門、月12列の予算額）
 *   - 複合キー: (shop_code, fiscal_year, month, department) ※departmentにはcategories.codeを格納
 *   - 年度スコープ: ファイル内の年度のみ更新対象
 *   - 削除なし運用（INSERT or UPDATE のみ）
 *   - actual_amount は触らない
 *   - 「全体」はDBに保存しない（必要時SUMで計算）
 *   - 部門列はカテゴリマスタの name で書く、内部で code に変換
 *   - 店舗が持たないカテゴリ（shop_categories に無い）はエラー
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleBudgetUpload($dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('budgets master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}

// ============================================================
// 予算マスタ専用 アップロード処理
// ============================================================
function handleBudgetUpload(bool $dryRun): array
{
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;

    if (!isset($_FILES['file'])) {
        jsonError('ファイルが添付されていません', 400);
    }
    try {
        $saved = saveUploadedFile($_FILES['file'], (int)$userId, 'budgets');
    } catch (Throwable $e) {
        jsonError($e->getMessage(), 400);
    }
    $filePath = $saved['path'];
    $origName = $saved['original_name'];

    try {
        // 0) カテゴリマスタ取得（部門ラベル↔コード変換用、shop_categories チェック用）
        $categories = query('SELECT code, name FROM categories WHERE is_active = 1 ORDER BY sort_order');
        $nameToCode = []; // 'フィットネス' => 'fitness'
        $codeToName = []; // 'fitness' => 'フィットネス'
        foreach ($categories as $c) {
            $nameToCode[$c['name']] = $c['code'];
            $codeToName[$c['code']] = $c['name'];
        }
        // 店舗×カテゴリの関連マップ: (shop_code => [cat_code => true])
        $shopCatRows = query('SELECT shop_code, category_code FROM shop_categories');
        $shopCats = [];
        foreach ($shopCatRows as $r) {
            $shopCats[$r['shop_code']][$r['category_code']] = true;
        }

        // 1) Excel解析（部門列は any text を許容、後でカテゴリ名と照合）
        $pivotColumns = [
            ['field' => 'fiscal_year',      'header' => '年度',       'type' => 'int',    'required' => true, 'min' => 2020, 'max' => 2099],
            ['field' => 'shop_code',        'header' => '店舗コード', 'type' => 'string', 'required' => true, 'max_length' => 5, 'pattern' => '/^\d{5}$/', 'pattern_msg' => '5桁の数字で指定してください'],
            ['field' => 'department_label', 'header' => '部門',       'type' => 'string', 'required' => true, 'max_length' => 50],
            ['field' => 'm04', 'header' => '4月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm05', 'header' => '5月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm06', 'header' => '6月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm07', 'header' => '7月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm08', 'header' => '8月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm09', 'header' => '9月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm10', 'header' => '10月', 'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm11', 'header' => '11月', 'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm12', 'header' => '12月', 'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm01', 'header' => '1月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm02', 'header' => '2月',  'type' => 'int', 'required' => true, 'min' => 0],
            ['field' => 'm03', 'header' => '3月',  'type' => 'int', 'required' => true, 'min' => 0],
        ];
        $parsed = parseUploadedXlsx($filePath, $pivotColumns);
        if (!empty($parsed['errors'])) {
            return ['success' => false, 'error' => 'Excelファイルにエラーがあります', 'data' => ['errors' => $parsed['errors']]];
        }

        // 2) バリデーション
        $validated = validateAndNormalize($parsed['rows'], $pivotColumns);
        $errors = $validated['errors'];

        // 2b) 部門ラベル → カテゴリコード変換
        //     「全体」など categories マスタに存在しないラベルは拒否
        $rows = $validated['rows'];
        foreach ($rows as $idx => &$r) {
            $label = $r['department_label'] ?? null;
            if ($label === null) continue;
            if ($label === '全体') {
                $errors[] = [
                    'row'     => $r['__row_num'] ?? 0,
                    'column'  => '部門',
                    'value'   => (string)$label,
                    'message' => '「全体」は各部門予算の合計から自動計算されます。入力対象外です',
                ];
            } else if (!isset($nameToCode[$label])) {
                $validNames = implode(' / ', array_keys($nameToCode));
                $errors[] = [
                    'row'     => $r['__row_num'] ?? 0,
                    'column'  => '部門',
                    'value'   => (string)$label,
                    'message' => "部門は「{$validNames}」のいずれかで指定してください",
                ];
            } else {
                $r['department'] = $nameToCode[$label];
            }
        }
        unset($r);

        // 2c) shop_code FK チェック（shops.code に存在必須）
        $shopFkErrors = checkFkReferences(
            $rows,
            [['field' => 'shop_code', 'ref_table' => 'shops', 'ref_column' => 'code']],
            ['shop_code' => '店舗コード']
        );
        $errors = array_merge($errors, $shopFkErrors);

        // 2d) ピボット内重複チェック + shop_categories 整合チェック
        $seenPivot = [];
        foreach ($rows as $r) {
            $year = $r['fiscal_year'] ?? null;
            $shop = $r['shop_code'] ?? null;
            $dept = $r['department'] ?? null;
            if ($year === null || $shop === null || $dept === null) continue;
            // 重複
            $k = "{$year}/{$shop}/{$dept}";
            if (isset($seenPivot[$k])) {
                $errors[] = [
                    'row'     => $r['__row_num'] ?? 0,
                    'column'  => '',
                    'value'   => $k,
                    'message' => "Excel内で年度+店舗+部門の組合せが重複しています（{$seenPivot[$k]}行目と同じ）",
                ];
            } else {
                $seenPivot[$k] = $r['__row_num'] ?? 0;
            }
            // shop_categories 整合
            if (!isset($shopCats[$shop][$dept])) {
                $deptLabel = $codeToName[$dept] ?? $dept;
                $errors[] = [
                    'row'     => $r['__row_num'] ?? 0,
                    'column'  => '部門',
                    'value'   => $deptLabel,
                    'message' => "店舗 {$shop} は「{$deptLabel}」を持っていないため予算を設定できません（shop_categories に未登録）",
                ];
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'error'   => 'Excelファイルにエラーが ' . count($errors) . ' 件あります',
                'data'    => ['errors' => $errors],
            ];
        }

        // 3) ピボット → 縦持ち展開
        $monthMap = ['m04'=>4,'m05'=>5,'m06'=>6,'m07'=>7,'m08'=>8,'m09'=>9,'m10'=>10,'m11'=>11,'m12'=>12,'m01'=>1,'m02'=>2,'m03'=>3];
        $vertical = [];
        foreach ($rows as $r) {
            $year = (int)$r['fiscal_year'];
            $shop = (string)$r['shop_code'];
            $dept = (string)$r['department']; // categories.code (例: 'fitness', 'golf')
            foreach ($monthMap as $field => $monthNum) {
                $vertical[] = [
                    'fiscal_year'   => $year,
                    'shop_code'     => $shop,
                    'month'         => $monthNum,
                    'department'    => $dept,
                    'budget_amount' => (int)$r[$field],
                ];
            }
        }

        // 4) 年度スコープで既存取得
        $yearsInFile = array_unique(array_map(fn($r) => (int)$r['fiscal_year'], $rows));
        sort($yearsInFile);
        if (empty($yearsInFile)) {
            return ['success' => false, 'error' => '有効な年度がファイル内にありません', 'data' => ['errors' => []]];
        }
        $placeholders = implode(',', array_fill(0, count($yearsInFile), '?'));
        $existingRows = query(
            "SELECT id, shop_code, fiscal_year, month, department, budget_amount
               FROM budgets
              WHERE fiscal_year IN ({$placeholders})",
            $yearsInFile
        );
        $existing = []; // key = year/shop/month/dept → amount
        foreach ($existingRows as $er) {
            $k = "{$er['fiscal_year']}/{$er['shop_code']}/{$er['month']}/{$er['department']}";
            $existing[$k] = (int)$er['budget_amount'];
        }

        // 5) 差分計算（INSERT or UPDATE のみ。削除なし。xlsxに無い部門は触らない）
        $inserts = [];
        $updates = [];
        $noChange = 0;
        foreach ($vertical as $v) {
            $k = "{$v['fiscal_year']}/{$v['shop_code']}/{$v['month']}/{$v['department']}";
            if (!isset($existing[$k])) {
                $inserts[] = $v;
            } else {
                $oldAmount = (int)$existing[$k];
                if ($oldAmount !== $v['budget_amount']) {
                    $updates[] = [
                        'before'        => array_merge($v, ['budget_amount' => $oldAmount]),
                        'after'         => $v,
                        'before_amount' => $oldAmount,
                        'after_amount'  => $v['budget_amount'],
                    ];
                } else {
                    $noChange++;
                }
            }
        }

        $summary = [
            'insert'    => count($inserts),
            'update'    => count($updates),
            'delete'    => 0,
            'no_change' => $noChange,
            'total'     => count($inserts) + count($updates),
            'years'     => $yearsInFile,
        ];

        // dry_run はここで返す（全件、共通レンダラ互換の形式）
        if ($dryRun) {
            $insertPreview = array_map(function($v) use ($codeToName) {
                return [
                    'fiscal_year'   => $v['fiscal_year'],
                    'shop_code'     => $v['shop_code'],
                    'month'         => $v['month'],
                    'department'    => $codeToName[$v['department']] ?? $v['department'],
                    'budget_amount' => $v['budget_amount'],
                ];
            }, $inserts);

            $updatePreview = array_map(function($u) use ($codeToName) {
                $key = "{$u['after']['fiscal_year']}/{$u['after']['shop_code']}/{$u['after']['month']}/{$u['after']['department']}";
                $deptName = $codeToName[$u['after']['department']] ?? $u['after']['department'];
                $beforeView = [
                    'fiscal_year' => $u['after']['fiscal_year'],
                    'shop_code'   => $u['after']['shop_code'],
                    'month'       => $u['after']['month'],
                    'department'  => $deptName,
                    'budget_amount' => $u['before_amount'],
                ];
                $afterView = $beforeView;
                $afterView['budget_amount'] = $u['after_amount'];
                return [
                    'key'            => $key,
                    'before'         => $beforeView,
                    'after'          => $afterView,
                    'changed_fields' => ['budget_amount'],
                ];
            }, $updates);

            return [
                'success' => true,
                'error'   => null,
                'data'    => [
                    'summary'  => $summary,
                    'errors'   => [],
                    'warnings' => [],
                    'diff'     => [
                        'insert' => $insertPreview,
                        'update' => $updatePreview,
                        'delete' => [],
                    ],
                ],
            ];
        }

        // 6) 確定実行（トランザクション）
        $batchId = generateUuid();
        beginTransaction();
        try {
            foreach ($inserts as $v) {
                execute(
                    "INSERT INTO budgets (shop_code, fiscal_year, month, department, budget_amount)
                     VALUES (:s, :y, :m, :d, :a)",
                    [
                        ':s' => $v['shop_code'],
                        ':y' => $v['fiscal_year'],
                        ':m' => $v['month'],
                        ':d' => $v['department'],
                        ':a' => $v['budget_amount'],
                    ]
                );
                $recKey = "{$v['fiscal_year']}/{$v['shop_code']}/{$v['month']}/{$v['department']}";
                logMasterChange('budgets', 'insert', $recKey, [
                    'after' => [
                        'fiscal_year'   => $v['fiscal_year'],
                        'shop_code'     => $v['shop_code'],
                        'month'         => $v['month'],
                        'department'    => $v['department'],
                        'budget_amount' => $v['budget_amount'],
                    ],
                ], $userId, $origName, $batchId);
            }
            foreach ($updates as $u) {
                $after = $u['after'];
                execute(
                    "UPDATE budgets SET budget_amount = :a
                      WHERE shop_code = :s AND fiscal_year = :y AND month = :m AND department = :d",
                    [
                        ':a' => $after['budget_amount'],
                        ':s' => $after['shop_code'],
                        ':y' => $after['fiscal_year'],
                        ':m' => $after['month'],
                        ':d' => $after['department'],
                    ]
                );
                $recKey = "{$after['fiscal_year']}/{$after['shop_code']}/{$after['month']}/{$after['department']}";
                logMasterChange('budgets', 'update', $recKey, [
                    'before'         => ['budget_amount' => $u['before_amount']],
                    'after'          => ['budget_amount' => $u['after_amount']],
                    'changed_fields' => ['budget_amount'],
                ], $userId, $origName, $batchId);
            }
            commit();
        } catch (Throwable $e) {
            rollback();
            throw $e;
        }

        return [
            'success' => true,
            'data' => [
                'summary'  => $summary,
                'batch_id' => $batchId,
            ],
        ];
    } finally {
        if (file_exists($filePath)) @unlink($filePath);
    }
}
