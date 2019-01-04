<?php

declare(strict_types=1);

namespace pocketmine;

use raklib\protocol\ACK;
use raklib\protocol\AdvertiseSystem;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\Packet;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPingOpenConnections;
use raklib\protocol\UnconnectedPong;

class RakLibPacketPool{

	/** @var \SplFixedArray<Packet|null> */
	private static $packetPool;

	public static function init(){
		self::$packetPool = new \SplFixedArray(256);

		self::$packetPool[UnconnectedPing::$ID] = new UnconnectedPing;
		self::$packetPool[UnconnectedPingOpenConnections::$ID] = new UnconnectedPingOpenConnections;
		self::$packetPool[OpenConnectionRequest1::$ID] = new OpenConnectionRequest1;
		self::$packetPool[OpenConnectionReply1::$ID] = new OpenConnectionReply1;
		self::$packetPool[OpenConnectionRequest2::$ID] = new OpenConnectionRequest2;
		self::$packetPool[OpenConnectionReply2::$ID] = new OpenConnectionReply2;
		self::$packetPool[UnconnectedPong::$ID] = new UnconnectedPong;
		self::$packetPool[AdvertiseSystem::$ID] = new AdvertiseSystem;
	}

	/**
	 * @param int    $id
	 * @param string $buffer
	 *
	 * @return Packet|null
	 */
	public static function getPacket(int $id, string $buffer = ""){
		$pk = self::$packetPool[$id];
		if($pk !== null){
			$pk = clone $pk;
			$pk->buffer = $buffer;
			return $pk;
		}

		return null;
	}
	
}