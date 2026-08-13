# Link Drive-Mirrored Files Into Synced Calendar Events Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a file uploaded to an `actioncomm` (agenda event) object is
successfully mirrored to Google Drive by the existing `ecmfilesCreate()`
trigger, also attach that file to the event's synced Google Calendar entry
— only if the event is already synced (has a `googleapi_EventId`).

**Architecture:** One new helper function in `lib/googleapi.lib.php`
(`googleapiAddDriveAttachmentToCalendarEvent()`) that fetches a Calendar
event, appends a new `EventAttachment` built from the Drive file, and
pushes the update — plus a new block inside the existing
`ecmfilesCreate()` trigger method that calls it, using the event owner's
own Google API client, right after the Drive upload already succeeds. No
new trigger, no new admin setting — this rides entirely on the existing
`GOOGLEAPI_DRIVE_SYNC_OBJECTS['actioncomm']` toggle.

**Tech Stack:** PHP 8.5 (Dolibarr custom module), `google/apiclient-services`
(`\Google\Service\Calendar\Event`, `\Google\Service\Calendar\EventAttachment`),
vendored via the sibling `prune` module
(`htdocs/custom/prune/vendor/google/apiclient-services`).

## Global Constraints

- PHP style: PSR-12, but indentation uses **tabs**, not spaces (matches the
  rest of this file/repo).
- No automated tests exist for this module (no PHPUnit coverage of
  `custom/` modules, no test double for the real Google API) — verification
  is `php -l` plus manual QA against a real Dolibarr instance and a real
  connected Google account.
- Never let a failure in this new code path affect the Drive upload or the
  primary Dolibarr file save that has already succeeded by the time this
  code runs — every failure mode here is caught internally and surfaced,
  at most, as its own separate `warnings`-level message.
- Commit message format: short lowercase summary line (see `git log` in
  this repo for the existing style, e.g. `git log --oneline -20`).
- Full design spec: `docs/superpowers/specs/2026-08-03-googleapi-calendar-attachment-design.md`
  — read it for the "why" behind decisions below (native Calendar
  attachment vs. description text, accumulate vs. replace, event-owner's
  client vs. uploader's client, "add anyway" on owner/uploader mismatch).

---

### Task 1: Add the Calendar-attachment helper and wire it into the Drive-mirror trigger

**Files:**
- Modify: `lib/googleapi.lib.php:359-360` (insert new function after
  `googleapiUploadFileToDrive()` closes, before `googleapiCreateActioncomm()`'s
  docblock)
- Modify: `core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php:826-831`
  (inside `ecmfilesCreate()`, right after the existing
  `$object->insertExtraFields()` success block, still inside the same
  `try`)
- Modify: `langs/en_US/googleapi.lang:46` (add new key right after
  `GoogleApiDriveSyncFailed`)
- Modify: `langs/fr_FR/googleapi.lang:65` (add new key right after
  `GoogleApiDriveSyncFailed`)

**Interfaces:**
- Consumes: `getGoogleApiClient($user)` (existing, `lib/googleapi.lib.php`),
  `dol_syslog()`, `\Google\Service\Calendar`, `\Google\Service\Calendar\EventAttachment`
  (both from the vendored `google/apiclient-services` package, confirmed
  present at
  `htdocs/custom/prune/vendor/google/apiclient-services/src/Calendar/EventAttachment.php`
  and `.../Calendar/Event.php`, with `setFileId()`, `setFileUrl()`,
  `setTitle()`, `setMimeType()` on `EventAttachment` and
  `getAttachments()`/`setAttachments()` on `Event` all present).
  `$driveid`, `$mimetype`, `$object` (the `EcmFiles` instance), `$langs`,
  all already in scope inside `ecmfilesCreate()`'s `try` block at the
  point of insertion.
- Produces: `googleapiAddDriveAttachmentToCalendarEvent($client, $calendarId, $eventId, $drivefileid, $filename, $mimetype, &$errmsg = '')`
  returning `bool` — this is the only new symbol other tasks or future
  work would need to know about, but this plan has no later tasks that
  consume it.

- [ ] **Step 1: Add the new helper function to `lib/googleapi.lib.php`**

Insert this new function at line 360 (the current blank line between
`googleapiUploadFileToDrive()`'s closing `}` at line 359 and the docblock
for `googleapiCreateActioncomm()` at line 361) — i.e. the function goes
*between* those two, with a blank line on each side exactly like every
other function boundary already in this file:

```php
/**
 * Append a Drive file as an attachment on an already-synced Google Calendar event.
 *
 * @param \Google\Client $client Google API client for the EVENT OWNER (not necessarily the uploader)
 * @param string $calendarId Calendar id ('primary' or the owner's configured calendar)
 * @param string $eventId Google Calendar event id (options_googleapi_EventId)
 * @param string $drivefileid Drive file id just returned by googleapiUploadFileToDrive()
 * @param string $filename Displayed attachment title
 * @param string $mimetype Attachment mime type
 * @param string $errmsg Set to the real error detail on failure (by reference)
 * @return bool true on success, false on failure
 */
function googleapiAddDriveAttachmentToCalendarEvent($client, $calendarId, $eventId, $drivefileid, $filename, $mimetype, &$errmsg = '')
{
	try {
		$service = new \Google\Service\Calendar($client);
		$event = $service->events->get($calendarId, $eventId);

		$attachments = $event->getAttachments();
		if (!is_array($attachments)) {
			$attachments = array();
		}
		$attachment = new \Google\Service\Calendar\EventAttachment();
		$attachment->setFileId($drivefileid);
		$attachment->setFileUrl('https://drive.google.com/file/d/'.$drivefileid.'/view');
		$attachment->setTitle($filename);
		$attachment->setMimeType($mimetype);
		$attachments[] = $attachment;
		$event->setAttachments($attachments);

		$service->events->update($calendarId, $eventId, $event, array('supportsAttachments' => true));
		return true;
	} catch (Throwable $e) {
		dol_syslog('googleapiAddDriveAttachmentToCalendarEvent: '.$e->getMessage(), LOG_ERR);
		$errmsg = $e->getMessage();
		return false;
	}
}
```

Note this catches `Throwable` (not `Exception`) — matching the fix applied
to the other Drive helpers (`googleapiUploadFileToDrive()`,
`googleapiGetOrCreateDriveFolder()`) during the previous plan's final
review, for the same reason: this runs from inside a trigger that itself
runs inside `EcmFiles::create()`'s open DB transaction, so an uncaught
`Error`/`TypeError` here must never be allowed to escape.

- [ ] **Step 2: Run `php -l` on the modified file**

Run: `php -l lib/googleapi.lib.php`
Expected: `No syntax errors detected in lib/googleapi.lib.php`

- [ ] **Step 3: Wire the helper into `ecmfilesCreate()`**

The current end of the `try` block in
`core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`
(inside `ecmfilesCreate()`) reads exactly:

```php
			$object->array_options['options_googleapiId'] = $driveid;
			if ($object->insertExtraFields() < 0) {
				throw new Exception('Could not record the Drive file id: '.$object->error);
			}
		} catch (Exception $e) {
			dol_syslog('ecmfilesCreate: '.$e->getMessage(), LOG_ERR);
			$langs->load('googleapi@googleapi');
			setEventMessages($langs->trans('GoogleApiDriveSyncFailed', $object->filename, $e->getMessage()), null, 'warnings');
		}

		return 0;
	}
```

Insert a new block right after the `insertExtraFields()` check and before
the closing `} catch (Exception $e) {` line, so the method becomes:

```php
			$object->array_options['options_googleapiId'] = $driveid;
			if ($object->insertExtraFields() < 0) {
				throw new Exception('Could not record the Drive file id: '.$object->error);
			}

			if ($object->src_object_type === 'actioncomm') {
				$eventobj = new ActionComm($this->db);
				if ($eventobj->fetch((int) $object->src_object_id) > 0 && !empty($eventobj->array_options['options_googleapi_EventId'])) {
					$owner = new User($this->db);
					if ($owner->fetch($eventobj->userownerid) > 0) {
						$ownerclient = getGoogleApiClient($owner);
						if ($ownerclient !== false) {
							$calendarId = !empty($owner->array_options['options_googleapi_calendarId']) ? $owner->array_options['options_googleapi_calendarId'] : 'primary';
							$attacherrmsg = '';
							$attached = googleapiAddDriveAttachmentToCalendarEvent(
								$ownerclient,
								$calendarId,
								$eventobj->array_options['options_googleapi_EventId'],
								$driveid,
								$object->filename,
								$mimetype,
								$attacherrmsg
							);
							if (!$attached) {
								$langs->load('googleapi@googleapi');
								setEventMessages($langs->trans('GoogleApiCalendarAttachFailed', $object->filename, $attacherrmsg !== '' ? $attacherrmsg : 'unknown error'), null, 'warnings');
							}
						}
					}
				}
			}
		} catch (Exception $e) {
			dol_syslog('ecmfilesCreate: '.$e->getMessage(), LOG_ERR);
			$langs->load('googleapi@googleapi');
			setEventMessages($langs->trans('GoogleApiDriveSyncFailed', $object->filename, $e->getMessage()), null, 'warnings');
		}

		return 0;
	}
```

This new block never throws on its own — every failure path inside it
(fetch failures, not-connected, the helper returning `false`) is handled
locally (silent skip or its own `setEventMessages()` warning), so it can
never cause the outer `catch` to fire and never make an already-successful
Drive upload look like it failed. It reuses `$driveid`, `$mimetype`, and
`$langs`, all already in scope at this point in the method (`$driveid` and
`$mimetype` are set a few lines earlier in the same `try` block by the
existing Drive-upload code; `$langs` is a method parameter).

`ActionComm` and `User` are both core Dolibarr classes already available
without a new `require`/`dol_include_once` in this file — confirm this by
checking the file's existing top-of-file requires/includes still cover
them (they do: `main.inc.php`, which bootstraps every request including
trigger dispatch, already loads both `htdocs/comm/action/class/actioncomm.class.php`
and `htdocs/user/class/user.class.php` as part of Dolibarr's own
autoloading of core object classes — this file already constructs a `User`
object elsewhere, e.g. inside `actionModify()`'s `$staticuser = new
User($this->db);`, without any local require, confirming the class is
already available in this scope).

- [ ] **Step 4: Run `php -l` on the modified trigger file**

Run: `php -l core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`
Expected: `No syntax errors detected in core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`

- [ ] **Step 5: Add the new language key (English)**

In `langs/en_US/googleapi.lang`, right after line 46
(`GoogleApiDriveSyncFailed=Could not copy "%s" to Google Drive: %s`), add:

```
GoogleApiCalendarAttachFailed=Could not attach "%s" to the Google Calendar event: %s
```

- [ ] **Step 6: Add the new language key (French)**

In `langs/fr_FR/googleapi.lang`, right after line 65
(`GoogleApiDriveSyncFailed=Impossible de copier "%s" vers Google Drive : %s`),
add:

```
GoogleApiCalendarAttachFailed=Impossible de joindre "%s" à l'événement Google Calendar : %s
```

- [ ] **Step 7: Commit**

```bash
git add lib/googleapi.lib.php core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php langs/en_US/googleapi.lang langs/fr_FR/googleapi.lang
git commit -m "attach Drive-mirrored actioncomm files to their synced Calendar event"
```

---

### Task 2: Manual QA pass

**Files:** none (verification only; fix forward in `lib/googleapi.lib.php`
or the trigger file if a check fails, then re-run `php -l` and re-test).

**Interfaces:**
- Consumes: Task 1's code, against a real Dolibarr instance with the
  `googleapi` module enabled, `actioncomm` already enabled in
  `GOOGLEAPI_DRIVE_SYNC_OBJECTS` (already true on this instance from the
  prior Drive-mirror plan's QA), and a real connected Google account with
  both Drive and Calendar access.

- [ ] **Step 1: Sync an event, then attach a file**

Create (or pick an existing) agenda event owned by the connected test user
and confirm it has already been pushed to Calendar (has a non-empty
`options_googleapi_EventId` — check via the event's extrafields, or just
confirm it's visible in the connected Google Calendar). Upload a file to
that event's "Linked files" tab. Confirm: the Drive upload still succeeds
exactly as before (folder path, file content, `ecm_files.googleapiId`
extrafield — same checks as the previous plan's QA), and the Calendar
event now shows the file as an attachment (check via the Google Calendar
UI, or by fetching the event through the API/`oauthlogintokens.php`-style
inspection).

- [ ] **Step 2: Confirm accumulation, not replacement**

Upload a second, different file to the same event. Confirm both files now
appear as separate attachments on the event (not just the most recent
one).

- [ ] **Step 3: Confirm the "not synced yet" skip**

Create a new agenda event that has never been pushed to Calendar (no
`options_googleapi_EventId` — e.g. create it in a way that doesn't trigger
the Calendar push, or check a pre-existing local-only event). Upload a
file to it. Confirm: the Drive upload still succeeds, and no Calendar API
call is attempted (no attachment appears anywhere, no warning message).

- [ ] **Step 4: Confirm owner/uploader mismatch still attaches**

If practical, upload a file to a synced event owned by a different
Dolibarr user than the one uploading (or reason about this via the code
path if a second connected test account isn't available). Confirm the
attachment is still added to the owner's Calendar event.

- [ ] **Step 5: Confirm a Calendar-side failure surfaces its own warning**

If practical without disrupting the real Google account outside of what's
reversible, force a Calendar API failure (e.g. an event id that no longer
exists, or by temporarily breaking connectivity in a reversible way) and
confirm: a `warnings`-level message appears mentioning the filename and
error detail, separate from any Drive-upload message, and the Drive
upload itself is unaffected. If this isn't practical to test live (same
constraint as the previous plan's equivalent Calendar/Drive-revocation
step), it's acceptable to instead verify the error path via careful code
review of Step 1's diff and note the gap in the final report, same as the
previous plan did.

- [ ] **Step 6: Record the outcome**

If every check above passes (or is verified via code review where live
testing isn't practical), no further commit is needed. If any check
fails, fix the relevant file, re-run `php -l`, re-test the specific QA
step here, then commit the fix with a message describing what was wrong.
