<?php

namespace SyncBasalam\Services\Products\Discount;

use SyncBasalam\JobManager;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class DiscountTaskProcessor
{
    private $discountManager;
    private $taskModel;
    private $jobManager;

    public function __construct()
    {
        $this->discountManager = new DiscountManager();
        $this->taskModel = new DiscountTaskModel();
        $this->jobManager = JobManager::getInstance();
    }

    public function processDiscountTasks()
    {
        $job = $this->jobManager->getJob(['job_type' => 'sync_basalam_discount_tasks', 'status' => 'pending']);

        if (!$job) return;

        $this->jobManager->updateJob(['status' => 'processing', 'started_at' => time()], ['id' => $job->id]);

        try {
            $runnableTasks = $this->taskModel->getRunnableTasks();

            if (!$runnableTasks) {
                $this->jobManager->deleteJob(['id' => $job->id]);
                return;
            }

            $this->processTaskGroup($runnableTasks);

            $remainingTasks = $this->taskModel->getTasksCountByStatus(DiscountTaskModel::STATUS_PENDING);

            if ($remainingTasks > 0) {
                $this->jobManager->updateJob(
                    ['status' => 'pending', 'started_at' => 0],
                    ['id' => $job->id]
                );
            } else $this->jobManager->deleteJob(['id' => $job->id]);
        } catch (\Exception $e) {
            $this->jobManager->updateJob(
                ['status' => 'failed', 'error_message' => $e->getMessage()],
                ['id' => $job->id]
            );
        }
    }

    private function processTaskGroup($group)
    {
        $taskIds = !empty($group->task_ids) ? explode(',', $group->task_ids) : [];
        $rawProductIds = !empty($group->product_ids) ? array_filter(explode(',', $group->product_ids)) : [];

        $rawVariationIds = !empty($group->variation_ids) ? array_filter(explode(',', $group->variation_ids)) : [];

        $productIds = array_filter($rawProductIds, function ($id) {
            return !empty($id) && $id !== 'NULL' && $id !== null;
        });

        $variationIds = array_filter($rawVariationIds, function ($id) {
            return !empty($id) && $id !== 'NULL' && $id !== null;
        });

        if (empty($productIds) && empty($variationIds)) {
            $this->taskModel->updateMultipleStatus(
                $taskIds,
                DiscountTaskModel::STATUS_FAILED,
                'No valid product or variation IDs found'
            );
            return;
        }

        $this->taskModel->updateMultipleStatus($taskIds, DiscountTaskModel::STATUS_PROCESSING);

        if ($group->action === 'remove') $result = $this->discountManager->remove($productIds, $variationIds);
        else {
            $result = $this->discountManager->apply(
                $group->discount_percent,
                $productIds,
                $variationIds,
                $group->active_days
            );
        }

        if ($result && isset($result['status_code']) && $result['status_code'] === 202) {
            $this->taskModel->updateMultipleStatus($taskIds, DiscountTaskModel::STATUS_COMPLETED);
        } else {
            $errorMessage = 'خطای ناشناخته';
            if ($result) {
                $decodedBody = is_string($result['body']) ? json_decode($result['body'], true) : $result['body'];
                if (isset($decodedBody['message'])) {
                    $errorMessage = $decodedBody['message'];
                } elseif (isset($decodedBody['error'])) {
                    $errorMessage = $decodedBody['error'];
                } elseif (isset($result['status_code'])) {
                    $errorMessage = sprintf('API returned status code: %d', $result['status_code']);
                }
            }

            $this->taskModel->updateMultipleStatus($taskIds, DiscountTaskModel::STATUS_FAILED, $errorMessage);
        }
    }

    public function addDiscountTasks($items)
    {
        $jobExists = $this->jobManager->getCountJobs(['job_type' => 'sync_basalam_discount_tasks', 'status' => ['pending', 'processing']]);

        if ($jobExists === 0) $this->jobManager->createJob('sync_basalam_discount_tasks', 'pending');

        $createdCount = 0;
        foreach ($items as $item) {
            $wcProductId = $item['product_id'] ?? null;
            $wcVariationId = $item['variation_id'] ?? null;
            $action = $item['action'] ?? 'apply';
            $discountPercent = $item['discount_percent'] ?? 0;
            $activeDays = $item['active_days'] ?? syncBasalamSettings()->getSettings(SettingsConfig::DISCOUNT_DURATION) ?? 7;

            $basalamProductId = null;
            $basalamVariationId = null;

            if ($wcProductId) $basalamProductId = get_post_meta($wcProductId, 'sync_basalam_product_id', true);
            if ($wcVariationId) $basalamVariationId = get_post_meta($wcVariationId, 'sync_basalam_variation_id', true);

            if ($basalamProductId || $basalamVariationId) {
                $taskData = [
                    'product_id'       => $basalamProductId,
                    'variation_id'     => $basalamVariationId,
                    'discount_percent' => $discountPercent,
                    'active_days'      => $activeDays,
                    'action'           => $action,
                    'status'           => DiscountTaskModel::STATUS_PENDING,
                ];

                $taskId = $this->taskModel->create($taskData);

                if ($taskId) {
                    $createdCount++;
                    $this->markProductsAsDiscounted([$wcProductId], [$wcVariationId], $action);
                }
            }
        }

        return $createdCount > 0;
    }

    public function handleProductDiscount($productId)
    {
        $priceField = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_PRICE_FIELD);

        $product = wc_get_product($productId);
        
        if (!$product) return;

        $items = [];

        if ($priceField !== 'sale_strikethrough_price') {
            $this->handleNonSalePriceMode($product);
            return;
        }

        if ($product->is_type('simple')) {
            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);

            if (!$basalamProductId) return;

            $salePrice = $product->get_sale_price();
            $regularPrice = $product->get_regular_price();

            if ($salePrice && $regularPrice) {
                $discountPercent = DiscountManager::calculateDiscountPercent($regularPrice, $salePrice);
                if ($discountPercent > 0) {
                    $items[] = [
                        'product_id' => $productId,
                        'discount_percent' => $discountPercent,
                        'action' => 'apply'
                    ];
                }
            } else {
                $items[] = [
                    'product_id' => $productId,
                    'action' => 'remove'
                ];
            }
        } elseif ($product->is_type('variable')) {
            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
            if (!$basalamProductId) return;

            foreach ($product->get_children() as $variationId) {
                $variation = wc_get_product($variationId);
                if (!$variation) continue;

                $basalamVariationId = get_post_meta($variationId, 'sync_basalam_variation_id', true);
                if (!$basalamVariationId) continue;

                $salePrice = $variation->get_sale_price();
                $regularPrice = $variation->get_regular_price();

                if ($salePrice && $regularPrice) {
                    $discountPercent = DiscountManager::calculateDiscountPercent($regularPrice, $salePrice);
                    if ($discountPercent > 0) {
                        $items[] = [
                            'product_id' => $productId,
                            'variation_id' => $variationId,
                            'discount_percent' => $discountPercent,
                            'action' => 'apply'
                        ];
                    }
                } else {
                    $items[] = [
                        'product_id' => $productId,
                        'variation_id' => $variationId,
                        'action' => 'remove'
                    ];
                }
            }
        }

        if (!empty($items)) {
            $this->addDiscountTasks($items);
        }
    }

    private function markProductsAsDiscounted(array $wcProductIds, array $wcVariationIds, string $action)
    {
        foreach ($wcProductIds as $wcProductId) {
            if ($wcProductId) {
                if ($action === 'apply') update_post_meta($wcProductId, 'sync_basalam_discounted', 'true');
                else delete_post_meta($wcProductId, 'sync_basalam_discounted');
            }
        }

        foreach ($wcVariationIds as $wcVariationId) {
            if ($wcVariationId) {
                if ($action === 'apply') update_post_meta($wcVariationId, 'sync_basalam_discounted', 'true');
                else delete_post_meta($wcVariationId, 'sync_basalam_discounted');
            }
        }
    }

    private function handleNonSalePriceMode($product)
    {
        $productId = $product->get_id();
        $items = [];

        if ($product->is_type('simple')) {
            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
            if (!$basalamProductId) return;

            $isDiscounted = get_post_meta($productId, 'sync_basalam_discounted', true);

            if ($isDiscounted === 'true') {
                $items[] = [
                    'product_id' => $productId,
                    'action' => 'remove'
                ];
            }
        } elseif ($product->is_type('variable')) {
            $basalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);

            if (!$basalamProductId) return;

            foreach ($product->get_children() as $variationId) {
                $basalamVariationId = get_post_meta($variationId, 'sync_basalam_variation_id', true);

                if (!$basalamVariationId) continue;

                $isDiscounted = get_post_meta($variationId, 'sync_basalam_discounted', true);

                if ($isDiscounted === 'true') {
                    $items[] = [
                        'product_id' => $productId,
                        'variation_id' => $variationId,
                        'action' => 'remove'
                    ];
                }
            }
        }

        if (!empty($items)) $this->addDiscountTasks($items);
    }
}
