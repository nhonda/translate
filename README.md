# DeepL Translation API Program
DeepL Translation Tool (PHP)

## 概要

ブラウザから `TXT / PDF / DOCX / XLSX / PPTX` をアップロードし、DeepL の Document API で翻訳します。アップロード時に概算料金を算出するため、PDF などの文字数はローカルで抽出します（Smalot PDF Parser → pdftotext → qpdf → ocrmypdf+tesseract の順でフォールバック）。PPTX はスライドXML/ノートXMLから簡易抽出します。

2025-09: PDF の文字数取得において、Smalot が空文字を返すケースでも確実にフォールバックするよう修正。TXT 入力の出力形式に PDF/DOCX を追加し、PDF/DOC/DOCX 入力でも TXT でダウンロード可能に。PPTX のアップロード/翻訳に対応。さらに、翻訳先の言語選択（日本語/英語）に対応し、出力ファイル名のサフィックス（`_jp` / `_en`）を導入。

## 本日の更新（UI/Glossary 周り）

- 用語集管理の強化（`glossary.php` / 新規 `glossary_edit.php` / `glossary_update.php`）
  - 一覧の言語表記を大文字に統一（`EN → JA`）。
  - 編集機能を追加し、編集画面は単方向（英→日 または 日→英）のみ編集。
  - 新規用語集フォームの見出しを「新規用語集」に、既定名を「デフォルトの用語集」に変更。
  - 入力テーブルの削除ボタン表記を「－行削除」に統一。
  - DeepL API 仕様に合わせ、更新は「再作成→旧ID削除」で実装。旧→新IDの対応を保持して一覧の並びを維持。
- 用語集の表示順を登録順で固定
  - `logs/glossary_order.json` に表示順を保存。`glossary.php` 一覧と `manage.php` / `upload_file.php` のプルダウンで同順序を適用。
- 画面UIの統一
  - `manage.php` 一覧を8列（ファイル名/文字数/概算コスト/用語集/翻訳言語/出力形式/翻訳再実行/削除）に整理。ファイル名はダウンロードリンク。
  - `upload_file.php` も並びを「用語集 → 翻訳言語 → 出力形式 → 翻訳実行」に統一。
- ダウンロード一覧（`downloads.php`）
  - ファイル名をダウンロードリンク化し、「ダウンロード」列を削除。各行に削除ボタンを配置。

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
    # 任意: デフォルト用語集ID
    DEEPL_GLOSSARY_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
    ```

    - `DEEPL_API_KEY` / `DEEPL_AUTH_KEY` : DeepLの認証キー（両対応、非空な方を使用）。
    - `DEEPL_API_BASE` : Document API のエンドポイント。Proプランの場合は `https://api.deepl.com/v2` を指定します。
    - `APP_DEBUG` : true で詳細デバッグログを出力。
    - `DEEPL_GLOSSARY_ID` : 未指定時に自動で使用する用語集ID（フォームで指定した値が優先されます）。

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
2. **翻訳先の選択** – 日本語（JA）または英語（EN-US）を選択できます。日本語の原文を英訳したい場合は「英語」を、英語の原文を和訳したい場合は「日本語」を選びます。
3. **変換形式の選択** – 元形式ごとの出力オプション:
   - TXT: `txt` / `pdf` / `docx`（txt→pdf は mPDF、txt→docx は PHPWord で生成）
   - PDF / DOC / DOCX: `pdf` / `docx` / `txt`（`txt` は DeepL で `docx` を取得後にサーバ側でテキスト抽出）
   - XLSX: `xlsx`
   - PPTX: `pptx`
4. **用語集の選択（任意）** – `.env` の `DEEPL_GLOSSARY_ID` が自動入力されます。ドロップダウンから既存の用語集を選ぶか、未選択のまま送信すると用語集無しで翻訳します。手動入力も可能で、DeepLの仕様上、例えば日本語↔英語など対応する言語ペアのみ利用できます。
5. **DeepLへ送信** – アップロードされたファイルを DeepL の Document API に送信して翻訳します。
6. **出力保存・リンク表示** – 翻訳結果は `downloads/` ディレクトリに保存され、ダウンロードリンクが表示されます。

### 言語とファイル名の規約

- 生成ファイル名は、翻訳先に応じてベース名にサフィックスを付与します。
  - 日本語に翻訳: `*_jp.<ext>`
  - 英語に翻訳: `*_en.<ext>`
- `downloads.php` の一覧と文字数集計は、`_jp` と `_en` のサフィックスを無視して同一ベース名として扱います。
- 「ファイル管理」からの削除は、同じベース名の `_jp` と `_en` 出力をまとめて削除します。

補足: 英語は既定で EN-US 方言を使用します。必要に応じてコード（`translate.php`）は EN-GB も受け付けます。

同一判定時の自動再試行（原文=翻訳先）

- DeepL から `Source and target language are equal.` が返った場合、翻訳先は維持し、原文言語（`source_lang`）を推定指定して1回だけ再試行します。
  - 例: 目標が EN-US のときは `source_lang=JA` を付与して再試行。
  - 例: 目標が JA のときは `source_lang=EN` を付与して再試行。
- これにより、ユーザーが選んだ翻訳先が勝手に変わることはありません。

ファイル名のサフィックスについて

- 出力ファイル名の生成時は、元ファイル名の末尾に既に `_jp` または `_en` が付いている場合、それらを一旦取り除いてから新しいサフィックスを付与します（重複防止）。

## ディレクトリ構成

- `uploads/` : アップロードされた元ファイル
- `downloads/` : DeepLから取得した翻訳結果を保存
- `logs/history.csv` : ファイル名・文字数・アップロード日時を記録
- `logs/glossary_order.json` : 用語集の表示順（登録順）を保持

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
  - 翻訳先の言語選択（日本語/英語）に対応、出力ファイル名に `_jp` / `_en` を付与
  - ダウンロード一覧と削除ロジックを `_jp` / `_en` の両方に対応

## 長時間処理のタイムアウト対策

Excelの翻訳処理に時間がかかる場合は、以下のように各コンポーネントのタイムアウト設定を調整してください。

- **Apache**: `httpd.conf` または vhost 設定で `ProxyTimeout` を十分に大きく設定。
- **PHP-FPM**: `php-fpm.conf` の `request_terminate_timeout` を延長。
- **PHP**: `php.ini` の `max_execution_time` を延長、または翻訳処理を CLI スクリプトとして実行し、`cron` やキューで非同期に処理して Web リクエストから切り離す。

## その他

- このプログラムを利用する際は自己責任でお願いいたします。
- システム要件や追加のセキュリティ対策については必要に応じて行って下さい。
