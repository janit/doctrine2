<?php


declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Common\Proxy\Proxy;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Query;
use ProxyManager\Proxy\GhostObjectInterface;

/**
 * Default query cache implementation.
 *
 * @since   2.5
 * @author  Fabio B. Silva <fabio.bat.silva@gmail.com>
 */
class DefaultQueryCache implements QueryCache
{
     /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    private $em;

    /**
     * @var \Doctrine\ORM\UnitOfWork
     */
    private $uow;

    /**
     * @var \Doctrine\ORM\Cache\Region
     */
    private $region;

    /**
     * @var \Doctrine\ORM\Cache\QueryCacheValidator
     */
    private $validator;

    /**
     * @var \Doctrine\ORM\Cache\Logging\CacheLogger
     */
    protected $cacheLogger;

    /**
     * @var array
     */
    private static $hints = [Query::HINT_CACHE_ENABLED => true];

    /**
     * @param \Doctrine\ORM\EntityManagerInterface $em     The entity manager.
     * @param \Doctrine\ORM\Cache\Region           $region The query region.
     */
    public function __construct(EntityManagerInterface $em, Region $region)
    {
        $cacheConfig = $em->getConfiguration()->getSecondLevelCacheConfiguration();

        $this->em           = $em;
        $this->region       = $region;
        $this->uow          = $em->getUnitOfWork();
        $this->cacheLogger  = $cacheConfig->getCacheLogger();
        $this->validator    = $cacheConfig->getQueryValidator();
    }

    /**
     * {@inheritdoc}
     */
    public function get(QueryCacheKey $key, ResultSetMapping $rsm, array $hints = [])
    {
        if ( ! ($key->cacheMode & Cache::MODE_GET)) {
            return null;
        }

        $entry = $this->region->get($key);

        if ( ! $entry instanceof QueryCacheEntry) {
            return null;
        }

        if ( ! $this->validator->isValid($key, $entry)) {
            $this->region->evict($key);

            return null;
        }

        $result      = [];
        $entityName  = reset($rsm->aliasMap);
        $hasRelation = ( ! empty($rsm->relationMap));
        $persister   = $this->uow->getEntityPersister($entityName);
        $region      = $persister->getCacheRegion();
        $regionName  = $region->getName();

        $cm = $this->em->getClassMetadata($entityName);

        $generateKeys = function (array $entry) use ($cm): EntityCacheKey {
            return new EntityCacheKey($cm->getRootClassName(), $entry['identifier']);
        };

        $cacheKeys = new CollectionCacheEntry(array_map($generateKeys, $entry->result));
        $entries   = $region->getMultiple($cacheKeys);

        // @TODO - move to cache hydration component
        foreach ($entry->result as $index => $entry) {
            $entityEntry = is_array($entries) ? ($entries[$index] ?? null) : null;

            if ($entityEntry === null) {
                if ($this->cacheLogger !== null) {
                    $this->cacheLogger->entityCacheMiss($regionName, $cacheKeys->identifiers[$index]);
                }

                return null;
            }

            if ($this->cacheLogger !== null) {
                $this->cacheLogger->entityCacheHit($regionName, $cacheKeys->identifiers[$index]);
            }

            if ( ! $hasRelation) {
                $result[$index] = $this->uow->createEntity(
                    $entityEntry->class,
                    $entityEntry->resolveAssociationEntries($this->em),
                    self::$hints
                );

                continue;
            }

            $data = $entityEntry->data;

            foreach ($entry['associations'] as $name => $assoc) {
                $assocPersister  = $this->uow->getEntityPersister($assoc['targetEntity']);
                $assocRegion     = $assocPersister->getCacheRegion();
                $assocMetadata   = $this->em->getClassMetadata($assoc['targetEntity']);

                // *-to-one association
                if (isset($assoc['identifier'])) {
                    $assocKey = new EntityCacheKey($assocMetadata->getRootClassName(), $assoc['identifier']);

                    if (($assocEntry = $assocRegion->get($assocKey)) === null) {
                        if ($this->cacheLogger !== null) {
                            $this->cacheLogger->entityCacheMiss($assocRegion->getName(), $assocKey);
                        }

                        $this->uow->hydrationComplete();

                        return null;
                    }

                    $data[$name] = $this->uow->createEntity(
                        $assocEntry->class,
                        $assocEntry->resolveAssociationEntries($this->em),
                        self::$hints
                    );

                    if ($this->cacheLogger !== null) {
                        $this->cacheLogger->entityCacheHit($assocRegion->getName(), $assocKey);
                    }

                    continue;
                }

                if ( ! isset($assoc['list']) || empty($assoc['list'])) {
                    continue;
                }

                $generateKeys = function ($id) use ($assocMetadata): EntityCacheKey {
                    return new EntityCacheKey($assocMetadata->getRootClassName(), $id);
                };

                $assocKeys    = new CollectionCacheEntry(array_map($generateKeys, $assoc['list']));
                $assocEntries = $assocRegion->getMultiple($assocKeys);

                // *-to-many association
                $collection = [];

                foreach ($assoc['list'] as $assocIndex => $assocId) {
                    $assocEntry = is_array($assocEntries) ? ($assocEntries[$assocIndex] ?? null) : null;

                    if ($assocEntry === null) {
                        if ($this->cacheLogger !== null) {
                            $this->cacheLogger->entityCacheMiss($assocRegion->getName(), $assocKeys->identifiers[$assocIndex]);
                        }

                        $this->uow->hydrationComplete();

                        return null;
                    }

                    $collection[$assocIndex] = $this->uow->createEntity(
                        $assocEntry->class,
                        $assocEntry->resolveAssociationEntries($this->em),
                        self::$hints
                    );

                    if ($this->cacheLogger !== null) {
                        $this->cacheLogger->entityCacheHit($assocRegion->getName(), $assocKeys->identifiers[$assocIndex]);
                    }
                }

                $data[$name] = $collection;
            }

            $result[$index] = $this->uow->createEntity($entityEntry->class, $data, self::$hints);
        }

        $this->uow->hydrationComplete();

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function put(QueryCacheKey $key, ResultSetMapping $rsm, $result, array $hints = [])
    {
        if ($rsm->scalarMappings) {
            throw new CacheException("Second level cache does not support scalar results.");
        }

        if (count($rsm->entityMappings) > 1) {
            throw new CacheException("Second level cache does not support multiple root entities.");
        }

        if ( ! $rsm->isSelect) {
            throw new CacheException("Second-level cache query supports only select statements.");
        }

        if (isset($hints[Query::HINT_FORCE_PARTIAL_LOAD]) && $hints[Query::HINT_FORCE_PARTIAL_LOAD]) {
            throw new CacheException("Second level cache does not support partial entities.");
        }

        if ( ! ($key->cacheMode & Cache::MODE_PUT)) {
            return false;
        }

        $data        = [];
        $entityName  = reset($rsm->aliasMap);
        $rootAlias   = key($rsm->aliasMap);
        $hasRelation = ( ! empty($rsm->relationMap));
        $persister   = $this->uow->getEntityPersister($entityName);

        if ( ! ($persister instanceof CachedPersister)) {
            throw CacheException::nonCacheableEntity($entityName);
        }

        $region = $persister->getCacheRegion();

        foreach ($result as $index => $entity) {
            $identifier                     = $this->uow->getEntityIdentifier($entity);
            $entityKey                      = new EntityCacheKey($entityName, $identifier);
            $data[$index]['identifier']     = $identifier;
            $data[$index]['associations']   = [];

            if (($key->cacheMode & Cache::MODE_REFRESH) || ! $region->contains($entityKey)) {
                // Cancel put result if entity put fail
                if ( ! $persister->storeEntityCache($entity, $entityKey)) {
                    return false;
                }
            }

            if ( ! $hasRelation) {
                continue;
            }

            // @TODO - move to cache hydration components
            foreach ($rsm->relationMap as $alias => $name) {
                $parentAlias  = $rsm->parentAliasMap[$alias];
                $parentClass  = $rsm->aliasMap[$parentAlias];
                $metadata     = $this->em->getClassMetadata($parentClass);
                $association  = $metadata->getProperty($name);
                $assocValue   = $this->getAssociationValue($rsm, $alias, $entity);

                if ($assocValue === null) {
                    continue;
                }

                // root entity association
                if ($rootAlias === $parentAlias) {
                    // Cancel put result if association put fail
                    if (($assocInfo = $this->storeAssociationCache($key, $association, $assocValue)) === null) {
                        return false;
                    }

                    $data[$index]['associations'][$name] = $assocInfo;

                    continue;
                }

                // store single nested association
                if ( ! is_array($assocValue)) {
                    // Cancel put result if association put fail
                    if ($this->storeAssociationCache($key, $association, $assocValue) === null) {
                        return false;
                    }

                    continue;
                }

                // store array of nested association
                foreach ($assocValue as $aVal) {
                    // Cancel put result if association put fail
                    if ($this->storeAssociationCache($key, $association, $aVal) === null) {
                        return false;
                    }
                }
            }
        }

        return $this->region->put($key, new QueryCacheEntry($data));
    }

    /**
     * @param \Doctrine\ORM\Cache\QueryCacheKey $key
     * @param AssociationMetadata               $assoc
     * @param mixed                             $assocValue
     *
     * @return array|null
     */
    private function storeAssociationCache(QueryCacheKey $key, AssociationMetadata $association, $assocValue)
    {
        $assocPersister = $this->uow->getEntityPersister($association->getTargetEntity());
        $assocMetadata  = $assocPersister->getClassMetadata();
        $assocRegion    = $assocPersister->getCacheRegion();

        // Handle *-to-one associations
        if ($association instanceof ToOneAssociationMetadata) {
            $assocIdentifier = $this->uow->getEntityIdentifier($assocValue);
            $entityKey       = new EntityCacheKey($assocMetadata->getRootClassName(), $assocIdentifier);

            if ((! $assocValue instanceof GhostObjectInterface && ($key->cacheMode & Cache::MODE_REFRESH)) || ! $assocRegion->contains($entityKey)) {
                // Entity put fail
                if (! $assocPersister->storeEntityCache($assocValue, $entityKey)) {
                    return null;
                }
            }

            return [
                'targetEntity'  => $assocMetadata->getRootClassName(),
                'identifier'    => $assocIdentifier,
            ];
        }

        // Handle *-to-many associations
        $list = [];

        foreach ($assocValue as $assocItemIndex => $assocItem) {
            $assocIdentifier = $this->uow->getEntityIdentifier($assocItem);
            $entityKey       = new EntityCacheKey($assocMetadata->getRootClassName(), $assocIdentifier);

            if (($key->cacheMode & Cache::MODE_REFRESH) || ! $assocRegion->contains($entityKey)) {
                // Entity put fail
                if ( ! $assocPersister->storeEntityCache($assocItem, $entityKey)) {
                    return null;
                }
            }

            $list[$assocItemIndex] = $assocIdentifier;
        }

        return [
            'targetEntity' => $assocMetadata->getRootClassName(),
            'list'         => $list,
        ];
    }

    /**
     * @param \Doctrine\ORM\Query\ResultSetMapping $rsm
     * @param string                               $assocAlias
     * @param object                               $entity
     *
     * @return array|object
     */
    private function getAssociationValue(ResultSetMapping $rsm, $assocAlias, $entity)
    {
        $path  = [];
        $alias = $assocAlias;

        while (isset($rsm->parentAliasMap[$alias])) {
            $parent = $rsm->parentAliasMap[$alias];
            $field  = $rsm->relationMap[$alias];
            $class  = $rsm->aliasMap[$parent];

            array_unshift($path, [
                'field'  => $field,
                'class'  => $class
            ]
            );

            $alias = $parent;
        }

        return $this->getAssociationPathValue($entity, $path);
    }

    /**
     * @param mixed $value
     * @param array $path
     *
     * @return array|object|null
     */
    private function getAssociationPathValue($value, array $path)
    {
        $mapping     = array_shift($path);
        $metadata    = $this->em->getClassMetadata($mapping['class']);
        $association = $metadata->getProperty($mapping['field']);
        $value       = $association->getValue($value);

        if ($value === null) {
            return null;
        }

        if (empty($path)) {
            return $value;
        }

        // Handle *-to-one associations
        if ($association instanceof ToOneAssociationMetadata) {
            return $this->getAssociationPathValue($value, $path);
        }

        $values = [];

        foreach ($value as $item) {
            $values[] = $this->getAssociationPathValue($item, $path);
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        return $this->region->evictAll();
    }

    /**
     * {@inheritdoc}
     */
    public function getRegion()
    {
        return $this->region;
    }
}
