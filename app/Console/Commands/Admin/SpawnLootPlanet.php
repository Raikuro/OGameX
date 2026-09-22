<?php

namespace OGame\Console\Commands\Admin;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Highscore;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;

#[Description('Spawns an "Abandoned Fortress" planet with max storages, full resources, and defenses proportional to 50% of server average score')]
#[Signature('ogamex:admin:spawn-loot-planet {galaxy} {system} {position}')]
class SpawnLootPlanet extends Command
{
    private const MAX_STORAGE_LEVEL = 30;

    private const PLANET_NAME = 'fortaleza abandonada';

    private const EVENT_USERNAME = 'evento';

    private const EVENT_EMAIL = 'evento@event.local';

    private const EVENT_PASSWORD = 'evento_evento';

    /**
     * Execute the console command.
     */
    public function handle(
        PlanetServiceFactory $planetServiceFactory,
        PlayerServiceFactory $playerServiceFactory,
        SettingsService $settingsService,
    ): int {
        $galaxy = (int) $this->argument('galaxy');
        $system = (int) $this->argument('system');
        $position = (int) $this->argument('position');

        $coordinate = new Coordinate($galaxy, $system, $position);

        if ($planetServiceFactory->planetExistsAtCoordinate($coordinate)) {
            $this->error("A planet already exists at {$coordinate->asString()}.");
            return 1;
        }

        $eventUser = $this->getOrCreateEventUser();
        $player = $playerServiceFactory->make($eventUser->id);

        $this->info('Creating planet...');
        $planet = $planetServiceFactory->createPlanetAtPosition($player, $coordinate, self::PLANET_NAME);

        $this->info('Setting storage buildings to max level...');
        $this->setMaxStorageBuildings($planet);

        $this->info('Filling resources to capacity...');
        $this->fillResources($planet);

        $this->info('Calculating defense budget...');
        $defenseBudget = $this->calculateDefenseBudget($settingsService);

        if ($defenseBudget <= 0) {
            $this->warn('No players in highscore yet. Skipping defenses.');
        } else {
            $this->info("Defense budget: {$defenseBudget} resource points");
            $this->spawnDefenses($planet, $defenseBudget);
        }

        $this->info("Fortress spawned at {$coordinate->asString()} for user " . self::EVENT_USERNAME . ".");
        return 0;
    }

    private function getOrCreateEventUser(): User
    {
        $user = User::where('username', self::EVENT_USERNAME)->first();

        if ($user !== null) {
            return $user;
        }

        $user = new User();
        $user->username = self::EVENT_USERNAME;
        $user->email = self::EVENT_EMAIL;
        $user->password = bcrypt(self::EVENT_PASSWORD);
        $user->lang = 'en';
        $user->save();

        $user->assignRole('admin');

        $this->info('Created event user: ' . self::EVENT_USERNAME);
        return $user;
    }

    private function setMaxStorageBuildings(PlanetService $planet): void
    {
        $storageBuildings = ['metal_store', 'crystal_store', 'deuterium_store'];
        foreach ($storageBuildings as $machineName) {
            $object = ObjectService::getObjectByMachineName($machineName);
            $planet->setObjectLevel($object->id, self::MAX_STORAGE_LEVEL, false);
        }
        $planet->updateResourceStorageStats(true);
    }

    private function fillResources(PlanetService $planet): void
    {
        $metalMax = $planet->metalStorage()->get();
        $crystalMax = $planet->crystalStorage()->get();
        $deuteriumMax = $planet->deuteriumStorage()->get();

        $resources = new Resources(
            metal: $metalMax - $planet->metal()->get(),
            crystal: $crystalMax - $planet->crystal()->get(),
            deuterium: $deuteriumMax - $planet->deuterium()->get(),
            energy: 0
        );

        $planet->addResources($resources, true);
    }

    private function calculateDefenseBudget(SettingsService $settingsService): int
    {
        $avgGeneral = Highscore::avg('general');

        if ($avgGeneral === null || $avgGeneral <= 0) {
            return 0;
        }

        // Half of the average general score, converted to resource points
        return (int) floor($avgGeneral / 2 * 1000);
    }

    private function spawnDefenses(PlanetService $planet, int $budget): void
    {
        $defenseObjects = ObjectService::getDefenseObjects();

        // Calculate cost per unit for each defense type
        $defenseCosts = [];
        foreach ($defenseObjects as $defense) {
            $price = ObjectService::getObjectRawPrice($defense->machine_name);
            $totalCost = $price->sum();
            if ($totalCost > 0) {
                $defenseCosts[$defense->machine_name] = $totalCost;
            }
        }

        if (empty($defenseCosts)) {
            return;
        }

        // Calculate total cost of one unit of each defense type
        $totalCostPerSet = array_sum($defenseCosts);

        // How many full sets of defenses can we afford?
        $numSets = (int) floor($budget / $totalCostPerSet);

        if ($numSets <= 0) {
            // Budget is too small for one of each, distribute to cheapest defense
            $cheapest = array_keys($defenseCosts, min($defenseCosts))[0];
            $amount = (int) floor($budget / $defenseCosts[$cheapest]);
            if ($amount > 0) {
                $planet->addUnit($cheapest, $amount, false);
            }
            $planet->save();
            return;
        }

        $remainingBudget = $budget - ($numSets * $totalCostPerSet);

        // Add defenses proportionally
        foreach ($defenseCosts as $machineName => $cost) {
            $amount = $numSets;
            // Distribute remaining budget to cheapest defense type
            if ($remainingBudget >= $cost) {
                $extra = (int) floor($remainingBudget / $cost);
                $amount += $extra;
                $remainingBudget -= $extra * $cost;
            }
            $planet->addUnit($machineName, $amount, false);
        }

        $planet->save();
    }
}
