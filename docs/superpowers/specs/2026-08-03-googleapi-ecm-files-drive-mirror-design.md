# Mirror Dolibarr attached files to Google Drive on upload

Date: 2026-08-03
Status: Approved by user, implementation plan pending.

## Context

The `googleapi` custom module (`htdocs/custom/googleapi`) already has:

- Per-user Google OAuth (`getGoogleApiClient($user)` in `lib/googleapi.lib.php`),
  reused throughout the module.
- A "Google Drive" ECM tab (`ecmgoogledrive.php` and friends) that lets a user
  browse/upload/download/rename/trash files in their own connected Drive,
  including a resumable, chunked upload implementation
  (`\Google\Http\MediaFileUpload`) built for that tab's manual upload form.
- A trigger class, `core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`
  (`InterfaceGoogleApiTriggers`), already registered and already handling
  several triggers (`ACTION_CREATE`, `CONTACT_CREATE`, ...) with an
  established idiom: call `getGoogleApiClient($user)`, bail out silently if
  it returns `false` (user not connected), otherwise call the Google API and
  write the result back into `$object->array_options[...]` followed by
  `$object->update($user, 1)` (the `1` suppresses the `_MODIFY` trigger to
  avoid recursion).
- A hook class, `class/actions_googleapi.class.php` (`ActionsGoogleApi`),
  registered for several hook contexts including `fileslib`, with two
  existing stub methods `moveUploadedFile()` and `deleteFile()` that
  currently do nothing (a debug-only event message behind
  `GOOGLEAPI_ENABLE_DEVELOPPER_MODE`).
- An extrafield already created on the `ecm_files` element:
  `googleapiId` (varchar 180, label `GoogleApiIdId`).
- An established idiom for a self-registering, per-key JSON toggle setting:
  `ActionsGoogleApi::sendMail()` reads `GOOGLEAPI_CONTEXTS_TO_SEND` as a JSON
  object, adds any newly-seen key with a `false` default via
  `dolibarr_set_const(...)`, and gates behavior on whether the value for the
  current key is truthy. `admin/setup.php` renders that same map as a list
  of on/off toggles.

## Goal

Whenever a file is uploaded onto any Dolibarr object's "linked files" area
(invoices, third parties, contracts, tickets, ...), also upload a copy to
the connected user's own Google Drive, inside a folder structure that
mirrors Dolibarr's own document folder structure, and record the resulting
Drive file id in the `ecm_files` extrafield `googleapiId`.

## Why `moveUploadedFile` is the wrong hook, and what to use instead

The stub in `ActionsGoogleApi::moveUploadedFile()` looks like the natural
place for this, but it fires too early: `dol_add_file_process()` calls
`dol_move_uploaded_file()` (which fires the `moveUploadedFile` hook) *before*
it calls `addFileIntoDatabaseIndex()`, which is what actually creates the
`llx_ecm_files` row. At `moveUploadedFile` time there is no `EcmFiles`
object yet to attach the Drive id to.

`EcmFiles::create()` (`htdocs/ecm/class/ecmfiles.class.php:388`) calls
`$this->call_trigger('ECMFILES_CREATE', $user)` right after the row is
inserted — the object passed to that trigger has `filepath`, `filename`,
`src_object_type`, `src_object_id`, `id`, all correctly populated, and
extrafield writes made in the trigger and followed by
`$object->update($user, 1)` land correctly (`EcmFiles::update()` already
calls `$this->insertExtraFields()`).

This spec adds a new `ecmfilesCreate($action, EcmFiles $object, User $user, Translate $langs, Conf $conf)`
method to the existing `InterfaceGoogleApiTriggers` class — the trigger
dispatcher there maps `ECMFILES_CREATE` to that exact camelCase method name
already (`runTrigger()`'s existing generic dispatch logic, unchanged).

The existing `moveUploadedFile`/`deleteFile` hook stubs are left untouched.

## Functional scope

- Fires for every new row in `llx_ecm_files` (both user uploads and
  Dolibarr-generated documents such as invoice PDFs — Dolibarr does not
  distinguish these at the hook/trigger level in a way that's worth
  special-casing here; scope is controlled by the per-object-type toggle
  below instead).
- Gated by a new setting, `GOOGLEAPI_DRIVE_SYNC_OBJECTS`: a JSON object
  mapping `src_object_type` (e.g. `facture`, `societe`, `contrat`,
  `expedition`, ...) to a boolean. The first time a given `src_object_type`
  is seen, it is added to the map with a `false` default and persisted via
  `dolibarr_set_const(...)` — the exact idiom `ActionsGoogleApi::sendMail()`
  already uses for `GOOGLEAPI_CONTEXTS_TO_SEND`. Nothing is synced for a
  given object type until an admin explicitly flips it on in Setup.
- `admin/setup.php` gets a new block listing every key currently in
  `GOOGLEAPI_DRIVE_SYNC_OBJECTS` with an on/off action link each, styled
  like the existing `GOOGLEAPI_CONTEXTS_TO_SEND` block in the same file
  (same table layout, same `contextenable_xxx`/`contextdisable_xxx` action
  naming convention, parameterized on the setting name so the existing code
  isn't duplicated blindly — see Architecture).
- A second new setting, `GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER` (plain text,
  default `Dolibarr`): the name of the top-level Drive folder under which
  the mirrored structure is created. Editable from the same new setup
  block.
- Folder mirroring: `$object->filepath` (already relative to `DOL_DATA_ROOT`,
  e.g. `facture/FA2401-0001`) is split on `/`; each segment becomes a nested
  Drive folder under the root folder above, resolved by name+parent and
  created if missing.
- The file itself is uploaded to the resolved deepest folder using the same
  resumable, chunked upload approach already built for the manual "Google
  Drive" tab (extracted into a shared helper, see Architecture), reading
  the physical file directly from
  `DOL_DATA_ROOT.'/'.$object->filepath.'/'.$object->filename`.
- On success: `$object->array_options['options_googleapiId']` is set to the
  new Drive file id and `$object->update($user, 1)` persists it.
- Whose Drive: the user who triggered the upload (`$user`, provided
  directly by the trigger signature) — consistent with the rest of this
  module's per-user OAuth design. There is no shared/company Drive account.

## Out of scope for this iteration

- No retroactive sync of files that already exist in `llx_ecm_files` before
  this feature ships — only newly created rows going forward.
- No mirroring on rename or delete (the existing `deleteFile` hook stub is
  untouched; a locally deleted or renamed file's Drive copy is not touched
  either). This may be revisited later as a separate spec.
- No de-duplication check against a possibly-already-uploaded copy (e.g. if
  the same physical file path were re-indexed) — every `ECMFILES_CREATE`
  firing that passes the object-type gate performs a fresh upload.

## Architecture

### 1. Shared helpers in `lib/googleapi.lib.php`

Three new functions, extracted/generalized so both the manual "Google
Drive" tab upload action and this new trigger use the same code (today the
chunked-upload logic lives only inline in `ecmgoogledrive.php`'s Actions
section):

- `googleapiUploadFileToDrive(\Google\Client $client, string $localpath, string $drivefilename, string $parentfolderid, string $mimetype): string|false`
  — performs the resumable, chunked upload (the same `setDefer(true)` +
  `MediaFileUpload` + `fread()` loop already in `ecmgoogledrive.php`, moved
  here almost verbatim) reading from a **local absolute path** instead of a
  `$_FILES` tmp file, and returns the new Drive file id on success or
  `false` on failure (catching exceptions internally and logging via
  `dol_syslog`, matching this module's established error-handling style —
  callers decide what to tell the user).
- `googleapiGetOrCreateDriveFolder(\Google\Service\Drive $driveservice, string $name, string $parentid): string|false`
  — looks up a folder by exact `name` under `parentid` via `files->listFiles()`
  with `q = "'<parentid>' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false and name='<escaped name>'"`,
  returns its id if found; otherwise creates it (`files->create()` with
  `mimeType = 'application/vnd.google-apps.folder'` and that parent) and
  returns the new id. Returns `false` only if both the lookup and the
  creation raise an exception.
- `googleapiResolveDriveFolderPath(\Google\Service\Drive $driveservice, string $rootfoldername, string $relativepath): string|false`
  — resolves `'root'` → `$rootfoldername` (via `googleapiGetOrCreateDriveFolder`),
  then walks every non-empty segment of `$relativepath` (split on `/`,
  discarding empty segments produced by a leading/trailing/doubled `/` —
  e.g. `explode('/', trim($relativepath, '/'))` filtered through
  `array_filter`), calling `googleapiGetOrCreateDriveFolder` once per
  segment with the previous result as parent. If `$relativepath` has zero
  non-empty segments (file attached with no sub-folder), the root folder id
  itself is returned. Returns `false` if any step fails.

`ecmgoogledrive.php`'s upload action is refactored to call
`googleapiUploadFileToDrive()` instead of inlining the chunk loop, passing
`$_FILES['userfile']['tmp_name']` as the local path — this removes
duplicate logic without changing that feature's behavior.

### 2. New trigger method

In `core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`:

```php
public function ecmfilesCreate($action, EcmFiles $object, User $user, Translate $langs, Conf $conf)
{
    if (empty($object->src_object_type)) {
        return 0;
    }

    $syncobjects = json_decode(getDolGlobalString('GOOGLEAPI_DRIVE_SYNC_OBJECTS', '{}'), true);
    if (!is_array($syncobjects)) {
        $syncobjects = array();
    }
    if (!array_key_exists($object->src_object_type, $syncobjects)) {
        $syncobjects[$object->src_object_type] = false;
        dolibarr_set_const($this->db, 'GOOGLEAPI_DRIVE_SYNC_OBJECTS', json_encode($syncobjects), 'chaine', 0, '', $conf->entity);
    }
    if (empty($syncobjects[$object->src_object_type])) {
        return 0;
    }

    $client = getGoogleApiClient($user);
    if ($client === false) {
        return 0; // user not connected: silent no-op, per spec
    }

    $localpath = DOL_DATA_ROOT.'/'.$object->filepath.'/'.$object->filename;
    if (!dol_is_file($localpath)) {
        return 0;
    }

    $rootfoldername = getDolGlobalString('GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER', 'Dolibarr');

    try {
        $driveservice = new \Google\Service\Drive($client);
        $parentfolderid = googleapiResolveDriveFolderPath($driveservice, $rootfoldername, $object->filepath);
        if ($parentfolderid === false) {
            throw new Exception('Could not resolve/create the Drive folder path');
        }

        $mimetype = dol_mimetype($object->filename, 'application/octet-stream', 0);
        $driveid = googleapiUploadFileToDrive($client, $localpath, $object->filename, $parentfolderid, $mimetype);
        if ($driveid === false) {
            throw new Exception('Drive upload failed');
        }

        $object->array_options['options_googleapiId'] = $driveid;
        $object->update($user, 1);
    } catch (Exception $e) {
        dol_syslog('ecmfilesCreate: '.$e->getMessage(), LOG_ERR);
        $langs->load('googleapi@googleapi');
        setEventMessages($langs->trans('GoogleApiDriveSyncFailed', $object->filename, $e->getMessage()), null, 'warnings');
    }

    return 0;
}
```

(Module-enabled check and the existing early-return guards already present
in `runTrigger()` — e.g. `empty($conf->googleapi->enabled)` — apply before
this method is even called, so `ecmfilesCreate()` doesn't repeat them.)

### 3. Admin setup UI

`admin/setup.php` gains one new block, modeled on the existing
`GOOGLEAPI_CONTEXTS_TO_SEND` block already in that file: a text input for
`GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER` (default `Dolibarr` if unset) and a table
listing every key currently in `GOOGLEAPI_DRIVE_SYNC_OBJECTS` with an
enable/disable action link per row (same `action=drivesyncenable_xxx` /
`drivesyncdisable_xxx` naming pattern as the existing block's
`contextenable_xxx`/`contextdisable_xxx`, just prefixed differently to stay
unambiguous — the two maps are unrelated).

### 4. Language keys

New keys in `langs/{en_US,fr_FR}/googleapi.lang`:
`GoogleApiDriveSyncTitle` (setup block title), `GoogleApiDriveSyncRootFolder`
(setting label), `GoogleApiDriveSyncObjects` (table title),
`GoogleApiDriveSyncEnabledObject` (`%s` placeholder, one row's label,
mirroring `GoogleApiEnabledContext`), `GoogleApiDriveSyncFailed` (warning
message shown on API failure, two `%s` placeholders: filename and error
detail).

## Error handling

- User not connected to Google Drive: silent no-op (no message, no log
  beyond what `getGoogleApiClient()` itself already logs internally).
- Object type not enabled in `GOOGLEAPI_DRIVE_SYNC_OBJECTS`: silent no-op.
- Physical file missing at the expected path (race condition, unusual
  storage layout): silent no-op (nothing sensible to upload).
- Any Drive API exception (folder resolution or file upload): the primary
  Dolibarr file upload is **never** affected (the trigger runs after the
  `ecm_files` row already exists and the physical file is already saved) —
  a `warnings`-level `setEventMessages()` is shown to the user with the
  filename and error detail, and the error is logged via `dol_syslog`.

## Security

- No new permission created: this is a passive, automatic side effect of
  an upload the user is already authorized to perform (existing Dolibarr
  object-level permissions already gate the primary upload).
- Drive folder/file names built from `$object->filepath`/`$object->filename`
  are Dolibarr-controlled path segments (already sanitized by
  `dol_sanitizeFileName()` when the file was first uploaded), not raw
  attacker input — no additional escaping beyond the existing Drive `q=`
  query escaping (`googleapiDriveEscapeId()`-style single-quote escaping,
  reused inside `googleapiGetOrCreateDriveFolder()`'s query construction).
- No local disk writes beyond what Dolibarr's own upload already did (the
  Drive upload reads the already-saved local file, never creates a new
  temp copy).

## Testing

No automated tests (same constraint as the rest of this module: no test
double for the real Google API, no PHPUnit coverage of `custom/` modules).
Manual QA once implemented, against a real Dolibarr instance with a real
connected Google account:

1. Enable a specific object type (e.g. `societe`) in the new setup block,
   leave others off.
2. Upload a file to a third party's "Linked files" tab. Confirm: the local
   Dolibarr upload succeeds as always; a matching folder path appears under
   the `Dolibarr` (or configured) root folder on Google Drive; the file is
   present there with the same name and content; the `ecm_files` row for
   that file has its `googleapiId` extrafield populated.
3. Upload a file to an object type that is *not* enabled (e.g. `facture`
   while only `societe` is on). Confirm nothing is sent to Drive and no
   extrafield is set.
4. Disconnect the test user's Google account (or test as a user who never
   connected one) and upload a file to an enabled object type. Confirm the
   Dolibarr upload still succeeds, silently, with no Drive copy and no
   error message.
5. Force a failure (e.g. temporarily revoke Drive API access or use an
   invalid folder root) and confirm a warning message appears without
   blocking the upload, and the detail is in the PHP/Dolibarr log.
6. Re-verify the existing manual "Google Drive" ECM tab upload still works
   after the `googleapiUploadFileToDrive()` extraction (regression check).
