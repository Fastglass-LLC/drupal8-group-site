<?php

namespace Drupal\redis_watchdog;

use Drupal\redis\ClientFactory as RedisClient;
use Psr\Log\AbstractLogger;


class RedisWatchdogLogger extends AbstractLogger
{

    protected $client;

    protected $key;

    protected $types = [];

    protected $recent;

    protected $archivelimit;

    public function __construct($prefix = '', $recentlength = 200, $archivelimit = 5000)
    {
        // @todo remove this when converstion to Drupal 8 is finished.
        // $this->client = Redis_Client::getManager()->getClient();
        $this->client = RedisClient::getClient();
        if (!empty($prefix)) {
            $this->key = 'drupal:watchdog:' . $prefix . ':';
        } else {
            $this->key = 'drupal:watchdog';
        }
        $this->recent = $recentlength;
        $this->archivelimit = $archivelimit;
    }

    /**
     * {@inheritDoc}
     */
    public function log($level, $message, array $context = [])
    {
        // Remove any backtraces since they may contain an unserializable variable.
        unset($context['backtrace']);

        // Convert PSR3-style messages to SafeMarkup::format() style, so they can be
        // translated too in runtime.
        $message_placeholders = $this->parser->parseMessagePlaceholders($message, $context);

        $log_entry = [
            'uid' => $context['uid'],
            'type' => Unicode::substr($context['channel'], 0, 64),
            'message' => $message,
            'variables' => serialize($message_placeholders),
            'severity' => $level,
            'link' => $context['link'],
            'location' => $context['request_uri'],
            'referer' => $context['referer'],
            'hostname' => Unicode::substr($context['ip'], 0, 128),
            'timestamp' => $context['timestamp'],
        ];

        // The user object may not exist in all conditions, so 0 is substituted if needed.
        $user_uid = isset($log_entry['user']->uid) ? $log_entry['user']->uid : 0;
        $wid = $this->getPushLogCounter();
        $message = [
            'wid' => $wid,
            'uid' => $user_uid,
            'type' => substr($log_entry['type'], 0, 64),
            'message' => $log_entry['message'],
            'variables' => serialize($log_entry['variables']),
            'severity' => $log_entry['severity'],
            'link' => substr($log_entry['link'], 0, 255),
            'location' => $log_entry['request_uri'],
            'referer' => $log_entry['referer'],
            'hostname' => substr($log_entry['ip'], 0, 128),
            'timestamp' => $log_entry['timestamp'],
        ];

        // Record the type only if it doesn't already exist in the hash.
        if (!$this->client->hExists($this->key . ':type', $message['type'])) {
            // Store types in a separate hash table to build the filters menu.
            $tid = $this->getTypeIDCounter();
            $this->client->hSet($this->key . ':type', $message['type'], $tid);
        } else {
            // Find the type ID already assigned.
            $tid = $this->client->hGet($this->key . ':type', $message['type']);
        }
        $message = (object)$message;

        // Push the log into the recent message list.
        $this->client->rPush($this->key . ':recentlogs', serialize($message));
        // Trim the recent message list to a set amount.
        lTrim($this->key . ':recentlogs', $this->recent);

        // Push the log into the type message list.
        $this->client->rPush($this->key . ':logs:' . $tid, serialize($message));
        // Trim the list for the type messages according to the archive limit.
        lTrim($this->key . ':logs:' . $tid, $this->archivelimit);

        $this->client->hSet($this->key, $wid, serialize($message));


    }


    /**
     * Returns the value of the counter.
     *
     * @return integer
     *
     * @see https://github.com/phpredis/phpredis#hget
     */
    protected function getLogCounter()
    {

        return $this->client->hGet($this->key . ':counters', 'logs');
    }

    /**
     * Returns the value of the counter and pushes it up by 1 when called.
     *
     * @return integer
     *
     * @see https://github.com/phpredis/phpredis#hincrby
     */
    protected function getPushLogCounter()
    {
        return $this->client->hIncrBy($this->key . ':counters', 'logs', 1);
    }

    /**
     * Returns the value of the typeid counter. This will indicate the number of
     * types stored
     *
     * @return integer
     *
     * @see https://github.com/phpredis/phpredis#hget
     */
    protected function getTypeIDCounterValue()
    {
        return $this->client->hGet($this->key . ':counters', 'typeid');
    }

    /**
     * Returns a value to use for the type ID number.
     *
     * @return integer
     *
     * @see https://github.com/phpredis/phpredis#hincrby
     */
    protected function getTypeIDCounter()
    {
        return $this->client->hIncrBy($this->key . ':counters', 'typeid', 1);
    }

    /**
     * Return the message types. Names only.
     *
     * @return array
     */
    public function getMessageTypes()
    {
        if (empty($this->types)) {
            $this->types = $this->client->hGetAll($this->key . ':type');
        }
        return $this->types;
    }

    /**
     * Return the count of messages per type
     *
     * @return array
     */
    public function getMessageTypesCounts()
    {
        $types = $this->getMessageTypes();
        if (empty($this->typescount)) {
            $this->typescount = [];
            foreach ($types as $typename => $id) {
                $this->typescount += [$typename => $this->client->lLen($this->key . ':logs:' . $id)];
            }
        }
        return $this->typescount;
    }

    /**
     * Retrieve a single log entry
     *
     * @param int $wid
     *  Log key ID number.
     *
     * @return bool|mixed
     */
    public function getSingle($wid)
    {
        $result = $this->client->hGet($this->key, $wid);
        return $result ? unserialize($result) : FALSE;
    }

    /**
     * Retrive multiple log entries.
     *
     * @param int $limit
     *
     * @return array
     */
    public function getMultiple($limit = 50)
    {
        $logs = [];
        $types = [];
        $max_wid = $this->getLogCounter();
        if ($max_wid) {
            if ($max_wid > $limit) {
                $keys = range($max_wid, $max_wid - $limit);
            } else {
                $keys = range($max_wid, 1);
            }

            $res = $this->client->hmGet($this->key, $keys);
            foreach ($res as $entry) {
                $entry = unserialize($entry);
                $logs[] = $entry;
                if (!in_array($entry->type, $types)) {
                    $types[] = $entry->type;
                }
            }
            $this->types = $types;
        }
        return $logs;
    }

    /**
     * Return multiple log entries for a specific log type.
     *
     * @param int $limit
     *  Limit of logs to return.
     *
     * @param int $tid
     *  ID number of the type to return.
     *
     * @param int $page
     *  The page being requested.
     *
     * @return array
     */
    public function getMultipleByType($limit = 50, $tid = NULL, $page = 0)
    {
        // Start point for the range.
        $start = (empty($page)) ? 0 : $limit * $page;
        // End point for the range.
        $end = $start + $limit;
        $logs = [];
        $types = [];
        if ($tid) {
            // @todo provide a range control.
            $res = $this->client->lRange($this->key . ':logs:' . $tid, $start, $end);
            foreach ($res as $entry) {
                $entry = unserialize($entry);
                $logs[] = $entry;
                if (!in_array($entry->type, $types)) {
                    $types[] = $entry->type;
                }
            }
            $this->types = $types;
        }
        return $logs;
    }

    /**
     * Return all logs stored in redis. Be cautious of use. Performance impact.
     *
     * @return array
     */

    public function getAllMessages()
    {
        $types = $this->getMessageTypes();
        $logs = [];
        foreach ($types as $logid) {
            $curr = $this->client->lGetRange($this->key . ':logs:' . $logid, 0, -1);
            $logs = array_merge($logs, $curr);
        }
        return $logs;
    }

    /**
     * Return the number of logs for a given type.
     *
     * @param int $tid
     *  Type ID Number.
     *
     * @return int
     */
    public function getTypeCount($tid)
    {
        return $this->client->lLen($this->key . ':logs:' . $tid);
    }

    /**
     * Retrieve recent log entries from linked list.
     *
     * @return array
     */
    public function getRecentLogs()
    {
        $logs = [];
        $res = $this->client->lRange($this->key . ':recentlogs', 0, -1);
        foreach ($res as $entry) {
            $entry = unserialize($entry);
            $logs[] = $entry;
        }
        return $logs;
    }

    /**
     * Clear all information from logs.
     */
    public function clear()
    {
        $typecount = $this->getTypeIDCounterValue();
        $this->client->multi();
        for ($i = 1; $i <= $typecount; $i++) {
            $this->client->delete($this->key . ':logs:' . $i);
        }
        $this->client->delete($this->key . ':type');
        $this->client->delete($this->key . ':counters');
        $this->client->delete($this->key . ':recentlogs');
        $this->client->delete($this->key);
        if ($this->client->exec()) {
            return TRUE;
        } else {
            return FALSE;
        }
    }


}