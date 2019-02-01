<?php

declare(strict_types=1);

namespace pocketmine;

use raklib\server\UDPServerSocket;
use raklib\utils\InternetAddress;

class Client{

	/** @var int */
	private $time;

	/** @var InternetAddress */
	private $serverAddress;
	/** @var InternetAddress */
	private $clientAddress;

	/** @var UDPServerSocket */
	private $socket;

	/** @var bool */
	public $loggedIn = false;

	public function __construct(InternetAddress $server, InternetAddress $client, UDPServerSocket $socket){
		$this->time = time();
		$this->serverAddress = $server;
		$this->clientAddress = $client;
		$this->socket = $socket;
	}

	public function writePacketToServer(string $buffer){
		$this->time = time();
		$address = $this->serverAddress;
		$this->socket->writePacket($buffer, $address->ip, $address->port);
	}

	public function receivePacketFromServer(&$buffer) : bool{
		$len = $this->socket->readPacket($buffer, $ip, $port);
		if($len === false || $len < 1){
			return false;
		}
		return true;
	}

	public function writePacketToClient(UDPServerSocket $socket, string $buffer){
		$address = $this->clientAddress;
		$socket->writePacket($buffer, $address->ip, $address->port);
	}

	public function uptime() : bool{
		if(time() - $this->time >= 10){
			$this->socket->close();
			return false;
		}
		return true;
	}

	public function getAddress() : InternetAddress{
		return $this->clientAddress;
	}

}