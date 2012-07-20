#!/usr/bin/php
<?php

namespace Cached;

class Handler
{
	public $connections = [];
	public $buffers = [];
	public $maxRead = 256;
	private $backend = FALSE;
	private $data = [];

	public function __construct()
	{
		$this->db = new \PDO('mysql:host=localhost;dbname=cached', 'root', '');
		$this->dbStmtInsert = $this->db->prepare("INSERT INTO cache(k, data, flags) VALUES(:k, :data, :flags)");

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
//echo '('.$id.')S: '.$string."\n";
		event_buffer_write($this->buffers[$id], $string);
	}

	protected function ev_read($buffer, $id)
	{
		$this->connections[$id]['clientData'] .= event_buffer_read($buffer, $this->maxRead);
		$clientDataLen = strlen($this->connections[$id]['clientData']);

//echo '('.$id.')C: '.$this->connections[$id]['clientData']."\n";

		if(!$this->connections[$id]['dataMode'] && ($pos = strpos($this->connections[$id]['clientData'], "\r\n"))) // add offset for faster search?
		{
			$data = substr($this->connections[$id]['clientData'], 0, $pos);
			$this->connections[$id]['clientData'] = substr($this->connections[$id]['clientData'], $pos+2, $clientDataLen);
			$clientDataLen = strlen($this->connections[$id]['clientData']);
			$tmp = explode(' ', $data, 2);
			$this->cmd($buffer, $id, $tmp[0], $tmp[1]);
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
		]))
		{
			return FALSE;
		}

		$this->data[$key] = [$data, $flags];
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
					//list($key, $flags, $exptime, $bytes, $noreply) = explode(' ', $line);
					list($key, $flags, $exptime, $bytes) = explode(' ', $line);
					
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

			default:
			{
				echo 'unknown command: '.$line."\n";
			}
			break;
		}
	}
}

new Handler();
