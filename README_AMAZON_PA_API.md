# Amazon Creators API 設定ガイド

このプロジェクトでは、書籍検索に Amazon Creators API（旧 Product Advertising API v5 の後継）を使用しています。

## 前提条件

Amazon Creators API を使用するには、以下が必要です：

1. Amazonアソシエイトアカウント
2. Creators API用の Credential ID と Credential Secret
3. アソシエイトタグ（PartnerTag）

## セットアップ手順

### 1. Amazonアソシエイトプログラムにログイン

[Amazonアソシエイト・セントラル](https://affiliate.amazon.co.jp/) にログインします。

### 2. Creators APIの認証情報を取得

1. メニューの「ツール」→「Creators API」（または「Product Advertising API」の移行案内リンク）を開きます。
2. アプリケーションを追加し、**Credential ID** と **Credential Secret** を取得します。
3. ご自身のアソシエイトタグ（例: `xxxx-22`）を確認します。

### 3. 環境変数ファイルの設定

プロジェクトのルートディレクトリに `.env` ファイルを作成（または編集）します：

```bash
cp .env.example .env
```

`.env` ファイルを開いて、取得した情報を入力します：

```env
# Amazon Creators API 認証情報
AMAZON_CREDENTIAL_ID=your-credential-id
AMAZON_CREDENTIAL_SECRET=your-credential-secret
AMAZON_ASSOCIATE_TAG=your-associate-tag-22
AMAZON_MARKETPLACE=www.amazon.co.jp
```

**重要**: `.env` ファイルは `.gitignore` に含まれているため、Gitリポジトリにコミットされません。

### 4. 動作確認

書籍登録画面（`register.html`）を開き、タイトル入力欄横の「検索」ボタンから書籍を検索してみてください。

## 機能

### 書籍検索

- 1回の検索で最大10件の書籍を取得
- 「さらに検索」ボタンで追加の10件を取得（最大10ページ、100件まで）
- 以下の情報を取得：
  - タイトル
  - 著者
  - 表紙画像（高解像度）
  - 出版日
  - ページ数 / 出版社 / ISBN
  - ASIN（Amazon固有識別番号）

## トラブルシューティング

### エラー: "Amazon認証トークンの取得に失敗しました: Client authentication failed"
→ `.env` ファイルの `AMAZON_CREDENTIAL_ID` または `AMAZON_CREDENTIAL_SECRET` が正しく設定されているか確認してください。旧PA-API用のAWSアクセスキー（AKIA...等）は使用できません。

### エラー: ".envファイルが見つかりません"
→ プロジェクトルートに `.env` ファイルを作成してください。

### エラー: "Amazon Creators APIの認証情報が設定されていません"
→ `.env` に `AMAZON_CREDENTIAL_ID`, `AMAZON_CREDENTIAL_SECRET`, `AMAZON_ASSOCIATE_TAG` を設定してください。

## ログ確認

APIエラーが発生した場合、`logs/api.log` に詳細な通信エラー内容が記録されます（最大10MBで自動ローテーション）。
