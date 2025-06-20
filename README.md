# translate
DeepL Translation API Program
# DeepL Translation Tool (PHP)

## 必要条件
- PHP 8.x
- Composer
- php-zip (DOCX 出力に必要)
- 以下の Composer パッケージ  
  - vlucas/phpdotenv  
  - mpdf/mpdf  
  - phpoffice/phpword  
  - smalot/pdfparser

## セットアップ
1. 上記パッケージをインストール  
   ```bash
   composer require vlucas/phpdotenv mpdf/mpdf phpoffice/phpword smalot/pdfparser
php-zip が有効になっていない場合は、PHP の拡張モジュールとして zip を追加してください。

リポジトリ直下に .env を作成し、DeepL API キーを設定します。

使い方
index.html にアクセスします。

左のメニューからファイルアップロードをクリックし、翻訳したいファイルをアップロード

upload_file.php からファイル（TXT, PDF, DOCX）をアップロード ※ファイルサイズは最大 20 MB

文字数を自動計算し、概算料金を表示

出力形式を PDF か DOCX から選択し、translate.php が DeepL API で翻訳

完成したファイルは downloads.php にリスト表示

manage.php ではアップロード済みファイルの削除や再翻訳が可能

アップロード履歴は logs/history.csv に記録

ディレクトリ
uploads/ : アップロードされた元ファイル

downloads/ : 翻訳後に保存されるファイル

logs/history.csv : ファイル名と文字数、アップロード日時を保存

このように整理すると、依存関係やセットアップ手順、ファイルの流れが明確になります。DOCX 出力に php-zip が必要な点も忘れずに記載することを推奨します。
