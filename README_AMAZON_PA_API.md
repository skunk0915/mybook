# Amazon PA API 設定ガイド

このプロジェクトでは、書籍検索にAmazon Product Advertising API (PA API) v5を使用しています。

## 前提条件

Amazon PA APIを使用するには、以下が必要です：

1. Amazonアソシエイトアカウント
2. PA API用のアクセスキーとシークレットキー
3. アソシエイトタグ

## セットアップ手順

### 1. Amazonアソシエイトプログラムに登録

まだ登録していない場合は、[Amazonアソシエイト](https://affiliate.amazon.co.jp/)に登録してください。

### 2. PA APIの認証情報を取得

1. Amazonアソシエイトのアカウントにログイン
2. 「ツール」→「Product Advertising API」を選択
3. アクセスキーとシークレットキーを取得
4. アソシエイトタグをメモ

### 3. 環境変数ファイルの設定

プロジェクトのルートディレクトリに `.env` ファイルを作成します：

```bash
cp .env.example .env
```

`.env` ファイルを開いて、以下の情報を入力します：

```env
# AWSアクセスキーID
AWS_ACCESS_KEY_ID=AKIA**************

# AWSシークレットアクセスキー
AWS_SECRET_ACCESS_KEY=****************************************

# Amazonアソシエイトタグ
ASSOCIATE_TAG=your-associate-tag-20

# リージョン (日本の場合: us-west-2)
AWS_REGION=us-west-2

# PA APIホスト (日本の場合: webservices.amazon.co.jp)
PA_API_HOST=webservices.amazon.co.jp

# マーケットプレイス (日本の場合: www.amazon.co.jp)
MARKETPLACE=www.amazon.co.jp
```

**重要**: `.env` ファイルは `.gitignore` に含まれているため、Gitリポジトリにコミットされません。

### 4. 動作確認

ブラウザでアプリケーションを開き、書籍登録画面で書籍を検索してみてください。

## 機能

### 書籍検索

- 1回の検索で最大10件の書籍を取得
- 「さらに検索」ボタンで追加の10件を取得（最大10ページ、100件まで）
- 以下の情報を取得：
  - タイトル
  - 著者
  - 表紙画像（高解像度）
  - 出版日
  - ページ数
  - ASIN（Amazon固有識別番号）

### Google Books APIからの移行について

以前はGoogle Books APIを使用していましたが、以下の理由でAmazon PA APIに移行しました：

- より高品質な表紙画像
- 日本の書籍に対する充実したデータ
- ページネーション機能（追加検索）
- ASINによる正確な書籍識別

## トラブルシューティング

### エラー: ".envファイルが見つかりません"

→ プロジェクトルートに `.env` ファイルを作成してください。

### エラー: "AWS認証情報が設定されていません"

→ `.env` ファイルに正しいアクセスキー、シークレットキー、アソシエイトタグを設定してください。

### エラー: "Amazon PA APIからエラーが返されました"

→ 以下を確認してください：
- 認証情報が正しいか
- PA APIの利用が有効になっているか
- アソシエイトアカウントが有効か
- リクエストレートリミットに達していないか

### 検索結果が表示されない

→ 以下を確認してください：
- キーワードが正確か
- `.env` ファイルの設定が正しいか
- ブラウザのコンソールにエラーが表示されていないか

## API制限

Amazon PA APIには以下の制限があります：

- **リクエストレート**: 1秒あたり1リクエスト
- **最大検索結果**: 10ページ（100件）まで
- **1ページあたりの件数**: 最大10件

## 参考リンク

- [Amazon PA API ドキュメント](https://webservices.amazon.com/paapi5/documentation/)
- [Amazonアソシエイト](https://affiliate.amazon.co.jp/)
