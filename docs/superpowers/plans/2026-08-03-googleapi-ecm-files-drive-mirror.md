# Mirror Attached Files to Google Drive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Whenever a new file is indexed into `llx_ecm_files` (uploaded or generated, on any Dolibarr object), also upload a copy to the connected user's own Google Drive, in a folder structure mirroring Dolibarr's own document path, and record the Drive file id in the `ecm_files` extrafield `googleapiId` — gated per Dolibarr object type by a self-registering admin toggle.

**Architecture:** A new `ecmfilesCreate()` method on the module's existing `InterfaceGoogleApiTriggers` trigger class handles the already-firing `ECMFILES_CREATE` trigger (fired by `EcmFiles::create()` right after the row — and the physical file — already exist). It resolves/creates a mirrored Drive folder path under a configurable root folder, uploads the file with a resumable chunked upload, and writes the resulting Drive id back onto the extrafield. The chunked-upload logic already built for the manual "Google Drive" ECM tab is extracted into a shared `lib/googleapi.lib.php` helper so both features use the same code.

**Tech Stack:** PHP 8.2+ (repo dev container runs PHP 8.5), Dolibarr module/trigger framework, `google/apiclient` (Drive service + `\Google\Http\MediaFileUpload`) already vendored via the sibling `prune` module.

## Global Constraints

- Indentation: tabs, not spaces (PSR-12 otherwise) — repo-wide PHP style rule, matches this module's existing files.
- Whose Google Drive: the user who triggered the file creation (`$user`, provided directly by the trigger signature) — no shared/company account.
- Not connected to Google Drive: silent no-op, no message, no log beyond what `getGoogleApiClient()` already does internally.
- Connected but the Drive API call fails: the primary Dolibarr file save is never affected (the trigger runs after both the physical file and the `ecm_files` row already exist) — show a `warnings`-level `setEventMessages()` with the filename and error detail, and log via `dol_syslog`.
- Sync is gated per `src_object_type` by `GOOGLEAPI_DRIVE_SYNC_OBJECTS` (JSON map, entity-scoped via `dolibarr_set_const(..., $conf->entity)`). A newly-seen `src_object_type` is added to the map with `false` and persisted — nothing syncs for a type until an admin flips it on. This is the exact idiom already used for `GOOGLEAPI_CONTEXTS_TO_SEND` in `class/actions_googleapi.class.php::sendMail()` and rendered in `admin/setup.php` — follow it, don't invent a new one.
- Root Drive folder name: `GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER` setting, default `Dolibarr` if unset.
- `EcmFiles::$table_element` is `'ecm_files'` (already fixed on the extrafield registration in `core/modules/modGoogleApi.class.php`, commit `d623851` — do not touch that again, just confirming the extrafield element key already matches so `array_options['options_googleapiId']` actually persists).
- No new SQL table, no dependency on the unrelated `gcloud` custom module.
- No automated tests are possible for the Drive-facing logic (no test double for the Google API in this project). Each task's "test" step is a PHP lint check (`php -l`); the final task is a manual QA pass against a live account.
- Commit message format for this repo: short lowercase summary (see `git log` in this repo).
- Repository: `htdocs/custom/googleapi` is its own git repository, currently on branch `dev`. All commits happen there.

Reference design doc: `docs/superpowers/specs/2026-08-03-googleapi-ecm-files-drive-mirror-design.md` (this same repo, moved there from the outer Dolibarr checkout which excludes `docs/superpowers/` from its own tracked history).

All file paths below are relative to `htdocs/custom/googleapi/` (this repo's root) unless stated otherwise.

---

### Task 1: Drive folder resolution helpers

**Files:**
- Modify: `lib/googleapi.lib.php` (insert after `googleapiDriveEscapeId()`, currently ending around line 228, before the `/** * Create agenda event from task` doc comment)

**Interfaces:**
- Consumes: `googleapiDriveEscapeId($id): string` (existing function, same file).
- Produces: `googleapiGetOrCreateDriveFolder($driveservice, $name, $parentid): string|false` and `googleapiResolveDriveFolderPath($driveservice, $rootfoldername, $relativepath): string|false` — both consumed by Task 4 (the trigger).

- [ ] **Step 1: Add the two functions**

Insert immediately after the existing `googleapiDriveEscapeId()` function:

```php
/**
 * Find a Drive folder by exact name under a given parent, creating it if it does not exist
 *
 * @param \Google\Service\Drive $driveservice Drive service for the current user
 * @param string $name Folder name to find or create
 * @param string $parentid Drive id of the parent folder ('root' for the Drive root)
 * @return string|false Drive folder id, or false on API failure
 */
function googleapiGetOrCreateDriveFolder($driveservice, $name, $parentid)
{
	try {
		$query = "'".googleapiDriveEscapeId($parentid)."' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false and name='".googleapiDriveEscapeId($name)."'";
		$result = $driveservice->files->listFiles(array(
			'q' => $query,
			'fields' => 'files(id)',
			'pageSize' => 1,
		));
		$existing = $result->getFiles();
		if (!empty($existing)) {
			return $existing[0]->getId();
		}

		$folder = new \Google\Service\Drive\DriveFile();
		$folder->setName($name);
		$folder->setMimeType('application/vnd.google-apps.folder');
		$folder->setParents(array($parentid));
		$created = $driveservice->files->create($folder, array('fields' => 'id'));
		return $created->getId();
	} catch (Exception $e) {
		dol_syslog('googleapiGetOrCreateDriveFolder: '.$e->getMessage(), LOG_ERR);
		return false;
	}
}

/**
 * Resolve (creating as needed) the Drive folder matching a Dolibarr relative document path,
 * nested under a fixed top-level root folder
 *
 * @param \Google\Service\Drive $driveservice Drive service for the current user
 * @param string $rootfoldername Name of the top-level Drive folder (e.g. 'Dolibarr')
 * @param string $relativepath Path relative to DOL_DATA_ROOT (e.g. "facture/FA2401-0001")
 * @return string|false Drive id of the deepest folder, or false on API failure
 */
function googleapiResolveDriveFolderPath($driveservice, $rootfoldername, $relativepath)
{
	$parentid = googleapiGetOrCreateDriveFolder($driveservice, $rootfoldername, 'root');
	if ($parentid === false) {
		return false;
	}

	$segments = array_filter(explode('/', trim((string) $relativepath, '/')), function ($segment) {
		return $segment !== '';
	});

	foreach ($segments as $segment) {
		$parentid = googleapiGetOrCreateDriveFolder($driveservice, $segment, $parentid);
		if ($parentid === false) {
			return false;
		}
	}

	return $parentid;
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l lib/googleapi.lib.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/googleapi.lib.php
git commit -m "add Drive folder resolution/creation helpers"
```

---

### Task 2: Chunked upload helper

**Files:**
- Modify: `lib/googleapi.lib.php` (insert right after the two functions added in Task 1)

**Interfaces:**
- Consumes: nothing from Task 1 directly (independent helper), but lives in the same file.
- Produces: `googleapiUploadFileToDrive($client, $localpath, $drivefilename, $parentfolderid, $mimetype): string|false` — consumed by Task 3 (refactor) and Task 4 (the trigger).

- [ ] **Step 1: Add the function**

```php
/**
 * Upload a local file to Google Drive using a resumable, chunked upload (never loads the
 * whole file into memory at once)
 *
 * @param \Google\Client $client Authenticated Google client (as returned by getGoogleApiClient())
 * @param string $localpath Absolute path of the local file to upload
 * @param string $drivefilename Name to give the file on Drive
 * @param string $parentfolderid Drive id of the destination folder
 * @param string $mimetype Mime type to set on the Drive file
 * @return string|false Drive file id, or false on failure
 */
function googleapiUploadFileToDrive($client, $localpath, $drivefilename, $parentfolderid, $mimetype)
{
	$handle = null;
	try {
		$filesize = (int) dol_filesize($localpath);

		$drivefile = new \Google\Service\Drive\DriveFile();
		$drivefile->setName($drivefilename);
		$drivefile->setParents(array($parentfolderid));

		// 1 MB chunks: avoids loading the whole file in memory at once, matching the same
		// approach already used by the manual "Google Drive" ECM tab upload.
		$chunksizebytes = 1 * 1024 * 1024;

		$client->setDefer(true);
		$uploadservice = new \Google\Service\Drive($client);
		$request = $uploadservice->files->create($drivefile, array('mimeType' => $mimetype));
		$media = new \Google\Http\MediaFileUpload($client, $request, $mimetype, null, true, $chunksizebytes);
		$media->setFileSize($filesize);

		$handle = fopen($localpath, 'rb');
		$status = false;
		while ($status === false && !feof($handle)) {
			$chunk = fread($handle, $chunksizebytes);
			$status = $media->nextChunk($chunk);
		}

		return $status->getId();
	} catch (Exception $e) {
		dol_syslog('googleapiUploadFileToDrive: '.$e->getMessage(), LOG_ERR);
		return false;
	} finally {
		if ($handle) {
			fclose($handle);
		}
		$client->setDefer(false);
	}
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l lib/googleapi.lib.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add lib/googleapi.lib.php
git commit -m "add shared chunked Drive upload helper"
```

---

### Task 3: Refactor the manual upload action to use the shared helper

**Files:**
- Modify: `ecmgoogledrive.php:54-100` (the `upload` action block)

**Interfaces:**
- Consumes: `googleapiUploadFileToDrive($client, $localpath, $drivefilename, $parentfolderid, $mimetype): string|false` (Task 2).
- Produces: nothing new — this task removes duplicate inline logic without changing behavior (pure refactor, regression risk is the point of its test).

- [ ] **Step 1: Replace the inline chunked-upload block with a call to the shared helper**

Replace:

```php
if ($action == 'upload' && $permissiontowrite) {
	if (!empty($_FILES['userfile']['tmp_name']) && is_uploaded_file($_FILES['userfile']['tmp_name'])) {
		$parentid = GETPOST('folderid', 'alpha') ? GETPOST('folderid', 'alpha') : 'root';
		$client = getGoogleApiClient($user);
		if (is_object($client)) {
			$handle = null;
			try {
				$uploadedfilename = dol_sanitizeFileName($_FILES['userfile']['name']);
				$mimetype = dol_mimetype($uploadedfilename, 'application/octet-stream', 0);
				$filesize = (int) $_FILES['userfile']['size'];

				$drivefile = new \Google\Service\Drive\DriveFile();
				$drivefile->setName($uploadedfilename);
				$drivefile->setParents(array($parentid));

				// Upload in fixed-size chunks read straight from the PHP upload tempfile instead of
				// loading the whole file into one string (file_get_contents() would not scale to
				// large Drive files and risks hitting memory_limit).
				$chunksizebytes = 1 * 1024 * 1024;

				$client->setDefer(true);
				$uploadservice = new \Google\Service\Drive($client);
				$request = $uploadservice->files->create($drivefile, array('mimeType' => $mimetype));
				$media = new \Google\Http\MediaFileUpload($client, $request, $mimetype, null, true, $chunksizebytes);
				$media->setFileSize($filesize);

				$handle = fopen($_FILES['userfile']['tmp_name'], 'rb');
				$status = false;
				while ($status === false && !feof($handle)) {
					$chunk = fread($handle, $chunksizebytes);
					$status = $media->nextChunk($chunk);
				}

				setEventMessages($langs->trans("GoogleApiDriveFileUploaded"), null, 'mesgs');
			} catch (Exception $e) {
				setEventMessages($langs->trans("GoogleApiErrorDriveApi", $e->getMessage()), null, 'errors');
			} finally {
				if ($handle) {
					fclose($handle);
				}
				$client->setDefer(false);
			}
		}
	} else {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("File")), null, 'errors');
	}
}
```

with:

```php
if ($action == 'upload' && $permissiontowrite) {
	if (!empty($_FILES['userfile']['tmp_name']) && is_uploaded_file($_FILES['userfile']['tmp_name'])) {
		$parentid = GETPOST('folderid', 'alpha') ? GETPOST('folderid', 'alpha') : 'root';
		$client = getGoogleApiClient($user);
		if (is_object($client)) {
			$uploadedfilename = dol_sanitizeFileName($_FILES['userfile']['name']);
			$mimetype = dol_mimetype($uploadedfilename, 'application/octet-stream', 0);
			$driveid = googleapiUploadFileToDrive($client, $_FILES['userfile']['tmp_name'], $uploadedfilename, $parentid, $mimetype);
			if ($driveid !== false) {
				setEventMessages($langs->trans("GoogleApiDriveFileUploaded"), null, 'mesgs');
			} else {
				setEventMessages($langs->trans("GoogleApiErrorDriveApi", 'upload failed'), null, 'errors');
			}
		}
	} else {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("File")), null, 'errors');
	}
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l ecmgoogledrive.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual regression check**

This is a pure refactor of already-shipped, already-QA'd behavior. Log in to the Dolibarr instance, open the ECM area's "Google Drive" tab (`custom/googleapi/ecmgoogledrive.php`), upload a small test file via the on-page upload form, confirm: the success message appears, the file shows up in the current folder's listing, and its content is intact (e.g. download it back and compare). Then delete the test file via the tab's own delete (trash) action to clean up.

- [ ] **Step 4: Commit**

```bash
git add ecmgoogledrive.php
git commit -m "reuse the shared chunked upload helper in the manual Drive tab"
```

---

### Task 4: `ECMFILES_CREATE` trigger — mirror the file to Drive

**Files:**
- Modify: `core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php` (add a new method; add one `require_once` near the top)
- Modify: `langs/en_US/googleapi.lang`
- Modify: `langs/fr_FR/googleapi.lang`

**Interfaces:**
- Consumes: `getGoogleApiClient($user): \Google_Client|false` (existing, same module), `googleapiResolveDriveFolderPath($driveservice, $rootfoldername, $relativepath): string|false` (Task 1), `googleapiUploadFileToDrive($client, $localpath, $drivefilename, $parentfolderid, $mimetype): string|false` (Task 2).
- Produces: the `GOOGLEAPI_DRIVE_SYNC_OBJECTS` and `GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER` global settings (read via `getDolGlobalString()`), consumed for display/editing by Task 5's admin UI. Lang keys `GoogleApiDriveSyncFailed` (used here) — Task 5 adds the remaining setup-page lang keys separately.

- [ ] **Step 1: Add `require_once` for `dol_filesize()`/`dol_is_file()`**

In `core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`, replace:

```php
require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
dol_include_once('/googleapi/lib/googleapi.lib.php');
```

with:

```php
require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
dol_include_once('/googleapi/lib/googleapi.lib.php');
```

- [ ] **Step 2: Add the `ecmfilesCreate()` method**

Add this method to the `InterfaceGoogleApiTriggers` class, right after the `contactDelete()` method and before the `fichinterSentbymail()` method (matching the file's existing per-object grouping: agenda triggers, then contact triggers, then this new file trigger, then the `*Sentbymail` triggers):

```php
	/**
	 * Trigger ECMFILES_CREATE
	 *
	 * @param string        $action     Event action code
	 * @param EcmFiles      $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
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
			return 0;
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
				throw new Exception('Could not resolve/create the Drive folder path for '.$object->filepath);
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

- [ ] **Step 3: Add the `GoogleApiDriveSyncFailed` language key**

Append to `langs/en_US/googleapi.lang`:

```
GoogleApiDriveSyncFailed=Could not copy "%s" to Google Drive: %s
```

Append to `langs/fr_FR/googleapi.lang`:

```
GoogleApiDriveSyncFailed=Impossible de copier "%s" vers Google Drive : %s
```

- [ ] **Step 4: Lint the trigger file**

Run: `php -l core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php langs/en_US/googleapi.lang langs/fr_FR/googleapi.lang
git commit -m "mirror newly attached files to Google Drive via ECMFILES_CREATE trigger"
```

---

### Task 5: Admin setup — root folder setting and per-object-type toggle list

**Files:**
- Modify: `admin/setup.php`
- Modify: `langs/en_US/googleapi.lang`
- Modify: `langs/fr_FR/googleapi.lang`

**Interfaces:**
- Consumes: the `GOOGLEAPI_DRIVE_SYNC_OBJECTS` setting (Task 4 self-registers new keys into it; this task only reads/renders/toggles it) and `GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER` (Task 4 reads it with a `'Dolibarr'` fallback; this task lets an admin set it explicitly).
- Produces: nothing consumed by later tasks (leaf feature).

- [ ] **Step 1: Add the root folder setting to `$arrayofparameters`**

In `admin/setup.php`, replace:

```php
	'OAUTH_GOOGLEAPI_URI' => [
		'css' => 'minwidth500',
		'default' => $urlwithroot . dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1),
	],
```

with:

```php
	'OAUTH_GOOGLEAPI_URI' => [
		'css' => 'minwidth500',
		'default' => $urlwithroot . dol_buildpath('/googleapi/core/modules/oauth/googleapi_oauthcallback.php', 1),
	],
	'GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER' => [
		'css' => 'minwidth200',
		'type' => 'text',
		'default' => 'Dolibarr',
	],
```

- [ ] **Step 2: Self-register the drive-sync-objects map and handle its toggle actions**

Replace:

```php
$googleapicontexts = json_decode(getDolGlobalString('GOOGLEAPI_CONTEXTS_TO_SEND', '{}'), true);
if (empty($googleapicontexts)) {
	// set default
	dolibarr_set_const($db, 'GOOGLEAPI_CONTEXTS_TO_SEND', json_encode(['standard' => true]), 'chaine', 0, '', $conf->entity);
	$googleapicontexts = json_decode(getDolGlobalString('GOOGLEAPI_CONTEXTS_TO_SEND', '{}'), true);
}
```

with:

```php
$googleapicontexts = json_decode(getDolGlobalString('GOOGLEAPI_CONTEXTS_TO_SEND', '{}'), true);
if (empty($googleapicontexts)) {
	// set default
	dolibarr_set_const($db, 'GOOGLEAPI_CONTEXTS_TO_SEND', json_encode(['standard' => true]), 'chaine', 0, '', $conf->entity);
	$googleapicontexts = json_decode(getDolGlobalString('GOOGLEAPI_CONTEXTS_TO_SEND', '{}'), true);
}

$googleapidrivesyncobjects = json_decode(getDolGlobalString('GOOGLEAPI_DRIVE_SYNC_OBJECTS', '{}'), true);
if (!is_array($googleapidrivesyncobjects)) {
	$googleapidrivesyncobjects = [];
}
```

Then replace:

```php
foreach ($googleapicontexts as $constant => $value) {
	if ($action == 'contextenable_' . strtolower($constant)) {
		$googleapicontexts[$constant] = true;
	}
	if ($action == 'contextdisable_' . strtolower($constant)) {
		$googleapicontexts[$constant] = false;
	}
	dolibarr_set_const($db, 'GOOGLEAPI_CONTEXTS_TO_SEND', json_encode($googleapicontexts), 'chaine', 0, '', $conf->entity);
}
```

with:

```php
foreach ($googleapicontexts as $constant => $value) {
	if ($action == 'contextenable_' . strtolower($constant)) {
		$googleapicontexts[$constant] = true;
	}
	if ($action == 'contextdisable_' . strtolower($constant)) {
		$googleapicontexts[$constant] = false;
	}
	dolibarr_set_const($db, 'GOOGLEAPI_CONTEXTS_TO_SEND', json_encode($googleapicontexts), 'chaine', 0, '', $conf->entity);
}
foreach ($googleapidrivesyncobjects as $constant => $value) {
	if ($action == 'drivesyncenable_' . strtolower($constant)) {
		$googleapidrivesyncobjects[$constant] = true;
	}
	if ($action == 'drivesyncdisable_' . strtolower($constant)) {
		$googleapidrivesyncobjects[$constant] = false;
	}
	dolibarr_set_const($db, 'GOOGLEAPI_DRIVE_SYNC_OBJECTS', json_encode($googleapidrivesyncobjects), 'chaine', 0, '', $conf->entity);
}
```

- [ ] **Step 3: Render the toggle table**

Replace:

```php
	print '</table>' . PHP_EOL;
	print '<br>' . PHP_EOL;

		// Contexts
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("GoogleApiSendContexts") . '</td>';
	print '<td align="center" width="100">' . $langs->trans("Action") . '</td>';
	print "</tr>\n";
	foreach ($googleapicontexts as $constant => $value) {
		print '<tr class="oddeven">';
		print '<td>' . $langs->trans('GoogleApiEnabledContext', $constant) . '</td>';
		print '<td align="center" width="100">';
		// $value = (isset($conf->global->$constant) ? $conf->global->$constant : 0);
		if (!$value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=contextenable_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Disabled"), 'switch_off');
			print '</a>';
		} elseif ($value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=contextdisable_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Enabled"), 'switch_on');
			print '</a>';
		}
		print "</td>";
		print '</tr>';
	}
	print "</table>\n";
	print "<br>\n";
}
```

with:

```php
	print '</table>' . PHP_EOL;
	print '<br>' . PHP_EOL;

		// Contexts
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("GoogleApiSendContexts") . '</td>';
	print '<td align="center" width="100">' . $langs->trans("Action") . '</td>';
	print "</tr>\n";
	foreach ($googleapicontexts as $constant => $value) {
		print '<tr class="oddeven">';
		print '<td>' . $langs->trans('GoogleApiEnabledContext', $constant) . '</td>';
		print '<td align="center" width="100">';
		// $value = (isset($conf->global->$constant) ? $conf->global->$constant : 0);
		if (!$value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=contextenable_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Disabled"), 'switch_off');
			print '</a>';
		} elseif ($value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=contextdisable_' . strtolower($constant) . '&amp;token=' . $_SESSION['newtoken'] . '">';
			print img_picto($langs->trans("Enabled"), 'switch_on');
			print '</a>';
		}
		print "</td>";
		print '</tr>';
	}
	print "</table>\n";
	print "<br>\n";

	// Drive sync per object type
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("GoogleApiDriveSyncObjects") . '</td>';
	print '<td align="center" width="100">' . $langs->trans("Action") . '</td>';
	print "</tr>\n";
	if (empty($googleapidrivesyncobjects)) {
		print '<tr class="oddeven"><td colspan="2">' . $langs->trans("GoogleApiDriveSyncObjectsEmpty") . '</td></tr>';
	}
	foreach ($googleapidrivesyncobjects as $constant => $value) {
		print '<tr class="oddeven">';
		print '<td>' . $langs->trans('GoogleApiDriveSyncEnabledObject', $constant) . '</td>';
		print '<td align="center" width="100">';
		if (!$value) {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=drivesyncenable_' . strtolower($constant) . '&amp;token=' . newToken() . '">';
			print img_picto($langs->trans("Disabled"), 'switch_off');
			print '</a>';
		} else {
			print '<a href="' . $_SERVER['PHP_SELF'] . '?action=drivesyncdisable_' . strtolower($constant) . '&amp;token=' . newToken() . '">';
			print img_picto($langs->trans("Enabled"), 'switch_on');
			print '</a>';
		}
		print "</td>";
		print '</tr>';
	}
	print "</table>\n";
	print "<br>\n";
}
```

- [ ] **Step 4: Add the new language keys**

Append to `langs/en_US/googleapi.lang`:

```
GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER=Root Drive folder for file mirroring
GoogleApiDriveSyncObjects=Sync attached files to Drive for these object types
GoogleApiDriveSyncEnabledObject=Object type: %s
GoogleApiDriveSyncObjectsEmpty=No object type seen yet. Upload a file to any object with this module enabled and its type will appear here to enable.
```

Append to `langs/fr_FR/googleapi.lang`:

```
GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER=Dossier racine Drive pour le miroir de fichiers
GoogleApiDriveSyncObjects=Synchroniser les fichiers joints vers Drive pour ces types d'objets
GoogleApiDriveSyncEnabledObject=Type d'objet : %s
GoogleApiDriveSyncObjectsEmpty=Aucun type d'objet vu pour l'instant. Envoyez un fichier sur un objet avec ce module actif et son type apparaîtra ici pour être activé.
```

- [ ] **Step 5: Lint the file**

Run: `php -l admin/setup.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add admin/setup.php langs/en_US/googleapi.lang langs/fr_FR/googleapi.lang
git commit -m "add Drive sync setup UI: root folder setting and per-object-type toggles"
```

---

### Task 6: Manual QA pass

**Files:** none (verification only; fix forward in the relevant file from Tasks 1-5 if a check fails, then re-run that task's `php -l` check and re-test).

**Interfaces:**
- Consumes: the whole feature (Tasks 1-5), against a real Dolibarr instance with the `googleapi` module enabled and a real connected Google account.

- [ ] **Step 1: Enable one object type**

Go to Setup > Modules > GoogleApi > Configuration (edit mode). Confirm the new "Root Drive folder for file mirroring" field shows `Dolibarr` by default, and that the new object-type table shows the empty-state message (no type seen yet, assuming this is the first upload since the feature shipped). Leave the root folder as `Dolibarr`.

- [ ] **Step 2: Discover and enable a type**

Upload a file to any object's "Linked files" tab (e.g. a third party's photo or a document). Reload the setup page: confirm the object's type (e.g. `societe`) now appears in the table, defaulted to off. Enable it.

- [ ] **Step 3: Confirm mirroring works**

Upload a second file to the same kind of object (or a new one of the same type). Confirm: the local Dolibarr upload succeeds as always; a folder path appears under `Dolibarr` (or the configured root) on Google Drive matching the object's document path; the file is present there with matching name and content; the corresponding `ecm_files` row has its `googleapiId` extrafield populated (check via Home > phpMyAdmin-equivalent or by re-uploading and checking Setup > Extrafields debug if `GOOGLEAPI_ENABLE_EXTRAFIELDS_DEBUG` is on).

- [ ] **Step 4: Confirm the object-type gate**

Upload a file to an object type that is *not* enabled. Confirm nothing is sent to Drive and no extrafield is set.

- [ ] **Step 5: Confirm not-connected is silent**

As a user who has not connected a Google account, upload a file to an enabled object type. Confirm the Dolibarr upload still succeeds, silently, with no Drive copy and no error message.

- [ ] **Step 6: Confirm API-failure warning**

Force a Drive API failure while still "connected" — e.g. temporarily revoke the test account's Drive access from https://myaccount.google.com/permissions (leaving the stored Dolibarr token in place, so `getGoogleApiClient()` still returns a client but the next Drive API call fails) — then upload a file to an enabled object type. Confirm a `warnings`-level message appears (with filename and error detail), the primary Dolibarr upload still succeeded, and the detail is present in the Dolibarr/PHP log. Afterwards, reconnect the test account so later checks aren't affected.

- [ ] **Step 7: Regression check on the manual Drive tab**

Re-verify the manual "Google Drive" ECM tab upload (Task 3's refactor target) still works: upload a file there, confirm success message and correct listing, then delete (trash) it to clean up.

- [ ] **Step 8: Record the outcome**

If every check above passes, no further commit is needed. If any check fails, fix the relevant file from the task that introduced it, re-run that task's `php -l` check, re-test the specific QA step here, then commit the fix with a message describing what was wrong.
