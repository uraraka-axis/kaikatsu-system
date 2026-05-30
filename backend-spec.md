# バックエンド開発仕様書

快活フロンティア フィットネス／ゴルフ 発注管理・予算管理システム

最終更新: 2026-05-30

---

## 1. システム概要

店舗スタッフと本部（商品部）が使う発注管理システム。
店舗から修理・備品・部品の発注を行い、本部が承認・管理する。

### ロール

| ロール | 説明 | 管轄キー | 識別 |
|--------|------|---------|------|
| `shop` | 各店舗のスタッフ。発注の作成・自店の一覧閲覧 | `shop_code` | セッション `role` |
| `admin` | 本部の商品部。全店舗の発注管理・マスタ管理 | （全店） | セッション `role` |
| `system` | IT 管理者。admin 全権限 + 監査ログ閲覧 ※2026-05 追加 | （全店） | セッション `role` |
| `zone` | ゾーンマネージャー。管轄ゾーン配下の店舗を横断閲覧（閲覧専用） ※2026-05-26 追加 | `zone_code` | セッション `role` |
| `area` | エリアマネージャー。管轄エリア配下の店舗を横断閲覧（閲覧専用） ※2026-05-26 追加 | `area_code` | セッション `role` |

権限ガードは `includes/auth.php` の以下ヘルパーを使用:

| ヘルパー | 通すロール | 用途 |
|---------|-----------|------|
| `requireLogin()` | ログイン中の全ロール | API/画面の認証必須チェック |
| `requireAdmin()` | admin / system | マスタ管理・システム設定など書き込み権限が必要な機能 |
| `requireManager()` | admin / system / zone / area | 発注一覧・予算管理・自店調達などの「複数店舗横断閲覧」画面・API |
| `requireSystem()` | system | 監査ログ閲覧などシステム管理者専用機能 |

#### ロール別スコープ絞り込み（`getRoleScopeSql()`）

zone / area ロールは、各 API で「閲覧可能な shop_code 集合」を SQL 条件として強制適用する:

| ロール | 追加 WHERE 句 |
|--------|---------------|
| `admin` / `system` | なし（全店） |
| `shop` | `s.code = :_scope_shop_code` |
| `zone` | `s.area_code IN (SELECT code FROM areas WHERE zone_code = :_scope_zone_code)` |
| `area` | `s.area_code = :_scope_area_code` |
| 不正状態（管轄コード未設定） | `1=0`（全件除外でフェイルセーフ） |

`api/orders.php` / `api/budgets.php` / `api/procurement.php` / `api/export/orders.php` / `api/export/budgets.php` / `api/photo.php` で同じパターンを適用。

### 画面一覧

凡例: `o` = 利用可、`ro` = 閲覧専用（管轄スコープ拘束）、`-` = アクセス不可

| # | 画面 | ファイル | shop | admin | system | zone | area | 概要 |
|---|------|---------|:----:|:-----:|:------:|:----:|:----:|------|
| 1 | ログイン | login.html | o | o | o | o | o | 認証（bcrypt + login_history 記録） |
| 2 | メニュー | menu.html | o | o | o | o | o | ロール別メニュー（system は監査ログ追加 / zone・area は閲覧 3 画面のみ） |
| 3 | 修理発注 | repair-order.html | o | - | - | - | - | 修理依頼フォーム |
| 4 | 備品発注 | equipment-order.html | o | - | - | - | - | 商品カタログからカート形式で発注 |
| 5 | 部品発注 | parts-order.html | o | - | - | - | - | 部品の個別発注フォーム |
| 6 | 発注一覧 | order-list.html | o | o | o | ro | ro | 一覧/詳細。ステータス管理・Excel出力・メール下書きは admin/system のみ |
| 7 | 予算管理 | budget-management.html | o | o | o | ro | ro | 予算消化状況・Excel出力（zone/area も管轄分は Excel 可） |
| 8 | 自店調達 | procurement-history.html | o | o | o | ro | ro | 一覧。申請作成は shop のみ |
| 9 | 管理メニュー | admin-menu.html | - | o | o | - | - | 7 種マスタ Excel UL/DL・予約更新・データ出力 |
| 10 | システム設定 | system-settings.html | - | o | o | - | - | カテゴリ管理・期間設定 |
| 11 | 監査ログ | master-change-log.html | - | - | o | - | - | マスタ変更/予約更新/ログイン履歴の 3 タブ |

zone / area の管轄スコープは、画面フィルタ UI で「ゾーン」「エリア」のドロップダウンを管轄値で固定 (disabled) 表示し、ユーザーが管轄外を選択できないようにする。Backend では `getRoleScopeSql()` で SQL レベルでも強制絞り込みを行う 2 層防御。

---

## 2. データモデル（テーブル設計案）

### 2.1 マスタテーブル

#### zones（ゾーン）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(3) PK | ゾーンコード（100, 200） |
| name | VARCHAR(50) | ゾーン名（東日本, 西日本） |

#### areas（エリア）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(3) PK | エリアコード（101, 102, 201...） |
| name | VARCHAR(50) | エリア名（北海道, 東北, 関東...） |
| zone_code | VARCHAR(3) FK | 所属ゾーン |

#### shops（店舗）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(5) PK | 店舗コード（10301 等） |
| name | VARCHAR(50) | 店舗名（新宿東口 等） |
| short_code | VARCHAR(3) | 短縮コード（S01 等）※発注番号の採番に使用 |
| area_code | VARCHAR(3) FK | 所属エリア |

#### categories（カテゴリ）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(20) PK | カテゴリコード（fitness, golf） |
| name | VARCHAR(50) | カテゴリ名（フィットネス, インドアゴルフ） |

#### products（商品マスタ）※備品発注用

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK | 商品ID |
| name | VARCHAR(100) | 商品名 |
| code | VARCHAR(20) | 商品コード（FIT-00001 等。{カテゴリ3文字}-{5桁連番}） |
| price | INT | 単価（税込・円） |
| supplier | VARCHAR(100) | 仕入先名 |
| category_code | VARCHAR(20) FK | カテゴリ |
| recommended | BOOLEAN | おすすめフラグ |

#### system_settings（システム設定）

| カラム | 型 | 説明 |
|--------|------|------|
| key | VARCHAR(50) PK | 設定キー |
| value | VARCHAR(255) | 設定値 |

現在の設定値：

| key | value | 説明 |
|-----|-------|------|
| fiscal_start_month | 4 | 期の開始月（1-12） |

> 備品発注の締めルールは `categories` テーブルの `closing_type` / `closing_day` で管理する（カテゴリ別）。

### 2.2 トランザクションテーブル

#### orders（発注）

| カラム | 型 | 説明 |
|--------|------|------|
| id | VARCHAR(30) PK | 発注番号（採番ルールは後述） |
| type | ENUM('repair','equipment','parts','seat-replacement') | 発注種別 |
| category_code | VARCHAR(20) FK | カテゴリ |
| status | TINYINT | ステータス（0〜4） |
| shop_code | VARCHAR(5) FK | 発注元店舗 |
| date | DATE | 発注日 |
| estimate_amount | INT NULL | 見積金額 |
| final_amount | INT NULL | 最終金額 |
| delivery_date | DATE NULL | 納品予定日 |
| actual_delivery_date | DATE NULL | 実納品日（備品・部品。予算計上月決定キー） |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |
| cancelled_at | DATETIME NULL | 取消日時（NULL=未取消、論理削除フラグ） |
| cancelled_by | VARCHAR(50) NULL | 取消者のユーザー名（スナップショット） |
| cancel_reason | TEXT NULL | 取消理由（必須入力、500文字以内） |

#### order_repair_details（修理発注の詳細）

| カラム | 型 | 説明 |
|--------|------|------|
| order_id | VARCHAR(30) FK PK | 発注番号 |
| equipment_name | VARCHAR(100) | 故障機材名 |
| issue | TEXT | 不具合内容 |
| repair_schedule_date | DATE NULL | 修理予定日 |
| repair_completed_date | DATE NULL | 修理完了日 |

#### order_repair_unavail_dates（修理の対応不可日時）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| date | DATE | 対応不可日 |
| is_all_day | BOOLEAN | 終日かどうか |
| time_start | TIME NULL | 不可開始時刻 |
| time_end | TIME NULL | 不可終了時刻 |

#### order_repair_unavail_days（修理の対応不可曜日）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| day_of_week | VARCHAR(10) | 曜日（火曜日, 木曜日 等） |

#### order_equipment_items（備品発注の明細行）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| product_id | INT FK | 商品ID |
| product_name | VARCHAR(100) | 商品名（スナップショット） |
| product_code | VARCHAR(20) | 商品コード（スナップショット） |
| price | INT | 発注時単価（スナップショット） |
| qty | INT | 数量 |
| supplier | VARCHAR(100) | 仕入先（スナップショット） |
| arrival_date | DATE NULL | 入荷予定日 |

#### order_parts_details（部品発注の詳細）

| カラム | 型 | 説明 |
|--------|------|------|
| order_id | VARCHAR(30) FK PK | 発注番号 |
| parts_name | VARCHAR(100) | 部品名・品番 |
| target_equipment | VARCHAR(100) | 対象機材 |
| reason | TEXT | 発注理由・備考 |
| quantity | INT | 数量 |

#### order_photos（発注写真）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| file_path | VARCHAR(255) | ファイルパス |
| sort_order | TINYINT | 表示順 |

#### order_status_history（ステータス履歴）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| status | TINYINT | ステータス（0〜4） |
| changed_at | DATETIME | 変更日時 |
| changed_by | VARCHAR(50) | 変更者（ユーザー名 or 'system'） |
| memo | TEXT | メモ |

#### procurement_requests（自店調達申請）

| カラム | 型 | 説明 |
|--------|------|------|
| id | VARCHAR(30) PK | 申請番号（REQ-店舗コード-日付-連番） |
| shop_code | VARCHAR(5) FK | 申請店舗 |
| category_code | VARCHAR(20) FK | カテゴリ |
| amount | INT | 金額 |
| reason | TEXT | 理由 |
| date | DATE | 申請日 |
| status | VARCHAR(20) | 承認ステータス |

#### budgets（予算）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| shop_code | VARCHAR(5) FK | 店舗 |
| fiscal_year | INT | 年度（2026 = 2025年4月〜2026年3月） |
| month | TINYINT | 月（1〜12）※暦月 |
| department | ENUM('all','fit','ig') | 部門（all=全体合算） |
| budget_amount | INT | 予算額 |
| actual_amount | INT | 実績額 |

---

## 3. 発注番号の採番ルール

| 種別 | プレフィクス | 形式 | 例 |
|------|-------------|------|-----|
| 修理 | REP | REP-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | REP-30101-20260301-0001 |
| 備品 | EQU | EQU-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | EQU-30101-20260226-0001 |
| 部品 | PTS | PTS-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | PTS-30101-20260303-0001 |
| シート交換 | SHT | SHT-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | SHT-30101-20260528-0001 |
| 自店調達 | REQ | REQ-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | REQ-30101-20260226-0001 |

連番は種別×店舗×日付ごとにリセット。

> **短縮コードについて（2026-05-30）**: 実運用では短縮コード（`shops.short_code`）に **店舗コード5桁をそのまま格納**する方針に変更（列も VARCHAR(3)→(5) に拡張）。よって発注番号は `REP-30101-...` のように 5桁店舗コードが入る。採番ロジックは従来どおり `short_code` を参照するため不変。

---

## 4. ステータスフロー

### 4.1 ステータス定義

| コード | 修理 | 備品 | 部品 |
|:------:|------|------|------|
| 0 | 依頼中 | 依頼中 | 依頼中 |
| 1 | 発注済 | 発注済 | 発注済 |
| 2 | 修理待ち | 配達中 | 配達中 |
| 3 | 修理済 | 納品済 | 納品済 |
| 4 | 完了 | 完了 | 完了 |

### 4.2 遷移ルール

```
0 (依頼中)
  │
  ├─ 管理者が手動 ──→ 1 (発注済)
  │   入力: 見積金額（必須）、予定日、メモ
  │
  └─ [備品のみ] システム自動（カテゴリ締め日到来）──→ 1 (発注済)
      見積金額 = カート合計額を自動適用

1 (発注済)
  │
  ├─ [修理] 管理者が手動 ──→ 2 (修理待ち)
  │   入力: メモ
  │
  ├─ [部品] 管理者が手動 ──→ 2 (配達中)
  │   入力: メモ
  │
  └─ [備品] システム自動（カテゴリ締め日翌日）──→ 2 (配達中)

2 (修理待ち / 配達中)
  │
  ├─ [修理] 店舗が手動 ──→ 3 (修理済)
  │   入力: 修理完了日（必須）、メモ
  │
  ├─ [備品・部品] 商品部が手動 ──→ 3 (納品済)
  │   入力: メモ
  │
  └─ [全種別] バッチ自動（予定日 = 当日）──→ 3 (納品済/修理済)

3 (修理済 / 納品済)
  │
  ├─ 管理者が手動 ──→ 4 (完了)
  │   入力: 最終金額（修理は必須、他は任意）、メモ
  │   未入力時: 最終金額 = 見積金額を自動適用
  │
  └─ [全種別] バッチ自動（予定日翌日 = 当日）──→ 4 (完了)
      最終金額未設定なら見積金額を適用 + 予算実績反映

4 (完了)
  └─ 最終状態。変更不可。
```

### 4.3 各ステータスでの画面表示

| ステータス | 店舗画面のアクション | 管理者画面のアクション |
|-----------|---------------------|----------------------|
| 0 依頼中 | 「本部対応待ち」表示 | 「発注済にする」ボタン |
| 1 発注済 | 備品:「配達待ち」/ 他:「本部対応待ち」 | 修理:「修理待ちにする」/ 部品:「配達中にする」/ 備品:「自動遷移待ち」 |
| 2 修理待ち/配達中 | 修理:「修理完了報告」/ 備品・部品:「納品済にする」 | 「店舗の報告待ち」表示 |
| 3 修理済/納品済 | 「本部の最終確認待ち」表示 | 「完了にする」ボタン |
| 4 完了 | 「完了」表示 | 「完了」表示 |

### 4.4 対応情報の編集権限

| 条件 | 編集可否 |
|------|---------|
| ステータス0（依頼中） | 編集不可 |
| ステータス1〜3 + 管理者 | 見積金額・予定日・最終金額・メモを編集可 |
| ステータス3（修理済）+ 店舗 + 修理 | 修理完了日・メモを編集可 |
| ステータス4（完了）+ 管理者 | 最終金額・メモを編集可 |

---

## 5. ビジネスルール

### 5.1 備品発注の締め日ロジック

- 締めルールは `categories` テーブルでカテゴリ別に管理
  - `closing_type`: `none`（都度）/ `monthly`（月次）/ `weekly`（週次）
  - `closing_day`: monthly なら日(1-31)、weekly なら曜日(0=日〜6=土)
- 初期値: フィットネス備品=毎月8日、ゴルフ備品=毎週火曜日
- 締め日の翌日に、商品部が「発注済にする」操作で 0→1 に進める
- ステータス遷移はすべて手動運用（自動遷移バッチは廃止 — 9-2参照）:
  - **0→1**: 商品部が「発注済にする」（個別 or 一括）
  - **1→2**: 商品部が「配達中にする / 修理待ちにする」（個別 or 一括）
  - **2→3**: 店舗が「納品済にする / 修理完了報告」（個別）
  - **3→4**: 商品部が「完了にする」（個別 or 一括）
- 画面「発注済にする」モーダルの納品予定日デフォルトは **「締め日+4日」**（`js/order-list.js` `getEquipmentDeliveryDate()`、配達 4 日想定）
- カテゴリの `closing_type = 'none'` の場合は都度発注

### 5.2 修理発注の対応不可日

- 店舗は発注時に「対応不可日時」と「対応不可曜日」を指定できる
- 対応不可日は複数指定可（終日 or 時間帯指定）
- 対応不可曜日は複数指定可
- 修理予定日は3営業日以上先の日付のみ指定可能

### 5.2.1 シート交換発注（2026-05-28 追加）

マシンのシート交換専用の発注種別。修理発注と同じステータスフロー / UI を持つ。

- **専用画面**: [seat-replacement.html](seat-replacement.html) / [js/seat-replacement.js](js/seat-replacement.js)
- **詳細テーブル**: [order_seat_replacement_details](database/db-spec.md#3111-order_seat_replacement_details純シート交換発注詳細)
- **カテゴリ**: フィットネス固定（サーバ側で強制）
- **依頼内容**: 「マシンのシート交換」固定文言
- **対応不可日時/曜日 UI**: 修理と同じ（`order_repair_unavail_*` テーブルを流用）
- **写真アップロード**: 最大3枚
- **ステータスフロー**: 修理発注と同一（0→1→2→3→4）
- **修理ライク判定 helper**: `includes/functions.php` の `isRepairLikeType()` / `getRepairLikeDetailTable()` を全 API/フロントで使用
- **発注番号 prefix**: SHT（例: `SHT-S01-20260528-0001`）
- **業務上の区別**: 発注一覧／Excel／メール下書きでは「シート交換」として独立表示。仕入先・集計でも区別される

### 5.3 写真アップロード

- 修理発注・部品発注・シート交換発注で写真添付可能
- 最大3枚まで
- プレビュー表示あり

### 5.4 予算管理

- 年度ラベル: 2026年度 = 2025年4月〜2026年3月（月度配列: 4,5,6,7,8,9,10,11,12,1,2,3）
- 部門別（全体 / フィットネス / インドアゴルフ）に予算・実績を管理
- 集計期間:
  - 当期: 年度全体（12ヶ月）
  - 期中: 四半期ベース（Q1=4〜6月, Q2=7〜9月, Q3=10〜12月, Q4=1〜3月）
  - 当月: 当該月のみ
- 消化率の色分け: 緑（60%未満）、黄（60〜90%）、赤（90%以上）
- 管理者画面: ゾーン→エリア→店舗のカスケードフィルタ、部門・年度フィルタ
- 店舗画面: 自店のデータのみ表示、部門・年度フィルタ
- デフォルト年度: 最新年度を初期選択

### 5.4.1 予算超過アラート（仮計上ベース / 2026-05-30 改訂）

予算実績 `budgets.actual_amount` は納品済（status=3）以降しか計上されない（§7 参照）。
そのままだと「依頼中〜配達中の未納品発注」が予算判定から漏れるため、**アラートは
「確定実績 ＋ 未納品の発注見込み」の仮計上ベースで判定する**。発火点は **備品発注フローのみ**。

**仮計上の定義**

```
仮計上合計 = budgets.actual_amount（納品済以上・全種別の確定実績）
           ＋ 未納品の発注見込み（status 0/1/2・全種別・同店舗・同カテゴリ・同四半期）
仮計上残高 = 四半期予算 − 仮計上合計
```

- 未納品の見込み額: `estimate_amount` を優先。NULL の場合、備品は `order_equipment_items` の明細合計、
  それ以外（修理/部品/シート交換の status=0 等、金額未確定）は 0 扱い。
- 計上四半期の振り分け: `COALESCE(actual_delivery_date, delivery_date, date)` の月が属する四半期。
- 取消（`cancelled_at IS NOT NULL`）は集計対象外。
- ヘルパー: `includes/budget.php` の `getInflightPipelineTotal()`。

**① 画面アラート（店舗の備品発注時）** — [equipment-order.js](equipment-order.js)
- `GET /api/budgets.php?action=inflight&dept=&year=&month=` で未納品見込みを取得し、
  `残高 = 予算 − 確定実績 − 見込み` で判定。
- 非ブロックの警告（発注は続行可）。同日に複数発注しても、既存の未納品（status=0 含む）が
  見込みに入るため累積で正しく超過表示される（自己修正型：取消されれば次回計算で消える）。

**② マネージャーメール（`【予算超過見込み】`）** — [includes/budget_notify.php](includes/budget_notify.php)
- 店舗が備品発注（status=0 作成）した時点で、仮計上合計が四半期予算を**新たに跨いだ
  （≤予算 → >予算）瞬間のみ**、ゾーン/エリアマネージャーへ送信。
- 判定は純関数 `quarterBudgetCrossedUpward(before, after, budget)`。状態フラグは持たず、
  発注のたびに before/after を都度計算（`notifyIfProvisionalQuarterBudgetCrossed()`）。
  - 既に超過済みなら再送しない／取消で予算内に戻り再度跨いだら再送する。
  - 取消されても送信済みメールは撤回しない（見込み時点のスナップショット通知）。
- 送信は [api/orders/create.php](api/orders/create.php) のメール非同期パターン（§5.8）に乗せる。
- **旧仕様の廃止**: 納品/完了時（status.php / bulk-status.php）の確定メール
  `notifyIfQuarterBudgetCrossed()` は撤去。仮計上の世界観では超過は発注時に一度だけ起きるため、
  納品時に新たなクロスは発生しない（マネージャー通知は status=0 一本化）。

### 5.5 一括ステータス変更（管理者）

- チェックボックスで複数発注を選択
- 同一ステータスの発注をまとめて次のステータスに遷移可能
- 全種別（修理・備品・部品・シート交換）が一括変更対象（2026-05-28 にバッチ廃止後、備品も対象に）

### 5.6 論理削除（発注取消）

- 誤発注対応として `orders.cancelled_at` で論理削除する仕組み。
- **取消可能条件**: status=0（依頼中）かつ admin/system ロールのみ
- **必須項目**: cancel_reason（取消理由、500文字以内）
- **挙動**:
  - `cancelled_at` / `cancelled_by` / `cancel_reason` を `orders` に記録
  - `order_status_history` に「【取消】<理由>」として履歴記録
  - 取消後は API レイヤ（`cancelled_at IS NULL` フィルタ）で一覧・Excel・メール下書きから完全に非表示
  - ステータス変更・編集 API は取消発注を拒否
- **API**: [POST /api/orders/cancel.php](api/orders/cancel.php)

### 5.7 自店調達申請の予算反映（2026-05-28）

- POST /api/procurement.php 成功時、トランザクション内で `applyBudgetActualDeltaByDate()` を呼び申請月の `budgets.actual_amount` に金額を即時加算。
- 計上月 = `procurement_requests.date`（申請日）の月。納品概念がないため `actual_delivery_date` ベースの発注とは別ロジック。
- ヘルパー: `includes/budget.php` の `resolveBudgetKeyByDate()` / `applyBudgetActualDeltaByDate()`。

### 5.8 メール送信の非同期化（2026-05-28）

発注作成・ステータス変更・一括ステータス変更の各 API は、SMTP 送信完了を待たずに先にクライアントへ JSON レスポンスを返す。

- **対象**:
  - `api/orders/create.php` — 商品部向け新規発注通知（`notifyProductDeptNewOrder()`）
  - `api/orders/status.php` — 四半期予算超過通知（`notifyIfQuarterBudgetCrossed()`）
  - `api/orders/bulk-status.php` — 同上（複数件発生し得る）
- **共通ヘルパー**: `includes/functions.php` の `jsonResponseAndContinue(mixed $data, int $status = 200)`
  - PHP-FPM 環境: `fastcgi_finish_request()` でレスポンスを確定し以後の処理を継続
  - mod_php / XAMPP 環境: 出力バッファをクリアし `Content-Length` + `Connection: close` を発行 → `flush()` で接続をクローズ
  - 共通の追加処理: `ignore_user_abort(true)` / `set_time_limit(60)` / `zlib.output_compression = Off`
- **呼び出し規約**: DB トランザクションを必ず `commit()` した後にレスポンスを返し、続けてメール送信を行うこと。トランザクション内で送信すると失敗時に矛盾が出る。
  ```php
  commit();
  jsonResponseAndContinue(['success' => true, ...]);
  notifyProductDeptNewOrder(...);
  exit;
  ```
- **背景**: SMTP タイムアウト（Mailpit 停止時等）で発注ボタン押下後に数秒〜十数秒の停止が発生していた。UX 影響と本番運用上のリスクを切り離す目的。
- **既知の制約**:
  - メール送信失敗はクライアントへ伝わらない（`error_log` のみ）。永続化リトライが必要なら mail_queue 機構を別途用意すること
  - 送信中はリクエストワーカーを占有する（Apache mpm_prefork 環境では並列度に注意）

---

## 6. API設計案

### 6.1 認証

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| POST | /api/login.php | ログイン |
| POST | /api/logout.php | ログアウト |
| GET | /api/me.php | ログインユーザー情報取得（呼び出すたびに DB から最新の name/shop_name/zone_name/area_name を取得しセッションを更新する。これにより admin が users マスタを Excel UL で変更した場合でも、対象ユーザーが画面リロードするだけで反映される） |

`api/me.php` のレスポンス例（zone マネージャー）:

```json
{
  "success": true,
  "user": {
    "id": 33,
    "login_id": "Z100",
    "name": "東日本ゾーンマネージャー",
    "role": "zone",
    "shop_code": null,
    "shop_name": null,
    "zone_code": "100",
    "zone_name": "東日本",
    "area_code": null,
    "area_name": null,
    "categories": []
  }
}
```

DB で `is_active=0` または削除されていた場合は自動ログアウト + 401 を返す（セッション残存対策）。

### 6.2 発注

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/orders.php | 発注一覧取得 | shop:自店 / admin・system:全店 / zone:管轄ゾーン配下 / area:管轄エリア配下 |
| POST | /api/orders/create.php | 発注作成（3種別統一） | shop のみ |
| POST | /api/orders/status.php | ステータス変更 | admin / system のみ（zone/area は閲覧専用） |
| POST | /api/orders/update-info.php | 対応情報の編集 | admin / system のみ |
| POST | /api/orders/bulk-status.php | 一括ステータス変更 | admin / system のみ |
| GET | /api/orders/draft-mails.php | ★発注メール下書き取得（仕入先別集計） | admin / system のみ |
| GET | /api/photo.php?id={id} | 発注写真取得 | 認証済（zone/area は管轄外の店舗写真は 403） |

**GET /api/orders クエリパラメータ:**

| パラメータ | 型 | 説明 |
|-----------|------|------|
| type | string | 種別フィルタ（repair/equipment/parts/seat-replacement） |
| status | int | ステータスフィルタ（0〜4） |
| category | string | カテゴリフィルタ |
| shop | string | 店舗コード（admin/system は自由 / zone/area は管轄内のみ受理） |
| zone | string | ゾーンコード（admin/system のみ自由 / zone は自身に強制） |
| area | string | エリアコード（admin/system/zone は自由 / area は自身に強制） |
| date_from | date | 発注日From |
| date_to | date | 発注日To |

**PUT /api/orders/{id}/status リクエストボディ:**

```json
{
  "action": "order|to-delivering|repair-done|delivery-done|complete",
  "estimate_amount": 35000,
  "schedule_date": "2026-03-15",
  "final_amount": 42000,
  "repair_completed_date": "2026-02-28",
  "actual_delivery_date": "2026-02-25",
  "memo": "メモ内容"
}
```

### 6.3 商品カタログ（備品発注用）

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/products | 商品一覧取得 | 店舗 |
| GET | /api/products?category=fitness | カテゴリ絞り込み | 店舗 |

### 6.4 予算

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/budgets.php | 予算一覧取得 | shop:自店 / admin・system:全店 / zone:管轄ゾーン配下 / area:管轄エリア配下 |
| GET | /api/budgets.php?action=years | データが存在する年度のみを降順で返す | 同上（スコープ内のみ集計） |
| GET | /api/budgets.php?action=inflight&dept=&year=&month= | 未納品の発注見込み額（仮計上、§5.4.1）を返す | shop:自店 / admin・system は shop 指定可 |
| GET | /api/export/budgets.php | 予算データを Excel(.xlsx) 出力 | 同上（スコープ内のみ集計） |

**クエリパラメータ:**

| パラメータ | 型 | 説明 |
|-----------|------|------|
| year | int | 年度（2026 等） |
| dept | string | 部門フィルタ（all/fit/ig） |
| zone | string | ゾーンコード（管理者のみ） |
| area | string | エリアコード（管理者のみ） |
| shop | string | 店舗コード |

**レスポンス例:**

```json
{
  "shop": "10301",
  "shop_name": "新宿東口",
  "zone": "100",
  "area": "103",
  "fiscal_year": 2026,
  "period": { "budget": 624000, "actual": 576100, "balance": 47900, "rate": 92.3 },
  "midterm": { "quarter": "Q4", "months": "1〜3月", "budget": 156000, "actual": 133660, "balance": 22340, "rate": 85.7 },
  "month": { "month": 3, "budget": 52000, "actual": 37000, "balance": 15000, "rate": 71.2 },
  "monthly_details": {
    "all": [
      { "month": 4, "budget": 52000, "actual": 47840, "balance": 4160, "rate": 92.0 },
      ...
    ],
    "fit": [...],
    "ig": [...]
  }
}
```

### 6.5 自店調達

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/procurement.php | 申請一覧取得（`?year=&category=&shop=`） | shop:自店 / admin・system:全店 / zone:管轄ゾーン配下 / area:管轄エリア配下 |
| GET | /api/procurement.php?action=years | 実在年度の降順リスト（データ無しは現在年度フォールバック） | 同上（スコープ内のみ集計） |
| POST | /api/procurement.php | 自店調達申請を作成 | shop のみ（admin/system/zone/area は閲覧専用） |

- `category` バリデーションは categories マスタの `is_active=1` を参照（ハードコード廃止）。
- POST 時は `shop_categories` の所属チェックも実施（自店所属カテゴリ以外は 400）。

### 6.6 マスタ管理（参照）

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/master/zones.php | ゾーン一覧 | 全ロール |
| GET | /api/master/areas.php | エリア一覧 | 全ロール |
| GET | /api/master/shops.php | 店舗一覧 | 全ロール |
| GET | /api/master/categories.php | カテゴリ一覧 | shop は所属店舗のカテゴリのみ / admin・system・zone・area は全カテゴリ（管轄内の店舗を横断するため） |

### 6.6.2 マスタ CRUD（Excel UL/DL）— 管理者

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| POST | /api/admin/master/zones.php | ゾーン UL（`dry_run=1` でプレビュー） |
| POST | /api/admin/master/areas.php | エリア UL |
| POST | /api/admin/master/shops.php | 店舗 UL |
| POST | /api/admin/master/suppliers.php | 仕入先 UL |
| POST | /api/admin/master/users.php | ユーザー UL（password はマスク扱い） |
| POST | /api/admin/master/products.php | 商品 UL |
| POST | /api/admin/master/budgets.php | 予算 UL（ピボット形式） |
| GET | /api/export/master/{type}.php | 現状の各マスタ DL（Excel） |
| POST | /api/admin/categories.php | カテゴリ追加/編集 |
| DELETE | /api/admin/categories.php | カテゴリ削除（使用中チェック） |
| GET/PUT | /api/admin/system-settings.php | 期間設定 |

### 6.6.3 マスタ予約更新 — 管理者

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| GET | /api/admin/scheduled-changes/list.php | 予約一覧 |
| POST | /api/admin/scheduled-changes/create.php | 予約作成（target_table/scheduled_at/change_data） |
| POST | /api/admin/scheduled-changes/cancel.php | 予約取消 |

cron バッチは `setup/apply_scheduled_changes.php`（5 分間隔想定）。

### 6.6.4 system 専用（監査ログ） — system ロールのみ

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| GET | /api/system/master-change-log.php | マスタ変更履歴 |
| GET | /api/system/master-scheduled-changes.php | マスタ予約更新一覧 |
| GET | /api/system/login-history.php | ログイン履歴 |

### 6.7 データ出力

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/export/orders.php | 発注データ Excel 出力（チェック行 or フィルタ） | 管理者 |
| GET | /api/export/budgets.php | 予算データ Excel 出力（年度別・部門別、月別明細付き） | 全ロール |
| GET | /api/export/master/{type}.php | マスタ Excel DL（現状データ + テンプレ） | 管理者 |

---

## 7. 自動バッチ処理

バックエンドで定期実行が必要な処理：

| 処理 | タイミング | 対象 | 動作 | 状況 |
|------|-----------|------|------|------|
| ~~ステータス自動遷移バッチ~~ | ~~毎日 0:00~~ | ~~備品発注（全遷移）~~ | ~~0→1, 1→2, 2→3, 3→4 を自動化~~ | **廃止**（2026-05-28、全て手動運用に変更） |
| マスタ予約更新の自動反映 | 5 分間隔 | master_scheduled_changes | pending を検出して反映 | **完成** `setup/apply_scheduled_changes.php` |

### ステータス遷移を全て手動運用とした理由

備品の自動遷移バッチ（`setup/auto_advance_status.php`）を当初運用していたが、2026-05-28 に廃止：

- 商品部の運用では「**実際に業者へ発注／配達／納品されたタイミング**でステータスを進めたい」というニーズが強い
- 自動化するとシステム上のステータスと実態がずれ、予算消化のタイミングも実態と乖離する
- 発注一覧画面の「ステータス一括変更」で複数発注をまとめて進められるため、手動でも十分運用可能

現在の全遷移ルール（[`api/orders/status.php`](api/orders/status.php) / [`api/orders/bulk-status.php`](api/orders/bulk-status.php)）:

| 遷移 | 操作者 | 操作 | 一括対応 |
|---|---|---|---|
| 0→1 (依頼中→発注済) | 商品部 (admin) | 「発注済にする」 | ◯ |
| 1→2 (発注済→配達中/修理待ち) | 商品部 (admin) | 「配達中にする / 修理待ちにする」 | ◯ |
| 2→3 (配達中→納品済) | 店舗 (shop) | 「納品済にする」（納品実績日入力必須） | ✗ |
| 2→3 (修理待ち→修理済) | 店舗 (shop) | 「修理完了報告」（修理完了日入力必須） | ✗ |
| 3→4 (納品済/修理済→完了) | 商品部 (admin) | 「完了にする」 | ◯ |

### 予算実績反映の設計（納品月ベース / 2026-05-28〜）

`budgets.actual_amount` は **納品月（修理は完了月）に発生分を加算する** 設計。詳細は [`includes/budget.php`](includes/budget.php) `applyBudgetActualDeltaByDelivery()` 参照。

| 遷移 | 加算額 | 計上月のキー |
|---|---|---|
| 2→3 (納品済/修理済) | `estimate_amount` | 備品/部品: `orders.actual_delivery_date` / 修理: `order_repair_details.repair_completed_date` |
| 3→4 (完了) | `final_amount - estimate_amount` の差分 | 同上 |
| 完了後の `final_amount` 編集 | 差分のみ加減算 | 同上 |

旧設計（カテゴリ締め日ベース）は廃止。カテゴリの `closing_type` / `closing_day` は発注期限カレンダー用としてのみ残存。

---

## 8. フロントエンド構成（参考）

```
kaikatsu-system/
├── index.html              ... ログイン画面
├── menu.html               ... メニュー
├── repair-order.html       ... 修理発注
├── equipment-order.html    ... 備品発注
├── parts-order.html        ... 部品発注
├── order-list.html         ... 発注一覧
├── budget-management.html  ... 予算管理
├── procurement-history.html ... 自店調達
├── admin-menu.html         ... 管理メニュー
├── system-settings.html    ... システム設定
├── css/
│   ├── common.css          ... 共通スタイル
│   └── [画面名].css        ... 画面別スタイル
└── js/
    ├── common-nav.js       ... ヘッダー・ナビ生成
    ├── login.js            ... ログイン処理
    └── [画面名].js         ... 画面別ロジック（サンプルデータ内包）
```

各JSファイルの先頭にサンプルデータが配列として定義されています。
バックエンド化する際は、この配列部分をAPI呼び出しに置き換えてください。

### 8.1 共通フィルタバー（発注一覧 / 予算管理 / 自店調達）

3 画面（発注一覧 admin/store・予算管理・自店調達）のフィルタは以下の共通 HTML 構造で統一されています。スタイル本体は `css/common.css` に集約。

```html
<div class="admin-filter-bar" id="[xxxFilterBar]">
  <div class="admin-filter-row">
    <div class="filter-group">
      <span class="filter-label">ラベル</span>
      <select|input class="form-select|form-input">
    </div>
    ...
  </div>
</div>
```

- 共通スタイル: `.admin-filter-bar` / `.admin-filter-row` / `.filter-group` / `.filter-label` / `.date-range` 一式は `common.css` 参照
- 初期非表示が必要な画面（発注一覧の admin/store 切替）は ID 指定で `display:none` を上書き、JS で `display = 'block'` をセットして表示
- **検索ボタン廃止**（2026-05-26）: 各 select/date input は `onchange` で即時に API 再フェッチ + 再描画する。明示的な「検索」ボタンは設置しない（自店調達画面と挙動を統一）。
- **zone/area ロールの管轄スコープ拘束**（2026-05-26）: フィルタの「ゾーン」「エリア」セレクトはロールに応じて選択肢を絞り、管轄値を固定（`disabled`）表示する。
  - `zone`: ゾーン = 管轄ゾーン 1 つだけ・disabled / エリア = 管轄ゾーン配下のみ / 店舗 = 管轄ゾーン配下のみ
  - `area`: ゾーン = 管轄ゾーンだけ・disabled / エリア = 管轄エリア 1 つだけ・disabled / 店舗 = 管轄エリア配下のみ
  - `admin/system`: 「すべて」+ 全選択肢（既存挙動）
  - フロント側だけでなく Backend API でも `getRoleScopeSql()` で同等の絞り込みを強制する 2 層防御
