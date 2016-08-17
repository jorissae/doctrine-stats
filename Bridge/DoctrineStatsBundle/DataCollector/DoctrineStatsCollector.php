<?php

namespace steevanb\DoctrineStats\Bridge\DoctrineStatsBundle\DataCollector;

use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use steevanb\DoctrineStats\Bridge\DoctrineCollectorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class DoctrineStatsCollector extends DataCollector implements DoctrineCollectorInterface
{
    /** @var array */
    protected $lazyLoadedEntities = [];

    /** @var SQLLogger */
    protected $sqlLogger;

    /** @var array */
    protected $managedEntities = [];

    /** @var array */
    protected $hydrationTimes = [];

    /** @var int */
    protected $queriesAlert = 1;

    /** @var int */
    protected $managedEntitiesAlert = 10;

    /** @var int */
    protected $lazyLoadedEntitiesAlert = 1;

    /** @var int */
    protected $hydrationTimeAlert = 5;

    /** @var array */
    protected $hydratedEntities = [];

    /**
     * @param DebugStack $sqlLogger
     */
    public function __construct(DebugStack $sqlLogger)
    {
        $this->sqlLogger = $sqlLogger;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'doctrine_stats';
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setQueriesAlert($count)
    {
        $this->queriesAlert = $count;

        return $this;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setManagedEntitiesAlert($count)
    {
        $this->managedEntitiesAlert = $count;

        return $this;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setLazyLoadedEntitiesAlert($count)
    {
        $this->lazyLoadedEntitiesAlert = $count;

        return $this;
    }

    /**
     * @param int $time Time in milliseconds
     * @return $this
     */
    public function setHydrationTimeAlert($time)
    {
        $this->hydrationTimeAlert = $time;

        return $this;
    }

    /**
     * @param EntityManagerInterface $entityManager
     * @param object $entity
     * @return $this
     */
    public function addLazyLoadedEntity(EntityManagerInterface $entityManager, $entity)
    {
        $className = get_class($entity);
        $classMetaData = $entityManager->getClassMetadata($className);
        $associations = array();
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metaData) {
            foreach ($metaData->associationMappings as $field => $mapping) {
                if ($mapping['targetEntity'] === $classMetaData->name) {
                    $associations[] = array_merge(
                        $this->explodeClassParts($metaData->name),
                        array('field' => $field)
                    );
                }
            }
        }

        $this->lazyLoadedEntities[] = array_merge(
            $this->explodeClassParts($classMetaData->name),
            array(
                'identifiers' => $classMetaData->getIdentifierValues($entity),
                'associations' => $associations
            )
        );

        return $this;
    }

    /**
     * @param string $className
     * @param array $identifiers
     * @return $this
     */
    public function addManagedEntity($className, array $identifiers)
    {
        if (array_key_exists($className, $this->managedEntities) === false) {
            $this->managedEntities[$className] = array();
        }
        $this->managedEntities[$className][] = $this->identifiersAsString($identifiers);

        return $this;
    }

    /**
     * @param string $hydratorClassName
     * @param float $time Time, in milliseconds
     * @return $this
     */
    public function addHydrationTime($hydratorClassName, $time)
    {
        if (isset($this->hydrationTimes[$hydratorClassName]) === false) {
            $this->hydrationTimes[$hydratorClassName] = 0;
        }
        $this->hydrationTimes[$hydratorClassName] += $time;

        return $this;
    }

    /**
     * @param string $hydratorClassName
     * @param string $className
     * @param array $classIdentifiers
     * @return $this
     */
    public function addHydratedEntity($hydratorClassName, $className, $classIdentifiers)
    {
        if (array_key_exists($hydratorClassName, $this->hydratedEntities) === false) {
            $this->hydratedEntities[$hydratorClassName] = [];
        }
        if (array_key_exists($className, $this->hydratedEntities[$hydratorClassName]) === false) {
            $this->hydratedEntities[$hydratorClassName][$className] = [];
        }

        $this->hydratedEntities[$hydratorClassName][$className][] = $this->identifiersAsString($classIdentifiers);

        return $this;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @param \Exception|null $exception
     */
    public function collect(Request $request, Response $response, \Exception $exception = null)
    {
        $this->data = array(
            'lazyLoadedEntities' => $this->lazyLoadedEntities,
            'queries' => $this->sqlLogger->queries,
            'managedEntities' => $this->managedEntities,
            'hydrationTimes' => $this->hydrationTimes,
            'queriesAlert' => $this->queriesAlert,
            'managedEntitiesAlert' => $this->managedEntitiesAlert,
            'lazyLoadedEntitiesAlert' => $this->lazyLoadedEntitiesAlert,
            'hydrationTimeAlert' => $this->hydrationTimeAlert,
            'hydratedEntities' => $this->hydratedEntities
        );
    }

    /**
     * @return array
     */
    public function getQueries()
    {
        static $return = false;

        if ($return === false) {
            $return = array();
            foreach ($this->data['queries'] as $query) {
                if (array_key_exists($query['sql'], $return) === false) {
                    $return[$query['sql']] = array('executionMS' => 0, 'data' => array());
                }
                $return[$query['sql']]['executionMS'] += $query['executionMS'] * 1000;
                $return[$query['sql']]['data'][] = array(
                    'params' => $query['params']
                );
            }

            uasort($return, function ($queryA, $queryB) {
                return count($queryA['data']) < count($queryB['data']) ? 1 : -1;
            });
        }

        return $return;
    }

    /**
     * @return int
     */
    public function countQueries()
    {
        return count($this->data['queries']);
    }

    /**
     * @return float
     */
    public function getQueriesTime()
    {
        $return = 0;
        foreach ($this->getQueries() as $query) {
            $return += $query['executionMS'];
        }

        return round($return, 2);
    }

    /**
     * @return int
     */
    public function getQueriesTimePercent()
    {
        return $this->getDoctrineTime() > 0 ? round(($this->getQueriesTime() * 100) / $this->getDoctrineTime()) : 0;
    }

    /**
     * @return int
     */
    public function countDifferentQueries()
    {
        return count($this->getQueries());
    }

    /**
     * @return array
     */
    public function getLazyLoadedEntities()
    {
        return $this->data['lazyLoadedEntities'];
    }

    /**
     * @return int
     */
    public function countLazyLoadedEntities()
    {
        return count($this->data['lazyLoadedEntities']);
    }

    /**
     * @return int
     */
    public function countWarnings()
    {
        return $this->countLazyLoadedEntities();
    }

    /**
     * @param string $fullyQualifiedClassName
     * @return int
     */
    public function countLazyLoadedClass($fullyQualifiedClassName)
    {
        $count = 0;
        foreach ($this->getLazyLoadedEntities() as $lazyLoaded) {
            if ($lazyLoaded['namespace'] . '\\' . $lazyLoaded['className'] === $fullyQualifiedClassName) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array
     */
    public function getManagedEntities()
    {
        static $ordered = false;
        if ($ordered === false) {
            uasort($this->data['managedEntities'], function($managedA, $managedB) {
                return $managedA > $managedB ? -1 : 1;
            });
            $ordered = true;
        }

        return $this->data['managedEntities'];
    }

    /**
     * @return int
     */
    public function countManagedEntities()
    {
        $count = 0;
        foreach ($this->getManagedEntities() as $managedEntity) {
            $count += count($managedEntity);
        }

        return $count;
    }

    /**
     * @return float
     */
    public function getHydrationTotalTime()
    {
        $return = 0;
        foreach ($this->getHydrationTimes() as $time) {
            $return += $time;
        }

        return round($return, 2);
    }

    /**
     * @return array
     */
    public function getHydrationTimes()
    {
        return $this->data['hydrationTimes'];
    }

    /**
     * @return int
     */
    public function getHydrationTimePercent()
    {
        return $this->getDoctrineTime() > 0
            ? round(($this->getHydrationTotalTime() * 100) / $this->getDoctrineTime())
            : 0;
    }

    /**
     * @return float
     */
    public function getDoctrineTime()
    {
        return round($this->getQueriesTime() + $this->getHydrationTotalTime(), 2);
    }

    /**
     * @return int
     */
    public function getQueriesAlert()
    {
        return $this->data['queriesAlert'];
    }

    /**
     * @return int
     */
    public function getManagedEntitiesAlert()
    {
        return $this->data['managedEntitiesAlert'];
    }

    /**
     * @return int
     */
    public function getLazyLoadedEntitiesAlert()
    {
        return $this->data['lazyLoadedEntitiesAlert'];
    }

    /**
     * @return int
     */
    public function getHydrationTimealert()
    {
        return $this->data['hydrationTimeAlert'];
    }

    /**
     * @return string|null
     */
    public function getToolbarStatus()
    {
        $alert =
            $this->countQueries() >= $this->getQueriesAlert()
            || $this->countManagedEntities() >= $this->data['managedEntitiesAlert']
            || $this->countLazyLoadedEntities() >= $this->data['lazyLoadedEntitiesAlert']
            || $this->getHydrationTotalTime() >= $this->data['hydrationTimeAlert'];

        return $alert ? 'red' : null;
    }

    /**
     * @return bool
     */
    public function isHydratorsOverloaded()
    {
        return $this->countQueries() === 0 || ($this->countQueries() > 0 && count($this->data['hydrationTimes']) > 0);
    }

    /**
     * @return bool
     */
    public function showDoctrineHydrationHelp()
    {
        $return = true;
        if (in_array(ObjectHydrator::class, $this->getHydrationTimes())) {
            foreach ($this->getQueries() as $sql => $data) {
                $sub7 = substr($sql, 0, 7);
                $sub8 = substr($sql, 0, 8);
                if ($sub7 === 'INSERT ' || $sub7 === 'UPDATE ' || $sub8 === 'REPLACE ') {
                    $return = false;
                    break;
                }
            }
        }

        return $return;
    }

    /**
     * @param string $hydratorClassName
     * @return array
     */
    public function getHydratedEntities($hydrator)
    {
        return array_key_exists($hydrator, $this->data['hydratedEntities'])
            ? $this->data['hydratedEntities'][$hydrator]
            : [];
    }

    /**
     * @param string $hydrator
     * @return int
     */
    public function countHydratedEntities($hydrator)
    {
        $count = 0;
        foreach ($this->getHydratedEntities($hydrator) as $classes) {
            foreach ($classes as $identifiers) {
                $count += count($identifiers);
            }
        }

        return $count;
    }

    /**
     * @param $fullyClassifiedClassName
     * @return array
     */
    public function explodeClassParts($fullyClassifiedClassName)
    {
        $posBackSlash = strrpos($fullyClassifiedClassName, '\\');

        return array(
            'namespace' => substr($fullyClassifiedClassName, 0, $posBackSlash),
            'className' => substr($fullyClassifiedClassName, $posBackSlash + 1)
        );
    }

    /**
     * @param array $identifiers
     * @return array
     */
    public function mergeIdentifiers(array $identifiers)
    {
        $return = [];
        foreach ($identifiers as $identifier) {
            if (array_key_exists($identifier, $return) === false) {
                $return[$identifier] = 0;
            }
            $return[$identifier]++;
        }

        return $return;
    }

    /**
     * @param array $identifiers
     * @return string
     */
    protected function identifiersAsString(array $identifiers)
    {
        $return = [];
        foreach ($identifiers as $name => $value) {
            $return[] = $name . ': ' . $value;
        }

        return implode(', ', $return);
    }
}