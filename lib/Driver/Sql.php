<?php
/**
 * The Kronolith_Driver_Sql class implements the Kronolith_Driver API for a
 * SQL backend.
 *
 * Copyright 1999-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Luc Saillard <luc.saillard@fr.alcove.com>
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 */
class Kronolith_Driver_Sql extends Kronolith_Driver
{
    /**
     * The object handle for the current database connection.
     *
     * @var DB
     */
    protected $_db;

    /**
     * Handle for the current database connection, used for writing. Defaults
     * to the same handle as $_db if a separate write database is not required.
     *
     * @var DB
     */
    protected $_write_db;

    /**
     * Cache events as we fetch them to avoid fetching the same event from the
     * DB twice.
     *
     * @var array
     */
    protected $_cache = array();

    /**
     * The class name of the event object to instantiate.
     *
     * Can be overwritten by sub-classes.
     *
     * @var string
     */
    protected $_eventClass = 'Kronolith_Event_Sql';

    /**
     * Returns the background color of the current calendar.
     *
     * @return string  The calendar color.
     */
    public function backgroundColor()
    {
        if (isset($GLOBALS['all_calendars'][$this->calendar])) {
            return $GLOBALS['all_calendars'][$this->calendar]->background();
        }
        return '#dddddd';
    }

    /**
     * @throws Kronolith_Exception
     */
    public function listAlarms($date, $fullevent = false)
    {
        $allevents = $this->listEvents($date, null, false, true);
        $events = array();
        foreach ($allevents as $dayevents) {
            foreach ($dayevents as $event) {
                if (!$event->recurs()) {
                    $start = new Horde_Date($event->start);
                    $start->min -= $event->alarm;
                    if ($start->compareDateTime($date) <= 0 &&
                        $date->compareDateTime($event->end) <= -1) {
                        $events[] = $fullevent ? $event : $event->id;
                    }
                } else {
                    if ($next = $event->recurrence->nextRecurrence($date)) {
                        if ($event->recurrence->hasException($next->year, $next->month, $next->mday)) {
                            continue;
                        }
                        $start = new Horde_Date($next);
                        $start->min -= $event->alarm;
                        $diff = Date_Calc::dateDiff($event->start->mday,
                                                    $event->start->month,
                                                    $event->start->year,
                                                    $event->end->mday,
                                                    $event->end->month,
                                                    $event->end->year);
                        if ($diff == -1) {
                            $diff = 0;
                        }
                        $end = new Horde_Date(array('year' => $next->year,
                                                    'month' => $next->month,
                                                    'mday' => $next->mday + $diff,
                                                    'hour' => $event->end->hour,
                                                    'min' => $event->end->min,
                                                    'sec' => $event->end->sec));
                        if ($start->compareDateTime($date) <= 0 &&
                            $date->compareDateTime($end) <= -1) {
                            if ($fullevent) {
                                $event->start = $start;
                                $event->end = $end;
                                $events[] = $event;
                            } else {
                                $events[] = $event->id;
                            }
                        }
                    }
                }
            }
        }

        return $events;
    }

    /**
     * Searches a calendar.
     *
     * @param object $query  An object with the criteria to search for.
     * @param boolean $json  Store the results of the events' toJson() method?
     *
     * @return mixed  An array of Kronolith_Events.
     * @throws Kronolith_Exception
     */
    public function search($query, $json = false)
    {
        /* Build SQL conditions based on the query string. */
        $cond = '((';
        $values = array();

        foreach (array('title', 'location', 'url', 'description') as $field) {
            if (!empty($query->$field)) {
                $binds = Horde_SQL::buildClause($this->_db, 'event_' . $field, 'LIKE', $this->convertToDriver($query->$field), true);
                if (is_array($binds)) {
                    $cond .= $binds[0] . ' AND ';
                    $values = array_merge($values, $binds[1]);
                } else {
                    $cond .= $binds;
                }
            }
        }

        if (!empty($query->baseid)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_baseid', '=', $query->baseid, true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }

        if (isset($query->status)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_status', '=', $query->status, true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }
        if (!empty($query->creator)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_creator_id', '=', $query->creator, true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }

        if ($cond == '((') {
            $cond = '';
        } else {
            $cond = substr($cond, 0, strlen($cond) - 5) . '))';
        }

        $eventIds = $this->_listEventsConditional($query->start,
                                                  $query->end,
                                                  $cond,
                                                  $values);
        $events = array();
        foreach ($eventIds as $eventId) {
            Kronolith::addSearchEvents($events, $this->getEvent($eventId), $query, $json);
        }

        return $events;
    }

    /**
     * Checks if the event's UID already exists and returns all event
     * ids with that UID.
     *
     * @param string $uid          The event's uid.
     * @param string $calendar_id  Calendar to search in.
     *
     * @return string|boolean  Returns a string with event_id or false if
     *                         not found.
     * @throws Kronolith_Exception
     */
    public function exists($uid, $calendar_id = null)
    {
        $query = 'SELECT event_id  FROM ' . $this->_params['table'] . ' WHERE event_uid = ?';
        $values = array($uid);

        if (!is_null($calendar_id)) {
            $query .= ' AND calendar_id = ?';
            $values[] = $calendar_id;
        }

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::exists(): user = "%s"; query = "%s"',
                                  $GLOBALS['registry']->getAuth(), $query), 'DEBUG');

        $event = $this->_db->getRow($query, $values, DB_FETCHMODE_ASSOC);
        $this->handleError($event);

        if ($event) {
            return $event['event_id'];
        }

        return false;
    }

    /**
     * Lists all events in the time range, optionally restricting results to
     * only events with alarms.
     *
     * @param Horde_Date $startInterval  Start of range date object.
     * @param Horde_Date $endInterval    End of range data object.
     * @param boolean $showRecurrence    Return every instance of a recurring
     *                                   event? If false, will only return
     *                                   recurring events once inside the
     *                                   $startDate - $endDate range.
     * @param boolean $hasAlarm          Only return events with alarms?
     * @param boolean $json              Store the results of the events'
     *                                   toJson() method?
     * @param boolean $coverDates        Whether to add the events to all days
     *                                   that they cover.
     * @param boolean $fetchTags         Whether to fetch tags for all events
     *
     * @return array  Events in the given time range.
     * @throws Kronolith_Exception
     */
    public function listEvents($startDate = null, $endDate = null,
                               $showRecurrence = false, $hasAlarm = false,
                               $json = false, $coverDates = true,
                               $hideExceptions = false, $fetchTags = false)
    {
        if (!is_null($startDate)) {
            $startDate = clone $startDate;
            $startDate->hour = $startDate->min = $startDate->sec = 0;
        }
        if (!is_null($endDate)) {
            $endDate = clone $endDate;
            $endDate->hour = 23;
            $endDate->min = $endDate->sec = 59;
        }

        $conditions =  $hasAlarm ? 'event_alarm > ?' : '';
        $values = $hasAlarm ? array(0) : array();
        if ($hideExceptions) {
            if (!empty($conditions)) {
                $conditions .= ' AND ';
            }
            $conditions .= "event_baseid = ''";
        }

        $events = $this->_listEventsConditional($startDate, $endDate, $conditions, $values);
        $results = array();
        if ($fetchTags && count($events)) {
            $tags = Kronolith::getTagger()->getTags(array_keys($events));
        }
        foreach ($events as $id) {
            $event = $this->getEvent($id);
            if (isset($tags) && !empty($tags[$event->uid])) {
                $event->setTags($tags[$event->uid]);
            }
            Kronolith::addEvents($results, $event, $startDate, $endDate,
                                 $showRecurrence, $json, $coverDates);
        }

        return $results;
    }

    /**
     * Lists all events that satisfy the given conditions.
     *
     * @param Horde_Date $startInterval  Start of range date object.
     * @param Horde_Date $endInterval    End of range data object.
     * @param string $conditions         Conditions, given as SQL clauses.
     * @param array $vals                SQL bind variables for use with
     *                                   $conditions clauses.
     *
     * @return array  Events in the given time range satisfying the given
     *                conditions.
     */
    private function _listEventsConditional($startInterval = null,
                                            $endInterval = null,
                                            $conditions = '', $vals = array())
    {
        if ($this->getParam('utc')) {
            if (!is_null($startInterval)) {
                $startInterval = clone $startInterval;
                $startInterval->setTimezone('UTC');
            }
            if (!is_null($endInterval)) {
                $endInterval = clone $endInterval;
                $endInterval->setTimezone('UTC');
            }
        }
        $q = 'SELECT event_id, event_uid, event_description, event_location,' .
            ' event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount, event_url,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id, event_resources, event_baseid,' .
            ' event_exceptionoriginaldate FROM ' . $this->_params['table'] .
            ' WHERE calendar_id = ?';
        $values = array($this->calendar);

        if ($conditions) {
            $q .= ' AND ' . $conditions;
            $values = array_merge($values, $vals);
        }

        if (!is_null($startInterval) && !is_null($endInterval)) {
            $etime = $endInterval->format('Y-m-d H:i:s');
            $stime = $startInterval->format('Y-m-d H:i:s');
            $q .= ' AND ((event_end >= ? AND event_start <= ?) OR (event_recurenddate >= ? AND event_start <= ? AND event_recurtype <> ?))';
            array_push($values, $stime, $etime, $stime, $etime, Horde_Date_Recurrence::RECUR_NONE);
        } elseif (!is_null($startInterval)) {
            $stime = $startInterval->format('Y-m-d H:i:s');
            $q .= ' AND ((event_end >= ?) OR (event_recurenddate >= ? AND event_recurtype <> ?))';
            array_push($values, $stime, $stime, Horde_Date_Recurrence::RECUR_NONE);
        } elseif (!is_null($endInterval)) {
            $q .= ' AND (event_start <= ?)';
            $values[] = $endInterval->format('Y-m-d H:i:s');
        }

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::_listEventsConditional(): user = "%s"; query = "%s"; values = "%s"',
                                  $GLOBALS['registry']->getAuth(), $q, implode(',', $values)), 'DEBUG');

        /* Run the query. */
        $qr = $this->_db->query($q, $values);
        $this->handleError($qr);

        $events = array();
        $row = $qr->fetchRow(DB_FETCHMODE_ASSOC);
        while ($row && !($row instanceof PEAR_Error)) {
            /* If the event did not have a UID before, we need to give
             * it one. */
            if (empty($row['event_uid'])) {
                $row['event_uid'] = (string)new Horde_Support_Guid;

                /* Save the new UID for data integrity. */
                $query = 'UPDATE ' . $this->_params['table'] . ' SET event_uid = ? WHERE event_id = ?';
                $values = array($row['event_uid'], $row['event_id']);

                /* Log the query at a DEBUG log level. */
                Horde::logMessage(sprintf('Kronolith_Driver_Sql::_listEventsConditional(): user = %s; query = "%s"; values = "%s"',
                                          $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

                $result = $this->_write_db->query($query, $values);
                if ($result instanceof PEAR_Error) {
                    Horde::logMessage($result, 'ERR');
                }
            }

            /* We have all the information we need to create an event object
             * for this event, so go ahead and cache it. */
            $this->_cache[$this->calendar][$row['event_id']] = new $this->_eventClass($this, $row);
            if ($row['event_recurtype'] == Horde_Date_Recurrence::RECUR_NONE) {
                $events[$row['event_uid']] = $row['event_id'];
            } else {
                $next = $this->nextRecurrence($row['event_id'], $startInterval);
                if ($next &&
                    (is_null($endInterval) ||
                     $next->compareDateTime($endInterval) < 0)) {
                    $events[$row['event_uid']] = $row['event_id'];
                }
            }

            $row = $qr->fetchRow(DB_FETCHMODE_ASSOC);
        }

        return $events;
    }

    /**
     * Returns the number of events in the current calendar.
     *
     * @return integer  The number of events.
     * @throws Kronolith_Exception
     */
    public function countEvents()
    {
        $query = sprintf('SELECT count(*) FROM %s WHERE calendar_id = ?',
                         $this->_params['table']);
        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::_countEvents(): user = "%s"; query = "%s"; values = "%s"',
                                  $GLOBALS['registry']->getAuth(), $query, $this->calendar), 'DEBUG');

        /* Run the query. */
        $result = $this->_db->getOne($query, array($this->calendar));
        $this->handleError($result);
        return $result;
    }

    /**
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     */
    public function getEvent($eventId = null)
    {
        if (!strlen($eventId)) {
            return new $this->_eventClass($this);
        }

        if (isset($this->_cache[$this->calendar][$eventId])) {
            return $this->_cache[$this->calendar][$eventId];
        }

        $query = 'SELECT event_id, event_uid, event_description,' .
            ' event_location, event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount, event_url,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id, event_resources,' .
            ' event_baseid, event_exceptionoriginaldate FROM ' .
            $this->_params['table'] . ' WHERE event_id = ? AND calendar_id = ?';
        $values = array($eventId, $this->calendar);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::getEvent(): user = "%s"; query = "%s"; values = "%s"',
                                  $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

        $event = $this->_db->getRow($query, $values, DB_FETCHMODE_ASSOC);
        $this->handleError($event);

        if ($event) {
            $this->_cache[$this->calendar][$eventId] = new $this->_eventClass($this, $event);
            return $this->_cache[$this->calendar][$eventId];
        }

        throw new Horde_Exception_NotFound(_("Event not found"));
    }

    /**
     * Get an event or events with the given UID value.
     *
     * @param string $uid The UID to match
     * @param array $calendars A restricted array of calendar ids to search
     * @param boolean $getAll Return all matching events? If this is false,
     * an error will be returned if more than one event is found.
     *
     * @return Kronolith_Event
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     */
    public function getByUID($uid, $calendars = null, $getAll = false)
    {
        $query = 'SELECT event_id, event_uid, calendar_id, event_description,' .
            ' event_location, event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount, event_url,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id, event_resources, event_baseid,' .
            ' event_exceptionoriginaldate FROM ' . $this->_params['table'] .
            ' WHERE event_uid = ?';
        $values = array($uid);

        /* Optionally filter by calendar */
        if (!is_null($calendars)) {
            if (!count($calendars)) {
                throw new Kronolith_Exception(_("No calendars to search"));
            }
            $query .= ' AND calendar_id IN (?' . str_repeat(', ?', count($calendars) - 1) . ')';
            $values = array_merge($values, $calendars);
        }

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::getByUID(): user = "%s"; query = "%s"; values = "%s"',
                                  $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

        $events = $this->_db->getAll($query, $values, DB_FETCHMODE_ASSOC);
        $this->handleError($events);
        if (!count($events)) {
            throw new Horde_Exception_NotFound($uid . ' not found');
        }

        $eventArray = array();
        foreach ($events as $event) {
            $this->open($event['calendar_id']);
            $this->_cache[$this->calendar][$event['event_id']] = new $this->_eventClass($this, $event);
            $eventArray[] = $this->_cache[$this->calendar][$event['event_id']];
        }

        if ($getAll) {
            return $eventArray;
        }

        /* First try the user's own calendars. */
        $ownerCalendars = Kronolith::listInternalCalendars(true, Horde_Perms::READ);
        $event = null;
        foreach ($eventArray as $ev) {
            if (isset($ownerCalendars[$ev->calendar])) {
                $event = $ev;
                break;
            }
        }

        /* If not successful, try all calendars the user has access too. */
        if (empty($event)) {
            $readableCalendars = Kronolith::listInternalCalendars(false, Horde_Perms::READ);
            foreach ($eventArray as $ev) {
                if (isset($readableCalendars[$ev->calendar])) {
                    $event = $ev;
                    break;
                }
            }
        }

        if (empty($event)) {
            $event = $eventArray[0];
        }

        return $event;
    }

    /**
     * Saves an event in the backend.
     *
     * If it is a new event, it is added, otherwise the event is updated.
     *
     * @param Kronolith_Event $event  The event to save.
     *
     * @return integer  The event id.
     * @throws Horde_Mime_Exception
     * @throws Kronolith_Exception
     */
    public function saveEvent($event)
    {
        if ($event->stored || $event->exists()) {
            $values = array();

            $query = 'UPDATE ' . $this->_params['table'] . ' SET ';

            foreach ($event->getProperties() as $key => $val) {
                $query .= " $key = ?,";
                $values[] = $val;
            }
            $query = substr($query, 0, -1);
            $query .= ' WHERE event_id = ?';
            $values[] = $event->id;

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::saveEvent(): user = "%s"; query = "%s"; values = "%s"',
                                      $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

            $result = $this->_write_db->query($query, $values);
            $this->handleError($result);

            /* Log the modification of this item in the history log. */
            if ($event->uid) {
                try {
                    $GLOBALS['injector']->getInstance('Horde_History')->log('kronolith:' . $this->calendar . ':' . $event->uid, array('action' => 'modify'), true);
                } catch (Exception $e) {
                    Horde::logMessage($e, 'ERR');
                }
            }

            /* If this event is an exception, we need to modify the base event's
             * history log also, or some synch clients will never pick up the
             *  change*/
             if ($event->baseid) {
                try {
                    $GLOBALS['injector']->getInstance('Horde_History')->log('kronolith:' . $this->calendar . ':' . $event->baseid, array('action' => 'modify'), true);
                } catch (Exception $e) {
                    Horde::logMessage($e, 'ERR');
                }
             }
            $this->_updateTags($event);

            /* Update Geolocation */
            if ($gDriver = Kronolith::getGeoDriver()) {
                $gDriver->setLocation($event->id, $event->geoLocation);
            }

            /* Notify users about the changed event. */
            $this->_handleNotifications($event, 'edit');

            return $event->id;
        }

        if ($event->id) {
            $id = $event->id;
        } else {
            $id = strval(new Horde_Support_Randomid);
            $event->id = $id;
        }

        if ($event->uid) {
            $uid = $event->uid;
        } else {
            $uid = (string)new Horde_Support_Guid;
            $event->uid = $uid;
        }

        $query = 'INSERT INTO ' . $this->_params['table'];
        $cols_name = ' (event_id, event_uid,';
        $cols_values = ' VALUES (?, ?,';
        $values = array($id, $uid);

        foreach ($event->getProperties() as $key => $val) {
            $cols_name .= " $key,";
            $cols_values .= ' ?,';
            $values[] = $val;
        }

        $cols_name .= ' calendar_id)';
        $cols_values .= ' ?)';
        $values[] = $this->calendar;

        $query .= $cols_name . $cols_values;

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::saveEvent(): user = "%s"; query = "%s"; values = "%s"',
                            $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

        $result = $this->_write_db->query($query, $values);
        $this->handleError($result);

        /* Log the creation of this item in the history log. */
        try {
            $GLOBALS['injector']->getInstance('Horde_History')->log('kronolith:' . $this->calendar . ':' . $uid, array('action' => 'add'), true);
        } catch (Exception $e) {
            Horde::logMessage($e, 'ERR');
        }

        $this->_addTags($event);

        /* Update Geolocation */
        if ($event->geoLocation && $gDriver = Kronolith::getGeoDriver()) {
            $gDriver->setLocation($event->id, $event->geoLocation);
        }

        /* Notify users about the new event. */
        $this->_handleNotifications($event, 'add');

        return $id;
    }

    /**
     * Helper function to update an existing event's tags to tagger storage.
     *
     * @param Kronolith_Event $event  The event to update
     */
    protected function _updateTags($event)
    {
        /* Update tags */
        Kronolith::getTagger()->replaceTags($event->uid, $event->tags, $event->creator, 'event');

        /* Add tags again, but as the share owner (replaceTags removes ALL tags). */
        try {
            $cal = $GLOBALS['kronolith_shares']->getShare($event->calendar);
        } catch (Horde_Share_Exception $e) {
            throw new Kronolith_Exception($e);
        }
        Kronolith::getTagger()->tag($event->uid, $event->tags, $cal->get('owner'), 'event');
    }

    /**
     * Helper function to add tags from a newly creted event to the tagger.
     *
     * @param Kronolith_Event $event  The event to save tags to storage for.
     */
    protected function _addTags($event)
    {
        /* Deal with any tags */
        $tagger = Kronolith::getTagger();
        $tagger->tag($event->uid, $event->tags, $event->creator, 'event');

        /* Add tags again, but as the share owner (replaceTags removes ALL
         * tags). */
        try {
            $cal = $GLOBALS['kronolith_shares']->getShare($event->calendar);
        } catch (Horde_Share_Exception $e) {
            Horde::logMessage($e->getMessage(), 'ERR');
            throw new Kronolith_Exception($e);
        }
        $tagger->tag($event->uid, $event->tags, $cal->get('owner'), 'event');
    }

    protected function _handleNotifications($event, $action)
    {
        Kronolith::sendNotification($event, $action);
    }

    /**
     * Moves an event to a new calendar.
     *
     * @param string $eventId      The event to move.
     * @param string $newCalendar  The new calendar.
     *
     * @return Kronolith_Event  The old event.
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     */
    protected function _move($eventId, $newCalendar)
    {
        /* Fetch the event for later use. */
        $event = $this->getEvent($eventId);

        $query = 'UPDATE ' . $this->_params['table'] . ' SET calendar_id = ? WHERE calendar_id = ? AND event_id = ?';
        $values = array($newCalendar, $this->calendar, $eventId);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::move(): %s; values = "%s"',
                                  $query, implode(',', $values)), 'DEBUG');

        /* Attempt the move query. */
        $result = $this->_write_db->query($query, $values);
        $this->handleError($result);

        return $event;
    }

    /**
     * Delete a calendar and all its events.
     *
     * @param string $calendar  The name of the calendar to delete.
     *
     * @throws Kronolith_Exception
     */
    public function delete($calendar)
    {
        $query = 'DELETE FROM ' . $this->_params['table'] . ' WHERE calendar_id = ?';
        $values = array($calendar);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::delete(): user = "%s"; query = "%s"; values = "%s"',
                                  $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

        $result = $this->_write_db->query($query, $values);
        $this->handleError($result);
    }

    /**
     * Delete an event.
     *
     * @param string $eventId  The ID of the event to delete.
     * @param boolean $silent  Don't send notifications, used when deleting
     *                         events in bulk from maintenance tasks.
     *
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     * @throws Horde_Mime_Exception
     */
    public function deleteEvent($eventId, $silent = false)
    {
        /* Fetch the event for later use. */
        $event = $this->getEvent($eventId);
        $original_uid = $event->uid;
        $isRecurring = $event->recurs();

        $query = 'DELETE FROM ' . $this->_params['table'] . ' WHERE event_id = ? AND calendar_id = ?';
        $values = array($eventId, $this->calendar);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::deleteEvent(): user = "%s"; query = "%s"; values = "%s"',
                                  $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

        $result = $this->_write_db->query($query, $values);
        $this->handleError($result);

        /* Log the deletion of this item in the history log. */
        if ($event->uid) {
            try {
                $GLOBALS['injector']->getInstance('Horde_History')->log('kronolith:' . $this->calendar . ':' . $event->uid, array('action' => 'delete'), true);
            } catch (Exception $e) {
                Horde::logMessage($e, 'ERR');
            }
        }

        /* Remove the event from any resources that are attached to it */
        //@TODO: Not sure this belongs _here_, but not sure about having to
        //       call this _everywhere_ we delete an event?
        $resources = $event->getResources();
        if (count($resources)) {
            $rd = Kronolith::getDriver('Resource');
            foreach ($resources as $uid => $resource) {
                if ($resource['response'] !== Kronolith::RESPONSE_DECLINED) {
                    $r = $rd->getResource($uid);
                    $r->removeEvent($event);
                }
            }
        }

        /* Remove any pending alarms. */
        $GLOBALS['injector']->getInstance('Horde_Alarm')->delete($event->uid);

        /* Remove any tags */
        $tagger = Kronolith::getTagger();
        $tagger->replaceTags($event->uid, array(), $event->creator, 'event');

        /* Remove any geolocation data */
        if ($gDriver = Kronolith::getGeoDriver()) {
            $gDriver->deleteLocation($event->id);
        }

        /* Notify about the deleted event. */
        if (!$silent) {
            $this->_handleNotifications($event, 'delete');
        }

        /* See if this event represents an exception - if so, touch the base
         * event's history. The $isRecurring check is to prevent an infinite
         * loop in the off chance that an exception is entered as a recurring
         * event.
         */
        if ($event->baseid && !$isRecurring) {
            try {
                $GLOBALS['injector']->getInstance('Horde_History')->log('kronolith:' . $this->calendar . ':' . $event->baseid, array('action' => 'modify'), true);
            } catch (Exception $e) {
                Horde::logMessage($e, 'ERR');
            }
        }

        /* Now check for any exceptions that THIS event may have */
        if ($isRecurring) {
            $query = 'SELECT event_id FROM ' . $this->_params['table'] . ' WHERE event_baseid = ? AND calendar_id = ?';
            $values = array($original_uid, $this->calendar);

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::deleteEvent(): user = "%s"; query = "%s"; values = "%s"',
                                      $GLOBALS['registry']->getAuth(), $query, implode(',', $values)), 'DEBUG');

            $result = $this->_db->getCol($query, 0, $values);
            $this->handleError($result);
            foreach ($result as $id) {
                $this->deleteEvent($id, $silent);
            }
        }
    }

    /**
     * Filters a list of events to return only those that belong to certain
     * calendars.
     *
     * @param array $uids      A list of event UIDs.
     * @param array $calendar  A list of calendar IDs.
     *
     * @return array  Event UIDs filtered by calendar IDs.
     * @throws Kronolith_Exception
     */
    public function filterEventsByCalendar($uids, $calendar)
    {
        $sql = 'SELECT event_uid FROM kronolith_events WHERE calendar_id IN (' . str_repeat('?, ', count($calendar) - 1) . '?) '
            . 'AND event_uid IN (' . str_repeat('?,', count($uids) - 1) . '?)';

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::filterEventsByCalendar(): %s', $sql), 'DEBUG');

        $result = $this->_db->getCol($sql, 0, array_merge($calendar, $uids));
        $this->handleError($result);

        return $result;
    }

    /**
     * Attempts to open a connection to the SQL server.
     *
     * @throws Kronolith_Exception
     */
    public function initialize()
    {
        Horde::assertDriverConfig($this->_params, 'calendar',
            array('phptype'));

        if (!isset($this->_params['database'])) {
            $this->_params['database'] = '';
        }
        if (!isset($this->_params['username'])) {
            $this->_params['username'] = '';
        }
        if (!isset($this->_params['hostspec'])) {
            $this->_params['hostspec'] = '';
        }
        if (!isset($this->_params['table'])) {
            $this->_params['table'] = 'kronolith_events';
        }

        /* Connect to the SQL server using the supplied parameters. */
        $this->_write_db = DB::connect($this->_params,
                                       array('persistent' => !empty($this->_params['persistent']),
                                             'ssl' => !empty($this->_params['ssl'])));
        $this->handleError($this->_write_db);
        $this->_initConn($this->_write_db);

        /* Check if we need to set up the read DB connection
         * seperately. */
        if (!empty($this->_params['splitread'])) {
            $params = array_merge($this->_params, $this->_params['read']);
            $this->_db = DB::connect($params,
                                     array('persistent' => !empty($params['persistent']),
                                           'ssl' => !empty($params['ssl'])));
            $this->handleError($this->_db);
            $this->_initConn($this->_db);
        } else {
            /* Default to the same DB handle for the writer too. */
            $this->_db = $this->_write_db;
        }
    }

    /**
     */
    private function _initConn(&$db)
    {
        // Set DB portability options.
        switch ($db->phptype) {
        case 'mssql':
            $db->setOption('portability', DB_PORTABILITY_LOWERCASE | DB_PORTABILITY_ERRORS | DB_PORTABILITY_RTRIM);
            break;
        default:
            $db->setOption('portability', DB_PORTABILITY_LOWERCASE | DB_PORTABILITY_ERRORS);
        }

        /* Handle any database specific initialization code to run. */
        switch ($db->dbsyntax) {
        case 'oci8':
            $query = "ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'";

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::_initConn(): user = "%s"; query = "%s"',
                                      $GLOBALS['registry']->getAuth(), $query), 'DEBUG');

            $db->query($query);
            break;

        case 'pgsql':
            $query = "SET datestyle TO 'iso'";

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::_initConn(): user = "%s"; query = "%s"',
                                      $GLOBALS['registry']->getAuth(), $query), 'DEBUG');

            $db->query($query);
            break;
        }
    }

    /**
     * Converts a value from the driver's charset to the default
     * charset.
     *
     * @param mixed $value  A value to convert.
     *
     * @return mixed  The converted value.
     */
    public function convertFromDriver($value)
    {
        return Horde_String::convertCharset($value, $this->_params['charset']);
    }

    /**
     * Converts a value from the default charset to the driver's
     * charset.
     *
     * @param mixed $value  A value to convert.
     *
     * @return mixed  The converted value.
     */
    public function convertToDriver($value)
    {
        return Horde_String::convertCharset($value, $GLOBALS['registry']->getCharset(), $this->_params['charset']);
    }

    /**
     * Remove all events owned by the specified user in all calendars.
     *
     * @todo Refactor: move to Kronolith:: and catch exceptions instead of relying on boolean return value.
     *
     * @param string $user  The user name to delete events for.
     *
     * @return boolean
     * @throws Kronolith_Exception
     * @throws Horde_Exception_NotFound
     * @throws Horde_Exception_PermissionDenied
     */
    public function removeUserData($user)
    {
        throw new Kronolith_Exception('to be refactored');

        if (!$GLOBALS['registry']->isAdmin()) {
            throw new Horde_Exception_PermissionDenied();
        }

        try {
            $shares = $GLOBALS['kronolith_shares']->listShares($user, Horde_Perms::EDIT);
        } catch (Horde_Share_Exception $e) {
            Horde::logMessage($shares, 'ERR');
            throw new Kronolith_Exception($shares);
        }

        foreach (array_keys($shares) as $calendar) {
            $ids = Kronolith::listEventIds(null, null, $calendar);
            $this->handleError($ids);
            $uids = array();
            foreach ($ids as $cal) {
                $uids = array_merge($uids, array_keys($cal));
            }

            foreach ($uids as $uid) {
                $event = $this->getByUID($uid);
                $this->deleteEvent($event->id);
            }
        }

        return true;
    }

    /**
     * Determines if the given result is a PEAR error. If it is, logs the event
     * and throws an exception.
     *
     * @param mixed $result The result to check.
     *
     * @throws Horde_Exception
     */
    protected function handleError($result)
    {
        if ($result instanceof PEAR_Error) {
            Horde::logMessage($result, 'ERR');
            throw new Kronolith_Exception($result);
        }
    }

}
