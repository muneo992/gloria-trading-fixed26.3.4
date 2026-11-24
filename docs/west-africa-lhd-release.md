# West Africa LHD release and recovery

Use the existing production approval and deployment process after the owner
approves the final PR. Production is served from `/home/gltr/www/gloria-site`.
The test deployment targets `/home/gltr/www/gloria-test` and uses its own
private directory. Keep the production and test runtime directories separate.

## Before production deployment

1. Record the release commit and the previous production release. Check the
   production destination and the existing 26-record RHD guard. Confirm PHP
   GD is available in the web PHP runtime.
2. Capture the current site archive with the existing production workflow.
   Keep the archive outside the web root. The archive does not cover the LHD
   private directory.
3. If a production LHD master already exists, run the backup script using
   the currently deployed production code, before replacing it:

   ```sh
   php /home/gltr/www/gloria-site/scripts/backup-west-africa-lhd.php
   ```

   For the first LHD release, confirm and record the absence of a production
   LHD master. An absent master is served as an empty list.
4. Set up the command above as a daily job with the production site's
   account and PHP CLI, after the release is approved. Alert on a nonzero
   exit code. Before an administrative change, the application also creates
   a snapshot of the previous master and its referenced images.
5. Confirm that an approved test mailbox and a team-controlled WhatsApp
   test account have received candidate-specific and general sourcing RFQs.
   Use a separate EmailJS test template and test WhatsApp routing for this
   verification. Keep recipient changes and credentials outside source control.

## Deploy and verify

After approval and merge, use **Deploy main to Sakura production** with
`private_data=deploy` and `confirm=DEPLOY_PRODUCTION`. Record the run URL and
site archive. Check the home page, LHD page, LHD JSON feed, anonymous
management redirect, existing RHD catalog and RFQ entry points. A fresh
release should accept general sourcing requests with zero LHD listings.

## LHD backup and restoration

Snapshots are created under `west-africa/lhd/backups` for production and
`west-africa-test/lhd/backups` for test, beneath the private data root.
Each snapshot contains the master JSON, its referenced JPEGs, and a SHA-256
manifest. Snapshot creation also removes complete snapshots older than 30
days. The existing RHD backup and archive policy is independent. A manual
or scheduled snapshot also covers periods without administrative changes.

To rehearse a recovery, use a new private directory outside the live store.
The Sakura test workflow's `verify_lhd` option performs the rehearsal using
a synthetic record. It verifies the manifest, restores the JSON and JPEG,
checks publication state and expiration, and checks the live master hash
before removing the isolated copy.

To restore live LHD data after a separately approved incident decision:

1. Suspend LHD admin writes and snapshot the current private state if possible.
   Select a backup from the same environment.
2. Restore into an unused private staging directory and verify its manifest,
   the JSON, the named JPEGs, publication state and UTC deadlines. Use
   `gt_lhd_restore_copy` for staging verification.
3. Acquire the live `master.lock`. Place verified JPEGs in the live private
   directory first, using their existing names and owner-only permissions.
   Replace the live JSON by an atomic rename from a temporary file in that
   directory. Keep the previous state until the validation is complete.
4. Verify the public feed and the admin preview. Reconfirm availability for
   any candidates restored with a still-valid deadline before resuming
   publication.

## Code rollback

If the release fails, pause further publishing. After approval, recover
the affected site code and static assets from the exact pre-release archive
or use an approved revert on `main` and the existing production workflow.
Inventory the newly deployed files and revert those paths explicitly if
they must be removed; the normal rsync does not delete unmatched files.
Validate the RHD count and RFQ path, and check that LHD routes and links
match the restored release. Keep private masters, photographs, credentials,
and the East and South Africa trees outside the code rollback.
