<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class AutoConnectProductsJob extends AbstractJobType
{
    private $autoConnect;

    public function __construct($jobManager,$autoConnect)
    {
        parent::__construct($jobManager);
        $this->autoConnect = $autoConnect;
    }

    public function getType(): string
    {
        return 'sync_basalam_auto_connect_products';
    }

    public function getPriority(): int
    {
        return 6;
    }

    public function execute(array $payload): JobResult
    {
        $mode = $payload['mode'] ?? 'vendor';

        try {
            if ($mode === 'search') {
                $lastProductId = (int) ($payload['last_product_id'] ?? 0);
                $result = $this->autoConnect->connectUnconnectedProductsBySearch($lastProductId);

                if (!empty($result['has_more'])) {
                    $this->jobManager->createJob(
                        'sync_basalam_auto_connect_products',
                        'pending',
                        json_encode([
                            'mode' => 'search',
                            'last_product_id' => $result['next_product_id'],
                        ])
                    );
                }

                return $this->success([
                    'mode' => 'search',
                    'last_product_id' => $lastProductId,
                    'connected' => $result['connected'] ?? 0,
                ]);
            }

            $cursor = $payload['cursor'] ?? null;
            $result = $this->autoConnect->checkSameProduct(null, $cursor);

            if (!empty($result['has_more']) && !empty($result['next_cursor'])) {
                $this->jobManager->createJob(
                    'sync_basalam_auto_connect_products',
                    'pending',
                    json_encode([
                        'mode' => 'vendor',
                        'cursor' => $result['next_cursor'],
                    ])
                );
            } elseif (empty($result['error']) || !empty($result['completed'])) {
                // The vendor scan is complete. Run the same title search used
                // on the single-product screen for everything still unconnected.
                $this->jobManager->createJob(
                    'sync_basalam_auto_connect_products',
                    'pending',
                    json_encode([
                        'mode' => 'search',
                        'last_product_id' => 0,
                    ])
                );
            }

            return $this->success(['cursor' => $cursor, 'mode' => 'vendor', 'processed' => true]);
        } catch (RetryableException $e) {
            Logger::error("خطا در اتصال خودکار محصولات: " . $e->getMessage(), [
                'operation' => 'اتصال خودکار محصولات',
            ]);
            throw $e;
        } catch (NonRetryableException $e) {
            Logger::error("خطا در اتصال خودکار محصولات: " . $e->getMessage(), [
                'operation' => 'اتصال خودکار محصولات',
            ]);
            throw $e;
        }
         catch (\Exception $e) {
            Logger::error("خطا در اتصال خودکار محصولات: " . $e->getMessage(), [
                'operation' => 'اتصال خودکار محصولات',
            ]);
            throw $e;
        }
    }
}
