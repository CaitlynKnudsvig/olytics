<?php

namespace Cygnus\OlyticsBundle\Aggregation;

use Cygnus\OlyticsBundle\Model\Metadata\Entity;
use Cygnus\OlyticsBundle\Model\Event\EventInterface;

class ContentArchiveAggregation extends AbstractAggregation
{
    /**
     * Gets the index mapping for the traffic archive aggregation
     *
     * @return array
     */
    public function getTrafficArchiveIndexes()
    {
        return [
            ['keys' => ['metadata.month' => 1, 'metadata.contentId' => 1, 'metadata.userId' => 1], 'options' => ['unique' => true]],
            ['keys' => ['lastAccessed' => 1], 'options' => []],
        ];
    }

    /**
     * Gets the index mapping for the session archive aggregation
     *
     * @return array
     */
    public function getSessionArchiveIndexes()
    {
        return [
            ['keys' => ['month' => 1, 'contentId' => 1, 'userId' => 1, 'sessionId' => 1], 'options' => ['unique' => true]],
            ['keys' => ['month' => 1, 'contentId' => 1, 'userId' => 1], 'options' => []],
            ['keys' => ['lastAccessed' => 1], 'options' => ['expireAfterSeconds' => 60*60*24*45]],
        ];
    }

    public function getIndexes()
    {
        return [];
    }

    /**
     * Executes the aggregation
     *
     * @param  Cygnus\OlyticsBundle\Model\Event\EventInterface  $event
     * @param  string                                           $accountKey
     * @param  string                                           $groupKey
     * @return self
     */
    protected function doExecute(EventInterface $event, $accountKey, $groupKey, $appKey)
    {
        $this
            ->handleSessionArchive($event, $accountKey, $groupKey)
            ->handleTrafficArchive($event, $accountKey, $groupKey)
        ;
        return $this;
    }

    /**
     * Handles content session archive aggregation
     *
     * @param  Cygnus\OlyticsBundle\Model\Event\EventInterface  $event
     * @param  string                                           $accountKey
     * @param  string                                           $groupKey
     * @return self
     */
    protected function handleSessionArchive(EventInterface $event, $accountKey, $groupKey)
    {
        // Get the database and collection names
        list($dbName, $collName) = $this->getSessionDbInfo($accountKey, $groupKey);

        // Create session archive indexes (if they don't exist)
        $indexes = $this->getIndexManager()->indexFactoryMulti($this->getSessionArchiveIndexes());
        $this->getIndexManager()->createIndexes($indexes, $dbName, $collName);

        // Create the query builder for upserting
        $builder = $this->createQueryBuilder($dbName, $collName)
            ->update()
            ->upsert(true)
        ;

        $insert = [
            'month'     => $this->getMonthFromDate($event->getCreatedAt()),
            'contentId' => $event->getEntity()->getClientId(),
            'sessionId' => $this->getMongoSessionId($event),
        ];

        $builder
            ->field('month')->equals($insert['month'])
            ->field('contentId')->equals($insert['contentId'])
            ->field('sessionId')->equals($insert['sessionId'])
        ;

        if (null !== ($userId = $this->getUserId($event))) {
            $insert['userId'] = $userId;
            $builder->field('userId')->equals($userId);
        } else {
            $builder->field('userId')->exists(false);
        }

        $newObj = [
            '$setOnInsert'  => $insert,
            '$set'          => [
                'lastAccessed'  => new \MongoDate($event->getCreatedAt()->getTimestamp()),
            ],
        ];

        // Perform the upsert
        $builder
            ->setNewObj($newObj)
            ->getQuery()
            ->execute()
        ;
        return $this;
    }

    /**
     * Handles content traffic archive aggregation
     *
     * @param  Cygnus\OlyticsBundle\Model\Event\EventInterface  $event
     * @param  string                                           $accountKey
     * @param  string                                           $groupKey
     * @return self
     */
    protected function handleTrafficArchive(EventInterface $event, $accountKey, $groupKey)
    {
        // Get the database and collection names
        list($dbName, $collName) = $this->getArchiveDbInfo($accountKey, $groupKey);

        // Create traffic archive indexes (if they don't exist)
        $indexes = $this->getIndexManager()->indexFactoryMulti($this->getTrafficArchiveIndexes());
        $this->getIndexManager()->createIndexes($indexes, $dbName, $collName);

        $builder = $this->createQueryBuilder($dbName, $collName)
            ->update()
            ->upsert(true)
        ;

        $metadata = [
            'month'     => $this->getMonthFromDate($event->getCreatedAt()),
            'contentId' => $event->getEntity()->getClientId(),
        ];

        $builder
            ->field('metadata.month')->equals($metadata['month'])
            ->field('metadata.contentId')->equals($metadata['contentId'])
        ;

        if (null !== ($userId = $this->getUserId($event))) {
            $metadata['userId'] = $userId;
            $builder->field('metadata.userId')->equals($userId);
        } else {
            $builder->field('metadata.userId')->exists(false);
        }

        $newObj = [
            '$setOnInsert'  => [
                'metadata'  => $metadata,
            ],
            '$set'          => [
                'lastAccessed'  => new \MongoDate($event->getCreatedAt()->getTimestamp()),
            ],
            '$inc'          => [
                'pageviews' => 1,
            ],
        ];

        if (0 !== ($visits = $this->getContentVisits($accountKey, $groupKey, $metadata))) {
            // Set the content visits
            $newObj['$set']['visits'] = $visits;
        }

        $builder
            ->setNewObj($newObj)
            ->getQuery()
            ->execute();
        ;
        return $this;
    }

    /**
     * Gets the number of content visits from the session archive
     *
     * @param  string   $accountKey
     * @param  string   $groupKey
     * @param  array    $criteria
     * @return int|null
     */
    protected function getContentVisits($accountKey, $groupKey, array $criteria)
    {
        list($dbName, $collName) = $this->getSessionDbInfo($accountKey, $groupKey);

        $builder = $this->createQueryBuilder($dbName, $collName)
            ->find()
            ->field('month')->equals($criteria['month'])
            ->field('contentId')->equals($criteria['contentId'])
            ->select('month')
            ->exclude('_id')
        ;

        if (isset($criteria['userId'])) {
            $builder->field('userId')->equals($criteria['userId']);
        } else {
            $builder->field('userId')->exists(false);
        }

        $cursor = $builder
            ->getQuery()
            ->execute()
        ;

        return $cursor->count(true);
    }

    /**
     * Gets the content session db info (database and collection names)
     *
     * @param  string   $accountKey
     * @param  string   $groupKey
     * @return array
     */
    protected function getSessionDbInfo($accountKey, $groupKey)
    {
        return [
            'content_session_archive',
            sprintf('%s_%s', $accountKey, $groupKey),
        ];
    }

    /**
     * Gets the content traffic archive db info (database and collection names)
     *
     * @param  string   $accountKey
     * @param  string   $groupKey
     * @return array
     */
    protected function getArchiveDbInfo($accountKey, $groupKey)
    {
        return [
            'content_traffic_archive',
            sprintf('%s_%s', $accountKey, $groupKey),
        ];
    }

    /**
     * Obtains a user id from an Event
     * Will return null if the user id doesn't exist, or is invalid
     *
     * @param  Cygnus\OlyticsBundle\Model\Event\EventInterface  $event
     * @return \MongoId|null
     */
    protected function getUserId(EventInterface $event)
    {
        $userId = $event->getSession()->getCustomerId();
        if (empty($userId) || !$userId) {
            return null;
        }
        return $userId;
    }

    /**
     * Obtains a session id in Mongo Binary format from the Event
     *
     * @param  Cygnus\OlyticsBundle\Model\Event\EventInterface  $event
     * @return \MongoBinData
     */
    protected function getMongoSessionId(EventInterface $event)
    {
        return new \MongoBinData($event->getSession()->getId(), \MongoBinData::UUID);
    }

    /**
     * Returns a month object based on a date
     *
     * @param  \DateTime    $date   The date to retrieve the month from
     * @param  bool         $mongo  Whether this should return a \MongoDate object
     * @return \MongoDate|\DateTime
     */
    protected function getMonthFromDate(\DateTime $date, $mongo = true)
    {
        $ts = strtotime($date->format('Y-m-01 00:00:00'));

        if (true === $mongo) {
            return new \MongoDate($ts);
        }
        $date = new \DateTime();
        $date->setTimestamp($ts);
        return $date;
    }

    /**
     * Determines if this aggregation supports the provided event, account, and group
     *
     * @param  Cygnus\OlyticsBundle\Model\Event\EventInterface  $event
     * @param  string                                           $accountKey
     * @param  string                                           $groupKey
     * @return bool
     */
    public function supports(EventInterface $event, $accountKey, $groupKey, $appKey)
    {
        if (!$this->isEnabled($accountKey, $groupKey)) {
            return false;
        }

        return 'content' === $event->getEntity()->getType();
    }
}
