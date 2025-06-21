DeepL Translation API Program
DeepL Translation Tool (PHP)

必要条件
PHP 8.x

Composer

php-zip（DOCX出力に必須。apt install php8.2-zip などで有効化）

以下の Composer パッケージ

vlucas/phpdotenv

mpdf/mpdf

phpoffice/phpword

smalot/pdfparser

セットアップ
Composerパッケージのインストール

sh
コピーする
編集する
composer require vlucas/phpdotenv mpdf/mpdf phpoffice/phpword smalot/pdfparser
php-zip拡張のインストール・有効化（DOCX出力に必須）

例（PHP 8.2の場合）:

sh
コピーする
編集する
sudo apt install php8.2-zip
sudo systemctl restart apache2
php -m | grep zip で「zip」と表示されればOK

.envファイルの作成とAPIキー設定

プロジェクトルート直下に.envファイルを作成し、下記のように記述します（実際のキーに書き換えてください）。

ini
コピーする
編集する
DEEPL_AUTH_KEY=xxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxxxx
.envファイルのセキュリティ対策

.env ファイルはWebからのアクセスを禁止してください。
プロジェクトルート直下に**.htaccess**を作成し、下記のように記述します：

php-template
コピーする
編集する
<FilesMatch "^\.env">
  Require all denied
</FilesMatch>
.env のパーミッションは -r--------（所有者のみ読み取り可）としてください。

フォントの配置（日本語PDF出力用）

fonts ディレクトリを作成し、ipaexg.ttf など日本語対応TrueTypeフォントを配置してください。

使い方
index.htmlにアクセス

左のメニューから**「ファイルアップロード」**をクリックし、翻訳したいファイルをアップロード

upload_file.php からファイル（TXT, PDF, DOCX）をアップロード（最大 20 MB）

文字数を自動計算し、概算料金を表示

出力形式を PDF か DOCX から選択し、translate.php が DeepL API で翻訳

.txt は「PDF」または「DOCX」で翻訳出力が可能

.pdf アップロード時は仕様上「PDF出力のみ」

完成したファイルは downloads.php でダウンロード可能

manage.php ではアップロード済みファイルの削除や再翻訳が可能

アップロード履歴は logs/history.csv に記録

ディレクトリ構成
uploads/ : アップロードされた元ファイル

downloads/ : 翻訳後に保存されるファイル

logs/history.csv : ファイル名・文字数・アップロード日時を記録

注意事項
DeepL APIでは PDF, DOCX, XLSXの翻訳は1回につき最低50,000文字分がカウントされます。

テストにはできるだけ .txt ファイルを使うことを推奨します。

PDFファイルをアップロードした場合、出力形式はPDFのみ選択可能です（DeepLの仕様です）。

.env ファイルのWebアクセス遮断とパーミッション制御を徹底してください。

その他
システム要件や詳細なセキュリティ対策については必要に応じて追記してください。
