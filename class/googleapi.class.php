<?php
/*
 * Copyright (C) 2019-2025  Frédéric France         <frederic.france@netlogic.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  \file       htdocs/googleapi/class/googleapi.class.php
 *  \ingroup    googleapi
 *  \brief      class GoogleApi
 */

// phpcs:disable
/**
 * GoogleApi
 */
class GoogleApi
{
	// phpcs:enable
	/**
	 * @var string output
	 */
	public $output;

	public $watchResp = '';

	/**
	 * @var string
	 */
	public $error;

	/**
	 * @var string[]
	 */
	public $errors = [];

	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Function to check expired shedule watch
	 * @return int
	 */
	public function checkExpiredSheduledWatch()
	{
		global $conf, $db;
		global $dolibarr_main_url_root;

		if (!is_object($this->db)) {
			$this->db = $db;
		}

		$this->output = "";

		$outputlangs = new Translate('', $conf);
		$outputlangs->setDefaultLang("fr_FR");
		$outputlangs->load("googleapi@googleapi");
		// Define $urlwithroot
		$urlwithouturlroot = preg_replace('/' . preg_quote(DOL_URL_ROOT, '/') . '$/i', '', trim($dolibarr_main_url_root));
		$urlwithouturlroot = str_replace('http://', 'https://', $urlwithouturlroot);
		$urlfornotification = $urlwithouturlroot . dol_buildpath('/googleapi/notifications.php', 1);

		$pushactive = 0;

		if (getDolGlobalString('OAUTH_GOOGLEAPI_SECRET')) {
			$sql = "SELECT u.rowid as uid, u.login FROM " . MAIN_DB_PREFIX . "user AS u";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "prune_oauth_token AS ot ON ot.fk_user=u.rowid";
			$sql .= " WHERE u.statut=1 AND ot.service='GoogleApi'";
			//print $sql;exit;
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($row = $this->db->fetch_object($resql)) {
					$staticuser = new User($this->db);
					$staticuser->fetch($row->uid);
					// we check for user which have a token
					if (getDolGlobalInt('GOOGLEAPI_ENABLE_PUSH_ME_EVENTS')) {
						$this->chekValidPushNotificationForEvents($staticuser, $urlfornotification);
						$pushactive++;
					}
					// if (! empty($conf->global->GOOGLEAPI_ENABLE_PUSH_ME_MESSAGES)) {
					// 	$this->chekValidPushNotificationFor($staticuser, 'messages', $urlfornotification);
					// 	$pushactive++;
					// }
					if (getDolGlobalInt('GOOGLEAPI_ENABLE_PUSH_ME_CONTACTS')) {
						$this->chekValidPushNotificationForContacts($staticuser, $urlfornotification);
						$pushactive++;
					}
				}
			}
		}
		if ($pushactive > 0) {
			$this->output .= $pushactive . " notification(s) push(s) active(s).";
		} else {
			$this->output .= $outputlangs->trans("GoogleApiCronNothingDone");
		}
		return 0;
	}

	/**
	 * Function to check if push is valid for user
	 *
	 * @param   User    $user             user id
	 * @param   string  $urlfornotification url
	 * @return int
	 */
	private function chekValidPushNotificationForEvents($user, $urlfornotification)
	{
		$now = dol_now();
		require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
		dol_include_once('/googleapi/lib/googleapi.lib.php');
		dol_include_once('/prune/vendor/autoload.php');
		$client = getGoogleApiClient($user);
		$service = new Google\Service\Calendar($client);
		$calendarId = $user->array_options['options_googleapi_calendarId'] ?: 'primary';

		$sql = "SELECT rowid, userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber FROM " . MAIN_DB_PREFIX . "googleapi_watchs";
		$sql .= ' WHERE userid=' . (int) $user->id . ' AND resourcetype="events"';

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			// on a déjà quelquechose
			$row = $this->db->fetch_object($resql);

			// is it going to expire in 30min
			// expiration is gmt
			$expiration = dol_stringtotime($row->expirationDateTime, 1);
			// check with cron
			if (($expiration - $now - (13 * 3600)) < 0) {
				// expired
				$this->output .= 'push expired ';
				// create a new one which may overlap
				$uuid = $this->getUuid();

				try {
					$channelId = $uuid;
					$channel = new Google\Service\Calendar\Channel($client);
					$channel->setId($channelId);
					$channel->setType('web_hook');
					$channel->setAddress($urlfornotification);
					$watch = $service->events->watch($calendarId, $channel);

					$sql = "INSERT INTO " . MAIN_DB_PREFIX . "googleapi_watchs";
					$sql .= " (userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber) VALUES(";
					$sql .= " " . (int) $user->id;
					$sql .= ", '" . $this->db->escape($uuid) . "'";
					$sql .= ", '" . $this->db->escape($watch->getId()) . "'";
					$sql .= ", 'events'";
					$sql .= ", '" . $this->db->escape($watch->getResourceUri()) . "'";
					$sql .= ", '" . $this->db->escape($watch->getResourceId()) . "'";
					//$sql .= ", '" . ($watch->getExpiration())->format('Y-m-d H:i:s') . "'";
					$sql .= ", '" . ($this->db->idate(substr($watch->getExpiration(), 0, -3))) . "'";
					$sql .= ", '1')";
					$resql = $this->db->query($sql);
					dol_syslog(get_class($this) . ' ' . $sql, LOG_NOTICE);
				} catch (Exception $e) {
					dol_syslog($e->getmessage(), LOG_ERR);
				}
				// delete the old one from db
				$sql = "DELETE FROM " . MAIN_DB_PREFIX . "googleapi_watchs ";
				$sql .= " WHERE rowid=" . (int) $row->rowid;
				$this->db->query($sql);
				dol_syslog(get_class($this) . ' ' . $sql, LOG_NOTICE);
				$this->output .= 'New Active ' . ($expiration - $now) . ' sec, ';
				//print $sql;exit;
			} else {
				// active
				$this->output .= 'Active ' . ($expiration - $now) . ' sec, ';
			}
		} else {
			$uuid = $this->getUuid();

			try {
				$channelId = $uuid;
				$channel = new Google\Service\Calendar\Channel($client);
				$channel->setId($channelId);
				$channel->setType('web_hook');
				$channel->setAddress($urlfornotification);
				$watch = $service->events->watch($calendarId, $channel);

				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "googleapi_watchs";
				$sql .= " (userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber) VALUES(";
				$sql .= " " . (int) $user->id;
				$sql .= ", '" . $this->db->escape($uuid) . "'";
				$sql .= ", '" . $this->db->escape($watch->getId()) . "'";
				$sql .= ", 'events'";
				$sql .= ", '" . $this->db->escape($watch->getResourceUri()) . "'";
				$sql .= ", '" . $this->db->escape($watch->getResourceId()) . "'";
				//$sql .= ", '" . ($watch->getExpiration())->format('Y-m-d H:i:s') . "'";
				// timestamp in ms
				$sql .= ", '" . ($this->db->idate(substr($watch->getExpiration(), 0, -3))) . "'";
				$sql .= ", '1')";

				$resql = $this->db->query($sql);
			} catch (Exception $e) {
				dol_syslog($e->getmessage(), LOG_ERR);
				return -1;
			}
			//exit;
		}
		return 0;
	}

	/**
	 * Function to check if push is valid for user
	 *
	 * @param   User    $user             user id
	 * @param   string  $urlfornotification url
	 * @return int
	 */
	private function chekValidPushNotificationForContacts($user, $urlfornotification)
	{
		$now = dol_now();
		require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
		dol_include_once('/googleapi/lib/googleapi.lib.php');
		dol_include_once('/prune/vendor/autoload.php');
		$client = getGoogleApiClient($user);
		$service = new Google\Service\WorkspaceEvents($client);

		$sql = "SELECT rowid, userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber FROM " . MAIN_DB_PREFIX . "googleapi_watchs";
		$sql .= ' WHERE userid=' . (int) $user->id . ' AND resourcetype="contacts"';

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			// on a déjà quelquechose
			$row = $this->db->fetch_object($resql);

			// is it going to expire in 30min
			// expiration is gmt
			$expiration = dol_stringtotime($row->expirationDateTime, 1);
			// check with cron
			if (($expiration - $now - (13 * 3600)) < 0) {
				// expired
				$this->output .= 'push expired ';
				// create a new one which may overlap
				$uuid = $this->getUuid();

				try {
					$channelId = $uuid;
					$channel = new Google\Service\WorkspaceEvents\Subscription();
					$channel->setEventTypes(['google.workspace.people.contact.v1.modified']);
					$channel->setNotificationEndpoint($urlfornotification);
					$channel->setPayloadOptions(['includeResource' => true]);
					$watch = $service->subscriptions->create($channel);

					$sql = "INSERT INTO " . MAIN_DB_PREFIX . "googleapi_watchs";
					$sql .= " (userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber) VALUES(";
					$sql .= " " . (int) $user->id;
					$sql .= ", '" . $this->db->escape($uuid) . "'";
					$sql .= ", '" . $this->db->escape($watch->getName()) . "'";
					$sql .= ", 'contacts'";
					$sql .= ", ''";
					$sql .= ", ''";
					//$sql .= ", '" . ($watch->getExpiration())->format('Y-m-d H:i:s') . "'";
					$sql .= ", '" . ($this->db->idate(substr($watch->getExpirationTime(), 0, -3))) . "'";
					$sql .= ", '1')";
					$resql = $this->db->query($sql);
					dol_syslog(get_class($this) . ' ' . $sql, LOG_NOTICE);
					//var_dump($this->watchResp);print $sql;exit;
				} catch (Exception $e) {
					dol_syslog($e->getmessage(), LOG_ERR);
				}
				// delete the old one from db
				$sql = "DELETE FROM " . MAIN_DB_PREFIX . "googleapi_watchs ";
				$sql .= " WHERE rowid=" . (int) $row->rowid;
				$this->db->query($sql);
				dol_syslog(get_class($this) . ' ' . $sql, LOG_NOTICE);
				$this->output .= 'New Active ' . ($expiration - $now) . ' sec, ';
				//print $sql;exit;
			} else {
				// active
				$this->output .= 'Active ' . ($expiration - $now) . ' sec, ';
			}
		} else {
			$uuid = $this->getUuid();

			try {
				$channelId = $uuid;
				$channel = new Google\Service\Calendar\Channel($client);
				$channel->setId($channelId);
				$channel->setType('web_hook');
				$channel->setAddress($urlfornotification);
				$calendarId = $user->array_options['options_googleapi_calendarId'] ?? 'primary';
				$watch = $service->peoples->watch($calendarId, $channel);

				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "googleapi_watchs";
				$sql .= " (userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber) VALUES(";
				$sql .= " " . (int) $user->id;
				$sql .= ", '" . $this->db->escape($uuid) . "'";
				$sql .= ", '" . $this->db->escape($watch->getId()) . "'";
				$sql .= ", 'contacts'";
				$sql .= ", '" . $this->db->escape($watch->getResourceUri()) . "'";
				$sql .= ", '" . $this->db->escape($watch->getResourceId()) . "'";
				// $sql .= ", '" . ($watch->getExpiration())->format('Y-m-d H:i:s') . "'";
				// timestamp in ms
				$sql .= ", '" . ($this->db->idate(substr($watch->getExpiration(), 0, -3))) . "'";
				$sql .= ", '1')";

				$resql = $this->db->query($sql);
			} catch (Exception $e) {
				dol_syslog($e->getmessage(), LOG_ERR);
				return -1;
			}
			//exit;
		}

		return 0;
	}

	/**
	 * generate uuid
	 * @return string
	 */
	private function getUuid()
	{
		$data = '0123456789012345';
		try {
			$data = random_bytes(16);
		} catch (Exception $e) {
			// empty catch if not enough entropy
		}
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * @param \Google\Service\Gmail\Message $message
	 * @param User $user
	 * @param ?string $object_type
	 * @param ?int $object_id
	 * @return GoogleApiGMailMessage
	 *
	 * @throws Exception
	 */
	public function saveGoogleServiceGmailMessage(\Google\Service\Gmail\Message $message, User $user, string $object_type = null, ?int $object_id = null): GoogleApiGMailMessage
	{
		$headers = $message->getPayload()->getHeaders();

		$subject = $from = $to = $date = '';
		foreach ($headers as $header) {
			if (in_array($header->getName(), ['Subject', 'From', 'To', 'Date'])) {
				$headerName = mb_strtolower($header->getName());
				$$headerName = $header->getValue();
			}
		}

		if (!$from || !$to || !$date) {
			throw new Exception('Gmail\Message is missing mandatory data');
		}

		// Date is ISO 8601
		$date = strtotime($date);

		$googleApiGMailMessage = new GoogleApiGMailMessage();
		$googleApiGMailMessage->date = $date;
		$googleApiGMailMessage->email_from = $from;
		$googleApiGMailMessage->email_to = $to;
		$googleApiGMailMessage->subject = $subject;
		$googleApiGMailMessage->snippet = $message->getSnippet();
		$googleApiGMailMessage->message_id = $message->getId();
		$googleApiGMailMessage->object_type = $object_type;
		$googleApiGMailMessage->object_id = $object_id;
		$googleApiGMailMessage->outgoing = (int) in_array('SENT', $message->getLabelIds());
		$googleApiGMailMessage->fk_user = $user->id;
		return $googleApiGMailMessage->save();
	}

	/**
	 * @param string $messageId
	 * @return GoogleApiGMailMessage
	 */
	public function fetchGoogleApiGMailMessage(string $messageId): GoogleApiGMailMessage
	{
		$message = new GoogleApiGMailMessage();
		$row = $this->db->getRow("SELECT * FROM llx_googleapi_email WHERE message_id = '{$messageId}'");
		if (is_object($row)) {
			$row->date = $this->db->jdate($row->date);
			$message->populate($row);
		}

		return $message;
	}
}

/**
 * Not Dolibarresque class
 */
class GoogleApiGMailMessage
{
	public $db;

	public $rowid;
	public $date;
	public $email_from;
	public $email_to;
	public $outgoing;
	public $subject;
	public $snippet;
	public $object_type;
	public $object_id;
	public $message_id;
	public $fk_user;
	public $tms;

	// Not saved
	public $unread;

	public function __construct()
	{
		global $db;

		$this->db = $db;
	}

	/**
	 * Populate object with stdClass data from db
	 */
	public function populate(stdClass $obj): void
	{
		foreach ((array) $obj as $property => $value) {
			if (property_exists($this, $property)) {
				$this->$property = $value;
			}
		}
	}

	/**
	 * @param int $id
	 * @return GoogleApiGMailMessage
	 */
	public function fetch(int $id): self
	{
		$row = $this->db->getRow("SELECT * FROM llx_googleapi_email WHERE rowid = {$id}");
		if (is_object($row)) {
			$row->date = $this->db->jdate($row->date);
			$this->populate($row);
		}

		return $this;
	}

	static public function fetchInstance(int $id)
	{
		global $db;
		$instance = new self($db);
		return $instance->fetch($id);
	}

	/**
	 * @return $this
	 * @throws Exception
	 */
	public function save(): self
	{

		$object_type = $this->object_type ? "'{$this->object_type}'" : 'NULL';
		$object_id = $this->object_id ? (int) $this->object_id : 'NULL';

		if ($this->rowid) {
			$sql = "UPDATE {$this->db->prefix()}googleapi_email SET date = '{$this->db->idate($this->date)}',
					email_from = '{$this->db->escape($this->email_from)}', email_to = '{$this->db->escape($this->email_to)}',
					subject = '{$this->db->escape($this->subject)}', snippet = '{$this->db->escape($this->snippet)}',
					outgoing = {$this->db->escape($this->outgoing)}, object_type = {$object_type}, object_id = {$object_id},
					message_id = '{$this->message_id}', fk_user = {$this->fk_user} WHERE rowid = {$this->rowid}";
		} else {
			$sql = "INSERT INTO {$this->db->prefix()}googleapi_email (date, email_from, email_to, outgoing, subject, snippet, object_type, object_id, message_id, fk_user)
				VALUE ('{$this->db->idate($this->date)}', '{$this->db->escape($this->email_from)}', '{$this->db->escape($this->email_to)}',
				       {$this->db->escape($this->outgoing)}, '{$this->db->escape($this->subject)}',
				      '{$this->db->escape($this->snippet)}', $object_type, $object_id, '{$this->message_id}', {$this->fk_user})";
		}

		if (!$this->db->query($sql)) {
			throw new Exception("DB ERROR: {$this->db->lasterror()}");
		}
		$this->rowid = $this->db->last_insert_id("{$this->db->prefix()}googleapi_email");
		return $this;
	}
}
