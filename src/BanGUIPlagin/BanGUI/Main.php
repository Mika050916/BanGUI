<?php

namespace NewPlugin\NewBans;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {
	private Config $config;
	private Config $database;

	public function onEnable(): void {
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
			"permissions" => [
				"mute" => [
					"newplugin.first" => 60,
					"newplugin.second" => 70
				],
				"ban" => [
					"newplugin.first" => 5,
					"newplugin.second" => 10
				]
			]
		]);
		$this->database = new Config($this->getDataFolder() . "database.yml", Config::JSON);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if (!($sender instanceof Player)) {
			return TRUE;
		}

		switch ($command->getName()) {
			case "unmute":
			{
				$form = new CustomForm(function (Player $player, ?array $data) {
					if ($data === NULL) return;

					if ($this->database->getNested("mutes." . $data[0]) === NULL) {
						$player->sendMessage("Игрок не замучен!");
						return;
					}

					$this->database->remove("mutes." . $data[0]);
					$this->database->save();

					$player->sendMessage("Игрок $data[0] был успешно размучен");
				});

				$form->setTitle("Размутить игрока");
				$form->addInput("Ник игрока", "Введите ник игрока");
				$sender->sendForm($form);
				return TRUE;
			}

			case "sunban":
			{
				$form = new CustomForm(function (Player $player, ?array $data) {
					if ($data === NULL) return;

					if ($this->database->getNested("bans." . $data[0]) === NULL) {
						$player->sendMessage("Игрок не заблокирован!");
						return;
					}

					$this->database->remove("bans." . $data[0]);
					$this->database->save();

					$player->sendMessage("Игрок $data[0] был успешно разблокирован");
				});

				$form->setTitle("Разблокировать игрока");
				$form->addInput("Ник игрока", "Введите ник игрока");
				$sender->sendForm($form);
				return TRUE;
			}

			case "unwarn":
			{
				$form = new CustomForm(function (Player $player, ?array $data) {
					if ($data === NULL) return;

					if (!$this->database->getNested("warns." . $data[0])) {
						$player->sendMessage("Игрок не имеет варнов!");
						return;
					}

					$get = $this->database->getNested("warns." . $data[0], 0);
					if ($get === 0) {
						$player->sendMessage("Игрок не имеет варнов!");
						return;
					}

					$this->database->setNested("warns." . $data[0], $get - 1);
					$this->database->save();

					$player->sendMessage("У игрока $data[0] был снят варн");
				});

				$form->setTitle("Снять варн");
				$form->addInput("Ник игрока", "Введите ник игрока");
				$sender->sendForm($form);
				return TRUE;
			}

			case "sban":
			{
				$form = new CustomForm(function (Player $player, ?array $data) {
					if ($data === NULL) return;

					$to = Server::getInstance()->getPlayerByPrefix($data[0]);
					if ($to === NULL) {
						$player->sendMessage("Игрок не найден!");
						return;
					}

					$time = (int)$data[1];
					if ($time < 1 || $time > 30) {
						$player->sendMessage("Обнаружена попытка взлома.");
						return;
					}

					$max = 0;
					foreach ($this->config->getNested("permissions")["ban"] as $perm => $value) {
						if ($player->hasPermission($perm)) {
							$max = $value;
						}
					}

					if ($time > $max) {
						$player->sendMessage("Вы не можете банить на такое большое рвемя, ваш лимит: $max");
						return;
					}

					$expire = time() + $time * 24 * 60 * 60;
					$this->database->setNested("bans.{$to->getName()}", [
						"by" => $player->getName(),
						"reason" => $data[2],
						"time" => $expire
					]);
					$this->database->save();

					$to->kick($this->getKickMessage($data[2], $expire));

					if (!isset($data[3]) || $data[3] === FALSE) {
						Server::getInstance()->broadcastMessage("Игрок {$player->getDisplayName()} заблокировал игрока {$to->getDisplayName()} по причине: {$data[2]} на $time дней");
					}
				});

				$form->setTitle("Заблокировать игрока");
				$form->addInput("Ник игрока", "Введите ник игрока");
				$form->addSlider("Выберите время (дни)", 1, 30);
				$form->addInput("Причина", "Введите причину");
				if ($sender->hasPermission("newplugin.nomessage")) {
					$form->addToggle("Не оповещать");
				}
				$sender->sendForm($form);
				return TRUE;
			}

			case "warn":
			{
				$form = new CustomForm(function (Player $player, ?array $data) {
					if ($data === NULL) return;

					$to = Server::getInstance()->getPlayerByPrefix($data[0]);
					if ($to === NULL) {
						$player->sendMessage("Игрок не найден!");
						return;
					}

					$get = $this->database->getNested("warns." . $to->getName(), 0);
					$this->database->setNested("warns." . $to->getName(), $get + 1);
					$this->database->save();

					if ($get === 2) {
						$expire = time() + 7 * 24 * 60 * 60; // 7 дней за 3 предупреждения
						$this->database->setNested("bans.{$to->getName()}", [
							"by" => $player->getName(),
							"reason" => $reason = "Превышено количество предупреждений",
							"time" => $expire
						]);
						$this->database->save();
						$to->kick($this->getKickMessage($reason, $expire));
						return;
					}

					if (!isset($data[1]) || $data[1] === FALSE) {
						Server::getInstance()->broadcastMessage("Игрок {$player->getDisplayName()} выдал варн игроку {$to->getDisplayName()}, теперь у него " . ($get + 1) . " варнов");
					}
				});

				$form->setTitle("Выдать предупреждение");
				$form->addInput("Ник игрока", "Введите ник игрока");
				if ($sender->hasPermission("newplugin.nomessage")) {
					$form->addToggle("Не оповещать");
				}
				$sender->sendForm($form);
				return TRUE;
			}

			case "mute":
			{
				$form = new CustomForm(function (Player $player, ?array $data) {
					if ($data === NULL) return;

					$to = Server::getInstance()->getPlayerByPrefix($data[0]);
					if ($to === NULL) {
						$player->sendMessage("Игрок не найден!");
						return;
					}

					$time = (int)$data[1];
					if ($time < 1 || $time > 3600) {
						$player->sendMessage("Обнаружена попытка взлома.");
						return;
					}

					$max = 0;
					foreach ($this->config->getNested("permissions")["mute"] as $perm => $value) {
						if ($player->hasPermission($perm)) {
							$max = $value;
						}
					}

					if ($time > $max) {
						$player->sendMessage("Вы не можете мутить на такое большое рвемя, ваш лимит: $max");
						return;
					}

					$this->database->setNested("mutes.{$to->getName()}", [
						"by" => $player->getName(),
						"reason" => $data[2],
						"time" => time() + $time * 60
					]);
					$this->database->save();

					if (!isset($data[3]) || $data[3] === FALSE) {
						Server::getInstance()->broadcastMessage("Игрок {$player->getDisplayName()} замутил игрока {$to->getDisplayName()} по причине: {$data[2]} на $time минут");
					}
				});

				$form->setTitle("Замутить игрока");
				$form->addInput("Ник игрока", "Введите ник игрока");
				$form->addSlider("Выберите время (минуты)", 1, 3600);
				$form->addInput("Причина", "Введите причину");
				if ($sender->hasPermission("newplugin.nomessage")) {
					$form->addToggle("Не оповещать");
				}
				$sender->sendForm($form);
				return TRUE;
			}
		}

		return TRUE;
	}

	public function onJoin(PlayerPreLoginEvent $event): void {
		$player = $event->getPlayerInfo()->getUsername();

		$val = $this->database->getNested("bans." . $player);
		if ($val !== NULL) {
			if ($val["time"] > time()) {
				$event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_BANNED, $this->getKickMessage($val["reason"], $val["time"]));
			} else {
				$this->database->remove("bans." . $player);
			}
		}
	}

	public function onChat(PlayerChatEvent $event): void {
		$player = $event->getPlayer();

		$val = $this->database->getNested("mutes." . $player->getName());
		if ($val !== NULL) {
			if ($val["time"] > time()) {
				$player->sendMessage("Вы находитесь в муте, истечет через: " . round(($val["time"] - time()) / 60) . " минут");
				$event->cancel();
			} else {
				$this->database->remove("mutes." . $player->getName());
				$this->database->save();
				$player->sendMessage("У вас истек срок мута");
			}
		}
	}

	private function getKickMessage(string $reason, int $time): string {
		return "Вы были заблокированы\nПричина: $reason\nВы будете разблокированы через " . round(($time - time()) / 60 / 60) . " часов";
	}
}