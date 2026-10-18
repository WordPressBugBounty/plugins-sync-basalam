<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Utils\OptionLock;
use SyncBasalam\SalamGate\Services\ConnectionCache;

defined('ABSPATH') || exit;
require_once __DIR__ . '/BasalamParcelOrderItem.php';

/** Shipping-only view: one real native parcel, never another Woo order or financial line. */
final class BasalamParcelOrder
{
    private \WC_Order $root;
    private int $nativeId;
    private array $allocation;
    private array $identity;
    private array $snapshots;

    public static function open(\WC_Order $root, int $nativeId): self
    {
        require_once __DIR__ . '/BasalamParcelSet.php';
        require_once dirname(__DIR__) . '/Utils/OptionLock.php';
        $connection = ConnectionCache::identityHash();
        $snapshot = BasalamParcelSet::load($root, $nativeId);
        $key = 'salam_gate_native_registry_' . (int) $root->get_id();
        $lease = OptionLock::acquire($key);
        if ($lease === null) { throw self::error('اطلاعات مرسوله‌های این سفارش در حال بررسی است؛ کمی بعد دوباره تلاش کنید.'); }
        try {
            $renewed = OptionLock::renew($key, $lease);
            if ($renewed === null) { throw self::error('زمان بررسی مرسوله تمام شد؛ دوباره مرسوله را انتخاب کنید.'); }
            $lease = $renewed;
            self::assertContext($root, $snapshot, $connection);
            if (method_exists($root, 'read_meta_data')) { $root->read_meta_data(true); }
            $renewed = OptionLock::renew($key, $lease);
            if ($renewed === null) { throw self::error('بررسی مرسوله هم‌زمان در درخواست دیگری انجام شد؛ دوباره آن را انتخاب کنید.'); }
            $lease = $renewed;
            self::assertContext($root, $snapshot, $connection);
            self::assertRegistry($root, $nativeId, $snapshot);
            return new self($root, $nativeId, $snapshot, static function () use ($key, &$lease, $root, $snapshot, $connection): bool {
                self::assertContext($root, $snapshot, $connection);
                $renewed = OptionLock::renew($key, $lease);
                if ($renewed === null) { return false; }
                $lease = $renewed;
                return true;
            });
        } finally { OptionLock::release($key, $lease); }
    }

    private function __construct(\WC_Order $root, int $nativeId, array $snapshot, callable $canPersist)
    {
        $this->root = $root;
        $this->nativeId = $nativeId;
        $this->allocation = $snapshot['allocations'];
        $this->identity = ['order_id' => (int) $root->get_id(), 'invoice_id' => $snapshot['invoice_id'],
            'vendor_id' => $snapshot['vendor_id'], 'parcel_id' => $nativeId];
        $this->snapshots = $snapshot;
        $stored = $root->get_meta($this->key('identity'));
        if ($stored !== '' && $stored !== null && $stored !== $this->identity) {
            throw self::error('ارتباط ذخیره‌شده این مرسوله با سفارش یا غرفه تغییر کرده است؛ ارسال انجام نشد.');
        }
        $allocation = $root->get_meta($this->key('allocations'));
        if ($allocation !== '' && $allocation !== null && $allocation !== $this->allocation) {
            throw self::error('اقلام این مرسوله در باسلام تغییر کرده‌اند؛ ارتباط ارسال قبلی باید بررسی شود.');
        }
        // Copy legacy evidence only to its authenticated exact original parcel.
        // Never delete the old fields or associate their payment with a sibling.
        $legacy = $root->get_meta('_salam_gate_basalam_mapping');
        $legacyExternal = $root->get_meta('_salam_gate_external_id');
        $legacyMatches = is_array($legacy) && $legacy === $this->identity
            && ($legacyExternal === '' || (string) $legacyExternal === (string) $nativeId)
            && in_array($root->get_meta('_salam_gate_market'), ['', 'basalam'], true);
        if (!$canPersist()) { throw self::error('زمان بررسی مرسوله تمام شد؛ دوباره مرسوله را انتخاب کنید.'); }
        if (($stored === '' || $stored === null) && $legacyMatches && self::hasLegacyShipment($root)) {
            // The old workflow could ship only the complete imported item set.
            // A later native split cannot silently inherit that complete receipt.
            if (count($snapshot['order']['items']) !== count($this->allocation)) {
                throw self::error('اقلام مرسوله قبلی با رسید کامل سفارش متفاوت است؛ قبل از ادامه، ارسال قبلی را در پنل باسلام بررسی کنید.');
            }
            foreach ($snapshot['order']['items'] as $line) {
                if (($this->allocation[(int) $line['id']]['quantity'] ?? null) !== (int) $line['quantity']) {
                    throw self::error('تعداد کالاهای مرسوله قبلی تغییر کرده است؛ رسید قبلی به بسته جدید منتقل نمی‌شود.');
                }
            }
            foreach (self::stateKeys() as $key) {
                $value = $root->get_meta($key);
                if ($value !== '' && $value !== null) { $this->update_meta_data($key, $value); }
            }
        }
        $root->update_meta_data($this->key('identity'), $this->identity);
        $root->update_meta_data($this->key('allocations'), $this->allocation);
        $this->save();
    }

    /** Stored-only view for cron, labels and rendering. No discovery HTTP calls. */
    public static function stored(\WC_Order $root, int $nativeId): ?self
    {
        $identity = $root->get_meta('_salam_gate_bp_' . $nativeId . '_identity');
        $allocation = $root->get_meta('_salam_gate_bp_' . $nativeId . '_allocations');
        $vendor = function_exists('syncBasalamSettings') ? (int) syncBasalamSettings()->getSettings('vendor_id') : 0;
        if ($nativeId < 1 || !is_array($identity) || !self::uniqueScopeMetadata($root, $nativeId) || !self::validAllocation($root, $allocation)
            || $identity !== ['order_id' => (int) $root->get_id(),
                'invoice_id' => BasalamOrderContext::getInvoiceId((int) $root->get_id()),
                'vendor_id' => $vendor, 'parcel_id' => $nativeId]) { return null; }
        // Constructor is deliberately bypassed: rendering/sync never migrates or discovers.
        $object = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $object->root = $root;
        $object->nativeId = $nativeId;
        $object->allocation = $allocation;
        $object->identity = $identity;
        $object->snapshots = [];
        return $object;
    }

    /** Detect damaged scoped metadata too: it must never enable legacy fallback. */
    public static function hasScopes(\WC_Order $root): bool
    {
        if (!method_exists($root, 'get_meta_data')) { return false; }
        foreach ($root->get_meta_data() as $meta) {
            if (preg_match('/^_salam_gate_bp_[1-9][0-9]*_/', (string) ($meta->get_data()['key'] ?? ''))) { return true; }
        }
        return false;
    }

    public static function entries(\WC_Order $root): array
    {
        $entries = [];
        if (method_exists($root, 'get_meta_data')) {
            foreach ($root->get_meta_data() as $meta) {
                $data = $meta->get_data();
                if (preg_match('/^_salam_gate_bp_([1-9][0-9]*)_identity$/D', (string) ($data['key'] ?? ''), $match)) {
                    $id = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $scope = $id !== false ? self::stored($root, $id) : null;
                    if ($scope !== null) { $entries[$id] = $scope; }
                }
            }
        }
        ksort($entries);
        return $entries;
    }

    public function getRoot(): \WC_Order { return $this->root; }
    public function getNativeParcelId(): int { return $this->nativeId; }
    public function getSnapshots(): array { return $this->snapshots; }
    public function getIdentity(): array { return $this->identity; }
    public function getAllocation(): array { return $this->allocation; }
    public function get_id() { return $this->root->get_id(); }

    public function get_items($types = 'line_item'): array
    {
        $items = [];
        foreach ($this->root->get_items($types) as $key => $item) {
            $external = (int) $this->root->get_meta('_sync_basalam_item_id_' . $item->get_id());
            if (isset($this->allocation[$external])) {
                $items[$key] = new BasalamParcelOrderItem($item, $this->allocation[$external]['quantity']);
            }
        }
        return $items;
    }

    public function get_meta($key = '', $single = true, $context = 'view')
    {
        if ($key === '_salam_gate_basalam_mapping') { return $this->identity; }
        return $this->root->get_meta($this->scopedKey($key), $single, $context);
    }

    public function update_meta_data($key, $value, $metaId = 0): void
    {
        if (!$this->isStateKey($key)) { throw self::error('تغییر اطلاعات اصلی سفارش از مسیر مرسوله مجاز نیست.'); }
        if ($key === '_salam_gate_parcel_id' && is_numeric($value) && (int) $value > 0) {
            $prior = $this->root->get_meta('_salam_gate_native_link_' . (int) $value);
            if ($prior !== '' && (string) $prior !== (string) $this->nativeId) {
                throw self::error('این رسید سلام‌گیت به مرسوله دیگری متصل است؛ ارتباط مرسوله را بررسی کنید.');
            }
            $this->root->update_meta_data('_salam_gate_native_link_' . (int) $value, $this->nativeId);
            $this->root->update_meta_data('_salam_gate_native_origin_' . $this->nativeId, (int) $value);
        }
        $this->root->update_meta_data($this->scopedKey($key), $value);
    }

    public function save()
    {
        // Metadata-only CRUD avoids status/order totals/stock hooks on every child write.
        if (method_exists($this->root, 'save_meta_data')) { $this->root->save_meta_data(); return $this->get_id(); }
        return $this->root->save(); // Isolated test adapters.
    }

    public function read_meta_data($force = false): void
    {
        if (method_exists($this->root, 'read_meta_data')) { $this->root->read_meta_data($force); }
    }

    public function add_order_note($note, $customer = 0, $addedByUser = false)
    {
        return $this->root->add_order_note('مرسوله باسلام #' . $this->nativeId . ': ' . $note, $customer, $addedByUser);
    }

    /** Sending one child must not mark the complete parent order shipped. */
    public function update_status($status, $note = '', $manual = false): void
    {
        if ($note !== '') { $this->add_order_note($note); }
    }

    public function __call($method, $arguments)
    {
        if (strpos($method, 'get_') === 0) { return $this->root->$method(...$arguments); }
        throw self::error('عملیات تغییر سفارش اصلی از مسیر مرسوله مجاز نیست.');
    }

    private function key(string $suffix): string { return '_salam_gate_bp_' . $this->nativeId . '_' . $suffix; }
    private function isStateKey(string $key): bool { return strpos($key, '_salam_gate_') === 0 || $key === '_basalam_order_tracking_code'; }
    private function scopedKey(string $key): string { return $this->isStateKey($key) ? $this->key(ltrim($key, '_')) : $key; }
    private static function stateKeys(): array
    {
        return ['_salam_gate_parcel_id', '_salam_gate_external_id', '_salam_gate_market', '_salam_gate_status',
            '_salam_gate_tracking_code', '_basalam_order_tracking_code', '_salam_gate_created_at', '_salam_gate_paid_at',
            '_salam_gate_invoice_id', '_salam_gate_pay_unknown', '_salam_gate_selected_service', '_salam_gate_delivery_node',
            '_salam_gate_shipping_cost_rial', '_salam_gate_status_checked_at', '_salam_gate_label',
            '_salam_gate_order_draft_payload', '_salam_gate_order_draft_parcel_id'];
    }

    private static function validAllocation(\WC_Order $root, $allocation): bool
    {
        if (!is_array($allocation) || $allocation === []) { return false; }
        $items = [];
        foreach ($root->get_items() as $item) { $items[(int) $item->get_id()] = $item; }
        foreach ($allocation as $external => $line) {
            if (!is_int($external) || $external < 1 || !is_array($line)
                || !is_int($line['item_id'] ?? null) || !is_int($line['quantity'] ?? null)
                || $line['quantity'] < 1 || !isset($items[$line['item_id']])
                || (string) $root->get_meta('_sync_basalam_item_id_' . $line['item_id']) !== (string) $external
                || $line['quantity'] > (int) $items[$line['item_id']]->get_quantity()) { return false; }
        }
        return true;
    }

    /** Cross-page/cross-request quantity fence, serialized before any new scope is saved. */
    private static function assertRegistry(\WC_Order $root, int $nativeId, array $snapshot): void
    {
        $entries = self::entries($root);
        // Unmigrated financial evidence belongs to exactly one native parcel.
        // Make it explicit first instead of silently ignoring it while opening a sibling.
        $legacy = $root->get_meta('_salam_gate_basalam_mapping');
        $legacyId = is_array($legacy) ? ($legacy['parcel_id'] ?? null) : null;
        $hasLegacy = self::hasLegacyShipment($root);
        if ($hasLegacy && (!is_int($legacyId) || ($legacyId !== $nativeId && !isset($entries[$legacyId])))) {
            throw self::error('ابتدا مرسوله قبلی این سفارش را انتخاب و وضعیت آن را بررسی کنید؛ پرداخت قبلی به بسته دیگری منتقل نمی‌شود.');
        }
        $totals = [];
        if (count($entries) >= 30 && !isset($entries[$nativeId])) {
            throw self::error('تعداد مرسوله‌های ذخیره‌شده این سفارش بیشتر از حد قابل بررسی است؛ ادامه ارسال را از پنل باسلام بررسی کنید.');
        }
        foreach ($entries as $id => $entry) {
            if ($id === $nativeId) { continue; }
            foreach ($entry->getAllocation() as $external => $line) { $totals[$external] = ($totals[$external] ?? 0) + $line['quantity']; }
        }
        foreach ($snapshot['allocations'] as $external => $line) { $totals[$external] = ($totals[$external] ?? 0) + $line['quantity']; }
        $parent = [];
        foreach ($snapshot['order']['items'] as $line) { $parent[(int) $line['id']] = (int) $line['quantity']; }
        foreach ($totals as $external => $quantity) {
            if (!isset($parent[$external]) || $quantity > $parent[$external]) {
                throw self::error('مجموع تعداد کالا در مرسوله‌ها بیشتر از تعداد سفارش است؛ مرسوله‌ها را در پنل باسلام بررسی کنید.');
            }
        }
        if (method_exists($root, 'get_meta_data')) {
            foreach ($root->get_meta_data() as $meta) {
                $key = (string) ($meta->get_data()['key'] ?? '');
                // An orphan allocation/receipt/unknown marker is evidence too. Losing
                // just its identity must not hide quantities or authorize a sibling.
                if (preg_match('/^_salam_gate_bp_([1-9][0-9]*)_/', $key, $match)
                    && !isset($entries[$match[1]])) {
                    throw self::error('ارتباط ذخیره‌شده یکی از مرسوله‌های این سفارش معتبر نیست؛ قبل از ارسال آن را بررسی کنید.');
                }
            }
        }
    }

    /** Woo single-meta reads must not conceal a conflicting duplicate scope row. */
    private static function uniqueScopeMetadata(\WC_Order $root, int $nativeId): bool
    {
        if (!method_exists($root, 'get_meta_data')) { return true; }
        $prefix = '_salam_gate_bp_' . $nativeId . '_';
        $seen = [];
        foreach ($root->get_meta_data() as $meta) {
            $key = (string) ($meta->get_data()['key'] ?? '');
            if (strpos($key, $prefix) !== 0) { continue; }
            if (isset($seen[$key])) { return false; }
            $seen[$key] = true;
        }
        return true;
    }
    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
    private static function assertContext(\WC_Order $root, array $snapshot, string $connection): void
    {
        ConnectionCache::assertCurrentIdentity($connection);
        if (BasalamOrderContext::getInvoiceId((int) $root->get_id()) !== $snapshot['invoice_id']
            || (int) syncBasalamSettings()->getSettings('vendor_id') !== $snapshot['vendor_id']
            || !self::validAllocation($root, $snapshot['allocations'])) {
            throw self::error('ارتباط سفارش، غرفه یا اقلام هنگام بررسی تغییر کرده است؛ دوباره مرسوله را انتخاب کنید.');
        }
    }
    private static function hasLegacyShipment(\WC_Order $root): bool
    {
        foreach (['_salam_gate_parcel_id', '_salam_gate_pay_unknown', '_salam_gate_paid_at', '_salam_gate_invoice_id'] as $key) {
            if (!in_array($root->get_meta($key), ['', null, false, 0, '0'], true)) { return true; }
        }
        return false;
    }
}
