<?php

namespace App\Service\Companion\Updater;

use App\Entity\CompanionCharacter;
use App\Entity\CompanionMarketItemEntry;
use App\Entity\CompanionRetainer;
use App\Entity\CompanionToken;
use App\Repository\CompanionCharacterRepository;
use App\Repository\CompanionMarketItemEntryRepository;
use App\Repository\CompanionRetainerRepository;
use App\Service\Companion\CompanionConfiguration;
use App\Service\Companion\CompanionErrorHandler;
use App\Service\Companion\CompanionMarket;
use App\Service\Companion\Models\MarketHistory;
use App\Service\Companion\Models\MarketItem;
use App\Service\Companion\Models\MarketListing;
use App\Service\Content\GameServers;
use App\Service\ThirdParty\GoogleAnalytics;
use Companion\CompanionApi;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Auto-Update item price + history
 */
class MarketUpdater
{
    /** @var EntityManagerInterface */
    private $em;
    /** @var CompanionCharacterRepository */
    private $repositoryCompanionCharacter;
    /** @var CompanionRetainerRepository */
    private $repositoryCompanionRetainer;

    /** @var ConsoleOutput */
    private $console;
    /** @var CompanionMarket */
    private $market;
    /** @var CompanionErrorHandler */
    private $errorHandler;
    /** @var array */
    private $tokens = [];
    /** @var array */
    private $items = [];
    /** @var array */
    private $marketItemEntryUpdated = [];
    /** @var array  */
    private $requests = [];
    /** @var int */
    private $priority = 0;
    /** @var int */
    private $queue = 0;
    /** @var int */
    private $deadline = 0;
    /** @var int */
    private $exceptions = 0;
    /** @var array */
    private $requestIds = [];
    /** @var array */
    private $times = [
        'startTime'  => 0,
        'firstPass'  => 0,
        'secondPass' => 0,
    ];


    public function __construct(
        EntityManagerInterface $em,
        CompanionMarket $companionMarket,
        CompanionErrorHandler $companionErrorHandler
    ) {
        $this->em           = $em;
        $this->market       = $companionMarket;
        $this->errorHandler = $companionErrorHandler;
        $this->console      = new ConsoleOutput();
        $this->times        = (Object)$this->times;

        // repositories for market data
        $this->repositoryCompanionCharacter = $this->em->getRepository(CompanionCharacter::class);
        $this->repositoryCompanionRetainer  = $this->em->getRepository(CompanionRetainer::class);
    }

    /**
     * Update a series of items in a queue.
     */
    public function update(int $priority, int $queue, int $patreonQueue = null)
    {
        $this->console("Priority: {$priority} - Queue: {$queue}");
        $this->times->startTime = microtime(true);
        $this->deadline = time() + CompanionConfiguration::CRONJOB_TIMEOUT_SECONDS;
        $this->priority = $priority;
        $this->queue = $queue;
        $this->console('Starting!');
        
        foreach (range(0,CompanionConfiguration::MAX_ITEMS_PER_CRONJOB + 10) as $i) {
            $this->requestIds[$i] = Uuid::uuid4()->toString();
        }
        
        //--------------------------------------------------------------------------------------------------------------

        if ($this->errorHandler->getCriticalExceptionCount() > CompanionConfiguration::ERROR_COUNT_THRESHOLD) {
            $this->console('Exceptions are above the ERROR_COUNT_THRESHOLD.');
            $this->closeDatabaseConnection();
            exit();
        }

        // fetch companion tokens
        $this->fetchCompanionTokens();

        // fetch item ids to update
        $this->fetchItemIdsToUpdate($priority, $queue, $patreonQueue);
        
        if (empty($this->items)) {
            $this->console('No items to update');
            $this->closeDatabaseConnection();
            return;
        }

        // initialize Companion API
        $api = new CompanionApi();
        $api->useAsync();
        
        // check things didn't take too long to start
        if ($this->atDeadline()) {
            exit;
        }

        // 1st pass - send queue requests for all Item Prices + History
        $a     = microtime(true);
        $total = count($this->items);
        foreach ($this->items as $i => $item) {
            $i = $i + 1;
            
            $itemId     = $item['item'];
            $server     = $item['server'];
            $serverName = GameServers::LIST[$server];
            $serverDc   = GameServers::getDataCenter($serverName);

            /** @var CompanionToken $token */
            $token  = $this->tokens[$server];
            
            if ($token == null) {
                $this->console("Token has expired for server: {$server}, skipping...");
                continue;
            }
            
            $api->Token()->set($token);

            // build requests (PRICES, HISTORY)
            $async = [
                "{$this->requestIds[$i]}{$itemId}{$server}a"  => $api->Market()->getItemMarketListings($itemId),
                "{$this->requestIds[$i]}{$itemId}{$server}b" => $api->Market()->getTransactionHistory($itemId),
            ];

            // send requests and wait
            $api->Sight()->settle($async)->wait();
            $this->console("({$i}/{$total}) Sent queue requests for: {$itemId} on: {$server} {$serverName} - {$serverDc}");
            GoogleAnalytics::companionTrackItemAsUrl("/{$itemId}/Prices");
            GoogleAnalytics::companionTrackItemAsUrl("/{$itemId}/History");
    
            // store requests
            $this->requests[$server . $itemId] = $async;
            usleep(
                mt_rand(CompanionConfiguration::DELAY_BETWEEN_REQUESTS_MS[0], CompanionConfiguration::DELAY_BETWEEN_REQUESTS_MS[1]) * 1000
            );
        }
        $this->times->firstPass = microtime(true) - $a;
    
        // check things didn't take too long to start
        if ($this->atDeadline()) {
            exit;
        }

        // sleep
        $this->console("Sleeping until requests ...");
        sleep(
            mt_rand(CompanionConfiguration::DELAY_BETWEEN_REQUEST_RESPONSE[0], CompanionConfiguration::DELAY_BETWEEN_REQUEST_RESPONSE[1])
        );

        // 2nd pass - request results of all Item Prices + History
        $a = microtime(true);
        foreach ($this->items as $i => $item) {
            $i = $i + 1;
            
            // if exceptions were thrown in any request, we stop
            // (store market updates exceptions if any thrown)
            if ($this->exceptions >= CompanionConfiguration::ERROR_COUNT_THRESHOLD) {
                $this->console('Ending as exceptions have internally hit the limit.');
                break;
            }

            $id         = $item['id'];
            $itemId     = $item['item'];
            $server     = $item['server'];
            $serverName = GameServers::LIST[$server];
            $serverDc   = GameServers::getDataCenter($serverName);

            // grab request
            $requests = $this->requests[$server . $itemId];
            
            try {
                // request them again
                $results = $api->Sight()->settle($requests)->wait();
                $results = $api->Sight()->handle($results);
            } catch (\Exception $ex) {
                $this->console("({$i}/{$total}) - Exception thrown for: {$itemId} on: {$server} {$serverName} - {$serverDc}");
                continue;
            }
    
            GoogleAnalytics::companionTrackItemAsUrl("/{$itemId}/Prices");
            GoogleAnalytics::companionTrackItemAsUrl("/{$itemId}/History");
            
            $this->console("({$i}/{$total}) Fetch queue responses for: {$itemId} on: {$server} {$serverName} - {$serverDc}");

            // save data
            $this->storeMarketData($i, $itemId, $server, $results);

            // update item entry
            $this->marketItemEntryUpdated[] = $id;
    
            // update analytics
            usleep(
                mt_rand(CompanionConfiguration::DELAY_BETWEEN_REQUESTS_MS[0], CompanionConfiguration::DELAY_BETWEEN_REQUESTS_MS[1]) * 1000
            );
        }

        // update the database market entries with the latest updated timestamps
        $this->updateDatabaseMarketItemEntries();
        $this->em->flush();

        // finish, output completed duration
        $duration = round(microtime(true) - $this->times->startTime, 1);
        $this->times->secondPass = microtime(true) - $a;
        $this->console("-> Completed. Duration: <comment>{$duration}</comment>");
        $this->closeDatabaseConnection();
    }
    
    /**
     * Tests to see if the time deadline has hit
     */
    private function atDeadline()
    {
        // if we go over the deadline, we stop.
        if (time() > $this->deadline) {
            $this->console->writeln(date('H:i:s') ." | Ending auto-update as time limit seconds reached.");
            return true;
        }
        
        return false;
    }

    /**
     * Store the market data
     */
    private function storeMarketData($i, $itemId, $server, $results)
    {
        // grab prices and history from response
        /** @var \stdClass $prices */
        /** @var \stdClass $history */
        $prices  = $results->{"{$this->requestIds[$i]}{$itemId}{$server}a"} ?? null;
        $history = $results->{"{$this->requestIds[$i]}{$itemId}{$server}b"} ?? null;
        
        /**
         * Query error
         */
        if (isset($prices->error)) {
            $this->errorHandler->exception(
                $prices->reason,
                "Prices: {$itemId} / {$server}"
            );
        }

        if (isset($history->error)) {
            $this->errorHandler->exception(
                $prices->reason,
                "History: {$itemId} / {$server}"
            );
        }
    
        /**
         * Rejected
         */
        if (isset($prices->state) && $prices->state == "rejected") {
            $this->errorHandler->exception(
                "Rejected",
                "Prices: {$itemId} / {$server}"
            );
        }
    
        if (isset($history->state) && $history->state == "rejected") {
            $this->errorHandler->exception(
                "Rejected",
                "History: {$itemId} / {$server}"
            );
        }

        // if responses null or both have errors
        if (
            ($prices === null && $history == null) ||
            (isset($prices->error) && isset($history->error))
        ) {
            // Analytics
            GoogleAnalytics::companionTrackItemAsUrl('companion_empty');
            $this->console("!!! EMPTY RESPONSE");
            return;
        }
    
        // grab market item document
        $marketItem = $this->getMarketItemDocument($server, $itemId);
    
        // record lodestone info
        $marketItem->LodestoneID = $prices->eorzeadbItemId;

        // ---------------------------------------------------------------------------------------------------------
        // CURRENT PRICES
        // ---------------------------------------------------------------------------------------------------------
        if ($prices && isset($prices->error) === false && $prices->entries) {
            // reset prices
            $marketItem->Prices = [];

            // append current prices
            foreach ($prices->entries as $row) {
                // try build a semi unique id
                $id = sha1(
                    implode("_", [
                        $itemId,
                        $row->isCrafted,
                        $row->hq,
                        $row->sellPrice,
                        $row->stack,
                        $row->registerTown,
                        $row->sellRetainerName,
                    ])
                );

                // grab internal records
                $row->_retainerId = $this->getInternalRetainerId($server, $row->sellRetainerName);
                $row->_creatorSignatureId = $this->getInternalCharacterId($server, $row->signatureName);

                // append prices
                $marketItem->Prices[] = MarketListing::build($id, $row);
            }

            // sort prices low -> high
            usort($marketItem->Prices, function($first,$second) {
                return $first->PricePerUnit > $second->PricePerUnit;
            });
        }

        // ---------------------------------------------------------------------------------------------------------
        // CURRENT HISTORY
        // ---------------------------------------------------------------------------------------------------------
        if ($history && isset($history->error) === false && $history->history) {
            foreach ($history->history as $row) {
                // build a custom ID based on a few factors (History can't change)
                // we don't include character name as I'm unsure if it changes if you rename yourself
                $id = sha1(
                    implode("_", [
                        $itemId,
                        $row->stack,
                        $row->hq,
                        $row->sellPrice,
                        $row->buyRealDate,
                    ])
                );

                // if this entry is in our history, then just finish
                $found = false;
                foreach ($marketItem->History as $existing) {
                    if ($existing->ID == $id) {
                        $found = true;
                        break;
                    }
                }

                // once we've found an existing entry we don't need to add anymore
                if ($found) {
                    break;
                }

                // grab internal record
                $row->_characterId = $this->getInternalCharacterId($server, $row->buyCharacterName);

                // add history to front
                array_unshift($marketItem->History, MarketHistory::build($id, $row));
            }

            // sort history new -> old
            usort($marketItem->History, function($first,$second) {
                return $first->PurchaseDate < $second->PurchaseDate;
            });
        }
        
        // save market item
        $this->market->set($marketItem);
    }
    
    /**
     * Returns the ID for internally stored retainers
     */
    private function getInternalRetainerId(int $server, string $name): ?string
    {
        return $this->handleMarketTrackingNames(
            $server,
            $name,
            $this->repositoryCompanionRetainer,
            CompanionRetainer::class
        );
    }
    
    /**
     * Returns the ID for internally stored character ids
     */
    private function getInternalCharacterId(int $server, string $name): ?string
    {
        return $this->handleMarketTrackingNames(
            $server,
            $name,
            $this->repositoryCompanionCharacter,
            CompanionCharacter::class
        );
    }
    
    /**
     * Handles the tracking logic for all name fields
     */
    private function handleMarketTrackingNames(int $server, string $name, ObjectRepository $repository, $class)
    {
        if (empty($name)) {
            return null;
        }
        
        $obj = $repository->findOneBy([
            'name'   => $name,
            'server' => $server,
        ]);
        
        if ($obj === null) {
            $obj = new $class($name, $server);
            $this->em->persist($obj);
            $this->em->flush();
        }
        
        return $obj->getId();
    }

    /**
     * Get the elastic search document
     */
    private function getMarketItemDocument($server, $itemId): MarketItem
    {
        // return an existing one, otherwise return a new one
        return $this->market->get($server, $itemId, null, true);
    }

    /**
     * Fetches items to auto-update, this is performed here as the entity
     * manager is quite slow for thousands of throughput every second.
     */
    private function fetchItemIdsToUpdate($priority, $queue, $patreonQueue)
    {
        // get items to update
        $this->console('Finding Item IDs to Auto-Update');
        $s = microtime(true);

        // patreon get their own table.
        $limit = CompanionConfiguration::MAX_ITEMS_PER_CRONJOB;
        $where = $patreonQueue ? "patreon_queue = {$patreonQueue}" : "priority = {$priority} AND consumer = ${queue}";

        $sql = "
            SELECT id, item, server
            FROM companion_market_item_queue
            WHERE {$where}
            LIMIT {$limit}
        ";
        
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->execute();

        $this->items = $stmt->fetchAll();
        
        $sqlDuration = round(microtime(true) - $s, 2);
        $this->console("Obtained items in: {$sqlDuration} seconds");
    }

    /**
     * Fetch the companion tokens.
     */
    private function fetchCompanionTokens()
    {
        $conn = $this->em->getConnection();
        $sql  = "SELECT server, online, token FROM companion_tokens";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        foreach ($stmt->fetchAll() as $arr) {
            $serverId = GameServers::getServerId($arr['server']);
            $token = json_decode($arr['token']);
    
            $this->tokens[$serverId] = $arr['online'] ? $token : null;
        }
    }

    /**
     * Update item entry
     */
    private function updateDatabaseMarketItemEntries()
    {
        $this->console('Updating database item entries');
        $conn = $this->em->getConnection();

        foreach ($this->marketItemEntryUpdated as $id) {
            $sql = "UPDATE companion_market_item_entry SET updated = ". time() .", patreon_queue = NULL WHERE id = '{$id}'";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
        }
    }

    /**
     * Write to log
     */
    private function console($text)
    {
        $this->console->writeln(date('Y-m-d H:i:s') . " | {$this->priority} | {$this->queue} | {$text}");
    }
    
    /**
     * Close the db connections
     */
    private function closeDatabaseConnection()
    {
        $this->em->flush();
        $this->em->clear();
        $this->em->close();
        $this->em->getConnection()->close();
    }
    
    /**
     * Get a single market item entry.
     */
    public function getMarketItemEntry(int $serverId, int $itemId)
    {
        return $this->em->getRepository(CompanionMarketItemEntry::class)->findOneBy([
            'server' => $serverId,
            'item'   => $itemId,
        ]);
    }
    
    /**
     * Mark an item to be manually updated on an DC
     */
    public function updateManual(int $itemId, int $server, int $queueNumber)
    {
        /** @var CompanionMarketItemEntryRepository $repo */
        $repo    = $this->em->getRepository(CompanionMarketItemEntry::class);
        $servers = GameServers::getDataCenterServersIds(GameServers::LIST[$server]);
        $items   = $repo->findItemsInServers($itemId, $servers);
        
        /** @var CompanionMarketItemEntry $item */
        foreach ($items as $item) {
            $item->setPatreonQueue($queueNumber);
            $this->em->persist($item);
        }
        
        $this->em->flush();
    }
}
