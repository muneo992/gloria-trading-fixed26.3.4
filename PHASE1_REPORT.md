# Phase 1 実装レポート

## 変更内容

- `.github/workflows/deploy-production.yml`
  - 本番への `rsync` から `frontend/data/vehicles.json` とルートの `vehicles.json` を除外しました。
  - デプロイ後に `frontend/data/vehicles.json` をルートの `vehicles.json` へコピーしていた処理を削除しました。
- `scripts/sakura-publish-links.sh`
  - ワークフローの後半で実行される公開用リンク処理から、ルートの `vehicles.json` を上書きする同期処理を削除しました。
- `admin/vehicle-data.php`
  - `saveVehicles()` が既存の `vehicles.json` を `frontend/data/backup/vehicles-YYYYMMDD-HHMMSS.json` にバックアップしてから保存するようにしました。
  - 同じ秒に複数回保存された場合は、2件目以降を `vehicles-YYYYMMDD-HHMMSS-1.json`、`-2.json` のような連番にし、既存バックアップを上書きしません。
  - バックアップディレクトリの作成、既存JSONの読み込み、バックアップの書き込みのいずれかに失敗した場合は、本体の保存を中止して `false` を返します。
  - バックアップファイルと本体ファイルの書き込みに `LOCK_EX` を使用します。

## 変更理由

管理画面で更新された車両データは本番サーバー上のファイルに保存されます。従来のデプロイはリポジトリ内のJSONで本番ファイルを上書きし、さらにルートの互換用JSONもコピーで上書きしていました。両ファイルをデプロイ対象から除外することで、管理画面による更新を維持します。また、管理画面から保存する直前に現行データを退避し、誤操作や書き込み内容の問題から復旧できるようにします。

## テスト方法

1. PHP構文チェックを実行します。

   ```bash
   php -l admin/vehicle-data.php
   ```

2. テスト用ディレクトリで `VEHICLES_JSON` を一時ファイルに向け、既存JSONを用意して `saveVehicles()` を実行します。
3. `frontend/data/backup/vehicles-YYYYMMDD-HHMMSS.json` に相当するバックアップが作成され、内容が保存前のJSONと完全に一致することを確認します。
4. 保存後の本体JSONが正常なJSONであり、従来の `vehicles` 配列形式を維持することを確認します。
5. バックアップ先を作成できない状態にして `saveVehicles()` を実行し、`false` が返り、本体JSONが変更されないことを確認します。
6. ワークフローの `rsync` オプションに両JSONの除外指定があり、ワークフローと `scripts/sakura-publish-links.sh` のどちらにもルートJSONへのコピー処理が残っていないことを確認します。

> 本番反映前にPHP構文確認とテスト環境での保存確認が必須。

### 実施結果

- PHP 8.4.24で `php -l admin/vehicle-data.php` を実行し、構文エラーがないことを確認しました。
- 一時テストデータを使用し、同じ時刻名のバックアップが既に存在する場合に既存ファイルを変更せず、`-1`付きのバックアップへ保存前JSONを退避してから本体を保存できることを確認しました。
- 本番サーバー固有の権限・ファイルシステムを含む保存確認は、本番反映前にテスト環境で別途実施してください。

## テスト環境用ワークフローの安全化

`.github/workflows/deploy-test.yml` を、指定したブランチ・タグ・コミットSHAをさくらのテスト環境へ安全に反映できる手動ワークフローへ変更しました。

- `confirm` が `DEPLOY_TEST` と完全一致しない場合は処理を中止します。
- `deploy_ref` でブランチ名、タグ名、またはコミットSHAを必須指定し、その値をCheckoutに使用します。
- 指定したrefの解決後SHAと実際のCheckout HEAD SHAをログへ出力し、不一致の場合は処理を中止します。
- `SAKURA_TEST_PATH` が `/home/gltr/www/gloria-test` と完全一致しない場合は処理を中止します。
- `frontend/data/vehicles.json`、ルートの `vehicles.json`、`frontend/data/backup/` を `rsync` から除外します。
- デプロイ前に、存在する `frontend/data/vehicles.json`、ルートの `vehicles.json`、`admin/vehicle-data.php` を `/home/gltr/www/_backups/gloria-test-before-YYYYMMDD-HHMMSS/` へ退避し、コピー結果が一致しない場合は処理を中止します。
- SSH接続は `ssh-keyscan` で作成した `known_hosts` と `StrictHostKeyChecking=yes` を使用します。
- デプロイ後に `admin/vehicle-data.php` のPHP構文、管理画面診断、主要ページのHTTP応答を確認します。

### テスト環境への実行手順

1. GitHub Actionsで `Deploy selected ref to Sakura Test (gloria-test)` を選び、`Run workflow`を開きます。
2. ワークフローを実行するブランチとして、ワークフローファイルを含む対象ブランチを選びます。
3. `confirm` に `DEPLOY_TEST` を入力します。
4. `deploy_ref` に反映対象のブランチ名、タグ名、または完全なコミットSHAを入力します。Phase 1確認時は、レビュー済みの最新コミットSHAを指定します。
5. 実行ログで `inputs.deploy_ref`、`resolved deploy_ref SHA`、`checked out HEAD SHA` が意図したコミットを示し、バックアップ、PHP構文確認、管理画面診断、主要ページ確認がすべて成功したことを確認します。

テスト環境へ反映する前に、GitHub Secret `SAKURA_TEST_PATH` が `/home/gltr/www/gloria-test` に設定されていることを確認してください。ワークフローは手動実行専用であり、この変更をコミットまたはPull Requestへ追加しても自動デプロイされません。

## ロールバック方法

1. 管理画面での保存を戻す場合は、`frontend/data/backup/` から対象時刻のバックアップを選び、内容を `frontend/data/vehicles.json` に戻します。必要に応じてルートの `vehicles.json` にも同じ内容を反映します。
2. コード変更全体を戻す場合は、このPhase 1のコミットを `git revert` します。

   ```bash
   git revert <Phase 1のコミットSHA>
   ```

3. revert後はPull Requestでレビューし、通常の承認手順を経て反映します。
