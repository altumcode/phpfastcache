<?php

/**
 *
 * This file is part of phpFastCache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Khoa Bui (khoaofgod)  <khoaofgod@gmail.com> https://www.phpfastcache.com
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 *
 */
declare(strict_types=1);

namespace Phpfastcache\Drivers\Apcu;

use DateTime;
use Phpfastcache\Cluster\AggregatablePoolInterface;
use Phpfastcache\Core\Pool\{DriverBaseTrait, ExtendedCacheItemPoolInterface};
use Phpfastcache\Entities\DriverStatistic;
use Phpfastcache\Exceptions\{PhpfastcacheInvalidArgumentException};
use Psr\Cache\CacheItemInterface;


/**
 * Class Driver
 * @package phpFastCache\Drivers
 * @property Config $config Config object
 * @method Config getConfig() Return the config object
 */
class Driver implements ExtendedCacheItemPoolInterface, AggregatablePoolInterface
{
    use DriverBaseTrait;

    /**
     * @param string $key
     * @return string
     */
    protected function getStorageKey(string $key): string
    {
        return $this->getConfig()->getOptPrefix() . $key;
    }

    /**
     * @param int $format
     * @return \APCUIterator
     */
    protected function getStorageIterator(int $format): \APCUIterator
    {
        $prefix = $this->getConfig()->getOptPrefix();
        $search = $prefix !== '' ? '/^' . preg_quote($prefix, '/') . '/' : null;

        return new \APCUIterator($search, $format);
    }

    /**
     * @return bool
     */
    public function driverCheck(): bool
    {
        return extension_loaded('apcu') && ini_get('apc.enabled');
    }

    /**
     * @return DriverStatistic
     */
    public function getStats(): DriverStatistic
    {
        $stats = (array)apcu_cache_info();
        $date = (new DateTime())->setTimestamp($stats['start_time']);
        $numEntries = (int)$stats['num_entries'];
        $size = (int)$stats['mem_size'];

        if ($this->getConfig()->getOptPrefix() !== '') {
            $numEntries = 0;
            $size = 0;

            foreach ($this->getStorageIterator(APC_ITER_KEY | APC_ITER_MEM_SIZE) as $entry) {
                ++$numEntries;
                $size += (int)($entry['mem_size'] ?? 0);
            }
        }

        return (new DriverStatistic())
            ->setData(implode(', ', array_keys($this->itemInstances)))
            ->setInfo(
                sprintf(
                    "The APCU cache is up since %s, and have %d item(s) in cache.\n For more information see RawData.",
                    $date->format(DATE_RFC2822),
                    $numEntries
                )
            )
            ->setRawData($stats)
            ->setSize($size);
    }

    /**
     * @return bool
     */
    protected function driverConnect(): bool
    {
        return true;
    }

    /**
     * @param CacheItemInterface $item
     * @return bool
     * @throws PhpfastcacheInvalidArgumentException
     */
    protected function driverWrite(CacheItemInterface $item): bool
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return (bool)apcu_store($this->getStorageKey($item->getKey()), $this->driverPreWrap($item), $item->getTtl());
        }

        throw new PhpfastcacheInvalidArgumentException('Cross-Driver type confusion detected');
    }

    /**
     * @param CacheItemInterface $item
     * @return null|array
     */
    protected function driverRead(CacheItemInterface $item)
    {
        $data = apcu_fetch($this->getStorageKey($item->getKey()), $success);

        if ($success === false) {
            return null;
        }

        return $data;
    }

    /**
     * @param CacheItemInterface $item
     * @return bool
     * @throws PhpfastcacheInvalidArgumentException
     */
    protected function driverDelete(CacheItemInterface $item): bool
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return (bool)apcu_delete($this->getStorageKey($item->getKey()));
        }

        throw new PhpfastcacheInvalidArgumentException('Cross-Driver type confusion detected');
    }

    /********************
     *
     * PSR-6 Extended Methods
     *
     *******************/

    /**
     * @return bool
     */
    protected function driverClear(): bool
    {
        if ($this->getConfig()->getOptPrefix() !== '') {
            $keys = [];

            foreach ($this->getStorageIterator(APC_ITER_KEY) as $entry) {
                if (isset($entry['key'])) {
                    $keys[] = $entry['key'];
                }
            }

            return $keys ? apcu_delete($keys) === [] : true;
        }

        return @apcu_clear_cache();
    }
}
