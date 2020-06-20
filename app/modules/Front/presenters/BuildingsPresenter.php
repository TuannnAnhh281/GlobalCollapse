<?php

declare(strict_types=1);

namespace App\FrontModule\Presenters;

use App\Model;
use DateTime;
use App\Model\UserRepository;
use App\Model\BuildingsRepository;
use Timezones;

final class BuildingsPresenter extends GamePresenter
{
	private $userRepository;
  public $buildingsRepository;

	public function __construct(
		UserRepository $userRepository,
		BuildingsRepository $buildingsRepository
	)
	{
		$this->userRepository = $userRepository;
		$this->buildingsRepository = $buildingsRepository;
	}

	protected function startup()
	{
			parent::startup();
	}

  public function renderDefault() {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$playerLand = $this->buildingsRepository->findPlayerLand($player->id)->fetch();
		$this->template->land = $playerLand;
		if (!$playerLand) {
			$this->template->emptyLand = $this->buildingsRepository->getLandPrice();
		} else {
			$playerBuildings = $this->buildingsRepository->findPlayerBuildings($player->id);
			$this->template->playerBuildings = $playerBuildings;
			$unlockedBuildings = $this->buildingsRepository->findAllUnlocked($player->id)->order('buildings.price DESC');
			$this->template->unlockedBuildings = $unlockedBuildings;
			$playerIncome = $this->buildingsRepository->findPlayerIncome($player->id)->fetch();
			$this->template->playerIncome = $playerIncome;
			$this->template->landUpgradeCost = $this->buildingsRepository->getLandUpgradeCost($playerLand->level);
			if (isset($playerIncome->last_collection)) {
				$this->template->lastCollection = Timezones::getUserTime($playerIncome->last_collection, $this->userPrefs->timezone);
				$updated = $playerIncome->last_collection;
				$now = new DateTime();
				$diff = abs($updated->getTimestamp() - $now->getTimestamp());
				if ($diff < 3600) {
					$this->template->timeAgo = round($diff / 60) . ' minutes';
				} else if ($diff <= 5400) {
					$this->template->timeAgo = round($diff / 3600) . ' hour';
				} else {
					$this->template->timeAgo = round($diff / 3600) . ' hours';
				}
			} else {
				$this->template->noLastCollection = true;
				$testIncome = $this->buildingsRepository->findPlayerIncome(1)->fetch();
				$updated = $testIncome->last_collection;
				$now = new DateTime();
				$diff = abs($updated->getTimestamp() - $now->getTimestamp());
				if ($diff < 3600) {
					$this->template->timeAgo = round($diff / 60) . ' minutes';
				} else if ($diff <= 5400) {
					$this->template->timeAgo = round($diff / 3600) . ' hour';
				} else {
					$this->template->timeAgo = round($diff / 3600) . ' hours';
				}
			}
		}
	}

	public function renderLands() {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$playerLand = $this->buildingsRepository->findPlayerLand($player->id)->fetch();
		$this->template->land = $playerLand;
	}

	public function actionUpgradeLand() {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$playerLand = $this->buildingsRepository->findPlayerLand($player->id)->fetch();
		if (isset($playerLand->level) && $playerLand->level >= 1 && !$playerLand->is_upgrading) {
			$cost = $this->buildingsRepository->getLandUpgradeCost($playerLand->level);
			$playerMoney = $player->money;
			if ($playerMoney >= $cost) {
				$this->buildingsRepository->startLandUpgrade($player->id);
				$this->userRepository->addMoney($player->id, -$cost);
				$this->flashMessage($this->translate('general.messages.success.landUpgradeStart'), 'success');
				$this->redirect('Buildings:default');
			} else {
				$this->flashMessage($this->translate('general.messages.danger.notEnoughMoney'), 'danger');
				$this->redirect('Buildings:default');
			}
		} else {
			$this->redirect('Buildings:default');
		}
	}

	public function actionBuyLand() {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$cost = $this->buildingsRepository->getLandPrice();
		$playerMoney = $player->money;
		if ($playerMoney >= $cost && !$this->buildingsRepository->findPlayerLand($player->id)->fetch()) {
			$this->buildingsRepository->buyLand($player->id);
			$this->userRepository->addMoney($player->id, -$cost);
			$this->flashMessage($this->translate('general.messages.success.landBought'), 'success');
			$this->redirect('Buildings:default');
		} else {
			$this->flashMessage($this->translate('general.messages.danger.notEnoughMoney'), 'danger');
			$this->redirect('Buildings:default');
		}
	}

	public function actionBuyBuilding(int $b) {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$building = $this->buildingsRepository->getBuilding($b)->fetch();
		if (isset($building->user_id) && $building->user_id === $player->id && $building->level === 0) {
			$playerMoney = $player->money;
			$cost = $building->buildings->price;
			if ($playerMoney >= $cost) {
				$this->buildingsRepository->buyBuilding($player->id, $building->buildings_id, $b);
				$this->userRepository->addMoney($player->id, -$cost);
				$this->flashMessage($this->translate('general.messages.success.buildingBought'), 'success');
				$this->redirect('Buildings:default');
			} else {
				$this->flashMessage($this->translate('general.messages.danger.notEnoughMoney'), 'danger');
				$this->redirect('Buildings:default');
			}
		}
	}

	public function actionUpgrade(int $b) {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$building = $this->buildingsRepository->getBuilding($b)->fetch();
		if (isset($building->user_id) && $building->user_id === $player->id) {
			$playerMoney = $player->money;
			$cost = $this->getUpgradeCost($building->buildings->price, $building->level);
			if (!$building->buildings->max_level || $building->level < $building->buildings->max_level) {
				if ($playerMoney >= $cost) {
					if ($this->buildingsRepository->upgradeBuilding($b, $player->id)) {
						$this->userRepository->addMoney($player->id, -$cost);
						$this->flashMessage($this->translate('general.messages.success.buildingBought'), 'success');
						$this->redirect('Buildings:default');
					} else {
						$this->flashMessage($this->translate('general.messages.danger.somethingFishy'), 'danger');
						$this->redirect('Buildings:default');
					}
				} else {
					$this->flashMessage($this->translate('general.messages.danger.notEnoughMoney'), 'danger');
					$this->redirect('Buildings:default');
				}
			} else {
				$this->flashMessage('This building is at maximum level or is not upgradable!', 'danger');
				$this->redirect('Buildings:default');
			}
		} else {
			$this->redirect('Buildings:default');
		}
	}

	public function actionDemolish(int $b) {
		$building = $this->buildingsRepository->getBuilding($b)->fetch();
		if ($building->user_id === $this->user->getIdentity()->id) {
			if ($this->buildingsRepository->demolishBuilding($b, $this->user->getIdentity()->id)) {
				$this->flashMessage('Building demolished!', 'success');
				$this->redirect('Buildings:default');
			} else {
				$this->flashMessage('Building not found!', 'danger');
				$this->redirect('Buildings:default');
			}
		} else {
			$this->redirect('Buildings:default');
		}
	}

	private function getUpgradeCost(int $basePrice = 0, int $level = 1): int
	{
		return (int)round(($basePrice * $level) / 3, -1);
	}
}
