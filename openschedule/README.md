# 📚 新教室オープン スケジュール管理システム

プログラマーさんのマニュアルの手順にそのまま沿って進められます。

---

## ファイル一覧

```
scheduler/
├── index.html      ← 画面
├── .htaccess       ← そのまま（編集不要）
├── api/
│   ├── config.php  ← ★ ここだけ書き換え
│   └── index.php   ← そのまま（編集不要）
├── setup.sql                          ← 新規構築用SQL
└── migration_task_category_order.sql  ← 既存DBのみ1回（カテゴリ並び替え機能用）
```

---

## 手順

### １．データベースの新規作成（マニュアル通り）

マニュアルの「１．データベースの新規作成」をそのまま実施。
- データベース名の例：`xs047468_classroom`
- ユーザー名の例：`xs047468_user`
- **パスワードは必ず控えてください**

### ２．phpMyAdminでSQLを実行

マニュアルの「２．データベースの利用開始準備」をそのまま実施。
- `setup.sql` の中身をすべてコピーして「SQL」タブに貼り付け→「実行」
- **すでに `setup.sql` 実行済みのDB**でカテゴリのドラッグ並び替えを使う場合は、追加で `migration_task_category_order.sql` を1回実行（「Duplicate column」なら済み）

### ３．ファイルの編集（config.php を書き換え）

`api/config.php` をメモ帳で開いて3か所書き換え：

```
define('DB_NAME', 'xs047468_classroom');    ← 手順1で作ったDB名
define('DB_USER', 'xs047468_user');         ← 手順1で作ったユーザー名
define('DB_PASS', 'ここにパスワードを入力');  ← 手順1で控えたパスワード
```

※ DB_HOST は `localhost` のままでOK

### ４．FTPアップロード

マニュアルの「４」をそのまま実施。フォルダ名の例：`scheduler`
**setup.sql 以外**をアップ。

### ５．動作確認

`http://xs047468.xsrv.jp/scheduler/` にアクセス

---

## 使い方

- **新教室作成**：トップ →「＋ 新しい教室プロジェクトを作成」
- **複製（2回目以降おすすめ）**：既存教室の「📋 複製」→ 日程自動調整
- **複数人**：同じURLにアクセスするだけ。自動同期。
