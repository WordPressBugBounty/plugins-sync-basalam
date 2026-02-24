<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Services\Products\AutoConnectProducts;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class AutoConnectProductsJob extends AbstractJobType
{
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
        $cursor = $payload['cursor'] ?? null;

        try {
            $autoConnect = new AutoConnectProducts();
            $result = $autoConnect->checkSameProduct(null, $cursor);

            if (!empty($result['has_more']) && !empty($result['next_cursor'])) {
                $this->jobManager->createJob(
                    'sync_basalam_auto_connect_products',
                    'pending',
                    json_encode(['cursor' => $result['next_cursor']])
                );
            }

            return $this->success(['cursor' => $cursor, 'processed' => true]);
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
