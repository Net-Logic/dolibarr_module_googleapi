# Link Drive-mirrored files into their synced Google Calendar event

Date: 2026-08-03
Status: Approved by user, implementation plan pending.

## Context

The `googleapi` custom module (`htdocs/custom/googleapi`) already has two
independent per-user Google syncs relevant here:

- **Drive mirroring of attached files** (just shipped, branch `dev`,
  commits `2897700..2a3e0e3`): `InterfaceGoogleApiTriggers::ecmfilesCreate()`
  (`core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`)
  fires on every new `llx_ecm_files` row. Gated per `src_object_type` by a
  self-registering JSON toggle map, `GOOGLEAPI_DRIVE_SYNC_OBJECTS` (nothing
  syncs for a given type until an admin flips it on in Setup). When enabled
  for a type, it uploads the physical file to the **uploading user's own**
  Google Drive (via `getGoogleApiClient($user)`), mirroring Dolibarr's
  document folder structure under a configurable root folder
  (`GOOGLEAPI_DRIVE_SYNC_ROOT_FOLDER`, default `Dolibarr`), and records the
  resulting Drive file id in the `ecm_files` extrafield `googleapiId`. On
  any Drive API failure it shows a `warnings`-level message with the
  filename and real error detail (`langs->trans('GoogleApiDriveSyncFailed',
  $object->filename, $errmsg)`) without blocking the primary Dolibarr
  upload; on "not connected" it is a silent no-op. `actioncomm` is one of
  the object types that can be (and, on this instance, already has been)
  enabled for this toggle.
- **Google Calendar sync of agenda events** (pre-existing, unrelated to the
  toggle above — it is unconditional, gated only by the module being active
  and an `AC_OTH_AUTO` auto-event filter): `actionCreate()`/`actionModify()`
  in the same trigger file build a `\Google\Service\Calendar\Event` from an
  `ActionComm` object (`summary`, `location`, `description`, `start`/`end`,
  `reminders`) and push it via `$service->events->insert()`/`->update()`,
  using the **event owner's** (`$object->userownerid`) Google API client —
  not the current logged-in user's. The link between the Dolibarr row and
  the Calendar event is the extrafield `options_googleapi_EventId` on
  `actioncomm`, set the first time an event is pushed and read on every
  subsequent update/delete. No Calendar `attachments` or
  `extendedProperties` usage exists anywhere in the module today — only the
  plain fields listed above are populated.

## Goal

When a file is uploaded onto an `actioncomm` (agenda event/task) object and
successfully mirrored to Google Drive by the existing `ecmfilesCreate()`
trigger, also attach that file to the event's synced Google Calendar entry
— *if and only if* that event is already synced (has a
`googleapi_EventId`). This makes the Drive copy directly reachable from
Calendar, next to the event it belongs to.

## Functional scope

- Runs as a direct continuation of `ecmfilesCreate()`, only when
  `$object->src_object_type === 'actioncomm'` and the Drive upload for that
  file just succeeded. No new trigger, no new object-type toggle — this
  rides entirely on the existing `GOOGLEAPI_DRIVE_SYNC_OBJECTS['actioncomm']`
  switch already gating the Drive upload itself.
- Fetches the parent `ActionComm` by `$object->src_object_id`. If it has no
  `options_googleapi_EventId` set, the event has never been synced to
  Calendar: skip silently — not an error, just not applicable yet.
- If it does have an event id: fetches the **event owner**
  (`$actioncomm->userownerid`) as a `User` and calls
  `getGoogleApiClient()` on *that* user — matching how `actionModify()`
  already authenticates Calendar writes (the calendar being written to
  belongs to the owner, not necessarily the person who uploaded the file).
  If the owner isn't connected: skip silently, same convention as the rest
  of this feature.
- Fetches the current event (`events->get()`), reads its existing
  `attachments` array, and appends a new entry for the just-uploaded file
  rather than replacing what's there — multiple files attached to the same
  event over time should all show up:
  - `fileId`: the Drive file id just returned by `googleapiUploadFileToDrive()`
  - `fileUrl`: `https://drive.google.com/file/d/{fileId}/view`
  - `title`: `$object->filename`
  - `mimeType`: the same mimetype already computed for the Drive upload
    (`dol_mimetype($object->filename, 'application/octet-stream', 0)`)
- Calls `events->update($calendarId, $eventId, $event, ['supportsAttachments' => true])`
  with the updated `attachments` array set back on the `Event` object.
  `$calendarId` uses the same existing rule as `actionModify()`:
  `$owner->array_options['options_googleapi_calendarId']` if set, else
  `'primary'`.
- Applies regardless of whether the uploading user is the same Dolibarr
  user as the event owner — if the file was uploaded by someone else, the
  attachment is still added (the Drive file lives in the *uploader's* own
  Drive per the existing per-user design; whether the event owner can open
  it is a sharing/permissions concern out of scope for this feature, same
  as the rest of this module's "best effort, per connected user" posture).
- Any failure in this new step (Calendar API error, event fetch failure,
  etc.) is caught and shown as its own `warnings`-level message, separate
  from the Drive-upload warning (which, by the time this step runs, has
  already succeeded) — it must never block or roll back the Drive upload or
  the primary Dolibarr file save that already completed.

## Out of scope for this iteration

- No removal of attachments when the underlying `ecm_files` row or Drive
  file is later deleted (mirrors the existing Drive-mirror feature's own
  "no mirroring on delete" scope cut).
- No de-duplication if the same file is somehow re-indexed and re-uploaded
  (mirrors the existing Drive-mirror feature's scope cut) — a second
  matching attachment entry would simply be appended again.
- No retroactive backfill of attachments for files already mirrored to
  Drive before this feature ships.
- No handling of the Calendar API's attachment count/size limits — if an
  event accumulates enough attachments to hit Google's own cap, the
  resulting API error is surfaced via the standard warning path, not
  specially handled.

## Architecture

### New helper in `lib/googleapi.lib.php`

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

Placed alongside the other Drive helpers already in this file
(`googleapiResolveDriveFolderPath()`, `googleapiUploadFileToDrive()`), even
though it calls the Calendar API rather than the Drive API — it's a direct
extension of the Drive-mirror flow, not a general-purpose Calendar helper.

### Extending `ecmfilesCreate()`

In `core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php`,
right after the existing successful-upload block (after
`$object->insertExtraFields()` currently at the end of the `try` block),
inside the same `try`:

```php
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
```

This block never throws — a failure inside it only shows its own warning
and does not re-enter the outer `catch`, so it can never make an already-
successful Drive upload look like it failed. (Fetch failures on `$eventobj`
or `$owner` are treated the same as "not applicable" — silent skip — since
`fetch()` returning `<= 0` here means the referenced row is gone or
unreadable, not a Drive/Calendar API error worth surfacing.)

Reuses `$driveid` and `$mimetype`, both already computed earlier in the
same `try` block by the existing Drive-upload code — no new fetch or
recomputation needed.

### Language keys

New key in `langs/{en_US,fr_FR}/googleapi.lang`:
`GoogleApiCalendarAttachFailed` (two `%s` placeholders: filename and error
detail), following the exact same shape as the existing
`GoogleApiDriveSyncFailed`.

## Error handling

- Event not synced to Calendar yet (no `googleapi_EventId`): silent no-op.
- Event owner not connected to Google: silent no-op.
- Parent `ActionComm` or owner `User` fetch fails: silent no-op (treated as
  "not applicable", not as an error).
- Any Calendar API exception (`events->get()` or `events->update()`): the
  Drive upload and the primary Dolibarr file save are **never** affected —
  a separate `warnings`-level `setEventMessages()` is shown with the
  filename and error detail, and the error is logged via `dol_syslog`.

## Security

- No new permission created: this is a passive continuation of a Drive
  mirror that itself is a passive side effect of an upload the user is
  already authorized to perform.
- No new user input is embedded in API calls beyond what the existing
  Drive-mirror feature already sends (filename, mimetype — both already
  Dolibarr-sanitized at original upload time).

## Testing

No automated tests (same constraint as the rest of this module). Manual QA
once implemented, against a real Dolibarr instance with a real connected
Google account, extending the existing Drive-mirror QA checklist:

1. Create/sync an agenda event to Calendar (owned by the connected test
   user) so it has a `googleapi_EventId`. Upload a file to that event's
   "Linked files" tab (with `actioncomm` enabled in
   `GOOGLEAPI_DRIVE_SYNC_OBJECTS`, as it already is on this instance).
   Confirm: the Drive upload succeeds as before, and the Calendar event now
   shows the file as an attachment (check via the Calendar UI or the API).
2. Upload a second file to the same event. Confirm both attachments are
   present (accumulation, not replacement).
3. Upload a file to an agenda event that has never been synced to Calendar
   (no `googleapi_EventId`). Confirm the Drive upload still succeeds and no
   Calendar call is attempted (no attachment, no error).
4. Upload a file to a synced event whose owner is a different Dolibarr user
   than the uploader. Confirm the attachment is still added to the owner's
   Calendar event (per the "add anyway" design decision).
5. Force a Calendar API failure (if practical) and confirm a warning
   message appears, separate from any Drive-upload message, without
   blocking the already-successful Drive upload.
