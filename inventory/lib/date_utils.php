<?php

/**
 * Date utility functions for consistent timezone handling
 */

// Set default timezone to Asia/Manila for display purposes
date_default_timezone_set('Asia/Manila');

/**
 * Converts a database timestamp to a display format
 * @param mixed $dateTime Can be a DateTime object, MongoDB\BSON\UTCDateTime, or string
 * @param string $format Output format (default: 'Y-m-d H:i A')
 * @return string Formatted date string
 */
function format_date($dateTime, string $format = 'Y-m-d h:i A'): string {
    if ($dateTime instanceof MongoDB\BSON\UTCDateTime) {
        $dateTime = $dateTime->toDateTime();
    }
    
    if ($dateTime instanceof DateTime) {
        $dateTime->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dateTime->format($format);
    }
    
    if (is_string($dateTime)) {
        $dt = new DateTime($dateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dt->format($format);
    }
    
    return '';
}

/**
 * Gets current date/time in UTC for database storage
 * @param string $format Output format (null returns DateTime object)
 * @return DateTime|string
 */
function now_utc(string $format = 'Y-m-d H:i:s') {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    return $format ? $now->format($format) : $now;
}

/**
 * Converts a local date/time string to UTC for database storage
 * @param string $dateTime Local date/time string
 * @param string $format Output format (null returns DateTime object)
 * @return DateTime|string
 */
function local_to_utc(string $dateTime, string $format = 'Y-m-d H:i:s') {
    $dt = new DateTime($dateTime, new DateTimeZone('Asia/Manila'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $format ? $dt->format($format) : $dt;
}
