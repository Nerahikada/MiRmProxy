<?php

declare(strict_types=1);

namespace pocketmine;

use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\PacketStream;
use pocketmine\utils\Config;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\OfflineMessage;
use raklib\server\UDPServerSocket;
use raklib\utils\InternetAddress;

class MiRmProxy{

	private const MCPE_RAKNET_PACKET_ID = "\xfe";
	private const MAX_SPLIT_SIZE = 128;
	private const MAX_SPLIT_COUNT = 4;

	private const PACKET_LIMIT = 200;
	private const SESSION_LIMIT = 10;

	/** @var Proxy */
	private static $instance = null;

	/** @var Config */
	private $config;
	/** @var Config */
	private $properties;

	/** @var int[] string (address) => int (unblock time) */
	private $block = [];
	/** @var int */
	private $lastSec = 0;
	/** @var int[] string (address) => int (number of packets) */
	private $ipSec = [];
	/** @var int[] */
	private $ipSession = [];

	/** @var UDPServerSocket */
	private $socket;

	/** @var InternetAddress */
	private $serverAddress;

	/** @var InternetAddress */
	private $reusableAddress;

	/** @var Client[] */
	private $clients = [];

	/** @var Datagram[][] */
	private $splitPackets = [];

	public function __construct(){
		if(self::$instance !== null){
			throw new Exception("Only one proxy instance can exist at once");
		}
		self::$instance = $this;

		echo \pocketmine\NAME . " v" . \pocketmine\VERSION . PHP_EOL;
		echo "Support protocol: " . \pocketmine\PROTOCOL . PHP_EOL;

		echo "Loading proxy settings..." . PHP_EOL;
		$this->config = new Config(\pocketmine\DATA . "config.yml", Config::YAML, [
			"server-ip" => "example.com"
		]);

		echo "Loading server properties..." . PHP_EOL;
		$this->properties = new Config(\pocketmine\DATA . "server.properties", Config::PROPERTIES, [
			"server-port" => 19132
		]);

		PacketPool::init();
		RakLibPacketPool::init();

		echo "Bind port: " . $this->properties->get("server-port") . PHP_EOL;
		$address = new InternetAddress("0.0.0.0", (int) $this->properties->get("server-port"), 4);
		$this->socket = new UDPServerSocket($address);

		$host = gethostbyname($this->config->get("server-ip"));
		echo "Target IP Address: " . $host . PHP_EOL;
		$this->serverAddress = new InternetAddress($host, 19132, 4);

		$this->reusableAddress = clone $address;

		echo "Start proxy!" . PHP_EOL;
		while(true){
			$this->receivePacketFromClient();
			$this->receivePacketFromServer();
		}
	}

	private function receivePacketFromClient(){
		$address = $this->reusableAddress;

		$len = $this->socket->readPacket($buffer, $address->ip, $address->port);
		if($len === false || $len < 1){
			return;
		}

		if(isset($this->block[$address->ip])){
			return;
		}

		$now = time();
		if($this->lastSec !== $now){
			$this->lastSec = $now;
			$this->ipSec = [];
		}

		if(isset($this->ipSec[$address->ip])){
			if(++$this->ipSec[$address->ip] >= self::PACKET_LIMIT){
				echo "Receive too much packet" . PHP_EOL;
				$this->blockAddress($address->ip);
				return;
			}
		}else{
			$this->ipSec[$address->ip] = 1;
		}

		if(isset($this->ipSession[$address->ip])){
			if($this->ipSession[$address->ip] >= self::SESSION_LIMIT){
				echo "Create over session" . PHP_EOL;
				$this->blockAddress($address->ip);
				return;
			}
		}else{
			$this->ipSession[$address->ip] = 1;
		}

		$client = $this->openSession($address);
		try{
			$pid = ord($buffer{0});
			if(!(RakLibPacketPool::getPacket($pid, $buffer) instanceof OfflineMessage)
					&& ($pid & Datagram::BITFLAG_VALID) !== 0
					&& !($pid & Datagram::BITFLAG_ACK) && !($pid & Datagram::BITFLAG_NAK)
					&& !$client->loggedIn){
				$datagram = new Datagram($buffer);
				if($datagram instanceof Datagram){
					$datagram->decode();
					foreach($datagram->packets as $pk){
						if($pk->hasSplit){
							$pk = $this->handleSplit($pk);
						}
						if($pk !== null){
							$id = ord($pk->buffer{0});
							if($id >= MessageIdentifiers::ID_USER_PACKET_ENUM){
								$pk = EncapsulatedPacket::fromInternalBinary($pk->toInternalBinary());
								if($pk->buffer !== "" && $pk->buffer{0} === self::MCPE_RAKNET_PACKET_ID){ //Batch
									$payload = substr($pk->buffer, 1);
									$payload = @zlib_decode($payload, 1024 * 1024 * 64); //Max 64MB
									if($payload !== false){
										$stream = new PacketStream($payload);
										while(!$stream->feof()){
											$packet = PacketPool::getPacket($stream->getString());
											if($packet instanceof LoginPacket){
												$client->loggedIn = true;
												$packet->decode();
												if(!$packet->feof() && !$packet->mayHaveUnreadBytes()){}
												####################################
												unset($packet->skin);
												unset($packet->chainData);
												unset($packet->clientDataJwt);
												unset($packet->identityPublicKey);
												unset($packet->clientData["CapeData"]);
												unset($packet->clientData["PremiumSkin"]);
												unset($packet->clientData["SkinData"]);
												unset($packet->clientData["SkinGeometry"]);
												unset($packet->clientData["SkinGeometryName"]);
												unset($packet->clientData["SkinId"]);
												unset($packet->offset);
												unset($packet->buffer);
												####################################
												var_dump($packet);
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}catch(\Throwable $e){
			echo "Packet from " . $address->ip . " (" . strlen($buffer) . " bytes): 0x" . bin2hex($buffer) . PHP_EOL;
			echo $e->getMessage() . PHP_EOL;
			echo $e->getTraceAsString() . PHP_EOL;
			$this->blockAddress($address->ip);
		}

		$client->writePacketToServer($buffer);
	}

	public function handleSplit(EncapsulatedPacket $packet) : ?EncapsulatedPacket{
		if($packet->splitCount >= self::MAX_SPLIT_SIZE or $packet->splitIndex >= self::MAX_SPLIT_SIZE or $packet->splitIndex < 0){
			return null;
		}

		if(!isset($this->splitPackets[$packet->splitID])){
			if(count($this->splitPackets) >= self::MAX_SPLIT_COUNT){
				return null;
			}
			$this->splitPackets[$packet->splitID] = [$packet->splitIndex => $packet];
		}else{
			$this->splitPackets[$packet->splitID][$packet->splitIndex] = $packet;
		}

		if(count($this->splitPackets[$packet->splitID]) === $packet->splitCount){
			$pk = new EncapsulatedPacket();
			$pk->buffer = "";

			$pk->reliability = $packet->reliability;
			$pk->messageIndex = $packet->messageIndex;
			$pk->sequenceIndex = $packet->sequenceIndex;
			$pk->orderIndex = $packet->orderIndex;
			$pk->orderChannel = $packet->orderChannel;

			for($i = 0; $i < $packet->splitCount; ++$i){
				$pk->buffer .= $this->splitPackets[$packet->splitID][$i]->buffer;
			}

			$pk->length = strlen($pk->buffer);
			unset($this->splitPackets[$packet->splitID]);

			return $pk;
		}

		return null;
	}

	private function receivePacketFromServer(){
		foreach($this->clients as $key => $client){
			if($client->receivePacketFromServer($buffer)){
				$client->writePacketToClient($this->socket, $buffer);
			}
			if(!$client->uptime()){
				$this->closeSession($client);
			}
		}
	}

	private function createSocket() : UDPServerSocket{
		$address = new InternetAddress("0.0.0.0", 19132, 4);
		while(true){
			$continue = false;
			try{
				$port = mt_rand(100, 65535);
				$address->port = $port;
				$socket = new UDPServerSocket($address);
			}catch(\Exception $e){
				$continue = true;
			}
			if(!$continue) break;
		}
		return $socket;
	}

	private function openSession(InternetAddress $address) : Client{
		$sessionKey = $address->toString();
		if(!isset($this->clients[$sessionKey])){
			$socket = $this->createSocket();
			echo "Open new session (" . $sessionKey . " -> " . $socket->getBindAddress()->port . ")" . PHP_EOL;
			++$this->ipSession[$address->ip];
			$this->clients[$sessionKey] = new Client($this->serverAddress, clone $address, $socket);
		}

		return $this->clients[$sessionKey];
	}

	private function closeSession(Client $client){
		$address = $client->getAddress();
		$sessionKey = $address->toString();

		echo "Close session (" . $sessionKey . ")" . PHP_EOL;
		--$this->ipSession[$address->ip];
		unset($this->clients[$sessionKey]);
	}

	private function blockAddress(string $address){
		echo "Blocked " . $address . PHP_EOL;
		$this->block[$address] = true;
	}

}