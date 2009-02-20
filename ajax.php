<?php
/**
 * Performs the AJAX-requested action.
 *
 * Copyright 2005-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Michael Slusarz <slusarz@horde.org>
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 */

// Need to load Util:: to give us access to Util::getPathInfo().
$kronolith_dir = dirname(__FILE__);
if (!defined('HORDE_BASE')) {
    /* Temporary fix - if horde does not live directly under the kronolith
     * directory, the HORDE_BASE constant should be defined in
     * kronolith/lib/base.local.php. */
    if (file_exists($kronolith_dir . '/lib/base.local.php')) {
        include $kronolith_dir . '/lib/base.local.php';
    } else {
        define('HORDE_BASE', $kronolith_dir . '/..');
    }
}
require_once HORDE_BASE . '/lib/core.php';
$action = basename(Util::getPathInfo());
if (empty($action)) {
    // This is the only case where we really don't return anything, since
    // the frontend can be presumed not to make this request on purpose.
    // Other missing data cases we return a response of boolean false.
    exit;
}

// The following actions do not need write access to the session and
// should be opened read-only for performance reasons.
if (in_array($action, array())) {
    $session_control = 'readonly';
}

$session_timeout = 'json';
require_once $kronolith_dir . '/lib/base.php';

// Process common request variables.
$cacheid = Util::getPost('cacheid');

// Open an output buffer to ensure that we catch errors that might break JSON
// encoding.
ob_start();

$notify = true;
$result = false;

switch ($action) {
case 'ListEvents':
    $start = new Horde_Date(Util::getFormData('start'));
    $end   = new Horde_Date(Util::getFormData('end'));
    $dates = Kronolith::listEvents($start, $end);
    if (is_a($dates, 'PEAR_Error')) {
        $notification->push($dates, 'horde.error');
        $result = false;
    } else {
        $result = new stdClass;
        $result->sig = $start->dateString() . $end->dateString();
        foreach ($dates as $date => $events) {
            foreach ($events as $id => $event) {
                $result->events[$date][$id] = $event->toJSON();
            }
        }
    }
    break;

case 'ChunkContent':
    $chunk = basename(Util::getPost('chunk'));
    if (!empty($chunk)) {
        $result = new stdClass;
        $result->chunk = Util::bufferOutput('include', KRONOLITH_TEMPLATES . '/chunks/' . $chunk . '.php');
    }
    break;
}

// Clear the output buffer that we started above, and log any unexpected
// output at a DEBUG level.
$errors = ob_get_clean();
if ($errors) {
    Horde::logMessage('Kronolith: unexpected output: ' .
                      $errors, __FILE__, __LINE__, PEAR_LOG_DEBUG);
}

// Send the final result.
Horde::sendHTTPResponse(Horde::prepareResponse($result, $notify ? $GLOBALS['kronolith_notify'] : null), 'json');
