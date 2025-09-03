# DeepL Translation API Program
DeepL Translation Tool (PHP)

## 必要条件

- **PHP 8.x**
- **Composer**
- **php-zip**（DOCX出力に必須。例: `sudo apt install php8.2-zip`）
- **pdftotext** と **qpdf**（PDF文字数見積もりのみに使用。コスト見積もりを行わない場合は不要。例: `apt install poppler-utils qpdf`）
- **ocrmypdf** と **tesseract-ocr**（オプション。標準のテキスト抽出が空だったPDFに自動でOCRを適用するために使用。例: `apt install ocrmypdf tesseract-ocr`）
- 以下の Composer パッケージ
    - [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv)
    - [`mpdf/mpdf`](https://github.com/mpdf/mpdf)
    - [`phpoffice/phpword`](https://github.com/PHPOffice/PHPWord)
    - [`phpoffice/phpspreadsheet`](https://github.com/PHPOffice/PhpSpreadsheet)
    
- PDF解析には外部ライブラリを使用せず、DeepLの**Document API**を直接利用します。

## セットアップ

1. **Composerパッケージのインストール**

    ```bash
    composer require vlucas/phpdotenv mpdf/mpdf phpoffice/phpword phpoffice/phpspreadsheet
    ```

    DeepLのDocument APIを直接利用するため、PDF解析用ライブラリは不要です。

2. **php-zip拡張のインストール・有効化**

    例（PHP 8.2の場合）:

    ```bash
    sudo apt install php8.2-zip
    sudo systemctl restart apache2
    ```

    `php -m | grep zip` で `zip` が表示されればOKです。

3. **OCRツールのインストール（オプション）**

    ```bash
    sudo apt install ocrmypdf tesseract-ocr
    ```

    標準のテキスト抽出が空だったPDFに自動でOCRを適用します。両方のコマンドが見つからない場合は処理をスキップします。

4. **.envファイルの作成とAPI設定**

    プロジェクトルート直下に`.env`ファイルを作成し、下記のように記述します（実際の値に置き換えてください）。

    ```
    DEEPL_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
    DEEPL_API_BASE=https://api-free.deepl.com/v2
    ```

    - `DEEPL_API_KEY` : DeepLの認証キー。
    - `DEEPL_API_BASE` : Document API のエンドポイント。Proプランの場合は `https://api.deepl.com/v2` を指定します。

5. **.envファイルのセキュリティ対策**

    `.env` ファイルはWebからのアクセスを禁止してください。  
    プロジェクトルート直下に**.htaccess**を作成し、以下の内容を記載します。

    ```apache
    <FilesMatch "^\.env">
      Require all denied
    </FilesMatch>
    ```

    `.env` のパーミッションは `-r--------`（所有者のみ読み取り可）としてください。

6. **フォントの配置（日本語PDF出力用）**

    - `fonts` ディレクトリを作成し、`ipaexg.ttf` など日本語対応TrueTypeフォントを配置してください。

## 使い方

1. **ファイルをアップロード** – `index.html` から翻訳したいファイル（TXT, PDF, DOCX, XLSX）をアップロードします。
2. **DeepLへ送信** – アップロードされたファイルを DeepL の Document API に送信して翻訳します。
3. **出力保存・リンク表示** – 翻訳結果は `output/` ディレクトリに保存され、ダウンロードリンクが表示されます。

## ディレクトリ構成

- `uploads/` : アップロードされた元ファイル
- `output/` : DeepLから取得した翻訳結果を保存
- `logs/history.csv` : ファイル名・文字数・アップロード日時を記録

## テスト手順（CLI例）

```bash
curl -F file=@sample.pdf "$DEEPL_API_BASE/document" \
     -H "Authorization: DeepL-Auth-Key $DEEPL_API_KEY"
```

## 注意事項

- **DeepL APIでは PDF, DOCX, XLSX の翻訳は1回につき最低50,000文字分がカウントされます。**  
  テストにはできるだけ `.txt` ファイルを使うことを推奨します。
- **PDFファイルをアップロードした場合、出力形式はPDFまたはDOCXを選択可能**です。
- **XLSXファイルをアップロードした場合、出力形式はXLSXのみ選択可能**です。
- `.env` ファイルの**Webアクセス遮断とパーミッション制御**を必ず行ってください。

## 長時間処理のタイムアウト対策

Excelの翻訳処理に時間がかかる場合は、以下のように各コンポーネントのタイムアウト設定を調整してください。

- **Apache**: `httpd.conf` または vhost 設定で `ProxyTimeout` を十分に大きく設定。
- **PHP-FPM**: `php-fpm.conf` の `request_terminate_timeout` を延長。
- **PHP**: `php.ini` の `max_execution_time` を延長、または翻訳処理を CLI スクリプトとして実行し、`cron` やキューで非同期に処理して Web リクエストから切り離す。

## その他

- このプログラムを利用する際は自己責任でお願いいたします。
- システム要件や追加のセキュリティ対策については必要に応じて行って下さい。
