<?php

namespace OpenDominion\Services\Dominion\Actions;

use DB;
use LogicException;
use OpenDominion\Calculators\Dominion\ImprovementCalculator;
use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\MilitaryCalculator;
use OpenDominion\Calculators\Dominion\RangeCalculator;
use OpenDominion\Helpers\BuildingHelper;
use OpenDominion\Helpers\EspionageHelper;
use OpenDominion\Helpers\ImprovementHelper;
use OpenDominion\Helpers\LandHelper;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\InfoOp;
use OpenDominion\Services\Dominion\HistoryService;
use OpenDominion\Services\Dominion\ProtectionService;
use OpenDominion\Services\Dominion\Queue\ConstructionQueueService;
use OpenDominion\Services\Dominion\Queue\ExplorationQueueService;
use OpenDominion\Services\Dominion\Queue\LandIncomingQueueService;
use OpenDominion\Services\Dominion\Queue\TrainingQueueService;
use OpenDominion\Services\Dominion\Queue\UnitsReturningQueueService;
use OpenDominion\Traits\DominionGuardsTrait;
use RuntimeException;
use Throwable;

class EspionageActionService
{
    use DominionGuardsTrait;

    /** @var BuildingHelper */
    protected $buildingHelper;

    /** @var ConstructionQueueService */
    protected $constructionQueueService;

    /** @var EspionageHelper */
    protected $espionageHelper;

    /** @var ExplorationQueueService */
    protected $explorationQueueService;

    /** @var ImprovementCalculator */
    protected $improvementCalculator;

    /** @var ImprovementHelper */
    protected $improvementHelper;

    /** @var LandCalculator */
    protected $landCalculator;

    /** @var LandHelper */
    protected $landHelper;

    /** @var LandIncomingQueueService */
    protected $landIncomingQueueService;

    /** @var MilitaryCalculator */
    protected $militaryCalculator;

    /** @var ProtectionService */
    protected $protectionService;

    /** @var RangeCalculator */
    protected $rangeCalculator;

    /** @var TrainingQueueService */
    protected $trainingQueueService;

    /** @var UnitsReturningQueueService */
    protected $unitsReturningQueueService;

    /**
     * EspionageActionService constructor.
     *
     * @param BuildingHelper $buildingHelper
     * @param ConstructionQueueService $constructionQueueService
     * @param EspionageHelper $espionageHelper
     * @param ExplorationQueueService $explorationQueueService
     * @param ImprovementCalculator $improvementCalculator
     * @param ImprovementHelper $improvementHelper
     * @param LandCalculator $landCalculator
     * @param LandHelper $landHelper
     * @param LandIncomingQueueService $landIncomingQueueService
     * @param MilitaryCalculator $militaryCalculator
     * @param ProtectionService $protectionService
     * @param RangeCalculator $rangeCalculator
     * @param TrainingQueueService $trainingQueueService
     * @param UnitsReturningQueueService $unitsReturningQueueService
     */
    public function __construct(
        BuildingHelper $buildingHelper,
        ConstructionQueueService $constructionQueueService,
        EspionageHelper $espionageHelper,
        ExplorationQueueService $explorationQueueService,
        ImprovementCalculator $improvementCalculator,
        ImprovementHelper $improvementHelper,
        LandCalculator $landCalculator,
        LandHelper $landHelper,
        LandIncomingQueueService $landIncomingQueueService,
        MilitaryCalculator $militaryCalculator,
        ProtectionService $protectionService,
        RangeCalculator $rangeCalculator,
        TrainingQueueService $trainingQueueService,
        UnitsReturningQueueService $unitsReturningQueueService
    ) {
        $this->buildingHelper = $buildingHelper;
        $this->constructionQueueService = $constructionQueueService;
        $this->espionageHelper = $espionageHelper;
        $this->explorationQueueService = $explorationQueueService;
        $this->improvementCalculator = $improvementCalculator;
        $this->improvementHelper = $improvementHelper;
        $this->landCalculator = $landCalculator;
        $this->landHelper = $landHelper;
        $this->landIncomingQueueService = $landIncomingQueueService;
        $this->militaryCalculator = $militaryCalculator;
        $this->protectionService = $protectionService;
        $this->rangeCalculator = $rangeCalculator;
        $this->trainingQueueService = $trainingQueueService;
        $this->unitsReturningQueueService = $unitsReturningQueueService;
    }

    /**
     * Performs a espionage operation for $dominion, aimed at $target dominion.
     *
     * @param Dominion $dominion
     * @param string $operationKey
     * @param Dominion $target
     * @return array
     * @throws Throwable
     */
    public function performOperation(Dominion $dominion, string $operationKey, Dominion $target): array
    {
        $this->guardLockedDominion($dominion);

        $operationInfo = $this->espionageHelper->getOperationInfo($operationKey);

        if (!$operationInfo) {
            throw new RuntimeException("Cannot perform unknown operation '{$operationKey}'");
        }

        if ($dominion->spy_strength < 30) {
            throw new RuntimeException("Your spies to not have enough strength to perform {$operationInfo['name']}.");
        }

        if ($this->protectionService->isUnderProtection($dominion)) {
            throw new RuntimeException('You cannot perform espionage operations while under protection');
        }

        if ($this->protectionService->isUnderProtection($target)) {
            throw new RuntimeException('You cannot perform espionage operations to targets which are under protection');
        }

        if (!$this->rangeCalculator->isInRange($dominion, $target)) {
            throw new RuntimeException('You cannot perform espionage operations to targets outside of your range');
        }

        if ($dominion->round->id !== $target->round->id) {
            throw new RuntimeException('Nice try, but you cannot perform espionage operations cross-round');
        }

        if ($dominion->realm->id === $target->realm->id) {
            throw new RuntimeException('Nice try, but you cannot perform espionage oprations on your realmies');
        }

        $result = null;

        DB::transaction(function () use ($dominion, $operationKey, &$result, $target) {

            if ($this->espionageHelper->isInfoGatheringOperation($operationKey)) {
                $result = $this->performInfoGatheringOperation($dominion, $operationKey, $target);

            } elseif ($this->espionageHelper->isResourceTheftOperation($operationKey)) {
                throw new LogicException('Not yet implemented');
            } elseif ($this->espionageHelper->isBlackOperation($operationKey)) {
                throw new LogicException('Not yet implemented');
            } elseif ($this->espionageHelper->isWarOperation($operationKey)) {
                throw new LogicException('Not yet implemented');
            } else {
                throw new LogicException("Unknown type for espionage operation {$operationKey}");
            }

            $dominion->spy_strength -= 2; // todo: different values for different kind of ops (info ops 2%, rest 5%)
            $dominion->save(['event' => HistoryService::EVENT_ACTION_PERFORM_ESPIONAGE_OPERATION]);

        });

        return [
                'message' => $result['message'],
                'data' => [
                    'operation' => $operationKey,
                ],
                'redirect' =>
                    $this->espionageHelper->isInfoGatheringOperation($operationKey) && $result['success']
                        ? route('dominion.op-center.show', $target->id)
                        : null,
            ] + $result;
    }

    protected function performInfoGatheringOperation(Dominion $dominion, string $operationKey, Dominion $target): array
    {
        $operationInfo = $this->espionageHelper->getOperationInfo($operationKey);

        $selfSpa = $this->militaryCalculator->getSpyRatio($dominion);
        $targetSpa = $this->militaryCalculator->getSpyRatio($target);

        // You need at least some positive SPA to perform espionage operations
        if ($selfSpa === 0.0) {
            return [
                'success' => false,
                'message' => "Your spy force is too weak to cast {$operationInfo['name']}. Please train some more spies.",
                'alert-type' => 'warning',
            ];
        }

        if ($targetSpa !== 0.0) {
            $ratio = ($selfSpa / $targetSpa);

            // todo: copied from spell success ratio. needs looking into later
            // todo: factor in spy strength
            $successRate = (
                (0.0172 * ($ratio ** 3))
                - (0.1809 * ($ratio ** 2))
                + (0.6767 * $ratio)
                - 0.0134
            );

            if (!random_chance($successRate)) {
                // todo: move to CasualtiesCalculator

                // Values (percentage)
                $spiesKilled = 2;
                $forestHavenSpyCasualtyReduction = 3;
                $forestHavenSpyCasualtyReductionMax = 30;

                $spiesKilledMulitplier = (1 - min(
                        (($dominion->building_forest_haven / $this->landCalculator->getTotalLand($dominion)) * $forestHavenSpyCasualtyReduction),
                        ($forestHavenSpyCasualtyReductionMax / 100)
                    ));

                $spiesKilled = (int)ceil(($dominion->military_spies * ($spiesKilled / 100)) * $spiesKilledMulitplier);

                $dominion->military_spies -= $spiesKilled;

                return [
                    'success' => false,
                    'message' => ("The enemy has prevented our {$operationInfo['name']} attempt and managed to capture " . number_format($spiesKilled) . ' of our spies.'),
                    'alert-type' => 'warning',
                ];
            }
        }

        // todo: is not invalid?
        $infoOp = InfoOp::firstOrNew([
            'source_realm_id' => $dominion->realm->id,
            'target_dominion_id' => $target->id,
            'type' => $operationKey,
        ], [
            'source_dominion_id' => $dominion->id,
        ]);

        if ($infoOp->exists) {
            // Overwrite casted_by_dominion_id for the newer data
            $infoOp->source_dominion_id = $dominion->id;
        }

        switch ($operationKey) {
            case 'barracks_spy':
                $data = [];

                // Units at home (85% accurate)
                foreach (range(1, 4) as $slot) {
                    $amountAtHome = $target->{'military_unit' . $slot};

                    if ($amountAtHome !== 0) {
                        $amountAtHome = random_int(
                            round($amountAtHome * 0.85),
                            round($amountAtHome / 0.85)
                        );
                    }

                    array_set($data, "units.home.unit{$slot}", $amountAtHome);
                }

                // Units returning (85% accurate)
                $amountReturning = $this->unitsReturningQueueService->getQueue($target);

                foreach ($amountReturning as $unitType => $returningData) {
                    foreach ($returningData as $hour => $amount) {
                        if ($amount !== 0) {
                            $amount = random_int(
                                round($amount * 0.85),
                                round($amount / 0.85)
                            );

                            array_set($amountReturning, "{$unitType}.{$hour}", $amount);
                        }
                    }
                }

                array_set($data, 'units.returning', $amountReturning);

                // Units in training (100% accurate)
                $amountInTraining = $this->trainingQueueService->getQueue($target);
                array_set($data, 'units.training', $amountInTraining);

                $infoOp->data = $data;
                break;

            case 'castle_spy':
                $data = [];

                foreach ($this->improvementHelper->getImprovementTypes() as $type) {
                    array_set($data, "{$type}.points", $target->{'improvement_' . $type});
                    array_set($data, "{$type}.rating",
                        $this->improvementCalculator->getImprovementMultiplierBonus($target, $type));
                }

                $infoOp->data = $data;
                break;

            case 'survey_dominion':
                $data = [];

                foreach ($this->buildingHelper->getBuildingTypes() as $buildingType) {
                    array_set($data, "constructed.{$buildingType}", $target->{'building_' . $buildingType});
                }

                array_set($data, 'constructing', $this->constructionQueueService->getQueue($target));

                array_set($data, 'barren_land', $this->landCalculator->getTotalBarrenLand($target));

                $infoOp->data = $data;
                break;

            case 'land_spy':
                $data = [];

                foreach ($this->landHelper->getLandTypes() as $landType) {
                    $amount = $target->{'land_' . $landType};

                    array_set($data, "explored.{$landType}.amount", $amount);
                    array_set($data, "explored.{$landType}.percentage",
                        (($amount / $this->landCalculator->getTotalLand($target)) * 100));
                    array_set($data, "explored.{$landType}.barren",
                        $this->landCalculator->getTotalBarrenLandByLandType($target, $landType));
                }

                // hacky hack
                $incoming = [];

                $exploringLand = $this->explorationQueueService->getQueue($target);
                $incomingLand = $this->landIncomingQueueService->getQueue($target);

                foreach ($this->landHelper->getLandTypes() as $landType) {
                    foreach (range(0, 11) as $hour) {
                        array_set($incoming, "{$landType}.{$hour}", (
                            array_get($exploringLand, "{$landType}.{$hour}", 0) +
                            array_get($incomingLand, "{$landType}.{$hour}", 0)
                        ));
                    }
                }

                array_set($data, 'incoming', $incoming);

                $infoOp->data = $data;
                break;

            default:
                throw new LogicException("Unknown info gathering operation {$operationKey}");
        }

        // Always force update updated_at on infoops to know when the last infoop was performed
        $infoOp->updated_at = now(); // todo: fixable with ->save(['touch'])?
        $infoOp->save();

        return [
            'success' => true,
            'message' => 'Your spies infiltrate the target\'s dominion successfully and return with a wealth of information.',
            'redirect' => route('dominion.op-center.show', $target),
        ];
    }

    // todo: theft/black ops/war
}
