# マスタCRUD（Excelアップロード方式）仕様書

最終更新: 2026-05-25
対象: 快活フロンティア 発注管理システム / develop ブランチ
ステータス: **本仕様の範囲は実装完了**（次フェーズの予約更新も別途完成済）

## 0. 概要

管理メニュー画面（admin-menu.html）から、Excel ファイルのアップロードにより 7 種のマスタを一括更新する機能。

### 対象マスタ（実装順）

| 順 | マスタ | テーブル | 主キー（マッチング） | FK依存 | 状況 |
|---|---|---|---|---|---|
| 1 | ゾーン | zones | code (VARCHAR 3) | なし | 完了 |
| 2 | エリア | areas | code (VARCHAR 3) | zones | 完了 |
| 3 | 店舗 | shops | code (VARCHAR 5) | areas | 完了 |
| 4 | 仕入先 | suppliers | code (VARCHAR 20) | なし | 完了 |
| 5 | ユーザー | users | login_id (VARCHAR 50) | shops | 完了（password マスク／system ロール対応） |
| 6 | 商品 | products | code (VARCHAR 20) | categories, suppliers | 完了（JAN・仕入先商品コード列追加済） |
| 7 | 予算 | budgets | (shop, year, month, dept) 複合 | shops | 完了（ピボット形式） |

### 本仕様の範囲

- ✅ 含む: Excel アップロード／プレビュー／確定／現状ダウンロード／監査ログ／products テーブル拡張
- ✅ 含む: 予約更新（フェーズ1: zones/areas/suppliers、フェーズ2A: shops/users/products/shop_categories、フェーズ2B: budgets）
- ✅ 含む: 監査ログ閲覧UI（B-3、system ロール専用、master-change-log.html）
- ✅ 含む: 機密列（password 等）のマスク（プレビュー／予約レコード／監査ログのすべて）
- ❌ 含まない: 画像ファイル本体のアップロード（FTP 直配置運用）／インライン CRUD

---

## 1. 操作フロー

```
┌─ admin-menu.html ─────────────────────────┐
│  ┌──────────────────┐                    │
│  │ ゾーンマスタ          │                    │
│  │ ┌──────────────┐ │                    │
│  │ │ クリックまたはD&D    │ │                    │
│  │ │ で .xlsx を選択    │ │                    │
│  │ └──────────────┘ │                    │
│  │ 最終更新: 2026/05/21  [📥 DL] [Excel] │
│  └──────────────────┘                    │
└────────────────────────────────────────┘
       │ ファイル選択
       ↓
  POST /api/admin/master/zones.php?dry_run=1  (multipart)
       │
       ↓ 解析・バリデーション
       ↓
┌─ プレビューモーダル ──────────────────────────┐
│  ゾーンマスタ 変更プレビュー                       │
│  追加: 1件   変更: 2件   削除: 0件   エラー: 0件    │
│  ─────────────────────────────       │
│  [追加] 300 中部                              │
│  [変更] 100 東日本 (表示順 1→2)                 │
│  [変更] 200 西日本 (表示順 2→1)                 │
│  ─────────────────────────────       │
│              [キャンセル] [この内容で確定]         │
└────────────────────────────────────────┘
       │ 「確定」クリック
       ↓
  POST /api/admin/master/zones.php  (multipart, dry_run なし)
       │
       ↓ トランザクションで反映 + master_change_log に記録
       ↓
  [完了モーダル] 「ゾーンマスタを更新しました（追加1件、変更2件）」
```

### エラー時

- バリデーションエラー（必須欠落・形式不正・FK 違反・コード重複等）が 1 件でもある場合、`dry_run` 段階で全エラーをリストアップしたモーダル表示。
- 「確定」ボタンは押下不可。
- ユーザーは Excel を修正して再アップロード。

---

## 2. Excel フォーマット仕様

### 共通ルール

- ファイル形式: .xlsx（Office Open XML）
- 1 シート目のみ読む。シート名は不問
- 1 行目: 日本語ヘッダ（**完全一致**チェック。順序固定）
- 2 行目以降: データ
- 空行は無視
- 全行 trim 処理
- 「有効」列: `TRUE` / `FALSE` または `1` / `0`（大文字小文字不問）

### 2-1. ゾーンマスタ (zones.xlsx)

| 列 | A | B | C | D |
|---|---|---|---|---|
| ヘッダ | コード | ゾーン名 | 有効 | 表示順 |
| 型 | VARCHAR(3) | VARCHAR(50) | BOOL | INT |
| 必須 | ✓ | ✓ | ✓ | ✓ |

### 2-2. エリアマスタ (areas.xlsx)

| 列 | A | B | C | D | E |
|---|---|---|---|---|---|
| ヘッダ | コード | エリア名 | ゾーンコード | 有効 | 表示順 |
| 型 | VARCHAR(3) | VARCHAR(50) | VARCHAR(3) | BOOL | INT |
| 必須 | ✓ | ✓ | ✓ | ✓ | ✓ |

### 2-3. 店舗マスタ (shops.xlsx)

| 列 | A | B | C | D | E | F |
|---|---|---|---|---|---|---|
| ヘッダ | 店舗コード | 店舗名 | 短縮コード | エリアコード | 有効 | 表示順 |
| 型 | VARCHAR(5) | VARCHAR(50) | VARCHAR(3) UNIQUE | VARCHAR(3) | BOOL | INT |
| 必須 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

### 2-4. 仕入先マスタ (suppliers.xlsx)

| 列 | A | B | C | D | E | F | G |
|---|---|---|---|---|---|---|---|
| ヘッダ | 仕入先コード | 仕入先名 | 担当者 | 電話番号 | メールアドレス | 有効 | 表示順 |
| 型 | VARCHAR(20) | VARCHAR(100) | VARCHAR(100) | VARCHAR(20) | VARCHAR(100) | BOOL | INT |
| 必須 | ✓ | ✓ | - | - | - | ✓ | ✓ |

※ 既存テーブルでは `id` が主キー、`code` が NULL 可だが、Excel 運用では `code` を必須化してマッチングキーに用いる（NOT NULL 化はしない、運用ルール）。

### 2-5. ユーザーマスタ (users.xlsx)

| 列 | A | B | C | D | E | F | G |
|---|---|---|---|---|---|---|---|
| ヘッダ | ログインID | ユーザー名 | ロール | 所属店舗コード | パスワード | 有効 | 表示順 |
| 型 | VARCHAR(50) | VARCHAR(50) | shop/admin | VARCHAR(5) | VARCHAR(255) | BOOL | INT |
| 必須 | ✓ | ✓ | ✓ | role=shop時必須 | (条件付) | ✓ | ✓ |

**パスワードの特殊扱い**:
- ダウンロード時: マスクして `********` で出力
- アップロード時:
  - `********` または空欄 → 既存パスワードを維持（変更しない）
  - 任意文字列 → bcrypt でハッシュ化して保存
- 新規追加時はパスワード必須

### 2-6. 商品マスタ (products.xlsx)

| 列 | A | B | C | D | E | F | G | H | I | J | K |
|---|---|---|---|---|---|---|---|---|---|---|---|
| ヘッダ | 商品コード | 商品名 | カテゴリコード | 仕入先コード | JANコード | 仕入先商品コード | 価格 | おすすめ | 画像パス | 説明 | 有効 | 表示順 |

(列が増えるため修正)

| 列 | ヘッダ | 型 | 必須 |
|---|---|---|---|
| A | 商品コード | VARCHAR(20) UNIQUE | ✓ |
| B | 商品名 | VARCHAR(100) | ✓ |
| C | カテゴリコード | VARCHAR(20) | ✓ |
| D | 仕入先コード | VARCHAR(20) | - |
| E | JANコード | VARCHAR(13) | - |
| F | 仕入先商品コード | VARCHAR(50) | - |
| G | 価格 | INT (税込円) | ✓ |
| H | おすすめ | BOOL | ✓ |
| I | 画像ファイル名 | VARCHAR(255) | - |
| J | 説明 | TEXT | - |
| K | 有効 | BOOL | ✓ |
| L | 表示順 | INT | ✓ |

**画像ファイル名**:
- ファイル本体は FTP で `uploads/products/{filename}` に配置（運用者作業）
- Excel には**ファイル名のみ**を記載（例: `mat-001.jpg`）
- DB の `image_path` カラムにも**ファイル名のみ**を保存（例: `mat-001.jpg`）
- ディレクトリ部分 `uploads/products/` はアプリケーション側で固定値として持ち、`api/products.php` が返却するときに `uploads/products/{filename}` の形に組み立てて返す（フロントは従来通り受け取った値を img タグの src に設定するだけ）
- 既存データ確認済み: 現在 `image_path` は全レコードで NULL のため、データ正規化のマイグレーションは不要

### 2-7. 予算マスタ (budgets.xlsx) ★追加（2026-05-22）

予算データは「店舗 × 年度 × 月 × 部門」の4軸を持つため、他マスタと異なり**ピボット形式**を採用する。

| 列 | A | B | C | D 〜 O |
|---|---|---|---|---|
| ヘッダ | 年度 | 店舗コード | 部門 | 4月, 5月, ..., 3月（会計年度順、12列） |
| 型 | INT | VARCHAR(5) | フィットネス/インドアゴルフ のみ | INT (≥0) |
| 必須 | ✓ | ✓ | ✓ | ✓ |

**特殊事情**:

- **複合ユニークキー**: `(shop_code, fiscal_year, month, department)`。共通基盤の単一PK前提が使えないため、`api/admin/master/budgets.php` 内で独自に diff・apply を実装する。共通基盤からは `parseUploadedXlsx` / `saveUploadedFile` / `logMasterChange` / `generateUuid` / トランザクション系のみ流用。
- **fiscal_year の意味**: `2026` = 2026年4月〜2027年3月（日本の標準的会計年度。スキーマコメントが旧定義で誤っているが、本仕様で正とする）。
- **部門マッピング**: Excel入力時の日本語値を内部コードに変換 → フィットネス=fit / インドアゴルフ=ig
- **「全体」は自動計算**: Excel入力対象外。サーバー側で各 (shop, year, month) について all = fit + ig として計算し、DB の `department='all'` レコードを自動メンテナンス。Excel に「全体」行が含まれていた場合はエラー。
- **店舗ごとに任意の部門構成**: 各店舗について、フィットネスだけ / インドアゴルフだけ / 両方、のいずれもOK。
- **xlsxに無い部門は既存値維持**: xlsx に出てこなかった部門（例: ig 行なし）はDBの既存値を変更せず、全体計算時にも既存値を使う。
- **DLにも「全体」行を含まない**: ダウンロードした xlsx を編集→再アップで自然な往復ができるよう、DL側でも fit と ig のみ出力。
- **年度スコープ**: アップロードファイル内の `年度` 列に含まれる年度のみを更新対象とする。他年度の DB データには触らない（誤って前年度を消す事故防止）。
- **削除なし運用**: ファイル内に存在しない `(shop, year, month, dept)` 組合せは「変更なし」扱い（INSERT or UPDATE のみ）。
- **`actual_amount` は対象外**: 実績は他系統（発注/締め処理）で更新するため、予算マスタからは触らない。
- **ファイル名**: 自由（例: `budget_master_2026年度.xlsx` 推奨だが固定ではない）。
- **プレビュー件数制限なし**: dry_run プレビューは全件返却（初回アップロードは数千件になることもあるが、表示は許容）。

**月列の並び**: 会計年度順に 4月, 5月, 6月, 7月, 8月, 9月, 10月, 11月, 12月, 1月, 2月, 3月 と並べる（4-12月は当年、1-3月は翌年）。

---

## 3. DB マイグレーション（products テーブル拡張）

新規ファイル: `database/migration_products_codes.sql`

```sql
-- products テーブルに JAN コード・仕入先商品コードを追加
ALTER TABLE products
  ADD COLUMN jan_code VARCHAR(13) NULL COMMENT 'JANコード（13桁）' AFTER category_code,
  ADD COLUMN supplier_product_code VARCHAR(50) NULL COMMENT '仕入先商品コード' AFTER jan_code,
  ADD INDEX idx_products_jan (jan_code),
  ADD INDEX idx_products_supplier_product (supplier_product_code);
```

`schema.sql` も同期更新。

### JAN コードの扱い

- 形式: 8 桁 または 13 桁の数字のみ（厳密チェック）
- 重複: 同一 JAN を持つ商品があってもエラーにしない（複数仕入先の同一商品ケースに対応）。ただし警告表示は検討
- 空欄可

---

## 4. 監査ログテーブル

新規ファイル: `database/migration_master_change_log.sql`

```sql
CREATE TABLE master_change_log (
  id               INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  target_table     VARCHAR(50)  NOT NULL COMMENT '対象テーブル名 (zones/areas/shops/suppliers/users/products)',
  operation        ENUM('insert','update','delete') NOT NULL COMMENT '操作種別',
  record_key       VARCHAR(100) NOT NULL COMMENT '対象レコードのキー（code, login_id等）',
  change_data      JSON         NULL     COMMENT '変更内容（before/after の JSON）',
  changed_by_id    INT          NULL     COMMENT '変更者ユーザーID',
  changed_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '変更日時',
  upload_filename  VARCHAR(255) NULL     COMMENT 'アップロード元ファイル名',
  upload_batch_id  VARCHAR(40)  NULL     COMMENT '同一アップロードのバッチID（UUID）',
  PRIMARY KEY (id),
  INDEX idx_master_change_log_table (target_table),
  INDEX idx_master_change_log_changed_at (changed_at),
  INDEX idx_master_change_log_changed_by (changed_by_id),
  INDEX idx_master_change_log_batch (upload_batch_id),
  CONSTRAINT fk_master_change_log_user FOREIGN KEY (changed_by_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='マスタ変更履歴';
```

### 記録ルール

- アップロード確定時、追加/変更/削除の各レコード 1 件につき 1 行
- `change_data`:
  - `insert`: `{"after": {…}}`
  - `update`: `{"before": {…}, "after": {…}, "changed_fields": ["name","sort_order"]}`
  - `delete`: `{"before": {…}}`
- ユーザーパスワードは `change_data` に含めない（マスクする）
- `upload_batch_id`: 同一アップロードで生成された行に同じ UUID を付与（ロールバック用途、当面表示専用）

### 閲覧 UI（2026-05 実装済）

`master-change-log.html` で 3 タブ構成の監査ログ閲覧画面を提供（system ロール専用）。

- **タブ 1**: マスタ変更履歴（`master_change_log`）— target_table・operation・record_key・changed_at・upload_batch_id でフィルタ／ページング、detail モーダルで before/after JSON 確認
- **タブ 2**: マスタ予約更新（`master_scheduled_changes`）— 反映予定の確認・状態（pending/applied/cancelled/error）
- **タブ 3**: ログイン履歴（`login_history`）— 成功/失敗の試行履歴（user_id・login_id・IP・User-Agent・failure_reason）

メニュー画面では `role === 'system'` のときだけ「監査ログ」リンクが表示される。

---

## 5. API 仕様

### 5-1. アップロード API（共通仕様）

**エンドポイント**: `POST /api/admin/master/{type}.php`

`{type}` ∈ `zones | areas | shops | suppliers | users | products`

**リクエスト**:
- `Content-Type`: `multipart/form-data`
- 認証: 管理者セッション必須
- パラメータ:
  - `file` (file, required): .xlsx
  - `dry_run` (query, optional): `1` ならプレビュー、未指定なら確定実行

**レスポンス（dry_run=1, 成功）**:
```json
{
  "success": true,
  "data": {
    "summary": {"insert": 1, "update": 2, "delete": 0, "total": 3},
    "errors": [],
    "warnings": [],
    "diff": {
      "insert": [{"code": "300", "name": "中部", ...}],
      "update": [{"key": "100", "before": {…}, "after": {…}, "changed_fields": ["sort_order"]}],
      "delete": []
    }
  }
}
```

**レスポンス（dry_run=1, バリデーションエラーあり）**:
```json
{
  "success": false,
  "error": "Excel ファイルにエラーが 3 件あります",
  "data": {
    "errors": [
      {"row": 3, "column": "ゾーンコード", "value": "999", "message": "存在しないゾーンコードです"},
      {"row": 5, "column": "短縮コード", "value": "S01", "message": "他の店舗と重複しています"},
      {"row": 7, "column": "有効", "value": "yes", "message": "TRUE / FALSE を指定してください"}
    ]
  }
}
```

**レスポンス（確定実行, 成功）**:
```json
{
  "success": true,
  "data": {
    "summary": {"insert": 1, "update": 2, "delete": 0, "total": 3},
    "batch_id": "550e8400-e29b-41d4-a716-446655440000"
  }
}
```

**HTTP ステータス**:
- 200: 正常（dry_run 含む）
- 400: バリデーションエラー
- 401: 未ログイン
- 403: 管理者以外
- 413: ファイルサイズ超過（10MB）
- 415: 不正な MIME タイプ
- 500: サーバーエラー

### 5-2. ダウンロード API

**エンドポイント**: `GET /api/export/master/{type}.php`

**レスポンス**: `.xlsx` ファイル（Content-Disposition: attachment）

**ファイル内容**:
- 現状の DB レコード全件（is_active 関わらず）
- ヘッダ + データ
- フォントは Meiryo UI、列幅自動調整、A 列固定なし（マスタの 1 行目をヘッダ行として）
- ファイル名: `{type}_master_{YYYYMMDD}.xlsx`

---

## 6. ファイル構成

```
api/
├── admin/
│   ├── categories.php (既存)
│   ├── system-settings.php (既存)
│   └── master/                       NEW
│       ├── zones.php                 NEW
│       ├── areas.php                 NEW
│       ├── shops.php                 NEW
│       ├── suppliers.php             NEW
│       ├── users.php                 NEW
│       └── products.php              NEW
└── export/
    ├── budgets.php (既存)
    ├── orders.php (既存)
    └── master/                       NEW
        ├── zones.php                 NEW
        ├── areas.php                 NEW
        ├── shops.php                 NEW
        ├── suppliers.php             NEW
        ├── users.php                 NEW
        └── products.php              NEW

includes/
└── master_excel.php                  NEW  共通: Excel 読み書き / 差分検出 / ログ書き込み

database/
├── schema.sql                        UPDATE (products カラム追加 + master_change_log 追加)
├── migration_products_codes.sql      NEW
└── migration_master_change_log.sql   NEW

admin-menu.html                       UPDATE  upload-area を実機能に接続
js/admin-menu.js                      UPDATE  アップロード処理 + プレビューモーダル
css/admin-menu.css                    UPDATE  プレビューモーダル用スタイル

uploads/
└── master/temp/                      NEW (gitignore) 一時保管ディレクトリ
```

---

## 7. セキュリティ・運用

### 認証・認可
- `requireAdmin()` で全 API を保護

### ファイル検証
- MIME: `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` のみ許可
- 拡張子: `.xlsx` のみ
- ファイルサイズ: 上限 10MB

### 一時ファイル
- アップロードファイルは `uploads/master/temp/{user_id}_{timestamp}.xlsx` に保存
- 確定処理が完了したら即削除
- 1 時間以上前の temp ファイルは（次回アップロード時に）自動クリーンアップ

### トランザクション
- 確定処理は単一トランザクションで実行
- INSERT / UPDATE / DELETE がいずれか失敗したら全件ロールバック
- master_change_log への記録も同一トランザクション内

### FK 違反による削除拒否
- 削除候補レコードが他テーブルから FK 参照されている場合、プレビュー時に警告 + 確定不可
- 参照チェック対象:
  - zones → areas
  - areas → shops
  - shops → users, orders, budgets, procurement_requests, shop_categories
  - suppliers → products
  - users → orders.created_by_id, order_status_history.changed_by_id, procurement_requests.created_by_id, master_change_log.changed_by_id
  - products → order_equipment_items

### バックアップ推奨
- 大量変更前に DB バックアップを取ることをお客様に説明（README / 運用ガイドに追記）

---

## 8. UI 設計（admin-menu.html 更新分）

### 既存 master-card の改修

```html
<div class="master-card" data-master-type="zone">
  <div class="master-card-header">
    <div class="master-card-info">
      <div class="master-icon">…</div>
      <div>
        <div class="master-card-title">ゾーンマスタ</div>
        <div class="master-card-desc">ゾーン情報の管理</div>
      </div>
    </div>
  </div>
  <div class="upload-area" onclick="triggerUpload('zone')">
    <input type="file" accept=".xlsx" style="display:none" id="upload-zone">
    <div class="upload-area-text">Excelファイルをクリックまたはドロップ</div>
    <div class="upload-area-hint">.xlsx形式</div>
  </div>
  <div class="master-card-footer">
    <span class="last-updated" id="lastUpdated-zone">最終更新: 取得中…</span>
    <button class="btn-dl" onclick="downloadMaster('zone')">📥 DL</button>
  </div>
</div>
```

### プレビューモーダル

`#masterPreviewModal` を admin-menu.html に追加。
- ヘッダ: マスタ名 + サマリ（追加/変更/削除/エラー件数）
- 中段: タブまたはリストで差分表示
- フッタ: [キャンセル] [この内容で確定]

### CSS（admin-menu.css 追加）

- `.master-preview-modal-overlay`
- `.diff-row.insert / .diff-row.update / .diff-row.delete`
- 色分け: 追加=緑、変更=青、削除=赤、エラー=赤強調

---

## 9. 実装スケジュール（着手順）

| 段階 | 内容 | 完了条件 |
|---|---|---|
| **準備** | DB マイグレーション実行（products カラム追加 + master_change_log 作成） | 既存データに影響なくマイグレーション完了 |
| **準備** | includes/master_excel.php 基盤実装 | Excel 読み書き、差分検出、ログ書き込みの共通関数が動作 |
| **1** | ゾーンマスタ（最小・FK 依存なし） | ダウンロード→編集→プレビュー→確定の一連が動作。監査ログ記録確認 |
| **2** | エリアマスタ（zones への FK） | FK 整合性チェック動作確認 |
| **3** | 店舗マスタ（短縮コード一意性） | UNIQUE 制約違反のエラー表示確認 |
| **4** | 仕入先マスタ | code カラム NULL 化解除なし（運用ルール対応） |
| **5** | ユーザーマスタ | パスワード処理（マスク・空欄維持・ハッシュ化）動作確認 |
| **6** | 商品マスタ（JAN/仕入先商品コード含む） | カテゴリ・仕入先の FK 整合性、画像パス保存確認 |
| **仕上げ** | admin-menu.html / js / css のリファクタ＋全体動作確認 | 6 マスタすべてが同じ UI 操作で動作 |

各段階完了時に Playwright で動作確認、ユーザー（あなた）の承認を経て次へ進む。

---

## 10. 未決事項・今後の検討

- **監査ログの閲覧 UI**: 次フェーズで `admin-menu.html` に「変更履歴」セクション追加を検討
- **ロールバック機能**: `batch_id` を使って「直前の変更を取り消す」機能は次フェーズ
- **マスタ予約更新**（master_scheduled_changes）: 次フェーズで cron と合わせて実装
- **画像ファイル管理 UI**: 現状 FTP 運用。将来的に管理メニューから画像アップロード可能にするか検討
- **CSV 形式サポート**: 現状 .xlsx のみ。CSV 要望があれば追加対応

---

以上。
