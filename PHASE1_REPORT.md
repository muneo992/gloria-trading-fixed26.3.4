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

## ロールバック方法

1. 管理画面での保存を戻す場合は、`frontend/data/backup/` から対象時刻のバックアップを選び、内容を `frontend/data/vehicles.json` に戻します。必要に応じてルートの `vehicles.json` にも同じ内容を反映します。
2. コード変更全体を戻す場合は、このPhase 1のコミットを `git revert` します。

   ```bash
   git revert <Phase 1のコミットSHA>
   ```

3. revert後はPull Requestでレビューし、通常の承認手順を経て反映します。
