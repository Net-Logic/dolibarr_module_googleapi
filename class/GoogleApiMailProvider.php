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
		return false;
	}

	public function getThreadedMessages($limitDays, $offset, $pageSize)
	{
		return false;
	}

	// ── Message detail (implemented in Task 7) ────────────────────────────────

	public function getMessageBody($messageId)
	{
		return false;
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
		return false;
	}

	public function markUnseen($messageId)
	{
		return false;
	}

	public function moveMessage($messageId, $targetFolder)
	{
		return false;
	}

	public function deleteMessage($messageId)
	{
		return false;
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
