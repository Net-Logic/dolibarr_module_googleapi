<?php
/* Copyright (C) 2019-2022  Frédéric France         <frederic.france@netlogic.fr>
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


/**
 * GoogleApi
 */
class GoogleApi
{
	/**
	 * @var string output
	 */
	public $output;

	public $watchResp = '';

	public $error;

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

		if (!empty($conf->global->OAUTH_GOOGLEAPI_ID) && !empty($conf->global->OAUTH_GOOGLEAPI_SECRET)) {
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
					if (!empty($conf->global->GOOGLEAPI_ENABLE_PUSH_ME_EVENTS)) {
						$this->chekValidPushNotificationFor($staticuser, 'events', $urlfornotification);
						$pushactive++;
					}
					// if (! empty($conf->global->GOOGLEAPI_ENABLE_PUSH_ME_MESSAGES)) {
					// 	$this->chekValidPushNotificationFor($staticuser, 'messages', $urlfornotification);
					// 	$pushactive++;
					// }
					// if (! empty($conf->global->GOOGLEAPI_ENABLE_PUSH_ME_CONTACTS)) {
					// 	$this->chekValidPushNotificationFor($staticuser, 'contacts', $urlfornotification);
					// 	$pushactive++;
					// }
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
	 * @param   string  $type               type
	 * @param   string  $urlfornotification url
	 * @return int
	 */
	private function chekValidPushNotificationFor($user, $type, $urlfornotification)
	{

		global $conf, $db;

		$now = dol_now();
		require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
		dol_include_once('/googleapi/lib/googleapi.lib.php');
		dol_include_once('/prune/vendor/autoload.php');
		$client = getGoogleApiClient($user);
		$service = new Google\Service\Calendar($client);

		$sql = "SELECT rowid, userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber FROM " . MAIN_DB_PREFIX . "googleapi_watchs";
		$sql .= ' WHERE userid=' . (int) $user->id . ' AND resourcetype="' . $this->db->escape($type) . '"';

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
					$watch = $service->events->watch('primary', $channel);

					$sql = "INSERT INTO " . MAIN_DB_PREFIX . "googleapi_watchs";
					$sql .= " (userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber) VALUES(";
					$sql .= " " . (int) $user->id;
					$sql .= ", '" . $this->db->escape($uuid) . "'";
					$sql .= ", '" . $this->db->escape($watch->getId()) . "'";
					$sql .= ", '" . $this->db->escape($type) . "'";
					$sql .= ", '" . $this->db->escape($watch->getResourceUri()) . "'";
					$sql .= ", '" . $this->db->escape($watch->getResourceId()) . "'";
					//$sql .= ", '" . ($watch->getExpiration())->format('Y-m-d H:i:s') . "'";
					$sql .= ", '" . ($this->db->idate(substr($watch->getExpiration(), 0, -3))) . "'";
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
				$watch = $service->events->watch('primary', $channel);

				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "googleapi_watchs";
				$sql .= " (userid, uuid, id, resourcetype, resourceUri, ressourceId, expirationDateTime, lastmessagenumber) VALUES(";
				$sql .= " " . (int) $user->id;
				$sql .= ", '" . $this->db->escape($uuid) . "'";
				$sql .= ", '" . $this->db->escape($watch->getId()) . "'";
				$sql .= ", '" . $this->db->escape($type) . "'";
				$sql .= ", '" . $this->db->escape($watch->getResourceUri()) . "'";
				$sql .= ", '" . $this->db->escape($watch->getResourceId()) . "'";
				//$sql .= ", '" . ($watch->getExpiration())->format('Y-m-d H:i:s') . "'";
				// timestamp in ms
				$sql .= ", '" . ($this->db->idate(substr($watch->getExpiration(), 0, -3))) . "'";
				$sql .= ", '1')";

				$resql = $this->db->query($sql);
			} catch (Exception $e) {
				dol_syslog($e->getmessage(), LOG_ERR);
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
		try {
			$data = random_bytes(16);
		} catch (Exception $e) {
			// empty catch if not enough entropy
		}
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
