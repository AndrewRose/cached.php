#!/usr/bin/php
<?php
/*
 This file is part of cached.php 
 http://github.com/AndrewRose/cached.php
 License: GPL; see below
 Copyright Andrew Rose (hello@andrewrose.co.uk) 2012

    cached.php is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    cached.php is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with cached.php.  If not, see <http://www.gnu.org/licenses/>
*/

namespace Cached;

class Handler
{
	private $version = 'cached.php v0.1';
	public $connections = [];
	public $buffers = [];
	public $maxRead = 256;
	private $data = [];
	private $stats = [
		'pid'                   => 0,     // 32u - Process id of this server process
		'uptime'                => 0,     // 32u - Number of secs since the server started
		'time'                  => 0,     // 32u  - current UNIX time according to the server
		'version'               => '',    // string - Version string of this server
		'pointer_size'          => 0,     // 32 - Default size of pointers on the host OS (generally 32 or 64)
		'rusage_user'           => 0,     // 32u.32u - Accumulated user time for this process (seconds:microseconds)
		'rusage_system'         => 0,     // 32u.32u - Accumulated system time for this process (seconds:microseconds)
		'curr_items'            => 0,     // 32u - Current number of items stored

		'total_items'           => 0,     // 32u - Total number of items stored since the server started
		'bytes'                 => 0,     // 64u - Current number of bytes used to store items
		'curr_connections'      => 0,     // 32u - Number of open connections
		'total_connections'     => 0,     // 32u  - Total number of connections opened since the server started running
		'connection_structures' => 0,     // 32u - Number of connection structures allocated by the server
		'reserved_fds'          => 0,     // 32u - Number of misc fds used internally
		'cmd_get'               => 0,     // 64u - Cumulative number of retrieval reqs
		'cmd_set'               => 0,     // 64u - Cumulative number of storage reqs
		'cmd_flush'             => 0,     // 64u - Cumulative number of flush reqs
		'cmd_touch'             => 0,     // 64u - Cumulative number of touch reqs
		'get_hits'              => 0,     // 64u - Number of keys that have been requested and found present
		'get_misses'            => 0,     // 64u - Number of items that have been requested and not found
		'delete_misses'         => 0,     // 64u - Number of deletions reqs for missing keys
		'delete_hits'           => 0,     // 64u - Number of deletion reqs resulting in an item being removed.
		'incr_misses'           => 0,     // 64u - Number of incr reqs against missing keys.
		'incr_hits'             => 0,     // 64u - Number of successful incr reqs.
		'decr_misses'           => 0,     // 64u - Number of decr reqs against missing keys.
		'decr_hits'             => 0,     // 64u - Number of successful decr reqs.
		'cas_misses'            => 0,     // 64u - Number of CAS reqs against missing keys.
		'cas_hits'              => 0,     // 64u - Number of successful CAS reqs.
		'cas_badval'            => 0,     // 64u - Number of CAS reqs for which a key was found, but the CAS value did not match.
		'touch_hits'            => 0,     // 64u - Numer of keys that have been touched with a new expiration time
		'touch_misses'          => 0,     // 64u - Numer of items that have been touched and not found
		'auth_cmds'             => 0,     // 64u - Number of authentication commands handled, success or failure.
		'auth_errors'           => 0,     // 64u - Number of failed authentications.
		'evictions'             => 0,     // 64u - Number of valid items removed from cache to free memory for new items
		'reclaimed'             => 0,     // 64u - Number of times an entry was stored using memory from an expired entry
		'bytes_read'            => 0,     // 64u - Total number of bytes read by this server from network
		'bytes_written'         => 0,     // 64u - Total number of bytes sent by this server to network
		'limit_maxbytes'        => 0,     // 32u - Number of bytes this server is allowed to use for storage.
		'threads'               => 0,     // 32u - Number of worker threads requested. (see doc/threads.txt)
		'conn_yields'           => 0,     // 64u - Number of times any connection yielded to another due to hitting the -R limit.
		'hash_power_level'      => 0,     // 32u - Current size multiplier for hash table
		'hash_bytes'            => 0,     // 64u - Bytes currently used by hash tables
		'hash_is_expanding'     => 0,     // bool - Indicates if the hash table is being grown to a new size
		'expired_unfetched'     => 0,     // 64u - Items pulled from LRU that were never touched by get/incr/append/etc before expiring
		'evicted_unfetched'     => 0,     // 64u - Items evicted from LRU that were never touched by get/incr/append/etc.
		'slab_reassign_running' => 0,     // bool - If a slab page is being moved
		'slabs_moved'           => 0      // 64u - Total slab pages moved
	];

	public function __construct($pid)
	{
		$this->stats['pid'] = $pid;
		$this->stats['version'] = $this->version;
		$this->stats['pointer_size'] = PHP_INT_SIZE==4?'32':(PHP_INT_SIZE==8?'64':'Unknown');

		//$this->db = new \PDO('sqlite:cached.db');
		$this->db = new \PDO('mysql:host=localhost;dbname=cached', 'root', '');
		$this->dbStmtInsert = $this->db->prepare("INSERT INTO cache(k, data, flags) VALUES(:k, :data, :flags)");
		$this->dbStmtDelete = $this->db->prepare("DELETE FROM cache WHERE k = :k");
		$this->dbStmtDeleteAll = $this->db->prepare("DELETE FROM cache");

		if(($result = $this->db->query('SELECT k, data, flags FROM cache')))
		{
			if($result->rowCount())
			{
				while($row = $result->fetch(\PDO::FETCH_ASSOC))
				{
					$this->data[$row['k']] = [$row['data'], $row['flags']];
				}
			}
		}

		$socket = stream_socket_server ('tcp://0.0.0.0:11211', $errno, $errstr);
		stream_set_blocking($socket, 0);
		$base = event_base_new();
		$event = event_new();
		event_set($event, $socket, EV_READ | EV_PERSIST, [&$this, 'ev_accept'], $base);
		event_base_set($event, $base);
		event_add($event);
		event_base_loop($base);
	}

	protected function updateStats()
	{
		$this->stats['uptime'] = explode(' ', exec('cat /proc/uptime'))[0];
		$this->stats['time'] = time();

		$pt = posix_times();
		$this->stats['rusage_user'] = $pt['utime'];
		$this->stats['rusage_system'] = $pt['stime'];

		$this->stats['curr_items'] = sizeof($this->data);
	}

	protected function ev_accept($socket, $flag, $base)
	{
		static $id = 0;
		$connection = stream_socket_accept($socket);
		stream_set_blocking($connection, 0);
		$id += 1;

		$this->connections[$id]['cnx'] = $connection;
		$this->connections[$id]['clientData'] = '';
		$this->connections[$id]['dataMode'] = FALSE;
		$this->connections[$id]['dataModeResume'] = FALSE;
		$this->connections[$id]['dataModeLength'] = FALSE;

		$buffer = event_buffer_new($connection, [&$this, 'ev_read'], NULL, [&$this, 'ev_error'], $id);

		event_buffer_base_set($buffer, $base);
		//event_buffer_timeout_set($buffer, 30, 30);
		event_buffer_watermark_set($buffer, EV_READ, 0, $this->maxRead);
		//event_buffer_priority_set($buffer, 10);
		event_buffer_enable($buffer, EV_READ | EV_WRITE);
		$this->buffers[$id] = $buffer;
	}

	protected function ev_error($buffer, $error, $id)
	{
		$this->ev_close($id);
	}

	protected function ev_close($id)
	{
		event_buffer_disable($this->buffers[$id], EV_READ | EV_WRITE);
		event_buffer_free($this->buffers[$id]);
		fclose($this->connections[$id]['cnx']);
		unset($this->buffers[$id], $this->connections[$id]);
	}

	protected function ev_write($id, $string)
	{
echo '('.$id.')S: '.$string."\n";
		event_buffer_write($this->buffers[$id], $string);
	}

	protected function ev_read($buffer, $id)
	{
		$this->connections[$id]['clientData'] .= event_buffer_read($buffer, $this->maxRead);
		$clientDataLen = strlen($this->connections[$id]['clientData']);

echo '('.$id.')C: '.$this->connections[$id]['clientData']."\n";

		if(!$this->connections[$id]['dataMode'] && ($pos = strpos($this->connections[$id]['clientData'], "\r\n"))) // add offset for faster search?
		{
			$data = substr($this->connections[$id]['clientData'], 0, $pos);
			$this->connections[$id]['clientData'] = substr($this->connections[$id]['clientData'], $pos+2, $clientDataLen);
			$clientDataLen = strlen($this->connections[$id]['clientData']);
			$tmp = explode(' ', $data, 2);
			$this->cmd($buffer, $id, $tmp[0], isset($tmp[1])?$tmp[1]:FALSE);
		}

		if($this->connections[$id]['dataMode'] && $clientDataLen == ($this->connections[$id]['dataModeLength']+2)) // +2 to include the \r\n
		{
			$this->connections[$id]['dataMode'] = FALSE;
			$this->cmd($buffer, $id, $this->connections[$id]['dataModeResume'][0], $this->connections[$id]['dataModeResume'][1], substr($this->connections[$id]['clientData'], 0, $clientDataLen-2));
			$this->connections[$id]['clientData'] = '';
		}
	}

	protected function getData($buffer, $id, $cmd, $line, $dataLength)
	{
		$this->connections[$id]['dataMode'] = TRUE;
		$this->connections[$id]['dataModeResume'] = [$cmd, $line];
		$this->connections[$id]['dataModeLength'] = $dataLength;
	}

	protected function insert($key, $data, $flags)
	{
		if(!$this->dbStmtInsert->execute([
			':k' => $key,
			':data' => $data,
			':flags' => $flags
		])){
			return FALSE;
		}

		$this->data[$key] = [$data, $flags];
		return TRUE;
	}

	protected function delete($key)
	{
		if(!isset($this->data[$key]))
		{
			return FALSE;
		}

		if(!$this->dbStmtDelete->execute([
			':k' => $key
		])){
			return FALSE;
		}

		unset($this->data[$key]);
		return TRUE;	
	}

	protected function cmd($buffer, $id, $cmd, $line, $data=FALSE)
	{
		// <command name> <key> <flags> <exptime> <bytes> [noreply]\r\n
		switch($cmd)
		{
			case 'set':
			case 'add':
			case 'replace':
			{
				if($data===FALSE)
				{
					$tmp = explode(' ', $line); // grab count
					$this->getData($buffer, $id, $cmd, $line, $tmp[3]);
				}
				else
				{
					$args = explode(' ', $line);
					$argsCount = sizeof($args);

					if($argsCount == 4)
					{
						list($key, $flags, $exptime, $bytes) = $args;
					}
					else if($argsCount == 5)
					{
						list($key, $flags, $exptime, $bytes, $noreply) = $args;
					}
					else
					{
						$this->ev_write($id, "CLIENT_ERROR unexpected number of command arguments, expected 4 or 5 but got: ".$argsCount."\r\n");
						return;
					}

					if($cmd == 'set' ||
						($cmd == 'add' && !isset($this->data[$key])) ||
						($cmd == 'replace' && isset($this->data[$key])))
					{
						if($this->insert($key, $data, $flags))
						{
							$this->ev_write($id, "STORED\r\n");
						}
						else
						{
							$this->ev_write($id, "SERVER_ERROR Failed to store key/value\r\n");
						}
					}
					else
					{
						$this->ev_write($id, "NOT_STORED\r\n");
					}
				}
			}
			break;

			// Response:
			//  VALUE <key> <flags> <bytes> [<cas unique>]\r\n
			//  <data block>\r\n
			//  END\r\n
			case 'get':
			{
				$keys = explode(' ', trim($line));
				foreach($keys as $key)
				{
					if(isset($this->data[$key])) // hit
					{
						$this->ev_write($id, 'VALUE '.$key.' 0 '.strlen($this->data[$key][0])."\r\n");
						$this->ev_write($id, $this->data[$key][0]."\r\n");
					} // else miss

				}
				$this->ev_write($id, "END\r\n");
			}
			break;

			case 'delete':
			{
				$tmp = explode(' ', trim($line));
				$key = $tmp[0];
				if(isset($this->data[$key]))
				{
					if($this->delete($key))
					{
						$this->ev_write($id, "DELETED\r\n");
					}
					else
					{
						$this->ev_write($id, "SERVER_ERROR Failed to delete key/value\r\n");
					}
				}
				else
				{
					$this->ev_write($id, "NOT_FOUND\r\n");
				}
			}
			break;

			case 'flush_all':
			{
				$this->dbStmtDeleteAll->execute();
				$this->data = [];
				$this->ev_write($id, "OK\r\n");
			}
			break;

			case 'quit':
			{
				$this->ev_close($id);
			}
			break;

			// Response:
			// STAT <name> <value>\r\n
			// END\r\n
			case 'stats':
			{
				$this->updateStats();
				foreach($this->stats as $k => $v)
				{
					$this->ev_write($id, 'STAT '.$k.' '.$v."\r\n");
				}
				$this->ev_write($id, "END\r\n");
			}
			break;

			default:
			{
				echo 'unknown command: '.$line."\n";
			}
			break;
		}
	}
}

$pid = pcntl_fork();
if($pid)
{
    exit();
}
new Handler($pid);
