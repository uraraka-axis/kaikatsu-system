@echo off
chcp 65001 >nul
setlocal

REM ============================================================
REM  快活システム 開発/検証環境 再構築スクリプト（Windows / XAMPP）
REM
REM  実行順:
REM    1) database\schema.sql               … DB再作成＋全テーブル
REM    2) database\seed_master_real.sql      … 本番相当マスタ（※個人情報含む・別途共有）
REM    3) tools\gen_product_placeholders.php … 全商品に仮画像3枚を生成
REM    4) tools\seed_orders_volume.php       … 検証用テスト発注 約3000件
REM
REM  ⚠ schema.sql は「kaikatsu」データベースを DROP して作り直します（破壊的）。
REM     本番DBでは絶対に実行しないこと。開発/検証用ローカルDB専用。
REM ============================================================

REM ---- 環境設定（XAMPP既定。必要に応じて変更） ----
set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "PHP=C:\xampp\php\php.exe"
set "DBUSER=root"
set "DBPASS="
set "DBNAME=kaikatsu"

REM ---- プロジェクトルート（このバッチの1つ上の階層） ----
set "ROOT=%~dp0.."
pushd "%ROOT%"

echo ============================================================
echo  快活システム 環境再構築
echo  対象DB : %DBNAME%   （※既存の %DBNAME% は削除され作り直されます）
echo  MySQL  : %MYSQL%
echo  PHP    : %PHP%
echo ============================================================

if not exist "%MYSQL%" ( echo [NG] mysql.exe が見つかりません: %MYSQL% & goto :fail )
if not exist "%PHP%"   ( echo [NG] php.exe が見つかりません: %PHP%   & goto :fail )

set /p ANS="続行しますか？ 既存の %DBNAME% は失われます (y/N): "
if /i not "%ANS%"=="y" ( echo 中止しました。 & goto :end )

set "PWARG="
if not "%DBPASS%"=="" set "PWARG=-p%DBPASS%"

echo.
echo [1/4] schema.sql を適用（DB再作成＋全テーブル）...
"%MYSQL%" -u %DBUSER% %PWARG% --default-character-set=utf8mb4 < "database\schema.sql"
if errorlevel 1 ( echo [NG] schema.sql の適用でエラー & goto :fail )

echo [2/4] seed_master_real.sql を適用（本番相当マスタ）...
if not exist "database\seed_master_real.sql" (
  echo [NG] database\seed_master_real.sql がありません。
  echo      個人情報を含むため Git 管理外です。別途共有を受けて database\ に配置してください。
  goto :fail
)
"%MYSQL%" -u %DBUSER% %PWARG% --default-character-set=utf8mb4 %DBNAME% < "database\seed_master_real.sql"
if errorlevel 1 ( echo [NG] seed_master_real.sql の適用でエラー & goto :fail )

echo [3/4] 商品の仮画像を生成（uploads\products に528枚）...
"%PHP%" "tools\gen_product_placeholders.php"
if errorlevel 1 ( echo [NG] gen_product_placeholders.php でエラー & goto :fail )

echo [4/4] 検証用テスト発注を生成（約3000件）...
"%PHP%" "tools\seed_orders_volume.php"
if errorlevel 1 ( echo [NG] seed_orders_volume.php でエラー & goto :fail )

echo.
echo ============================================================
echo  完了しました。
echo  URL   : http://localhost/kaikatsu-system/login.html
echo  ログイン例: admin / password , 30101 / password
echo ============================================================
goto :end

:fail
echo.
echo *** 途中で失敗しました。上記メッセージを確認してください。 ***
popd
endlocal
exit /b 1

:end
popd
endlocal
exit /b 0
