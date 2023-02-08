<?php
/* Copyright (C) 2019-2021  Frédéric France         <frederic.france@netlogic.fr>
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
 *
 * Library javascript to enable Browser notifications
 */

/**
 * \file    htdocs/googleapi/js/googleapi.js.php
 * \ingroup googleapi
 * \brief   JavaScript file for module GoogleApi.
 */

$defines = [
	'NOLOGIN',
	'NOREQUIRESOC',
	'NOREQUIRETRAN',
	'NOCSRFCHECK',
	'NOTOKENRENEWAL',
	'NOREQUIREMENU',
	'NOREQUIREHTML',
	'NOREQUIREAJAX',
];

// Load Dolibarr environment
include '../config.php';

// Define js type
top_httphead('text/javascript; charset=UTF-8');
header('Cache-Control: no-cache');

if (!is_object($user) && empty($user->id)) {
	exit;
}
$nowtime = time();
if (! isset($_SESSION['auto_check_googleapiemail_not_before'])) {
	print 'console.log("_SESSION[auto_check_googleapiemail_not_before] is not set");' . "\n";
	// Round to eliminate the seconds
	$_SESSION['auto_check_googleapiemail_not_before'] = $nowtime;
}

print "/* Javascript library of module GoogleApi */\n";

print "var nowtime = " . $nowtime . ";\n";
print "var login = '" . $_SESSION['dol_login'] . "';\n";
print "var auto_check_googleapiemail_not_before = " . $_SESSION['auto_check_googleapiemail_not_before'] . ";\n";
print "var time_js_next_check = Math.max(nowtime, auto_check_googleapiemail_not_before);\n";
print "var time_auto_update = " . $conf->global->MAIN_BROWSER_NOTIFICATION_FREQUENCY . ";\n";
?>
var refresh_work;
/* Launch timer */
// We set a delay before launching first test so next check will arrive after the time_auto_update compared to previous one.
var time_first_execution = (time_auto_update - (nowtime - time_js_next_check)) * 1000;   //need milliseconds
if (login != '') {
	console.log("Launch GoogleApi Email check: ")
	console.log("setTimeout is set to launch 'first_execution' function after a wait of time_first_execution="+time_first_execution+". nowtime (time php page generation) = "+nowtime+" auto_check_googleapiemail_not_before (val in session)= "+auto_check_googleapiemail_not_before+" time_js_next_check (max now,auto_check_googleapiemail_not_before) = "+time_js_next_check+" time_auto_update="+time_auto_update);
	setTimeout(first_execution, time_first_execution);
}

function first_execution() {
	console.log("Call first_execution time_auto_update (MAIN_BROWSER_NOTIFICATION_FREQUENCY) = " + time_auto_update);
	check_googleapiemail();    //one check before launching timer to launch other checks
	setInterval(check_googleapiemail, time_auto_update * 1000); //program time to run next check googleapiemail
}

function check_googleapiemail() {
	console.log("Call check_googleapiemail time_js_next_check = date we are looking for event after = "+time_js_next_check);
	$.ajax("<?php echo dol_buildpath('/googleapi/core/ajax/check_email.php', 1); ?>", {
		type: "post",
		async: true,
		data: {
			time: time_js_next_check
		},
		success: function (result) {
			// console.log(result);
			$('#googleapicounter').attr('data-count', result.unread);
			$('.googleapicounterinfo').attr('title', result.info);
		}
	});
	time_js_next_check += time_auto_update;
	console.log('Updated time_js_next_check. New value is '+time_js_next_check);
}
$(window).blur(function() {
	console.log('Clear google check mail refresh');
	clearInterval(refresh_work);
	refresh_work = 0;
});
$(window).focus(function() {
	console.log('Enable google check mail refresh');
	if (!refresh_work) {
		refresh_work = setInterval(check_googleapiemail, time_auto_update * 1000);
	}
});
