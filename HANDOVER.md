# 引継ぎドキュメント — 快活フロンティア 発注管理システム

最終更新: 2026-04-28
最新コミット: `75e3820 カテゴリ別締めルール対応`（develop ブランチ push 済み）

---

## 1. プロジェクト概要

店舗向け発注管理システム（修理・備品・部品の3種別 + 予算管理 + 自店調達申請）。
快活CLUB等の店舗運営における発注業務の効率化が目的。

- **業務領域**: 発注管理 / 予算管理 / 自店調達申請 / 各種マスタメンテ
- **発注タイプ**: 修理発注 / 備品発注（フィットネス・ゴルフ）/ 部品発注
- **ステータス**: 依頼中(0) → 発注済(1) → 配達中/修理待ち(2) → 納品済/修理済(3) → 完了(4)
- **ロール**: `admin`（商品部） / `shop`（店舗）

---

## 2. リポジトリ・ブランチ運用

| 項目 | 値 |
|---|---|
| リポジトリ | https://github.com/uraraka-axis/kaikatsu-system.git |
| `master` | **モック版（静的HTML/CSS/JS）** — GitHub Pages で先方確認用に公開中。**直接 push 禁止** |
| `develop` | **PHP版（バックエンド開発）** — ローカル XAMPP で動作確認 |
| モック公開URL | https://uraraka-axis.github.io/kaikatsu-system/ |
| モック公開URL(ログイン) | https://uraraka-axis.github.io/kaikatsu-system/login.html |

### コミット運用
- 機能単位でコミット、メッセージは日本語可
- master への直接 push 禁止／force-push は原則禁止
- 開発は `develop` で行う

---

## 3. 技術スタック

| 領域 | 採用技術 |
|---|---|
| サーバ | XAMPP（Apache + MySQL 8.0 + PHP 8.2） |
| 言語 | PHP 8.2 / vanilla JavaScript（フレームワーク不使用） |
| フロント | HTML + CSS + JS、フォントは Inter / Noto Sans JP |
| ライブラリ | PhpSpreadsheet（Excel出力用、Composer で導入） |
| 文字コード | UTF-8（BOM なし）／ DB は utf8mb4 |
| 本番想定 | さくらレンタルサーバー |

---

## 4. 開発環境構築手順

### 4-1. 必要なソフト
- [XAMPP](https://www.apachefriends.org/jp/index.html)（PHP 8.2 系を含むバージョン）
- Git（Git for Windows 等）
- 任意のエディタ（VS Code 推奨）

### 4-2. リポジトリのクローン
```bash
# 推奨配置: C:\Users\<ユーザー名>\kaikatsu-system\
cd C:\Users\<ユーザー名>\
git clone https://github.com/uraraka-axis/kaikatsu-system.git
cd kaikatsu-system
git checkout develop
```

### 4-3. Apache Alias 設定
htdocs にコピーせず、git リポジトリを直接配信する方式を採用。

`C:\xampp\apache\conf\httpd.conf` の末尾付近に以下を追加（パスはクローン先に合わせて変更）:

```apache
# === kaikatsu-system: git repo を直接配信 ===
Alias /kaikatsu-system "C:/Users/<ユーザー名>/kaikatsu-system"
<Directory "C:/Users/<ユーザー名>/kaikatsu-system">
    Options Indexes FollowSymLinks Includes ExecCGI
    AllowOverride All
    Require all granted
</Directory>
```

設定後、XAMPP コントロールパネルから Apache を再起動。

### 4-4. データベースセットアップ
1. XAMPP の MySQL を起動
2. phpMyAdmin（http://localhost/phpmyadmin/）または MySQL CLI で以下を実行:
   ```sql
   CREATE DATABASE kaikatsu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. スキーマ・初期データを投入:
   ```bash
   mysql -u root kaikatsu < database/schema.sql
   mysql -u root kaikatsu < database/seed.sql
   ```
4. 既存DBがある場合は追加で:
   ```bash
   mysql -u root kaikatsu < database/migration_categories_closing.sql
   ```

### 4-5. パスワードハッシュ化
seed.sql ではパスワードがプレーンテキスト `password` で投入されるため、ハッシュ化スクリプトを実行:

```bash
cd C:\Users\<ユーザー名>\kaikatsu-system
php setup/hash_passwords.php
```

（再実行しても安全。既にハッシュ化済みのものはスキップされる）

### 4-6. Composer 依存ライブラリの導入
```bash
php composer.phar install
```

→ `vendor/` ディレクトリに PhpSpreadsheet がインストールされる。

### 4-7. uploads ディレクトリの権限
```
kaikatsu-system/uploads/
```
が書き込み可能であることを確認（写真アップロード用）。

### 4-8. 動作確認
ブラウザで http://localhost/kaikatsu-system/login.html にアクセス。
以下のテストアカウントでログイン可能:

| login_id | password | role | 備考 |
|---|---|---|---|
| admin | password | admin | 商品部 |
| 10301 | password | shop | 新宿東口店 |
| 10101 | password | shop | 札幌店 |
| (他の店舗コードも全て password) | | shop | seed.sql 参照 |

---

## 5. ディレクトリ構成

```
kaikatsu-system/
├── api/                  # APIエンドポイント
│   ├── admin/            # 管理者向けマスタ更新API（categories, system-settings）
│   ├── export/           # Excel出力API（budgets, orders）
│   ├── master/           # マスタ参照API（categories, shops, zones, areas...）
│   ├── orders/           # 発注関連API（create, status, bulk-status, update-info）
│   ├── budgets.php       # 予算API
│   ├── login.php / logout.php / me.php
│   ├── orders.php        # 発注一覧API
│   ├── procurement.php   # 自店調達申請API
│   └── products.php      # 商品API
├── css/                  # スタイルシート
├── database/
│   ├── schema.sql                          # DBスキーマ（22テーブル）
│   ├── seed.sql                            # 初期データ
│   ├── migration_categories_closing.sql    # カテゴリ締めルール追加マイグレーション
│   ├── fix_comments.sql                    # カラムコメント修正
│   ├── db-spec.md                          # DBスキーマ仕様書
│   └── db-guide.md                         # DB運用ガイド（非エンジニア向け）
├── includes/             # PHP共通基盤
│   ├── config.php        # 設定（DB接続情報など）
│   ├── db.php            # PDO接続
│   ├── auth.php          # 認証
│   ├── csrf.php          # CSRF対策
│   └── functions.php     # 共通関数
├── js/                   # フロントエンドJS
├── setup/
│   └── hash_passwords.php  # パスワードハッシュ化スクリプト
├── uploads/              # 写真アップロード保存先（gitignore）
├── vendor/               # Composer依存（gitignore）
├── docs/                 # 画面設計書・SVGスライド・ダイアログキャプチャ（gitignore）
├── backup/               # 古いバックアップ（gitignore）
├── *.html                # 各画面（login, menu, order-list, ...）
├── backend-spec.md       # バックエンド仕様書（必読）
├── composer.json / composer.lock / composer.phar
└── HANDOVER.md           # 本ファイル
```

---

## 6. 仕様書・ドキュメント（リポジトリ内）

| ファイル | 内容 |
|---|---|
| `backend-spec.md` | バックエンド全体仕様（API一覧・締めルール・バッチ処理・ステータス遷移） |
| `database/db-spec.md` | DBスキーマ仕様（全22テーブル定義） |
| `database/db-guide.md` | DB運用ガイド（非エンジニア向けの解説、初期データ含む） |
| `docs/screen-design.html` | 画面設計書（master ブランチ参照） |

---

## 7. 重要な仕様（最近の変更点）

### 7-1. カテゴリ別締めルール（2026-04-28 導入）
**締めルールの所在を `system_settings` から `categories` テーブルへ移行。**

`categories` テーブルに以下を追加:
- `closing_type` ENUM(`none`, `monthly`, `weekly`)
- `closing_day` INT — monthly なら日付(1-31), weekly なら曜日(0=日…6=土)

| カテゴリ | closing_type | closing_day | 意味 |
|---|---|---|---|
| フィットネス備品 | monthly | 8 | 毎月8日締め |
| ゴルフ備品 | weekly | 2 | 毎週火曜締め |
| 部品・修理 | none | NULL | 都度発注 |

`system_settings.equipment_deadline_weekday` は廃止。`fiscal_start_month`(=4) のみ残存。

### 7-2. 締め日計算ロジックの実装位置
- **PHP**: `api/budgets.php`（予算計上月の判定）
- **JS**: `js/equipment-order.js`（備品発注画面の予算アラート）
- **JS**: `js/order-list.js`（管理者モーダルの納品予定日初期値）— 今回追加

### 7-3. 年度規約
- 日本の会計年度（4月開始3月終了）
- 「開始年=年度名」（例: 2026/4〜2027/3 は 2026年度）
- `getCurrentFiscalYear()` および JS `getFiscalMonthIndex()` でこの規約を実装

### 7-4. 部門マッピング
budgets テーブルの `department` 列:
- フィットネス系 → `fit`
- ゴルフ系 → `ig`

---

## 8. コーディング規約（重要）

### 8-1. PHP（`.claude/rules/php-coding.md`）
- DB操作は **PDOプリペアドステートメント必須**（文字列結合での SQL 構築は禁止）
- 複数テーブル更新時は **トランザクション必須**
- DBカラム名は **snake_case**
- ユーザー入力は `filter_input` / `filter_var` でバリデーション
- HTML出力は `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
- セッションに `user_id` と `role` がない場合は 401
- ファイルアップロードは MIME タイプとサイズを検証
- API レスポンス形式:
  - 成功: `{"success": true, "data": ...}`
  - エラー: `{"success": false, "error": "メッセージ"}`
- HTTP ステータスコードを適切に使う（200/400/401/403/404/500）
- Content-Type: `application/json; charset=utf-8`

### 8-2. フロントエンド（`.claude/rules/frontend.md`）
- フォントサイズ最小 **12px**（WCAG準拠）
- テーブル画面は **1400px**、フォーム画面は **1200px**
- フォント: Inter, Noto Sans JP
- API レスポンスのエラーハンドリング必須
- fetch 時の **401 はログインページへリダイレクト**
- `DOMContentLoaded` で初期化

### 8-3. Git（`.claude/rules/git-workflow.md`）
- master への直接 push 禁止（GitHub Pages 公開中）
- develop での開発が基本
- force-push は原則禁止
- コミット前にユーザー確認

---

## 9. 残タスク・継続課題

### 9-1. 直近の未完了タスク
- [ ] **ブラウザでの動作確認**（カテゴリ別締めルール対応）
  - 備品発注画面の予算オーバーアラート（カテゴリ締め日基準で計上月が変わるか）
  - 発注一覧（管理者）モーダルの「納品予定日」初期値
    - 2026-04-28（火）時点での期待値:
      - フィットネス備品 → 2026-05-09
      - ゴルフ備品 → 2026-04-29

### 9-2. 中期で残っている開発項目（見積明細ベース）

詳細は **[docs/快活システム_画面機能一覧_開発状況.xlsx](docs/快活システム_画面機能一覧_開発状況.xlsx)** を参照（2026-04-28 時点）。

5シート構成:
1. **画面一覧** — 全10画面のフロント/バック開発状況
2. **API一覧** — 全25エンドポイントのメソッド・権限・状況
3. **機能要件** — ビジネスルール36項目の実装状況
4. **要件トレーサビリティ** — 元要件・見積明細・設計事項・バッチ処理27項目の対応表
5. **サマリー** — カテゴリ別進捗 + 見積金額ベース進捗（¥1,900,000 / ¥2,100,000 = 90%）+ 主な残作業リスト

> 更新方法: `docs/generate_status_excel.py` を編集して `python generate_status_excel.py` で再生成。

---

## 10. テスト・確認ツール

### 10-1. URL
- ログイン: http://localhost/kaikatsu-system/login.html
- メニュー: http://localhost/kaikatsu-system/menu.html
- phpMyAdmin: http://localhost/phpmyadmin/

### 10-2. ロール切替（開発時のみ）
URLに `?role=admin` を付けると admin モード、デフォルトは shop。
※ 本番ではセッションの `role` を信頼する（`api/me.php` 経由）。

---

## 11. 連絡・補足

- 不明点は `backend-spec.md` を **正** として参照
- DB変更時は必ず `database/db-spec.md` および `database/db-guide.md` を更新
- 仕様変更があれば本 `HANDOVER.md` も追記更新

---

以上。
