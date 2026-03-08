<?php

namespace SignSell;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\Server;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\block\tile\Sign;
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
            if($sender instanceof Player){

                $cfg = $this->getConfig()->get("shop-teleport");

                $world = Server::getInstance()->getWorldManager()->getWorldByName($cfg["world"]);

                if($world === null){
                    $sender->sendMessage("§cWorld not found.");
                    return true;
                }

                $sender->teleport(new Position($cfg["x"], $cfg["y"], $cfg["z"], $world));
                $sender->sendMessage($this->getConfig()->get("messages")["teleport"]);
            }
        }
        return true;
    }

    public function onSignChange(SignChangeEvent $event): void{

        $player = $event->getPlayer();

        if(!$player->hasPermission("signsell.create")){
            return;
        }

        $line1 = strtolower($event->getLine(0));
        $item = strtolower($event->getLine(1));
        $amount = (int)$event->getLine(2);
        $price = (int)$event->getLine(3);

        if($line1 !== "[buy]" && $line1 !== "[sell]"){
            return;
        }

        $parsed = StringToItemParser::getInstance()->parse($item);

        if($parsed === null){
            $player->sendMessage($this->getConfig()->get("messages")["invalid-item"]);
            return;
        }

        $event->setLine(0, "§a" . strtoupper(str_replace(["[","]"], "", $line1)));
        $event->setLine(1, $item);
        $event->setLine(2, (string)$amount);
        $event->setLine(3, "$" . $price);

        $player->sendMessage($this->getConfig()->get("messages")["shop-created"]);
    }

    public function onInteract(PlayerInteractEvent $event): void{

        $player = $event->getPlayer();
        $block = $event->getBlock();
        $tile = $player->getWorld()->getTile($block->getPosition());

        if(!$tile instanceof Sign){
            return;
        }

        $text = $tile->getText()->getLines();

        $type = strtolower($text[0]);
        $itemName = strtolower($text[1]);
        $amount = (int)$text[2];
        $price = (int)str_replace("$","",$text[3]);

        if($type !== "§abuy" && $type !== "§asell"){
            return;
        }

        $name = $player->getName();

        if(!isset($this->confirm[$name])){
            $this->confirm[$name] = [
                "time" => time(),
                "pos" => $block->getPosition()
            ];

            $player->sendMessage($this->getConfig()->get("messages")["tap-confirm"]);
            return;
        }

        if(time() - $this->confirm[$name]["time"] > $this->getConfig()->get("confirm-time")){
            unset($this->confirm[$name]);
            $player->sendMessage($this->getConfig()->get("messages")["confirm-expired"]);
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
                $player->sendMessage($this->getConfig()->get("messages")["no-money"]);
                return;
            }

            $economy->reduceMoney($player, $price);
            $player->getInventory()->addItem($item);

            $msg = str_replace(
                ["{amount}","{item}","{price}"],
                [$amount,$itemName,$price],
                $this->getConfig()->get("messages")["bought"]
            );

            $player->sendMessage($msg);
        }

        if($type === "§asell"){

            if(!$player->getInventory()->contains($item)){
                $player->sendMessage($this->getConfig()->get("messages")["no-items"]);
                return;
            }

            $player->getInventory()->removeItem($item);
            $economy->addMoney($player, $price);

            $msg = str_replace(
                ["{amount}","{item}","{price}"],
                [$amount,$itemName,$price],
                $this->getConfig()->get("messages")["sold"]
            );

            $player->sendMessage($msg);
        }

        unset($this->confirm[$name]);
    }
}
