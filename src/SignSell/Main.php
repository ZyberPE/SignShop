<?php

namespace SignSell;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerInteractEvent;

use pocketmine\block\tile\Sign;
use pocketmine\block\utils\SignText;

use pocketmine\item\StringToItemParser;

use onebone\economyapi\EconomyAPI;

class Main extends PluginBase implements Listener{

    private array $confirm = [];

    public function onEnable(): void{
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{

        if($command->getName() === "shop"){

            if(!$sender instanceof Player){
                return true;
            }

            $cfg = $this->getConfig()->get("shop");

            $world = Server::getInstance()->getWorldManager()->getWorldByName($cfg["world"]);

            if($world === null){
                $sender->sendMessage("§cWorld not found.");
                return true;
            }

            $sender->teleport(new Position($cfg["x"], $cfg["y"], $cfg["z"], $world));
            $sender->sendMessage($this->getConfig()->getNested("messages.teleport"));
        }

        return true;
    }

    public function onSignChange(SignChangeEvent $event): void{

        $player = $event->getPlayer();

        if(!$player->hasPermission("signsell.create")){
            return;
        }

        $text = $event->getNewText();

        $type = strtolower($text->getLine(0));
        $itemName = strtolower($text->getLine(1));
        $amount = (int)$text->getLine(2);
        $price = (int)$text->getLine(3);

        if($type !== "[buy]" && $type !== "[sell]"){
            return;
        }

        $item = StringToItemParser::getInstance()->parse($itemName);

        if($item === null){
            $player->sendMessage($this->getConfig()->getNested("messages.invalid-item"));
            return;
        }

        $typeFormatted = strtoupper(str_replace(["[","]"], "", $type));

        $event->setNewText(new SignText([
            "§a".$typeFormatted,
            $itemName,
            (string)$amount,
            "$".$price
        ]));

        $player->sendMessage($this->getConfig()->getNested("messages.shop-created"));
    }

    public function onInteract(PlayerInteractEvent $event): void{

        $player = $event->getPlayer();
        $block = $event->getBlock();

        $tile = $player->getWorld()->getTile($block->getPosition());

        if(!$tile instanceof Sign){
            return;
        }

        $lines = $tile->getText()->getLines();

        $type = strtolower($lines[0]);

        if($type !== "§abuy" && $type !== "§asell"){
            return;
        }

        $itemName = strtolower($lines[1]);
        $amount = (int)$lines[2];
        $price = (int)str_replace("$","",$lines[3]);

        $playerName = $player->getName();
        $pos = $block->getPosition()->asVector3()->__toString();

        if(!isset($this->confirm[$playerName]) || $this->confirm[$playerName]["pos"] !== $pos){

            $this->confirm[$playerName] = [
                "time" => time(),
                "pos" => $pos
            ];

            $player->sendMessage($this->getConfig()->getNested("messages.tap-confirm"));
            return;
        }

        if(time() - $this->confirm[$playerName]["time"] > $this->getConfig()->get("confirm-seconds")){
            unset($this->confirm[$playerName]);
            $player->sendMessage($this->getConfig()->getNested("messages.expired"));
            return;
        }

        $item = StringToItemParser::getInstance()->parse($itemName);

        if($item === null){
            return;
        }

        $item->setCount($amount);

        $economy = EconomyAPI::getInstance();

        if($type === "§abuy"){

            if($economy->myMoney($player) < $price){
                $player->sendMessage($this->getConfig()->getNested("messages.not-enough-money"));
                return;
            }

            $economy->reduceMoney($player, $price);
            $player->getInventory()->addItem($item);

            $msg = str_replace(
                ["{amount}","{item}","{price}"],
                [$amount,$itemName,$price],
                $this->getConfig()->getNested("messages.bought")
            );

            $player->sendMessage($msg);
        }

        if($type === "§asell"){

            if(!$player->getInventory()->contains($item)){
                $player->sendMessage($this->getConfig()->getNested("messages.not-enough-items"));
                return;
            }

            $player->getInventory()->removeItem($item);
            $economy->addMoney($player, $price);

            $msg = str_replace(
                ["{amount}","{item}","{price}"],
                [$amount,$itemName,$price],
                $this->getConfig()->getNested("messages.sold")
            );

            $player->sendMessage($msg);
        }

        unset($this->confirm[$playerName]);
    }
}
