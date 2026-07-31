# Gloria Trading 管理画面 Phase 1 完了報告書

## 1. 概要

Phase 1では、管理画面で更新した車両データをデプロイや保存処理によって失わないことを目的に、データ保護と管理画面保存処理の安全化を行った。

対象リポジトリ: `muneo992/gloria-trading-fixed26.3.4`

本番反映日: 2026年7月31日

## 2. 発生していた問題

- 本番デプロイ時の`rsync`と公開用リンク処理が、サーバー上の`frontend/data/vehicles.json`およびルートの`vehicles.json`をリポジトリ内のデータで上書きする可能性があった。
- 管理画面から保存する直前の車両JSONが自動退避されず、誤操作や保存異常からの復旧手段が不足していた。
- 管理画面の全件保存処理で、既存の`resale_markets`が正規化後のデータから失われていた。
- `site_only`保存を選択した状態で見積項目を変更すると、その入力が警告なしで破棄されていた。

## 3. 実施した修正

### PR #2: Protect production vehicle data

- ブランチ: `agent/phase1-protect-vehicle-data`
- Merge SHA: `748515d8a80c32e45f60dde3341ee0a644357b21`

主な変更:

- 本番デプロイから`frontend/data/vehicles.json`と`vehicles.json`を除外。
- 公開用リンク処理からルート`vehicles.json`の同期処理を削除。
- `saveVehicles()`の実行時に、保存前JSONを`frontend/data/backup/vehicles-YYYYMMDD-HHMMSS.json`へ自動退避。
- 同一秒の保存では連番を付け、既存バックアップを上書きしないようにした。
- バックアップ作成失敗時は本体を保存しない。
- バックアップと本体の書き込みに`LOCK_EX`を使用。

### PR #4: Prevent admin save data loss

- ブランチ: `agent/fix-admin-save-data-loss`
- Merge SHA: `de72b63878a41ea2d37897e235bd879ab4e8c31f`

主な変更:

- 入力に存在する既存の`resale_markets`を正規化後も同じ文字列で保持。
- `site_only`選択時に見積項目の変更がある場合、保存を中止して明確なエラーを表示。
- 見積項目に変更がない`site_only`保存、`quote_only`保存、`both`保存の既存構成は維持。
- JSON配置、全件保存方式、ルート`vehicles.json`の役割、画面構成は変更していない。

## 4. 実施したテスト

### 静的・ローカル確認

- `php -l admin/vehicle-data.php`
- `php -l admin/edit.php`
- `git diff --check`
- 同一秒のバックアップ名重複回避。
- バックアップ失敗時に本体を保存しないこと。
- 19件すべての`resale_markets`保持。
- `resale_markets`が存在しないレコードへ固定値を追加しないこと。
- `quote_only`と`both`で見積項目を受信できること。
- 見積変更ありの`site_only`をブロックし、変更なしの場合は保存できること。

### Sakuraテスト環境

- 安全化した手動ワークフローで指定コミットをデプロイ。
- 車両JSONと`frontend/data/backup/`が`rsync`対象外であることを確認。
- デプロイ前バックアップ、PHP構文確認、管理画面診断、主要ページのHTTP応答を確認。
- REF-001で見積メモを保存し、保存前バックアップ作成、見積値の保持、`resale_markets`の保持を確認。
- 異常調査時に使用したデータはバックアップから復元し、公開ページの正常表示を確認。

## 5. 本番反映と確認結果

本番デプロイ:

- Workflow: `Deploy main to Sakura production`
- Run ID: `30617530502`
- URL: https://github.com/muneo992/gloria-trading-fixed26.3.4/actions/runs/30617530502
- Checkout SHA: `de72b63878a41ea2d37897e235bd879ab4e8c31f`
- 実行結果: 成功
- デプロイ前退避: `/home/gltr/www/_backups/gloria-site-before-20260731-084727.tar.gz`

確認結果:

- 本番デプロイ時に両方の車両JSONが転送されていないことを確認。
- トップ、カタログ、Ghana、Côte d’Ivoire、車両詳細、画像および旧URLリダイレクトが正常。
- 本番管理画面が正常に開き、19件の車両を表示。
- REF-001の`quote_memo`に`Production save test`を入力し、`quote_only`で保存成功。
- 再読込後も見積メモが保持されることを確認。
- 見積メモを空欄へ戻して再度`quote_only`保存し、空欄へ復元されたことを確認。
- 2回とも保存成功したため、保存前バックアップ作成が成功していることを確認。
- 最終的にREF-001の見積メモは空欄へ復元済み。
- 車両数19件と全19件の`resale_markets`保持を確認。

## 6. ロールバック

- 管理画面保存を戻す場合は、`frontend/data/backup/`内の対象時刻のファイルを確認し、`frontend/data/vehicles.json`へ復元する。
- 本番デプロイ全体を戻す場合は、`/home/gltr/www/_backups/gloria-site-before-20260731-084727.tar.gz`を使用する。
- コード変更を戻す場合は、対象マージコミットを`git revert`し、別Pull Requestでレビューしてから反映する。
- 復元前には必ず現在ファイルを別名で退避し、JSON構文、件数、SHA-256および公開表示を確認する。

## 7. 現時点で残っている改善項目

以下はPhase 1の対象外とし、現在の安全運用を優先して変更していない。

- 見積書、Commercial Invoice、Packing Listなど帳票機能の整理。
- 管理画面のUI、項目配置、保存モード表示の整理。
- バックアップ一覧、比較、復元を管理画面から行う機能。
- 全件再構築方式から、対象車両だけを更新する方式への見直し。
- テスト・本番ワークフローにおけるPHP構文確認と診断項目の拡充。
- バックアップ保持期間、世代数、監視・通知ルールの策定。

これらを実施する場合は、Phase 1のデータ保護、バックアップ失敗時の保存中止、`LOCK_EX`、車両JSONのデプロイ除外を維持し、個別のPull Requestとテスト環境で検証する。
