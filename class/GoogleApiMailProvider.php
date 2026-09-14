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
 * unifiedinbox account carries no credentials of its own: it points at a
 * Dolibarr user (UnifiedInboxAccount::$fk_user) whose Google account is
 * already connected through googleapi's own OAuth flow.
 *
 * Deliberately not implemented (return false, per the interface's own
 * documented convention for "Extended actions" providers don't support):
 * getAttachments(), getAttachmentData(), setKeyword(), clearKeyword(),
 * appendMessage(). The Gmail API could support all of these (message parts /
 * attachments, labels-as-keywords, users.messages.insert) but that's
 * follow-up work, not part of this feature — see
 * docs/superpowers/specs/2026-09-14-external-provider-hook-design.md in the
 * unifiedinbox repo, section "Non-goals".
 */

require_once DOL_DOCUMENT_ROOT.'/custom/unifiedinbox/class/UnifiedInboxProviderInterface.php';
require_once DOL_DOCUMENT_ROOT.'/custom/googleapi/class/googleapi.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/googleapi/lib/googleapi.lib.php';

class GoogleApiMailProvider implements UnifiedInboxProviderInterface
{
	/** @var User|null  Dolibarr user that owns the Google connection */
	private $fuser;
	/** @var string  Gmail label ID to scope getMessages()/getThreadedMessages() to, set by connect() */
	private $folder = 'INBOX';
	/** @var string */
	private $error = '';

	// ── Connection lifecycle ──────────────────────────────────────────────────

	public function connect(UnifiedInboxAccount $account, $folder = 'INBOX')
	{
		global $db;

		if (empty($account->fk_user)) {
			$this->error = 'No Dolibarr user configured for this Gmail account';
			return false;
		}

		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
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
		if (!$this->fuser) return [];

		try {
			$client = getGoogleApiClient($this->fuser);
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
		if (!$this->fuser) return 0;

		try {
			$client = getGoogleApiClient($this->fuser);
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
		if (!$this->fuser) return false;

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
				$batch = getGoogleMailMessages($query, min(50, $limitNb - $totalSeen), $pageToken, $this->fuser, labelIds: [$this->folder]);
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
			$client = getGoogleApiClient($this->fuser);
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
		if (!$this->fuser) return false;

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
		$item->cc = '';
		$item->from = $row->email_from;
		$item->to = $row->email_to;
		$item->subject = $row->subject ?: '(no subject)';
		$item->has_attachments = 0;
		return $item;
	}

	// ── Message detail (implemented in Task 7) ────────────────────────────────

	public function getMessageBody($messageId)
	{
		if (!$this->fuser) return false;

		try {
			$result = getGoogleMailMessageAndBody($messageId, $this->fuser);
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
		if (!$this->fuser) return [];

		try {
			$client = getGoogleApiClient($this->fuser);
			$gMailService = new Google_Service_Gmail($client);
			$message = $gMailService->users_messages->get('me', $messageId, [
				'format' => 'metadata',
				'metadataHeaders' => ['Message-Id', 'In-Reply-To', 'References'],
			]);
		} catch (\Exception $e) {
			$this->error = 'Failed to load message headers: '.$e->getMessage();
			return [];
		}

		$headers = [];
		foreach ($message->getPayload()->getHeaders() as $header) {
			$name = strtolower($header->getName());
			if ($name === 'message-id') {
				$headers['message_id'] = $header->getValue();
			} elseif ($name === 'in-reply-to') {
				$headers['in_reply_to'] = $header->getValue();
			} elseif ($name === 'references') {
				$headers['references'] = $header->getValue();
			}
		}
		return $headers;
	}

	public function getAttachments($messageId)
	{
		return [];
	}

	public function getAttachmentData($messageId, $partNo, $encoding)
	{
		return false;
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
		if (!$this->fuser) return false;

		// Gmail's Trash is a first-class action (also excludes the message from
		// IMAP/POP and schedules permanent deletion after 30 days) — reuse it
		// instead of a plain label swap so trashing behaves like real Gmail.
		if ($targetFolder === 'TRASH') {
			return $this->deleteMessage($messageId);
		}

		try {
			$client = getGoogleApiClient($this->fuser);
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
		if (!$this->fuser) return false;

		try {
			$client = getGoogleApiClient($this->fuser);
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
		if (!$this->fuser) return false;

		try {
			$client = getGoogleApiClient($this->fuser);
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

	public function setKeyword($messageId, $keyword)
	{
		return false;
	}

	public function clearKeyword($messageId, $keyword)
	{
		return false;
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
