# Gloria Trading — GitHub リポジトリの見方

## 正しいブランチ

| ブランチ | 用途 |
|---------|------|
| **`main`** | 最新版（`frontend/` 構成・新管理画面）。**デプロイは必ずここ** |
| `develop` | `main` と同期済みの作業用（旧ルート直下構成は廃止） |

GitHub で **`main` ブランチ** を選んでファイルを確認してください。  
`develop` を見ると古い `index.html`（ルート直下）が表示され、リポジトリが「おかしい」ように見えます。

## テストサイト

- URL: https://gltr.sakura.ne.jp/gloria-test/
- 管理画面: https://gltr.sakura.ne.jp/gloria-test/admin/

## デプロイ（GitHub Actions）

**使う workflow:** `Deploy to Sakura Test Environment`

1. Actions タブを開く
2. 左から **Deploy to Sakura Test Environment** を選択
3. **Run workflow** → Branch は **`main`** → 確認欄に `YES`

使わないもの（混乱の元）:

- `FINAL Deploy to Test` … 旧名称。今後 `deploy-test.yml` に統合予定
- `develop` ブランチ上の古い workflow 名 … `main` をマージ済みなら表示されません

## 管理画面パスワード

サーバー上の `admin/password.txt`（Git には含めない）

## 本番

`Deploy develop to Sakura production` … 名称は legacy ですが、`main` から実行されます。
