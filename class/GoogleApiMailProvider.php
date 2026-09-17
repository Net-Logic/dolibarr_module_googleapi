<?php
/**
 * \file    class/GoogleApiMailProvider.php
 * \ingroup googleapi
 * \brief   UnifiedInboxProviderInterface implementation backed by googleapi's
 *          existing Gmail integration (per-Dolibarr-user OAuth, llx_googleapi_email
 *          activity cache, llx_googleapi_mailboxes unread counter, live Gmail API
 *          calls for message body / mark seen / delete).
 *
 * Unlike unifiedinbox's own IMAP/WhatsApp providers, a googleapi-type
 * unifiedinbox account carries no credentials of its own: it points at either
 * a Dolibarr user (UnifiedInboxAccount::$fk_user) whose Google account is
 * already connected through googleapi's own OAuth flow, or — when $fk_user is
 * left empty — a sender-profile-scoped token (fk_user=0 in
 * llx_prune_oauth_token, keyed by UnifiedInboxAccount::$email) authorized via
 * the "connect with Google" action on admin/mails_senderprofile_list.php, for
 * a shared Gmail inbox not owned by any one Dolibarr user.
 *
 * Deliberately not implemented (return false, per the interface's own
 * documented convention for "Extended actions" providers don't support):
 * appendMessage(). The Gmail API could support this too (users.messages.insert)
 * but that's follow-up work, not part of this feature — see
 * docs/superpowers/specs/2026-09-14-external-provider-hook-design.md in the
 * unifiedinbox repo, section "Non-goals".
 */

require_once DOL_DOCUMENT_ROOT.'/custom/unifiedinbox/class/UnifiedInboxProviderInterface.php';
require_once DOL_DOCUMENT_ROOT.'/custom/googleapi/class/googleapi.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/googleapi/lib/googleapi.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

class GoogleApiMailProvider implements UnifiedInboxProviderInterface
{
	/** @var User|null  Dolibarr user that owns the Google connection (personal mode) */
	private $fuser;
	/** @var string|null  Account's own email, used to look up a sender-profile-scoped token (shared mode, no owning user) */
	private $senderEmail;
	/** @var string  Gmail label ID to scope getMessages()/getThreadedMessages() to, set by connect() */
	private $folder = 'INBOX';
	/** @var string */
	private $error = '';

	// ── Connection lifecycle ──────────────────────────────────────────────────

	public function connect(UnifiedInboxAccount $account, $folder = 'INBOX')
	{
		global $db;

		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		if (!empty($account->fk_user)) {
			$fuser = new User($db);
			if ($fuser->fetch((int) $account->fk_user) <= 0) {
				$this->error = 'Dolibarr user #'.$account->fk_user.' not found';
				return false;
			}
			$fuser->loadDefaultValues();
			if (empty($fuser->array_options['options_googleapi_email'])) {
				$this->error = 'This user has no linked Google account (Setup > OAuth login tokens)';
				return false;
			}

			try {
				$client = getGoogleApiClient($fuser);
			} catch (\Exception $e) {
				$this->error = 'Google API connection failed: '.$e->getMessage();
				return false;
			}
			if (empty($client)) {
				$this->error = 'Google API connection failed for user '.$fuser->login;
				return false;
			}

			$this->fuser = $fuser;
		} else {
			// No Dolibarr user configured: fall back to a sender-profile-scoped token
			// for this account's own email (see the class docblock).
			if (empty($account->email)) {
				$this->error = 'No Dolibarr user configured for this Gmail account, and no email to look up a sender-profile token for';
				return false;
			}

			try {
				$client = getGoogleApiClient(new User($db), $account->email);
			} catch (\Exception $e) {
				$this->error = 'Google API connection failed: '.$e->getMessage();
				return false;
			}
			if (empty($client)) {
				$this->error = 'No Google token found for sender profile '.$account->email.' (Setup > Emails > Sender profiles)';
				return false;
			}

			$this->senderEmail = $account->email;
		}

		$this->folder = $folder ?: 'INBOX';
		return true;
	}

	public function close()
	{
		// Stateless REST provider — nothing to close
	}

	public function getError()
	{
		return $this->error;
	}

	/**
	 * Build a Google client for whichever identity connect() resolved —
	 * the personal user, or the sender-profile-scoped email fallback.
	 *
	 * @return \Google\Client|false
	 */
	private function client()
	{
		global $db;
		if ($this->fuser) {
			return getGoogleApiClient($this->fuser);
		}
		return getGoogleApiClient(new User($db), $this->senderEmail);
	}

	/**
	 * @return bool  True once connect() has resolved either identity
	 */
	private function connected()
	{
		return (bool) ($this->fuser || $this->senderEmail);
	}

	/**
	 * A real (possibly unfetched, id-less) User object for the shared
	 * getGoogleMailMessages()/getGoogleMailMessageAndBody() helpers, which
	 * treat a falsy $user as "use the globally logged-in user" — passing an
	 * object here (even an empty one, in sender-profile mode) skips that
	 * fallback so $this->senderEmail is what actually resolves the token.
	 *
	 * @return User
	 */
	private function identityUser()
	{
		global $db;
		return $this->fuser ?: new User($db);
	}

	// ── Folder / conversation navigation ─────────────────────────────────────

	/** @var array<string,array{name:string,type:string}> System Gmail labels shown as folders, with the same French names/type vocabulary as ImapClient::getFolders() */
	private const SYSTEM_LABELS = [
		'INBOX' => ['name' => 'Boîte de réception', 'type' => 'inbox'],
		'SENT'  => ['name' => 'Envoyés', 'type' => 'sent'],
		'DRAFT' => ['name' => 'Brouillons', 'type' => 'drafts'],
		'TRASH' => ['name' => 'Corbeille', 'type' => 'trash'],
		'SPAM'  => ['name' => 'Pourriel', 'type' => 'spam'],
	];

	public function getFolders()
	{
		if (!$this->connected()) return [];

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$response = $gMailService->users_labels->listUsersLabels('me');
		} catch (\Exception $e) {
			$this->error = 'Failed to list labels: '.$e->getMessage();
			return [];
		}

		$folders = [];
		foreach ($response->getLabels() as $lbl) {
			$id = $lbl->getId();
			if ($lbl->getType() === 'system') {
				// Only show the handful of system labels that are genuine "folders" —
				// skip CATEGORY_*, STARRED, IMPORTANT, UNREAD, CHAT (Gmail flags/tabs,
				// not folders; IMAPClient::getFolders() has no equivalent for these).
				if (!isset(self::SYSTEM_LABELS[$id])) continue;
				$name = self::SYSTEM_LABELS[$id]['name'];
				$type = self::SYSTEM_LABELS[$id]['type'];
			} else {
				// Respect the user's own choice to hide a label from their Gmail sidebar.
				if ($lbl->getLabelListVisibility() === 'labelHide') continue;
				$name = $lbl->getName();
				$type = 'folder';
			}

			$folders[] = [
				'id'     => $id,
				'name'   => $name,
				'label'  => $name,
				'type'   => $type,
				// Real per-label unseen counts need one extra API call per label (Gmail's
				// labels.list doesn't return messagesUnread, only labels.get does) — only
				// worth it for INBOX, which is the only folder unifiedinbox's UI surfaces
				// an unseen count for today (see getUnseenCount()).
				'unseen' => ($id === 'INBOX') ? $this->getUnseenCount('INBOX') : 0,
			];
		}

		usort($folders, static function ($a, $b) {
			$order = ['inbox' => 1, 'sent' => 2, 'drafts' => 3, 'archive' => 4, 'spam' => 5, 'trash' => 6, 'folder' => 10];
			$wa = $order[$a['type']] ?? 10;
			$wb = $order[$b['type']] ?? 10;
			return ($wa === $wb) ? strcasecmp($a['name'], $b['name']) : ($wa - $wb);
		});

		return $folders;
	}

	public function getUnseenCount($folder = 'INBOX')
	{
		if (!$this->connected()) return 0;

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$label = $gMailService->users_labels->get('me', $folder);
			return (int) $label->getMessagesUnread();
		} catch (\Exception $e) {
			$this->error = 'Failed to get unseen count: '.$e->getMessage();
			return 0;
		}
	}

	// ── Message listing (implemented in Task 7) ───────────────────────────────

	public function getMessages($limitNb, $limitDays, $offset, $pageSize)
	{
		if (!$this->connected()) return false;

		// getGoogleMailMessages() paginates via an opaque Gmail pageToken, not a
		// numeric offset, so pull sequential pages until $offset is reached, then
		// return the next $pageSize. $limitNb caps how many messages we'll ever
		// walk through looking for that offset (mirrors WhatsAppProvider's $limitNb
		// role as a pool-size cap, not a Gmail API concept).
		$pageToken = null;
		$skipped = 0;
		$collected = [];
		$collectedRows = [];
		$totalSeen = 0;
		$query = ['newer_than:'.(int) $limitDays.'d'];

		try {
			do {
				$batch = getGoogleMailMessages($query, min(50, $limitNb - $totalSeen), $pageToken, $this->identityUser(), null, null, [$this->folder], $this->senderEmail);
				if (empty($batch)) break;
				foreach ($batch as $row) {
					$totalSeen++;
					if ($skipped < $offset) {
						$skipped++;
						continue;
					}
					if (count($collected) < $pageSize) {
						$collected[] = $this->rowToMessage($row);
						$collectedRows[] = $row;
					}
				}
			} while ($pageToken && $totalSeen < $limitNb && count($collected) < $pageSize);
		} catch (\Exception $e) {
			$this->error = 'Failed to list messages: '.$e->getMessage();
			return false;
		}

		// The cached `unread` flag (llx_googleapi_email.unread) is only ever set once,
		// at first ingestion, and only refreshed afterward if the user marks the
		// message seen/unseen through unifiedinbox itself — reading a message directly
		// in Gmail (app, web, another client) never updates it, so it silently goes
		// stale. Re-check the real state for exactly what's on this page with a single
		// extra Gmail API call (not one per message) and reconcile both the in-memory
		// result and the local cache.
		$this->refreshUnreadStatus($collected, $collectedRows, $limitDays);

		return [
			'messages' => $collected,
			'total'    => $totalSeen,
			'has_more' => (bool) $pageToken,
		];
	}

	/**
	 * Re-check which of the given messages are genuinely unread in Gmail right now,
	 * via a single `labelIds=[$this->folder, 'UNREAD']` list call, and correct both
	 * the in-memory stdClass items (`->seen`) and the local cache
	 * (`llx_googleapi_email.unread`) wherever they disagree with Gmail's real state.
	 * Best-effort: on any API failure, silently keeps the (possibly stale) cached
	 * values rather than breaking the message list.
	 *
	 * @param  stdClass[]             $items ->uid-keyed items from rowToMessage(), updated in place
	 * @param  GoogleApiGMailMessage[] $rows  Same order as $items, for DB rowid access
	 * @param  int                     $limitDays
	 */
	private function refreshUnreadStatus(array $items, array $rows, $limitDays)
	{
		if (empty($items)) return;

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$unreadIds = [];
			$pageToken = null;
			do {
				$filters = [
					'q'          => 'newer_than:'.(int) $limitDays.'d',
					'labelIds'   => [$this->folder, 'UNREAD'],
					'maxResults' => 500,
				];
				if ($pageToken) $filters['pageToken'] = $pageToken;
				$response = $gMailService->users_messages->listUsersMessages('me', $filters);
				foreach ($response->getMessages() as $m) {
					$unreadIds[$m->getId()] = true;
				}
				$pageToken = $response->getNextPageToken() ?: null;
			} while ($pageToken);
		} catch (\Exception $e) {
			// Best-effort — keep whatever the cache already said.
			return;
		}

		global $db;
		foreach ($items as $i => $item) {
			$reallyUnread = isset($unreadIds[$item->message_id]);
			$cachedUnread = ($item->seen == 0);
			if ($reallyUnread === $cachedUnread) continue;

			$item->seen = $reallyUnread ? 0 : 1;
			if (isset($rows[$i]->rowid) && $rows[$i]->rowid) {
				$db->query('UPDATE '.MAIN_DB_PREFIX.'googleapi_email SET unread='.($reallyUnread ? 1 : 0).' WHERE rowid='.(int) $rows[$i]->rowid);
			}
		}
	}

	public function getThreadedMessages($limitDays, $offset, $pageSize)
	{
		if (!$this->connected()) return false;

		// Gmail already groups by thread_id server-side; ask for one message per
		// thread by paging through and de-duplicating on threadId client-side,
		// since getGoogleMailMessages() returns flat GoogleApiGMailMessage rows
		// without a threadId column today — group on message_id prefix isn't
		// reliable, so group on subject instead (same heuristic already visible
		// in emails_list.php's own thread grouping, not a new convention).
		$flat = $this->getMessages(500, $limitDays, 0, 500);
		if ($flat === false) return false;

		$byThread = [];
		foreach ($flat['messages'] as $msg) {
			$key = $msg->subject;
			if (!isset($byThread[$key])) {
				$thread = clone $msg;
				$thread->is_thread = true;
				$thread->participants = [$msg->from];
				$thread->messages = [$msg];
				$byThread[$key] = $thread;
			} else {
				$byThread[$key]->messages[] = $msg;
				if (!in_array($msg->from, $byThread[$key]->participants)) {
					$byThread[$key]->participants[] = $msg->from;
				}
			}
		}

		$threads = array_slice(array_values($byThread), $offset, $pageSize);
		return [
			'messages' => $threads,
			'total'    => count($byThread),
			'has_more' => ($offset + $pageSize) < count($byThread),
		];
	}

	/**
	 * Map a GoogleApiGMailMessage row onto the shape the shared unifiedinbox UI
	 * expects (same fields as WhatsAppProvider::rowToMessage()).
	 *
	 * @param  GoogleApiGMailMessage $row
	 * @return stdClass
	 */
	private function rowToMessage($row)
	{
		$item = new stdClass();
		$item->uid = $row->message_id;
		$item->message_id = $row->message_id;
		$item->seen = $row->unread ? 0 : 1;
		$item->answered = 0;
		$item->deleted = 0;
		$item->keywords = '';
		$item->date = date('Y-m-d H:i:s', (int) $row->date);
		$item->cc = $row->email_cc ?? '';
		$item->from = $row->email_from;
		$item->to = $row->email_to;
		$item->subject = $row->subject ?: '(no subject)';
		$item->has_attachments = (int) $row->has_attachments;
		return $item;
	}

	// ── Message detail (implemented in Task 7) ────────────────────────────────

	public function getMessageBody($messageId)
	{
		if (!$this->connected()) return false;

		try {
			$result = getGoogleMailMessageAndBody($messageId, $this->identityUser(), $this->senderEmail);
		} catch (\Exception $e) {
			$this->error = 'Failed to load message body: '.$e->getMessage();
			return false;
		}
		if (empty($result['body'])) {
			$this->error = 'Message not found: '.$messageId;
			return false;
		}

		// The interface docblock says this should return ['html'=>..,'plain'=>..], but the
		// only genuinely-working implementation (ImapProvider, via IMAPClient::getMessageBody())
		// returns a plain HTML string, which is what js/app.js actually consumes — match reality.
		return $result['body']['html'] ?: $result['body']['plain'];
	}

	public function getMessageHeaders($messageId)
	{
		if (!$this->connected()) return [];

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$message = $gMailService->users_messages->get('me', $messageId, [
				'format' => 'metadata',
				'metadataHeaders' => ['Message-Id', 'In-Reply-To', 'References', 'X-Dolibarr-TRACKID'],
			]);
		} catch (\Exception $e) {
			$this->error = 'Failed to load message headers: '.$e->getMessage();
			return [];
		}

		// Confirmed live (2026-09-15): Gmail's own outbound SMTP relay rewrites
		// Message-Id on submission (e.g. Dolibarr's CMailFile-generated id becomes
		// a Gmail-generated <...@mail.gmail.com> one), but leaves the custom
		// X-Dolibarr-TRACKID header CMailFile sets independently untouched — this
		// is the reliable signal for any mail actually sent through Gmail.
		$headers = [];
		foreach ($message->getPayload()->getHeaders() as $header) {
			$name = strtolower($header->getName());
			if ($name === 'message-id') {
				$headers['message_id'] = $header->getValue();
			} elseif ($name === 'in-reply-to') {
				$headers['in_reply_to'] = $header->getValue();
			} elseif ($name === 'references') {
				$headers['references'] = $header->getValue();
			} elseif ($name === 'x-dolibarr-trackid') {
				$headers['x_dolibarr_trackid'] = $header->getValue();
			}
		}
		return $headers;
	}

	public function getAttachments($messageId)
	{
		if (!$this->connected()) return [];

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$message = $gMailService->users_messages->get('me', $messageId, ['format' => 'full']);
		} catch (\Exception $e) {
			$this->error = 'Failed to load attachments: '.$e->getMessage();
			return [];
		}

		$attachments = [];
		$this->collectAttachmentParts($message->getPayload(), $attachments);
		return $attachments;
	}

	public function getAttachmentData($messageId, $partNo, $encoding)
	{
		if (!$this->connected()) return false;

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$message = $gMailService->users_messages->get('me', $messageId, ['format' => 'full']);

			$attachmentId = $this->findAttachmentId($message->getPayload(), (string) $partNo);
			if (!$attachmentId) {
				$this->error = 'Attachment part not found: '.$partNo;
				return false;
			}

			$body = $gMailService->users_messages_attachments->get('me', $messageId, $attachmentId);
		} catch (\Exception $e) {
			$this->error = 'Failed to load attachment data: '.$e->getMessage();
			return false;
		}

		// Gmail always returns attachment bytes base64url-encoded (RFC 4648 §5),
		// regardless of the part's original Content-Transfer-Encoding — $encoding
		// (the IMAP-style transfer-encoding constant from the interface's own
		// docblock) is meaningless here and deliberately unused, same as this
		// class's other REST-provider methods that ignore IMAP-only parameters.
		return base64_decode(strtr((string) $body->getData(), '-_', '+/'));
	}

	/**
	 * Recursively collect every part with both a filename and an attachmentId
	 * — Gmail's own definition of "this part is a downloadable attachment",
	 * as opposed to an inline body part (text/plain, text/html) which has
	 * neither.
	 *
	 * @param  \Google\Service\Gmail\MessagePart $part
	 * @param  array                             $attachments  Appended to by reference
	 */
	private function collectAttachmentParts($part, &$attachments)
	{
		$body = $part->getBody();
		if (!empty($part->getFilename()) && $body && $body->getAttachmentId()) {
			$attachments[] = [
				'partno'     => $part->getPartId(),
				'filename'   => $part->getFilename(),
				'mime'       => $part->getMimeType(),
				'size'       => (int) $body->getSize(),
				'encoding'   => 0,
				'content_id' => $this->findContentIdHeader($part),
			];
		}
		foreach ((array) $part->getParts() as $childPart) {
			$this->collectAttachmentParts($childPart, $attachments);
		}
	}

	/**
	 * A part referenced as cid:... in the HTML body carries a Content-ID
	 * MIME header — Gmail's API surfaces MIME headers per-part (only when
	 * fetched with format=full, already the case for every caller of
	 * collectAttachmentParts()) rather than as a dedicated accessor the way
	 * IMAP/Graph expose one.
	 *
	 * @param  \Google\Service\Gmail\MessagePart $part
	 * @return string|null
	 */
	private function findContentIdHeader($part)
	{
		foreach ((array) $part->getHeaders() as $header) {
			if (strcasecmp($header->getName(), 'Content-ID') === 0) {
				return $header->getValue();
			}
		}
		return null;
	}

	/**
	 * Find the attachmentId for a given Gmail partId, walking the same MIME
	 * tree getAttachments() built 'partno' values from.
	 *
	 * @param  \Google\Service\Gmail\MessagePart $part
	 * @param  string                            $wantedPartId
	 * @return string|null
	 */
	private function findAttachmentId($part, $wantedPartId)
	{
		if ((string) $part->getPartId() === $wantedPartId) {
			$body = $part->getBody();
			return $body ? $body->getAttachmentId() : null;
		}
		foreach ((array) $part->getParts() as $childPart) {
			$found = $this->findAttachmentId($childPart, $wantedPartId);
			if ($found) return $found;
		}
		return null;
	}

	// ── Message actions (implemented in Task 7) ───────────────────────────────

	public function markSeen($messageId)
	{
		return $this->setUnreadLabel($messageId, false);
	}

	public function markUnseen($messageId)
	{
		return $this->setUnreadLabel($messageId, true);
	}

	public function moveMessage($messageId, $targetFolder)
	{
		if (!$this->connected()) return false;

		// Gmail's Trash is a first-class action (also excludes the message from
		// IMAP/POP and schedules permanent deletion after 30 days) — reuse it
		// instead of a plain label swap so trashing behaves like real Gmail.
		if ($targetFolder === 'TRASH') {
			return $this->deleteMessage($messageId);
		}

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$modifyRequest = new \Google\Service\Gmail\ModifyMessageRequest();
			$modifyRequest->setAddLabelIds([$targetFolder]);
			if (!empty($this->folder) && $this->folder !== $targetFolder) {
				$modifyRequest->setRemoveLabelIds([$this->folder]);
			}
			$gMailService->users_messages->modify('me', $messageId, $modifyRequest);
		} catch (\Exception $e) {
			$this->error = 'Gmail move failed: '.$e->getMessage();
			return false;
		}

		return true;
	}

	public function deleteMessage($messageId)
	{
		if (!$this->connected()) return false;

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$gMailService->users_messages->trash('me', $messageId);
		} catch (\Exception $e) {
			$this->error = 'Gmail trash failed: '.$e->getMessage();
			return false;
		}

		global $db;
		$db->query('UPDATE '.MAIN_DB_PREFIX."googleapi_email SET unread=0 WHERE message_id='".$db->escape($messageId)."'");
		return true;
	}

	/**
	 * Add/remove Gmail's UNREAD label and mirror the result into the local cache.
	 *
	 * @param  string $messageId
	 * @param  bool   $unread    true = mark unread (add UNREAD), false = mark read (remove UNREAD)
	 * @return bool
	 */
	private function setUnreadLabel($messageId, $unread)
	{
		if (!$this->connected()) return false;

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);
			$modifyRequest = new \Google\Service\Gmail\ModifyMessageRequest();
			if ($unread) {
				$modifyRequest->setAddLabelIds(['UNREAD']);
			} else {
				$modifyRequest->setRemoveLabelIds(['UNREAD']);
			}
			$gMailService->users_messages->modify('me', $messageId, $modifyRequest);
		} catch (\Exception $e) {
			$this->error = 'Gmail label update failed: '.$e->getMessage();
			return false;
		}

		global $db;
		$db->query('UPDATE '.MAIN_DB_PREFIX."googleapi_email SET unread=".($unread ? 1 : 0)." WHERE message_id='".$db->escape($messageId)."'");
		return true;
	}

	// ── Extended actions — not supported, see file docblock ───────────────────

	public function appendMessage($folder, $message)
	{
		return false;
	}

	public function setKeyword($messageId, $keyword, $color = null)
	{
		if (!$this->connected() || $keyword === '') return false;

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);

			$labelId = $this->findOrCreateLabelId($gMailService, $keyword, $color);
			if (!$labelId) return false;

			$modify = new \Google\Service\Gmail\ModifyMessageRequest();
			$modify->setAddLabelIds([$labelId]);
			$gMailService->users_messages->modify('me', $messageId, $modify);
			return true;
		} catch (\Exception $e) {
			$this->error = 'Failed to set label: '.$e->getMessage();
			return false;
		}
	}

	public function clearKeyword($messageId, $keyword)
	{
		if (!$this->connected() || $keyword === '') return false;

		try {
			$client = $this->client();
			$gMailService = new Google_Service_Gmail($client);

			$labelId = $this->findLabelId($gMailService, $keyword);
			if (!$labelId) return true; // nothing to remove, not an error

			$modify = new \Google\Service\Gmail\ModifyMessageRequest();
			$modify->setRemoveLabelIds([$labelId]);
			$gMailService->users_messages->modify('me', $messageId, $modify);
			return true;
		} catch (\Exception $e) {
			$this->error = 'Failed to clear label: '.$e->getMessage();
			return false;
		}
	}

	/**
	 * Looks the sender up in the connected user's Google Contacts (People
	 * API searchContacts — covered by the 'contacts' scope already granted,
	 * no extra consent needed) and returns their photo, if they have a real
	 * one uploaded.
	 *
	 * Google always returns *some* photo for a matched contact, even with no
	 * real one set — a generated monogram avatar (initial-on-a-colour-tile),
	 * flagged via Photo::getDefault(). That is no better than the initials
	 * circle the UI already shows, so those are skipped rather than treated
	 * as a hit. searchContacts's fuzzy matching can also return people whose
	 * *other* addresses matched, not $email itself, so results are filtered
	 * down to an exact email match first.
	 *
	 * @param  string $email
	 * @return string|false
	 */
	public function getSenderPhoto($email)
	{
		if (!$this->connected() || empty($email)) return false;

		try {
			$client = $this->client();
			if (!$client) return false;

			$people = new Google_Service_PeopleService($client);
			$response = $people->people->searchContacts([
				'query'    => $email,
				'readMask' => 'emailAddresses,photos',
			]);

			$photoUrl = null;
			foreach ((array) $response->getResults() as $result) {
				$person = $result->getPerson();
				$emails = array_map(function ($e) { return strtolower((string) $e->getValue()); }, (array) $person->getEmailAddresses());
				if (!in_array(strtolower($email), $emails, true)) continue;

				foreach ((array) $person->getPhotos() as $photo) {
					if ($photo->getDefault()) continue;
					$photoUrl = $photo->getUrl();
					break 2;
				}
			}
			if (!$photoUrl) return false;

			// The photo URL itself is a plain public CDN link (no OAuth needed
			// to fetch the bytes, only to have looked it up) — a normal GET,
			// per repo convention via getURLContent() rather than curl_*.
			$res = getURLContent($photoUrl, 'GET', '', 1, [], ['https']);
			if (empty($res['content']) || (int) ($res['http_code'] ?? 0) !== 200) return false;

			return $res['content'];
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Find a Gmail label's id by its exact display name.
	 *
	 * @param  Google_Service_Gmail $gMailService
	 * @param  string               $name
	 * @return string|null
	 */
	private function findLabelId($gMailService, $name)
	{
		$labels = $gMailService->users_labels->listUsersLabels('me')->getLabels();
		foreach ((array) $labels as $label) {
			if ($label->getName() === $name) {
				return $label->getId();
			}
		}
		return null;
	}

	/**
	 * Find a Gmail label's id by its exact display name, creating it
	 * (visible in the label list and on messages, like any label a user
	 * creates by hand) if it doesn't exist yet.
	 *
	 * @param  Google_Service_Gmail $gMailService
	 * @param  string               $name
	 * @param  string|null          $color   Tag color (#rrggbb), applied only
	 *                                       when the label is newly created —
	 *                                       Gmail has no per-message color, only
	 *                                       a color on the label itself.
	 * @return string|null
	 */
	private function findOrCreateLabelId($gMailService, $name, $color = null)
	{
		$existing = $this->findLabelId($gMailService, $name);
		if ($existing) return $existing;

		$label = new \Google\Service\Gmail\Label();
		$label->setName($name);
		$label->setLabelListVisibility('labelShow');
		$label->setMessageListVisibility('show');

		if ($color) {
			$background = $this->nearestGmailPaletteColor($color);
			$labelColor = new \Google\Service\Gmail\LabelColor();
			$labelColor->setBackgroundColor($background);
			$labelColor->setTextColor($this->contrastTextColor($background));
			$label->setColor($labelColor);
		}

		$created = $gMailService->users_labels->create('me', $label);
		return $created->getId();
	}

	/**
	 * Gmail only accepts backgroundColor/textColor from this fixed 96-color
	 * palette (confirmed live: an arbitrary hex is rejected with HTTP 400
	 * "Label color ... is not on the allowed color palette") — there is no
	 * free-form color support, so a tag's Dolibarr color is approximated by
	 * its nearest palette entry rather than used as-is.
	 *
	 * @var string[]
	 */
	private const GMAIL_LABEL_PALETTE = [
		'#000000', '#434343', '#666666', '#999999', '#cccccc', '#efefef', '#f3f3f3', '#ffffff',
		'#fb4c2f', '#ffad47', '#fad165', '#16a766', '#43d692', '#4a86e8', '#a479e2', '#f691b3',
		'#f6c5be', '#ffe6c7', '#fef1d1', '#b9e4d0', '#c6f3de', '#c9daf8', '#e4d7f5', '#fcdee8',
		'#efa093', '#ffd6a2', '#fce8b3', '#89d3b2', '#a0eac9', '#a4c2f4', '#d0bcf1', '#fbc8d9',
		'#e66550', '#ffbc6b', '#fcda83', '#44b984', '#68dfa9', '#6d9eeb', '#b694e8', '#f7a7c0',
		'#cc3a21', '#eaa041', '#f2c960', '#149e60', '#3dc789', '#3c78d8', '#8e63ce', '#e07798',
		'#ac2b16', '#cf8933', '#d5ae49', '#0b804b', '#2a9c68', '#285bac', '#653e9b', '#b65775',
		'#822111', '#a46a21', '#aa8831', '#076239', '#1a764d', '#1c4587', '#41236d', '#83334c',
		'#464646', '#e7e7e7', '#0d3472', '#b6cff5', '#0d3b44', '#98d7e4', '#3d188e', '#e3d7ff',
		'#711a36', '#fbd3e0', '#8a1c0a', '#f2b2a8', '#7a2e0b', '#ffc8af', '#7a4706', '#ffdeb5',
		'#594c05', '#fbe983', '#684e07', '#fdedc1', '#0b4f30', '#b3efd3', '#04502e', '#a2dcc1',
		'#c2c2c2', '#4986e7', '#2da2bb', '#b99aff', '#994a64', '#f691b2', '#ff7537', '#ffad46',
		'#662e37', '#ebdbde', '#cca6ac', '#094228', '#42d692', '#16a765',
	];

	/**
	 * @param  string $hex  Arbitrary #rrggbb color
	 * @return string       The closest color in GMAIL_LABEL_PALETTE, by RGB
	 *                      Euclidean distance
	 */
	private function nearestGmailPaletteColor($hex)
	{
		[$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

		$best = self::GMAIL_LABEL_PALETTE[0];
		$bestDistance = null;
		foreach (self::GMAIL_LABEL_PALETTE as $candidate) {
			[$cr, $cg, $cb] = sscanf($candidate, '#%02x%02x%02x');
			$distance = ($r - $cr) ** 2 + ($g - $cg) ** 2 + ($b - $cb) ** 2;
			if ($bestDistance === null || $distance < $bestDistance) {
				$bestDistance = $distance;
				$best = $candidate;
			}
		}
		return $best;
	}

	/**
	 * @param  string $hex  #rrggbb background color
	 * @return string       '#000000' or '#ffffff', whichever reads better on it
	 */
	private function contrastTextColor($hex)
	{
		[$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
		// Standard relative-luminance weighting (ITU-R BT.601)
		$luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
		return $luminance > 0.6 ? '#000000' : '#ffffff';
	}

	// ── Provider capabilities ─────────────────────────────────────────────────

	public function getType()
	{
		return 'googleapi';
	}

	public function supportsFolders()
	{
		return true;
	}

	public function supportsCompose()
	{
		// No Gmail-send implementation yet (would need MIME construction + a
		// users.messages.send call) — report false rather than let the UI
		// offer a reply/compose action that always fails. Real IMAP/SMTP and
		// WhatsApp accounts are unaffected.
		return false;
	}

	public function supportsThreads()
	{
		return true;
	}
}
