# DeepL Translation API Program
DeepL Translation Tool (PHP)

## 必要条件

- **PHP 8.x**
- **Composer**
- **php-zip**（DOCX出力に必須。例: `sudo apt install php8.2-zip`）
- 以下の Composer パッケージ  
    - [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv)  
    - [`mpdf/mpdf`](https://github.com/mpdf/mpdf)  
    - [`phpoffice/phpword`](https://github.com/PHPOffice/PHPWord)
    - [`phpoffice/phpspreadsheet`](https://github.com/PHPOffice/PhpSpreadsheet)
    - [`smalot/pdfparser`](https://github.com/smalot/pdfparser)

## セットアップ

1. **Composerパッケージのインストール**

    ```bash
    composer require vlucas/phpdotenv mpdf/mpdf phpoffice/phpword phpoffice/phpspreadsheet smalot/pdfparser
    ```

2. **php-zip拡張のインストール・有効化**

    例（PHP 8.2の場合）:

    ```bash
    sudo apt install php8.2-zip
    sudo systemctl restart apache2
    ```

    `php -m | grep zip` で `zip` が表示されればOKです。

3. **.envファイルの作成とAPIキー設定**

    プロジェクトルート直下に`.env`ファイルを作成し、下記のように記述します（実際のAPIキーに書き換えてください）。

    ```
    DEEPL_AUTH_KEY=xxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxxxx
    ```

4. **.envファイルのセキュリティ対策**

    `.env` ファイルはWebからのアクセスを禁止してください。  
    プロジェクトルート直下に**.htaccess**を作成し、以下の内容を記載します。

    ```apache
    <FilesMatch "^\.env">
      Require all denied
    </FilesMatch>
    ```

    `.env` のパーミッションは `-r--------`（所有者のみ読み取り可）としてください。

5. **フォントの配置（日本語PDF出力用）**

    - `fonts` ディレクトリを作成し、`ipaexg.ttf` など日本語対応TrueTypeフォントを配置してください。

## 使い方

1. `index.html` にアクセス
2. 左のメニューから「ファイルアップロード」をクリックし、翻訳したいファイルをアップロード
3. `upload_file.php` からファイル（**TXT, PDF, DOCX, XLSX**）をアップロード（最大 20 MB）
4. 文字数を自動計算し、**概算料金**を表示
5. 出力形式を **PDF・DOCX・XLSX** から選択し、`translate.php` が DeepL API で翻訳
    - `.txt` は「PDF」または「DOCX」どちらも選択可能
    - `.pdf` アップロード時は**仕様上「PDF出力のみ」**（DeepL APIの制約）
    - `.xlsx` アップロード時は**仕様上「XLSX出力のみ」**
6. 完成したファイルは `downloads.php` でダウンロード可能
7. `manage.php` ではアップロード済みファイルの**削除や再翻訳**が可能
8. アップロード履歴は `logs/history.csv` に記録

## ディレクトリ構成

- `uploads/` : アップロードされた元ファイル
- `downloads/` : 翻訳後に保存されるファイル
- `logs/history.csv` : ファイル名・文字数・アップロード日時を記録

## 注意事項

- **DeepL APIでは PDF, DOCX, XLSX の翻訳は1回につき最低50,000文字分がカウントされます。**  
  テストにはできるだけ `.txt` ファイルを使うことを推奨します。
- **PDFファイルをアップロードした場合、出力形式はPDFのみ選択可能**です（DeepL APIの仕様です）。
- **XLSXファイルをアップロードした場合、出力形式はXLSXのみ選択可能**です。
- `.env` ファイルの**Webアクセス遮断とパーミッション制御**を必ず行ってください。

## 長時間処理のタイムアウト対策

翻訳処理に時間がかかる場合は、以下のように各コンポーネントのタイムアウト設定を調整してください。

- **Apache**: `httpd.conf` または vhost 設定で `ProxyTimeout` を十分に大きく設定。
- **PHP-FPM**: `php-fpm.conf` の `request_terminate_timeout` を延長。
- **PHP**: `php.ini` の `max_execution_time` を延長、または翻訳処理を CLI スクリプトとして実行し、`cron` やキューで非同期に処理して Web リクエストから切り離す。

## その他

- このプログラムを利用する際は自己責任でお願いいたします。
- システム要件や追加のセキュリティ対策については必要に応じて行って下さい。
