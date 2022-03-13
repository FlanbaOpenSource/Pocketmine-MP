<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\data\bedrock\LegacyBlockIdToStringIdMap;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\player\Player;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use Webmozart\PathUtil\Path;
use function file_get_contents;

/**
 * @internal
 */
final class RuntimeBlockMapping{
	use SingletonTrait;

	public const CANONICAL_BLOCK_STATES_PATH = 0;
	public const R12_TO_CURRENT_BLOCK_MAP_PATH = 1;

	/** @var int[][] */
	private $legacyToRuntimeMap = [];
	/** @var int[][] */
	private $runtimeToLegacyMap = [];
	/** @var CompoundTag[][] */
	private $bedrockKnownStates = [];

	private function __construct(){
		$protocolPaths = [
			ProtocolInfo::CURRENT_PROTOCOL => [
				self::CANONICAL_BLOCK_STATES_PATH => '',
				self::R12_TO_CURRENT_BLOCK_MAP_PATH => '',
			],
			ProtocolInfo::PROTOCOL_1_18_0 => [
				self::CANONICAL_BLOCK_STATES_PATH => '-1.18.0',
				self::R12_TO_CURRENT_BLOCK_MAP_PATH => '-1.18.0',
			],
			ProtocolInfo::PROTOCOL_1_17_40 => [ // 1.18.0 has negative chunk hacks
				self::CANONICAL_BLOCK_STATES_PATH => '-1.18.0',
				self::R12_TO_CURRENT_BLOCK_MAP_PATH => '-1.18.0',
			],
			ProtocolInfo::PROTOCOL_1_17_30 => [
				self::CANONICAL_BLOCK_STATES_PATH => '-1.17.30',
				self::R12_TO_CURRENT_BLOCK_MAP_PATH => '-1.18.0',
			],
			ProtocolInfo::PROTOCOL_1_17_10 => [
				self::CANONICAL_BLOCK_STATES_PATH => '-1.17.10',
				self::R12_TO_CURRENT_BLOCK_MAP_PATH => '-1.17.10',
			],
			ProtocolInfo::PROTOCOL_1_17_0 => [
				self::CANONICAL_BLOCK_STATES_PATH => '-1.17.0',
				self::R12_TO_CURRENT_BLOCK_MAP_PATH => '-1.17.10',
			]
		];

		foreach($protocolPaths as $mappingProtocol => $paths){
			$stream = PacketSerializer::decoder(
				Utils::assumeNotFalse(file_get_contents(Path::join(\pocketmine\BEDROCK_DATA_PATH, "canonical_block_states" . $paths[self::CANONICAL_BLOCK_STATES_PATH] . ".nbt")), "Missing required resource file"),
				0,
				new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary(GlobalItemTypeDictionary::getDictionaryProtocol($mappingProtocol)))
			);
			$list = [];
			while(!$stream->feof()){
				$list[] = $stream->getNbtCompoundRoot();
			}
			$this->bedrockKnownStates[$mappingProtocol] = $list;

			$this->setupLegacyMappings($mappingProtocol, $paths[self::R12_TO_CURRENT_BLOCK_MAP_PATH]);
		}
	}

	public static function getMappingProtocol(int $protocolId) : int{
		return $protocolId;
	}

	/**
	 * @param Player[] $players
	 *
	 * @return Player[][]
	 */
	public static function sortByProtocol(array $players) : array{
		$sortPlayers = [];

		foreach($players as $player){
			$mappingProtocol = self::getMappingProtocol($player->getNetworkSession()->getProtocolId());

			if(isset($sortPlayers[$mappingProtocol])){
				$sortPlayers[$mappingProtocol][] = $player;
			}else{
				$sortPlayers[$mappingProtocol] = [$player];
			}
		}

		return $sortPlayers;
	}

	private function setupLegacyMappings(int $mappingProtocol, string $path) : void{
		$legacyIdMap = LegacyBlockIdToStringIdMap::getInstance();
		/** @var R12ToCurrentBlockMapEntry[] $legacyStateMap */
		$legacyStateMap = [];
		$legacyStateMapReader = PacketSerializer::decoder(
			Utils::assumeNotFalse(file_get_contents(Path::join(\pocketmine\BEDROCK_DATA_PATH, "r12_to_current_block_map" . $path . ".bin")), "Missing required resource file"),
			0,
			new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary(GlobalItemTypeDictionary::getDictionaryProtocol($mappingProtocol)))
		);
		$nbtReader = new NetworkNbtSerializer();
		while(!$legacyStateMapReader->feof()){
			$id = $legacyStateMapReader->getString();
			$meta = $legacyStateMapReader->getLShort();

			$offset = $legacyStateMapReader->getOffset();
			$state = $nbtReader->read($legacyStateMapReader->getBuffer(), $offset)->mustGetCompoundTag();
			$legacyStateMapReader->setOffset($offset);
			$legacyStateMap[] = new R12ToCurrentBlockMapEntry($id, $meta, $state);
		}

		/**
		 * @var int[][] $idToStatesMap string id -> int[] list of candidate state indices
		 */
		$idToStatesMap = [];
		foreach($this->bedrockKnownStates[$mappingProtocol] as $k => $state){
			$idToStatesMap[$state->getString("name")][] = $k;
		}
		foreach($legacyStateMap as $pair){
			$id = $legacyIdMap->stringToLegacy($pair->getId());
			if($id === null){
				throw new \RuntimeException("No legacy ID matches " . $pair->getId());
			}
			$data = $pair->getMeta();
			if($data > 15){
				//we can't handle metadata with more than 4 bits
				continue;
			}
			$mappedState = $pair->getBlockState();
			$mappedName = $mappedState->getString("name");
			if(!isset($idToStatesMap[$mappedName])){
				throw new \RuntimeException("Mapped new state does not appear in network table");
			}
			foreach($idToStatesMap[$mappedName] as $k){
				$networkState = $this->bedrockKnownStates[$mappingProtocol][$k];
				if($mappedState->equals($networkState)){
					$this->registerMapping($mappingProtocol, $k, $id, $data);
					continue 2;
				}
			}
			throw new \RuntimeException("Mapped new state does not appear in network table");
		}
	}

	public function toRuntimeId(int $internalStateId, int $mappingProtocol = ProtocolInfo::CURRENT_PROTOCOL) : int{
		return $this->legacyToRuntimeMap[$internalStateId][$mappingProtocol] ?? $this->legacyToRuntimeMap[BlockLegacyIds::INFO_UPDATE << Block::INTERNAL_METADATA_BITS][$mappingProtocol];
	}

	public function fromRuntimeId(int $runtimeId, int $mappingProtocol = ProtocolInfo::CURRENT_PROTOCOL) : int{
		return $this->runtimeToLegacyMap[$runtimeId][$mappingProtocol];
	}

	private function registerMapping(int $mappingProtocol, int $staticRuntimeId, int $legacyId, int $legacyMeta) : void{
		$this->legacyToRuntimeMap[($legacyId << Block::INTERNAL_METADATA_BITS) | $legacyMeta][$mappingProtocol] = $staticRuntimeId;
		$this->runtimeToLegacyMap[$staticRuntimeId][$mappingProtocol] = ($legacyId << Block::INTERNAL_METADATA_BITS) | $legacyMeta;
	}

	/**
	 * @return CompoundTag[]
	 */
	public function getBedrockKnownStates(int $mappingProtocol = ProtocolInfo::CURRENT_PROTOCOL) : array{
		return $this->bedrockKnownStates[$mappingProtocol];
	}
}
