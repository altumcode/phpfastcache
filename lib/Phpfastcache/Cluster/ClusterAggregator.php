<?php

/**
 *
 * This file is part of Phpfastcache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Georges.L (Geolim4) <contact@geolim4.com>
 *
 */

declare(strict_types=1);

namespace Phpfastcache\Cluster;

use Exception;
use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Phpfastcache\Event\Event;
use Phpfastcache\EventManager;
use Phpfastcache\Exceptions\PhpfastcacheDriverCheckException;
use Phpfastcache\Exceptions\PhpfastcacheDriverException;
use Phpfastcache\Exceptions\PhpfastcacheDriverNotFoundException;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use Phpfastcache\Exceptions\PhpfastcacheLogicException;
use stdClass;

class ClusterAggregator implements AggregatorInterface
{
    /**
     * @var array<int, AggregatablePoolInterface>
     */
    protected array $driverPools;

    protected ClusterPoolInterface $cluster;

    protected string $clusterAggregatorName;

    /**
     * ClusterAggregator constructor.
     * @param string $clusterAggregatorName
     * @param array<AggregatablePoolInterface> $driverPools
     * @throws PhpfastcacheLogicException
     */
    public function __construct(string $clusterAggregatorName = '', AggregatablePoolInterface ...$driverPools)
    {
        $clusterAggregatorName = trim($clusterAggregatorName);
        if (empty($clusterAggregatorName)) {
            try {
                $clusterAggregatorName = 'cluster_' . \bin2hex(\random_bytes(15));
            } catch (Exception) {
                $clusterAggregatorName = 'cluster_' . \str_shuffle(\sprintf('%016x0000000000000000', \spl_object_id(new stdClass())));
            }
        }

        $this->clusterAggregatorName = $clusterAggregatorName;

        foreach ($driverPools as $driverPool) {
            $this->aggregateDriver($driverPool);
        }
    }

    /**
     * @param AggregatablePoolInterface $driverPool
     *
     * @throws PhpfastcacheLogicException
     */
    public function aggregateDriver(AggregatablePoolInterface $driverPool): void
    {
        if (isset($this->cluster)) {
            throw new PhpfastcacheLogicException('The cluster has been already build, cannot aggregate more pools.');
        }

        $splId = \spl_object_id($driverPool);
        if (!isset($this->driverPools[$splId])) {
            if ($driverPool instanceof ClusterPoolInterface) {
                throw new PhpfastcacheLogicException('Recursive cluster aggregation is not allowed !');
            }

            $this->driverPools[$splId] = $driverPool;
        } else {
            throw new PhpfastcacheLogicException('This pool has been already aggregated !');
        }
    }

    /**
     * @param string $driverName
     * @param ConfigurationOption|null $driverConfig
     * @throws PhpfastcacheDriverCheckException
     * @throws PhpfastcacheDriverException
     * @throws PhpfastcacheDriverNotFoundException
     * @throws PhpfastcacheLogicException
     */
    public function aggregateDriverByName(string $driverName, ?ConfigurationOption $driverConfig = null): void
    {
        if (isset($this->cluster)) {
            throw new PhpfastcacheLogicException('The cluster has been already build, cannot aggregate more pools.');
        }

        $driverInstance = CacheManager::getInstance($driverName, $driverConfig);

        if ($driverInstance instanceof AggregatablePoolInterface) {
            $this->aggregateDriver($driverInstance);
        }

        throw new PhpfastcacheDriverException(
            \sprintf(
                'Driver "%s" does not implements "%s"',
                $driverInstance::class,
                AggregatablePoolInterface::class
            )
        );
    }

    /**
     * @param AggregatablePoolInterface $driverPool
     *
     * @throws PhpfastcacheLogicException
     */
    public function disaggregateDriver(AggregatablePoolInterface $driverPool): void
    {
        if (isset($this->cluster)) {
            throw new PhpfastcacheLogicException('The cluster has been already build, cannot disaggregate pools.');
        }

        $splId = \spl_object_id($driverPool);
       if (isset($this->driverPools[$splId])) {
            unset($this->driverPools[$splId]);
        } else {
            throw new PhpfastcacheLogicException('This pool was not aggregated !');
        }
    }

    /**
     * @param int $strategy
     *
     * @return ClusterPoolInterface
     * @throws PhpfastcacheInvalidArgumentException
     */
    public function getCluster(int $strategy = AggregatorInterface::STRATEGY_FULL_REPLICATION): ClusterPoolInterface
    {
        if (isset(ClusterPoolAbstract::STRATEGY[$strategy])) {
            if (!isset($this->cluster)) {
                $clusterClass = ClusterPoolAbstract::STRATEGY[$strategy];
                $this->cluster = new $clusterClass(
                    $this->getClusterAggregatorName(),
                    EventManager::getInstance(),
                    ...\array_values($this->driverPools)
                );

                $this->cluster->getEventManager()->dispatch(Event::CACHE_CLUSTER_BUILT, $this, $this->cluster);
            }
        } else {
            throw new PhpfastcacheInvalidArgumentException('Unknown cluster strategy');
        }

        return $this->cluster;
    }

    /**
     * @return string
     */
    public function getClusterAggregatorName(): string
    {
        return $this->clusterAggregatorName;
    }
}
