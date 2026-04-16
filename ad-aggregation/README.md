# リスティング広告 集計ダッシュボード - セットアップ手順

## ファイル構成
```
listing-dashboard/
├── index.html        ← 画面（フロントエンド）
├── .htaccess         ← URL・キャッシュ設定
├── setup.sql         ← phpMyAdminで実行するSQL
└── api/
    ├── config.php    ← DB接続設定（★3か所書き換え）
    └── index.php     ← サーバー側API処理
```

## セットアップ手順

### 1. データベース作成
1. Xserverの管理画面からMySQL設定を開く
2. データベースを作成（例: `xs123456_listing`）
3. MySQLユーザーを作成し、上記DBへのアクセス権を付与

### 2. テーブル作成・初期データ投入
1. phpMyAdminにログイン
2. 作成したデータベースを選択
3. 「SQL」タブを開く
4. `setup.sql` の中身を全て貼り付けて「実行」

### 3. config.php を編集
`api/config.php` の以下3か所を書き換え：
```php
$db_name = 'xs123456_listing';   // ← DB名
$db_user = 'xs123456_user';       // ← ユーザー名
$db_pass = 'your_password';       // ← パスワード
```

### 4. FTPアップロード
全ファイルをサーバーの公開ディレクトリにアップロード：
```
public_html/listing/   ← 任意のディレクトリ
├── index.html
├── .htaccess
└── api/
    ├── config.php
    └── index.php
```
※ `.htaccess` はドット始まりの隠しファイルです。FTPソフトの設定で表示をONにしてください。

### 5. アクセス確認
ブラウザで `https://yourdomain.com/listing/` にアクセス

## 機能一覧
- **4つのビューモード**: ✏️入力 / 📊一覧 / 📈グラフ / 📉推移
- **CSVインポート**: Google広告・Yahoo広告のCSVをドラッグ&ドロップ
- **按分ルール**: 垂水区全域・姫路全域等の按分先を画面から変更可能
- **セル編集**: クリックで直接編集、Enterで確定
- **正社員募集**: 本部計上の正社員募集キャンペーンも自動取込
- **全データDB保存**: 編集内容はリアルタイムでMySQLに保存
