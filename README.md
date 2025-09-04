# DeepL Translation API Program
DeepL Translation Tool (PHP)

## 概要

ブラウザから `TXT / PDF / DOCX / XLSX / PPTX` をアップロードし、DeepL の Document API で翻訳します。アップロード時に概算料金を算出するため、PDF などの文字数はローカルで抽出します（Smalot PDF Parser → pdftotext → qpdf → ocrmypdf+tesseract の順でフォールバック）。PPTX はスライドXML/ノートXMLから簡易抽出します。

2025-09: PDF の文字数取得において、Smalot が空文字を返すケースでも確実にフォールバックするよう修正。TXT 入力の出力形式に PDF/DOCX を追加し、PDF/DOC/DOCX 入力でも TXT でダウンロード可能に。PPTX のアップロード/翻訳に対応。

## 必要条件

- PHP 8.x（例: Ubuntu 22.04 の PHP 8.2）
- Composer
- PHP拡張: `mbstring`（文字数カウント）, `zip`（DOCX/XLSX/PPTXの展開）
- CLIツール（PDF文字数抽出用）:
  - `pdftotext`（poppler-utils）
  - `qpdf`（ストリーム解凍）
  - `ocrmypdf` と `tesseract-ocr`（OCRフォールバック）
  - 日本語OCR: `tesseract-ocr-jpn`、必要に応じて `tesseract-ocr-jpn-vert`
  - 依存: `ghostscript`
- Composerパッケージ:
  - `vlucas/phpdotenv`
  - `mpdf/mpdf`（TXT→PDF 出力）
  - `phpoffice/phpword`（TXT→DOCX 出力）
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
    # どちらの名前でも可（非空優先）
    DEEPL_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
    # または
    DEEPL_AUTH_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx

    DEEPL_API_BASE=https://api-free.deepl.com/v2
    # 任意: デバッグログ（機微はマスク）
    APP_DEBUG=false
    ```

    - `DEEPL_API_KEY` / `DEEPL_AUTH_KEY` : DeepLの認証キー（両対応、非空な方を使用）。
    - `DEEPL_API_BASE` : Document API のエンドポイント。Proプランの場合は `https://api.deepl.com/v2` を指定します。
    - `APP_DEBUG` : true で詳細デバッグログを出力。

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

1. **ファイルをアップロード** – `index.html` から翻訳したいファイル（TXT, PDF, DOCX, XLSX, PPTX）をアップロードします。
2. **変換形式の選択** – 元形式ごとの出力オプション:
   - TXT: `txt` / `pdf` / `docx`（txt→pdf は mPDF、txt→docx は PHPWord で生成）
   - PDF / DOC / DOCX: `pdf` / `docx` / `txt`（`txt` は DeepL で `docx` を取得後にサーバ側でテキスト抽出）
   - XLSX: `xlsx`
   - PPTX: `pptx`
3. **DeepLへ送信** – アップロードされたファイルを DeepL の Document API に送信して翻訳します。
4. **出力保存・リンク表示** – 翻訳結果は `downloads/` ディレクトリに保存され、ダウンロードリンクが表示されます。

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
- **PDFファイルをアップロードした場合、出力形式はPDF/DOCX/TXTを選択可能**です。
- **DOC/DOCXファイルをアップロードした場合、出力形式はPDF/DOCX/TXTを選択可能**です。
- **XLSXファイルをアップロードした場合、出力形式はXLSXのみ選択可能**です。
- **PPTXファイルをアップロードした場合、出力形式はPPTXのみ選択可能**です。
- `.env` ファイルの**Webアクセス遮断とパーミッション制御**を必ず行ってください。

## トラブルシューティング

- 文字数の取得に失敗しました（PDF）
  - `pdftotext` が見つからない/実行不可 → `poppler-utils` を導入、FPMの `PATH` を設定
  - Smalot で空文字 → 2025-09 修正で `pdftotext → qpdf → ocrmypdf+tesseract` に自動フォールバックします
  - OCR日本語データ不足 → `tesseract-ocr-jpn`（および `-jpn-vert`）を導入
  - `disable_functions` に `shell_exec` が含まれている → `php.ini` から除外

- Missing auth_key（DeepL 400）
  - `.env` は `DEEPL_API_KEY` か `DEEPL_AUTH_KEY` のどちらでも可（非空優先）
  - 本アプリは Authorization ヘッダ・POST フィールド・URL クエリの3箇所で `auth_key` を送信
  - 反映が怪しい時は `APP_DEBUG=true` にして FPM を再起動、ログで送信内容を確認（機微はマスク済み）

- PHP拡張の依存エラー（mbstring/zip）
  - 稼働中の PHP と同じバージョンでインストール（例: `php8.2-mbstring`）

- FPMサービス名が違う/再起動できない
  - `systemctl list-unit-files | grep php` で確認。例: `php8.2-fpm`

## 変更履歴（抜粋）

- 2025-09:
  - PDF抽出のフォールバック強化（Smalotが空文字を返した場合もCLI/OCRに自動切替）
  - TXT入力での PDF/DOCX 出力、PDF/DOC/DOCX 入力での TXT 出力に対応
  - PPTX のアップロード/翻訳対応、文字数カウント追加

## 長時間処理のタイムアウト対策

Excelの翻訳処理に時間がかかる場合は、以下のように各コンポーネントのタイムアウト設定を調整してください。

- **Apache**: `httpd.conf` または vhost 設定で `ProxyTimeout` を十分に大きく設定。
- **PHP-FPM**: `php-fpm.conf` の `request_terminate_timeout` を延長。
- **PHP**: `php.ini` の `max_execution_time` を延長、または翻訳処理を CLI スクリプトとして実行し、`cron` やキューで非同期に処理して Web リクエストから切り離す。

## その他

- このプログラムを利用する際は自己責任でお願いいたします。
- システム要件や追加のセキュリティ対策については必要に応じて行って下さい。
