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
 * getAttachments(), getAttachmentData(), moveMessage(), setKeyword(),
 * clearKeyword(), appendMessage(). The Gmail API could support all of these
 * (message parts / attachments, labels-as-folders-and-keywords,
 * users.messages.insert) but that's follow-up work, not part of this
 * feature — see docs/superpowers/specs/2026-09-14-external-provider-hook-design.md
 * in the unifiedinbox repo, section "Non-goals".
 */

require_once DOL_DOCUMENT_ROOT.'/custom/unifiedinbox/class/UnifiedInboxProviderInterface.php';
require_once DOL_DOCUMENT_ROOT.'/custom/googleapi/class/googleapi.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/googleapi/lib/googleapi.lib.php';

class GoogleApiMailProvider implements UnifiedInboxProviderInterface
{
	/** @var User|null  Dolibarr user that owns the Google connection */
	private $fuser;
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

	public function getFolders()
	{
		return [
			[
				'name'   => 'INBOX',
				'label'  => 'Gmail',
				'unseen' => $this->getUnseenCount(),
			],
		];
	}

	public function getUnseenCount($folder = 'INBOX')
	{
		global $db;

		if (!$this->fuser) return 0;

		$sql = 'SELECT unread FROM '.MAIN_DB_PREFIX.'googleapi_mailboxes WHERE userid='.(int) $this->fuser->id;
		$res = $db->query($sql);
		if (!$res || !($obj = $db->fetch_object($res))) return 0;
		return (int) $obj->unread;
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
		$totalSeen = 0;
		$query = ['newer_than:'.(int) $limitDays.'d'];

		try {
			do {
				$batch = getGoogleMailMessages($query, min(50, $limitNb - $totalSeen), $pageToken, $this->fuser);
				if (empty($batch)) break;
				foreach ($batch as $row) {
					$totalSeen++;
					if ($skipped < $offset) {
						$skipped++;
						continue;
					}
					if (count($collected) < $pageSize) {
						$collected[] = $this->rowToMessage($row);
					}
				}
			} while ($pageToken && $totalSeen < $limitNb && count($collected) < $pageSize);
		} catch (\Exception $e) {
			$this->error = 'Failed to list messages: '.$e->getMessage();
			return false;
		}

		return [
			'messages' => $collected,
			'total'    => $totalSeen,
			'has_more' => (bool) $pageToken,
		];
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

		return ['html' => $result['body']['html'], 'plain' => $result['body']['plain']];
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
		return false;
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
		return true;
	}

	public function supportsThreads()
	{
		return true;
	}
}
