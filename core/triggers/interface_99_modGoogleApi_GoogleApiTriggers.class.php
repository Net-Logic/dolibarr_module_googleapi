<?php
/* Copyright (C) 2019-2025  Frédéric France         <frederic.france@netlogic.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * \file    core/triggers/interface_99_modGoogleApi_GoogleApiTriggers.class.php
 * \ingroup googleapi
 * \brief   GoogleApi trigger.
 *
 * Put detailed description here.
 *
 * \remarks You can create other triggers by copying this one.
 * - File name should be either:
 *      - interface_99_modGoogleApi_MyTrigger.class.php
 *      - interface_99_all_MyTrigger.class.php
 * - The file must stay in core/triggers
 * - The class name must be InterfaceMytrigger
 * - The constructor method must be named InterfaceMytrigger
 * - The name property name must be MyTrigger
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
dol_include_once('/googleapi/lib/googleapi.lib.php');
dol_include_once('/prune/lib/prune.lib.php');
dol_include_once('/prune/vendor/autoload.php');

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Grant\RefreshToken;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;


/**
 *  Class of triggers for GoogleApi module
 */
class InterfaceGoogleApiTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "Net-Logic";
		$this->description = "GoogleApi triggers.";
		// 'development', 'experimental', 'dolibarr' or version
		$this->version = 'dolibarr';
		$this->picto = 'googleapi@googleapi';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}


	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string        $action     Event action code
	 * @param CommonObject  $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (empty($conf->googleapi->enabled)) {
			// Module not active, we do nothing
			return 0;
		}

		if (get_class($object) == 'ActionComm' && empty($conf->global->GOOGLEAPI_INCLUDE_AUTO_EVENT) && $object->type_code == 'AC_OTH_AUTO') {
			// we don't want to pollute calendar with auto events
			return 0;
		}
		if (get_class($object) == 'ActionComm' && !empty($object->context['googleapi'])) {
			// object comes from api googleapi
			return 0;
		}
		if (get_class($object) == 'Contact' && !empty($object->context['googleapi'])) {
			// object comes from api googleapi
			return 0;
		}

		// You can isolate code for each action in a separate method: this method should be named like the trigger in camelCase.
		// For example : COMPANY_CREATE => public function companyCreate($action, $object, User $user, Translate $langs, Conf $conf)
		$methodName = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($action)))));
		// setEventMessage($methodName);
		$callback = array($this, $methodName);
		if (is_callable($callback)) {
			dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id, LOG_INFO);
			return call_user_func($callback, $action, $object, $user, $langs, $conf);
		};

		return 0;
	}

	/**
	 * Trigger ACTION_CREATE
	 * @param string        $action     Event action code
	 * @param ActionComm  $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function actionCreate($action, ActionComm $object, User $user, Translate $langs, Conf $conf)
	{
		$error = 0;
		// does user have valid token
		// il faut rechercher le token du propriétaire de l'evt
		$staticuser = new User($this->db);
		$staticuser->fetch($object->userownerid);

		$client = getGoogleApiClient($staticuser);
		if ($client === false) {
			return 0;
		}

		googleapi_complete_label_and_note($object, $langs);
		if (empty($object->datef)) {
			$dateend = $object->datep;
		} else {
			$dateend = $object->datef;
		}
		//'DateTime' => '2017-04-03T10:00:00',
		//'DateTime' => '2019-09-14T22:02:34Z'
		//'TimeZone' => 'Pacific Standard Time',
		if ($object->fulldayevent) {
			$start = [
				'date' => dol_print_date($object->datep + 60, '%Y-%m-%d'),
				'timeZone' => 'Europe/Paris',
			];
			$end = [
				'date' => dol_print_date($dateend + 60, '%Y-%m-%d'),
				'timeZone' => 'Europe/Paris',
			];
		} else {
			$start = [
				'dateTime' => dol_print_date($object->datep, '%Y-%m-%dT%H:%M:%S'),
				'timeZone' => 'UTC',
			];
			$end = [
				'dateTime' => dol_print_date($dateend, '%Y-%m-%dT%H:%M:%S'),
				'timeZone' => 'UTC',
			];
		}
		dol_syslog('Sending new dates to googleapi ' . $object->datep, LOG_NOTICE);
		$event = new \Google\Service\Calendar\Event([
			'summary' => $object->label,
			'location' => $object->location,
			'description' => $object->note,
			'start' => $start,
			'end' => $end,
			'recurrence' => array(
				//'RRULE:FREQ=DAILY;COUNT=2'
			),
			'attendees' => array(
				// array('email' => 'lpage@example.com'),
				// array('email' => 'sbrin@example.com'),
			),
			'reminders' => array(
				// 'useDefault' => false,
				// 'overrides' => array(
				// 	array('method' => 'email', 'minutes' => 24 * 60),
				// 	array('method' => 'popup', 'minutes' => 10),
				// ),
			),
		]);

		if (empty($staticuser->array_options['options_googleapi_calendarId'])) {
			$calendarId = 'primary';
		} else {
			$calendarId = $staticuser->array_options['options_googleapi_calendarId'];
		}
		$service = new \Google\Service\Calendar($client);
		$event = $service->events->insert($calendarId, $event);

		// enregistrer l'id googleapi dans dolibarr (extrafield)
		$object->array_options['options_googleapi_EventId'] = $event->getId();
		$object->update($user, 1);

		return (!$error ? 0 : -1);
	}

	/**
	 * Trigger ACTION_MODIFY
	 * @param string        $action     Event action code
	 * @param ActionComm    $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function actionModify($action, ActionComm $object, User $user, Translate $langs, Conf $conf)
	{
		$error = 0;
		// il faut rechercher le token du propriétaire de l'evt
		$staticuser = new User($this->db);
		$staticuser->fetch($object->userownerid);
		// on complete ou pas?

		$client = getGoogleApiClient($staticuser);
		if ($client === false) {
			return 0;
		}
		$overrides = [];
		// quand on a actionModify les reminders ne sont pas à jour en db...
		// il faut utiliser actioncommreminderCreate car ils sont supprimés en db et recéés
		$object->loadReminders('', 0, false);
		if (is_array($object->reminders) && count($object->reminders)) {
			foreach ($object->reminders as $reminder) {
				$mul = 1;
				// var_dump($reminder->offsetvalue);
				// var_dump($reminder->offsetunit);
				if ($reminder->offsetunit == 'h') {
					$mul = 60;
				} elseif ($reminder->offsetunit == 'd') {
					$mul = 24 * 60;
				} elseif ($reminder->offsetunit == 'w') {
					$mul = 7 * 24 * 60;
				}
				$offsetInMinutes = $mul * (int) $reminder->offsetvalue;
				if ($reminder->typeremind == 'googleapiremindemail') {
					$overrides[] = [
						'method' => 'email',
						'minutes' => $offsetInMinutes,
					];
				}
				if ($reminder->typeremind == 'googleapiremindnotif') {
					$overrides[] = [
						'method' => 'popup',
						'minutes' => $offsetInMinutes,
					];
				}
			}
		}
		if (empty($object->array_options['options_googleapi_EventId'])) {
			googleapi_complete_label_and_note($object, $langs);
		}
		if (empty($object->datef)) {
			$dateend = $object->datep;
		} else {
			$dateend = $object->datef;
		}
		// 'DateTime' => '2017-04-03T10:00:00',
		// 'DateTime' => '2019-09-14T22:02:34Z'
		// 'TimeZone' => 'Pacific Standard Time',
		// $datestart = $object->fulldayevent ? dol_print_date($object->datep + 60, '%Y-%m-%d') : dol_print_date($object->datep, '%Y-%m-%dT%H:%M:%S');
		// $dateend = $object->fulldayevent ? dol_print_date($dateend + 60, '%Y-%m-%d') : dol_print_date($dateend, '%Y-%m-%dT%H:%M:%S');
		if ($object->fulldayevent) {
			$start = [
				'date' => dol_print_date($object->datep + 60, '%Y-%m-%d'),
				'timeZone' => 'Europe/Paris',
			];
			$end = [
				'date' => dol_print_date($dateend + 60, '%Y-%m-%d'),
				'timeZone' => 'Europe/Paris',
			];
		} else {
			$start = [
				'dateTime' => dol_print_date($object->datep, '%Y-%m-%dT%H:%M:%S'),
				'timeZone' => 'UTC',
			];
			$end = [
				'dateTime' => dol_print_date($dateend, '%Y-%m-%dT%H:%M:%S'),
				'timeZone' => 'UTC',
			];
		}
		// var_dump($overrides);
		dol_syslog('Sending new dates to googleapi ' . $object->datep, LOG_NOTICE);
		$event = new \Google\Service\Calendar\Event([
			'summary' => $object->label,
			'location' => $object->location,
			'description' => $object->note,
			'start' => $start,
			'end' => $end,
			'recurrence' => [
				//'RRULE:FREQ=DAILY;COUNT=2'
			],
			'attendees' => [
				// ['email' => 'lpage@example.com'],
				// ['email' => 'sbrin@example.com'],
			],
			'reminders' => [
				'useDefault' => false,
				'overrides' => $overrides,
			],
		]);

		if (empty($staticuser->array_options['options_googleapi_calendarId'])) {
			$calendarId = 'primary';
		} else {
			$calendarId = $staticuser->array_options['options_googleapi_calendarId'];
		}
		$service = new \Google\Service\Calendar($client);
		if (empty($object->array_options['options_googleapi_EventId'])) {
			$event = $service->events->insert($calendarId, $event);
			// // enregistrer l'id googleapi dans dolibarr (extrafield)
			$object->fetch_optionals();
			$object->array_options['options_googleapi_EventId'] = $event->getId();
			$object->update($user, 1);
		} else {
			$event = $service->events->update($calendarId, $object->array_options['options_googleapi_EventId'], $event);
		}
		return (!$error ? 0 : -1);
	}

	/**
	 * Trigger ACTION_DELETE
	 * @param string        $action     Event action code
	 * @param ActionComm  $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function actionDelete($action, ActionComm $object, User $user, Translate $langs, Conf $conf)
	{
		$error = 0;
		// si on a un id dans l'ancienne copie de l'évènement
		if (!empty($object->oldcopy->array_options['options_googleapi_EventId'])) {
			// il faut rechercher le token du propriétaire de l'evt
			$staticuser = new User($this->db);
			$staticuser->fetch($object->userownerid);

			$client = getGoogleApiClient($staticuser);

			if (empty($staticuser->array_options['options_googleapi_calendarId'])) {
				$calendarId = 'primary';
			} else {
				$calendarId = $staticuser->array_options['options_googleapi_calendarId'];
			}
			$service = new \Google\Service\Calendar($client);
			try {
				$event = $service->events->delete($calendarId, $object->oldcopy->array_options['options_googleapi_EventId']);
			} catch (Exception $e) {
				dol_syslog($e->getMessage(), LOG_WARNING);
			}
		}
		return (!$error ? 0 : -1);
	}

	/**
	 * Trigger FICHINTER_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Fichinter      $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function fichinterSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "fichinter", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger ORDER_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Commande      $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function orderSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "commande", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger PROJECT_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Project      $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function projectSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "project", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger PROPAL_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Propal        $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function propalSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "propal", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * Trigger SHIPPING_SENTBYMAIL
	 * @param string        $action     Event action code
	 * @param Expedition    $object     Object
	 * @param User          $user       Object user
	 * @param Translate     $langs      Object langs
	 * @param Conf          $conf       Object conf
	 * @return int                      <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function shippingSentbymail($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $googleapiMessageId;

		if (!empty($googleapiMessageId)) {
			$this->addEmailSent($user->id, $object->id, $object->socid, "shipping", $googleapiMessageId);
		}
		return 0;
	}

	/**
	 * function addEmailSent
	 * @param   int     $userid             User Id
	 * @param   int     $id                 Object Id
	 * @param   int     $socid              Thirdparty Id
	 * @param   string  $module             Module concerned
	 * @param   string  $googleapiMessageId GoogleApi message Id
	 * @return  void
	 */
	private function addEmailSent($userid, $id, $socid, $module, $googleapiMessageId)
	{
		$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'googleapi_emails_sent (';
		$sql .= 'userid, module, messageid, fk_object, fk_soc) VALUES (';
		$sql .= (int) $userid;
		$sql .= ', "' . $this->db->escape($module) . '"';
		$sql .= ', "' . $this->db->escape($googleapiMessageId) . '"';
		$sql .= ', ' . (int) $id;
		$sql .= ', ' . (int) $socid;
		$sql .= ')';
		$resql = $this->db->query($sql);
	}
}
