# -*- coding: utf-8 -*-
"""快活システム 回帰スモークテスト（Playwright / 読み取り専用）

主要画面をロール別に開き、以下を検証する:
  1. HTTP応答が正常（<400）
  2. ログインページへ弾かれていない（セッション有効）
  3. body が auth-pending のまま固まっていない（過去の「画面真っ白」事故の検知）
  4. 画面に実コンテンツが描画されている（空白画面でない）
  5. JSコンソールエラー・pageerror が出ていない
  6. 権限ガード（未ログイン→login誘導 / shopが管理画面に入れない）

DBへの書き込みは行わない（発注登録・ステータス変更はスコープ外）。
実行:  python tests/e2e/smoke_test.py   （リポジトリルートから。XAMPP起動が前提）
       環境変数 KAIKATSU_BASE_URL でURL上書き可。--headed でブラウザ表示。
"""
import os
import sys
import time

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

from playwright.sync_api import sync_playwright

BASE = os.environ.get("KAIKATSU_BASE_URL", "http://localhost/kaikatsu-system/")

# ロール別アカウント {role: (login_id, password)}
# 現ローカルDBは「パスワード=ログインID」（2026-07-07 実DBのハッシュ照合で確認。
# 検証用アクセス情報.txt の「全員 password」は旧DB時代の記載）
ACCOUNTS = {
    "admin": ("admin",  "admin"),   # 商品部 管理者
    "shop":  ("30101",  "30101"),   # 札幌西岡店（構造化テストデータ投入店）
    "zone":  ("100004", "100004"),  # FiT東日本ゾーン（閲覧専用）
}

# (パス, 開けるロール)
PAGES = [
    ("menu.html",                ["admin", "shop", "zone"]),
    ("order-list.html",          ["admin", "shop", "zone"]),
    ("budget-management.html",   ["admin", "shop", "zone"]),
    ("procurement-history.html", ["admin", "shop", "zone"]),
    ("repair-order.html",        ["shop"]),
    ("equipment-order.html",     ["shop"]),
    ("parts-order.html",         ["shop"]),
    ("seat-replacement.html",    ["shop"]),
    ("admin-menu.html",          ["admin"]),
    ("system-settings.html",     ["admin"]),
]

# 開けてはいけない管理画面（権限ガード確認）
DENIED = [
    ("shop", "admin-menu.html"), ("shop", "system-settings.html"),
    ("zone", "admin-menu.html"), ("zone", "system-settings.html"),
]


def page_name(url: str) -> str:
    """URLの末尾ファイル名（'admin-menu.html' が 'menu.html' に部分一致する偽陽性を防ぐ）"""
    return url.split("?")[0].rstrip("/").rsplit("/", 1)[-1]

results = []  # (ok, label, note)


def record(ok: bool, label: str, note: str = ""):
    results.append((ok, label, note))
    print(f"  {'○' if ok else '✖'} {label}" + (f"  … {note}" if note else ""))


def login_context(browser, role: str):
    """APIログインでセッションCookie入りのコンテキストを作る。"""
    ctx = browser.new_context()
    login_id, password = ACCOUNTS[role]
    resp = ctx.request.post(
        BASE + "api/login.php",
        data={"login_id": login_id, "password": password},
    )
    body = resp.json()
    if not (resp.ok and body.get("success")):
        ctx.close()
        raise RuntimeError(f"{role} ログイン失敗: {resp.status} {body}")
    return ctx


def check_page(ctx, role: str, path: str):
    """1画面のスモークチェック。"""
    page = ctx.new_page()
    errors = []
    page.on("console", lambda m: errors.append(f"console: {m.text[:120]}")
            if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append(f"pageerror: {str(e)[:120]}"))
    try:
        resp = page.goto(BASE + path, wait_until="networkidle", timeout=30000)
        label = f"[{role}] {path}"
        if resp is None or resp.status >= 400:
            record(False, label, f"HTTP {resp.status if resp else '?'}")
            return
        if "login.html" in page.url:
            record(False, label, "ログインページへ弾かれた（セッション切れ扱い）")
            return
        body_class = page.evaluate("document.body.className") or ""
        if "auth-pending" in body_class:
            record(False, label, "auth-pending のまま（画面真っ白バグの兆候）")
            return
        text = page.inner_text("body").strip()
        if len(text) < 50:
            record(False, label, f"描画テキストが少なすぎる（{len(text)}文字）")
            return
        if errors:
            record(False, label, "; ".join(errors[:3]))
            return
        record(True, label)
    finally:
        page.close()


def main():
    headed = "--headed" in sys.argv
    t0 = time.time()
    print(f"=== 快活システム スモークテスト ===\nBASE: {BASE}\n")

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not headed)

        # 0. 未ログインアクセスは login.html へ誘導されること
        ctx = browser.new_context()
        page = ctx.new_page()
        page.goto(BASE + "order-list.html", wait_until="networkidle", timeout=30000)
        record("login.html" in page.url, "[未ログイン] order-list.html → login誘導",
               "" if "login.html" in page.url else f"到達URL: {page.url}")
        ctx.close()

        # 0b. ログインUI（正常系＋誤パスワード）
        ctx = browser.new_context()
        page = ctx.new_page()
        page.goto(BASE + "login.html", timeout=30000)
        admin_id, admin_pw = ACCOUNTS["admin"]
        page.fill("#userId", admin_id)
        page.fill("#password", "wrong-password")
        page.click("#loginBtn")
        page.wait_for_timeout(1500)
        record("login.html" in page.url, "[UI] 誤パスワードでログインできない",
               "" if "login.html" in page.url else f"到達URL: {page.url}")
        page.fill("#userId", admin_id)
        page.fill("#password", admin_pw)
        page.click("#loginBtn")
        try:
            page.wait_for_url("**/menu.html", timeout=10000)
            record(True, "[UI] admin ログイン → menu.html 遷移")
        except Exception:
            record(False, "[UI] admin ログイン → menu.html 遷移", f"到達URL: {page.url}")
        ctx.close()

        # 1. ロール別に各画面を開く
        for role in ACCOUNTS:
            print(f"\n--- role: {role} ({ACCOUNTS[role][0]}) ---")
            try:
                ctx = login_context(browser, role)
            except RuntimeError as e:
                record(False, f"[{role}] APIログイン", str(e))
                continue
            for path, roles in PAGES:
                if role in roles:
                    check_page(ctx, role, path)
            # 権限ガード: 開けてはいけない画面
            for r, path in DENIED:
                if r != role:
                    continue
                page = ctx.new_page()
                label = f"[{role}] {path} へのアクセスがブロックされる"
                try:
                    page.goto(BASE + path, wait_until="domcontentloaded", timeout=15000)
                    page.wait_for_timeout(2500)  # ガードJSのリダイレクトを待つ
                    blocked = page_name(page.url) in ("login.html", "menu.html")
                    record(blocked, label,
                           "" if blocked else f"到達URL: {page.url}（要確認）")
                except Exception as e:
                    record(False, label, f"例外: {str(e)[:100]}")
                finally:
                    page.close()
            ctx.close()

        browser.close()

    ok = sum(1 for r in results if r[0])
    ng = len(results) - ok
    print(f"\n=== 結果: {ok} OK / {ng} NG （{time.time()-t0:.0f}秒） ===")
    if ng:
        print("NG一覧:")
        for okf, label, note in results:
            if not okf:
                print(f"  ✖ {label} … {note}")
    return 1 if ng else 0


if __name__ == "__main__":
    sys.exit(main())
