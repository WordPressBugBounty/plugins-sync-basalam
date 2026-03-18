<?php

namespace SyncBasalam\Infrastructure\Container;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Admin\Settings\SettingsContainer;
use SyncBasalam\JobManager;
use SyncBasalam\Jobs\DiscountTaskScheduler;
use SyncBasalam\Jobs\JobExecutor;
use SyncBasalam\Jobs\JobRegistry;
use SyncBasalam\Jobs\LockManager;
use SyncBasalam\Jobs\Types\AutoConnectProductsJob;
use SyncBasalam\Jobs\Types\BulkUpdateProductsJob;
use SyncBasalam\Jobs\Types\CreateAllProductsJob;
use SyncBasalam\Jobs\Types\CreateSingleProductJob;
use SyncBasalam\Jobs\Types\FetchOrdersJob;
use SyncBasalam\Jobs\Types\UpdateAllProductsJob;
use SyncBasalam\Jobs\Types\UpdateSingleProductJob;
use SyncBasalam\JobsRunner;
use SyncBasalam\Plugin;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\Products\AutoConnectProducts;
use SyncBasalam\Services\Products\Discount\DiscountTaskProcessor;
use SyncBasalam\Services\Orders\FetchOrdersService;
use SyncBasalam\Services\Orders\SyncOrderService;
use SyncBasalam\Services\SystemResourceMonitor;

defined('ABSPATH') || exit;

class AppServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(SettingsContainer::class, function () {
            return new SettingsContainer();
        });

        $container->singleton(Plugin::class, function () {
            return new Plugin();
        });

        $container->singleton(JobManager::class, function () {
            return new JobManager();
        });

        $container->singleton(ApiServiceManager::class, function () {
            return $this->newInstance(ApiServiceManager::class);
        });

        $container->singleton(ProductOperations::class, function () {
            return $this->newInstance(ProductOperations::class);
        });

        $container->singleton(LockManager::class, function () {
            return new LockManager();
        });

        $container->singleton(SystemResourceMonitor::class, function () {
            return new SystemResourceMonitor();
        });

        $container->singleton(UpdateSingleProductJob::class, function (ContainerInterface $container) {
            return new UpdateSingleProductJob(
                $container->get(JobManager::class),
                $container->get(ProductOperations::class)
            );
        });

        $container->singleton(CreateSingleProductJob::class, function (ContainerInterface $container) {
            return new CreateSingleProductJob(
                $container->get(JobManager::class),
                $container->get(ProductOperations::class)
            );
        });

        $container->singleton(BulkUpdateProductsJob::class, function (ContainerInterface $container) {
            return new BulkUpdateProductsJob(
                $container->get(JobManager::class),
                $container->get(ApiServiceManager::class),
                $container->get(\SyncBasalam\Admin\Product\ProductDataFactory::class),
                $container->get(SettingsContainer::class)
            );
        });

        $container->singleton(CreateAllProductsJob::class, function (ContainerInterface $container) {
            return new CreateAllProductsJob($container->get(JobManager::class));
        });

        $container->singleton(UpdateAllProductsJob::class, function (ContainerInterface $container) {
            return new UpdateAllProductsJob($container->get(JobManager::class));
        });

        $container->singleton(AutoConnectProducts::class, function () {
            return new AutoConnectProducts();
        });

        $container->singleton(AutoConnectProductsJob::class, function (ContainerInterface $container) {
            return new AutoConnectProductsJob(
                $container->get(JobManager::class),
                $container->get(AutoConnectProducts::class)
            );
        });

        $container->singleton(FetchOrdersService::class, function () {
            return new FetchOrdersService();
        });

        $container->singleton(SyncOrderService::class, function () {
            return new SyncOrderService();
        });

        $container->singleton(FetchOrdersJob::class, function (ContainerInterface $container) {
            return new FetchOrdersJob(
                $container->get(JobManager::class),
                $container->get(FetchOrdersService::class),
                $container->get(SyncOrderService::class)
            );
        });

        $container->singleton(JobRegistry::class, function (ContainerInterface $container) {
            return new JobRegistry([
                $container->get(BulkUpdateProductsJob::class),
                $container->get(UpdateAllProductsJob::class),
                $container->get(UpdateSingleProductJob::class),
                $container->get(CreateSingleProductJob::class),
                $container->get(CreateAllProductsJob::class),
                $container->get(AutoConnectProductsJob::class),
                $container->get(FetchOrdersJob::class),
            ]);
        });

        $container->singleton(JobExecutor::class, function (ContainerInterface $container) {
            return new JobExecutor(
                $container->get(JobManager::class),
                $container->get(LockManager::class),
                $container->get(JobRegistry::class)
            );
        });

        $container->singleton(DiscountTaskProcessor::class, function () {
            return new DiscountTaskProcessor();
        });

        $container->singleton(DiscountTaskScheduler::class, function (ContainerInterface $container) {
            return new DiscountTaskScheduler(
                $container->get(DiscountTaskProcessor::class)
            );
        });

        $container->singleton(JobsRunner::class, function (ContainerInterface $container) {
            return new JobsRunner(
                $container->get(JobManager::class),
                $container->get(JobExecutor::class),
                $container->get(DiscountTaskScheduler::class)
            );
        });
    }

    private function newInstance(string $class)
    {
        return new $class();
    }
}
