# DeepL Translation API Program
DeepL Translation Tool (PHP)

## 概要

ブラウザから `TXT / PDF / DOCX / XLSX` をアップロードし、DeepL の Document API で翻訳します。アップロード時に概算料金を算出するため、PDF などの文字数はローカルで抽出します（Smalot PDF Parser → pdftotext → qpdf → ocrmypdf+tesseract の順でフォールバック）。

2025-09: PDF の文字数取得において、Smalot が空文字を返すケースでも確実にフォールバックするよう修正しました（以前は例外時のみフォールバック）。

## 必要条件

- PHP 8.x（例: Ubuntu 22.04 の PHP 8.2）
- Composer
- PHP拡張: `mbstring`（文字数カウント）, `zip`（DOCX/XLSXの展開）
- CLIツール（PDF文字数抽出用）:
  - `pdftotext`（poppler-utils）
  - `qpdf`（ストリーム解凍）
  - `ocrmypdf` と `tesseract-ocr`（OCRフォールバック）
  - 日本語OCR: `tesseract-ocr-jpn`、必要に応じて `tesseract-ocr-jpn-vert`
  - 依存: `ghostscript`
- Composerパッケージ:
  - `vlucas/phpdotenv`
  - `mpdf/mpdf`
  - `phpoffice/phpword`
  - `phpoffice/phpspreadsheet`
  - `smalot/pdfparser`（PDFテキスト抽出の第一段階）

## セットアップ

1. Composer 依存のインストール

    ```bash
    composer install
    ```

2. PHP拡張（Ubuntu 22.04, PHP 8.2 の例）

    ```bash
    sudo apt-get update
    sudo apt-get install -y php8.2-mbstring php8.2-zip
    # FPM利用時
    sudo systemctl restart php8.2-fpm
    # Apache利用時
    # sudo systemctl restart apache2
    ```

3. PDF抽出ツールのインストール（Ubuntu 22.04）

    ```bash
    sudo apt-get install -y poppler-utils qpdf ocrmypdf tesseract-ocr tesseract-ocr-jpn tesseract-ocr-jpn-vert ghostscript
    ```

4. FPMのPATHとshell_exec確認（FPM利用時）

    - `/etc/php/8.2/fpm/pool.d/www.conf` に以下を追記し、FPMを再起動
      - `env[PATH] = /usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin`
    - `php.ini` の `disable_functions` に `shell_exec` が含まれていないことを確認

5. **.envファイルの作成とAPI設定**

    プロジェクトルート直下に`.env`ファイルを作成し、下記のように記述します（実際の値に置き換えてください）。

    ```
    DEEPL_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
    DEEPL_API_BASE=https://api-free.deepl.com/v2
    ```

    - `DEEPL_API_KEY` : DeepLの認証キー。
    - `DEEPL_API_BASE` : Document API のエンドポイント。Proプランの場合は `https://api.deepl.com/v2` を指定します。

6. **.envファイルのセキュリティ対策**

    `.env` ファイルはWebからのアクセスを禁止してください。  
    プロジェクトルート直下に**.htaccess**を作成し、以下の内容を記載します。

    ```apache
    <FilesMatch "^\.env">
      Require all denied
    </FilesMatch>
    ```

    `.env` のパーミッションは `-r--------`（所有者のみ読み取り可）としてください。

7. **フォントの配置（日本語PDF出力用）**

    - `fonts` ディレクトリを作成し、`ipaexg.ttf` など日本語対応TrueTypeフォントを配置してください。

## 使い方

1. **ファイルをアップロード** – `index.html` から翻訳したいファイル（TXT, PDF, DOCX, XLSX）をアップロードします。
2. **DeepLへ送信** – アップロードされたファイルを DeepL の Document API に送信して翻訳します。
3. **出力保存・リンク表示** – 翻訳結果は `downloads/` ディレクトリに保存され、ダウンロードリンクが表示されます。

## ディレクトリ構成

- `uploads/` : アップロードされた元ファイル
- `downloads/` : DeepLから取得した翻訳結果を保存
- `logs/history.csv` : ファイル名・文字数・アップロード日時を記録

## テスト手順（CLI例）

```bash
curl -F file=@sample.pdf "$DEEPL_API_BASE/document" \
     -H "Authorization: DeepL-Auth-Key $DEEPL_API_KEY"
```

## 注意事項

- DeepL Document API では PDF/DOCX/XLSX の翻訳は1回につき最低50,000文字分がカウントされます。
  - 本アプリの見積もりも最小 50,000 文字で計算します。
  - テストには `.txt` を推奨します。
- **PDFファイルをアップロードした場合、出力形式はPDFまたはDOCXを選択可能**です。
- **XLSXファイルをアップロードした場合、出力形式はXLSXのみ選択可能**です。
- `.env` ファイルの**Webアクセス遮断とパーミッション制御**を必ず行ってください。

## トラブルシューティング

- 文字数の取得に失敗しました（PDF）
  - `pdftotext` が見つからない/実行不可 → `poppler-utils` を導入、FPMの `PATH` を設定
  - Smalot で空文字 → 2025-09 修正で `pdftotext → qpdf → ocrmypdf+tesseract` に自動フォールバックします
  - OCR日本語データ不足 → `tesseract-ocr-jpn`（および `-jpn-vert`）を導入
  - `disable_functions` に `shell_exec` が含まれている → `php.ini` から除外

- PHP拡張の依存エラー（mbstring/zip）
  - 稼働中の PHP と同じバージョンでインストール（例: `php8.2-mbstring`）

- FPMサービス名が違う/再起動できない
  - `systemctl list-unit-files | grep php` で確認。例: `php8.2-fpm`

## 変更履歴（抜粋）

- 2025-09: PDF抽出のフォールバック強化（Smalotが空文字を返した場合もCLI/OCRに自動切替）。

## 長時間処理のタイムアウト対策

Excelの翻訳処理に時間がかかる場合は、以下のように各コンポーネントのタイムアウト設定を調整してください。

- **Apache**: `httpd.conf` または vhost 設定で `ProxyTimeout` を十分に大きく設定。
- **PHP-FPM**: `php-fpm.conf` の `request_terminate_timeout` を延長。
- **PHP**: `php.ini` の `max_execution_time` を延長、または翻訳処理を CLI スクリプトとして実行し、`cron` やキューで非同期に処理して Web リクエストから切り離す。

## その他

- このプログラムを利用する際は自己責任でお願いいたします。
- システム要件や追加のセキュリティ対策については必要に応じて行って下さい。
