<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/public/inventory/helpers.php';
require_once __DIR__ . '/../backend/public/assets/helpers.php';
require_once __DIR__ . '/../backend/public/income/helpers.php';
require_once __DIR__ . '/../backend/public/purchase/helpers.php';
require_once __DIR__ . '/../backend/public/payheads/helpers.php';

function ensure_resignation_rules_schema_local(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hr_resignation_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(140) NOT NULL,
            department_name VARCHAR(120) DEFAULT NULL,
            employee_type VARCHAR(80) DEFAULT NULL,
            min_tenure_months INT NOT NULL DEFAULT 0,
            notice_period_days INT NOT NULL DEFAULT 30,
            buyout_allowed TINYINT(1) NOT NULL DEFAULT 0,
            buyout_multiplier DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            final_settlement_days INT NOT NULL DEFAULT 15,
            exit_interview_required TINYINT(1) NOT NULL DEFAULT 1,
            approvals_required TEXT DEFAULT NULL,
            status_label VARCHAR(20) NOT NULL DEFAULT "Active",
            description_text TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_resignation_rules_name (rule_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function fetch_actor_ids(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, full_name FROM employees ORDER BY id ASC LIMIT 2');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $first = isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    $second = isset($rows[1]['id']) ? (int) $rows[1]['id'] : $first;
    $firstName = isset($rows[0]['full_name']) ? (string) $rows[0]['full_name'] : 'System User';

    return [
        'created_by' => $first,
        'assigned_to' => $second,
        'assigned_name' => $firstName,
    ];
}

function exists_by_column(PDO $pdo, string $table, string $column, string $value): bool
{
    $sql = sprintf('SELECT id FROM %s WHERE %s = :value LIMIT 1', $table, $column);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['value' => $value]);
    return (bool) $stmt->fetchColumn();
}

function fetch_id_by_column(PDO $pdo, string $table, string $column, string $value): ?int
{
    $sql = sprintf('SELECT id FROM %s WHERE %s = :value LIMIT 1', $table, $column);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['value' => $value]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

$pdo = db();

ensure_inventory_schema($pdo);
ensure_assets_schema($pdo);
ensure_income_schema($pdo);
ensure_purchase_schema($pdo);
ensure_payheads_schema($pdo);
ensure_resignation_rules_schema_local($pdo);

$actors = fetch_actor_ids($pdo);
$createdBy = $actors['created_by'];
$assignedTo = $actors['assigned_to'];
$assignedName = $actors['assigned_name'];

$today = date('Y-m-d');
$nextMonth = date('Y-m-d', strtotime('+30 days'));

$report = [
    'inventory_items' => 0,
    'inventory_movements' => 0,
    'assets_items' => 0,
    'income_entries' => 0,
    'purchase_orders' => 0,
    'purchase_order_items' => 0,
    'hr_payheads' => 0,
    'hr_resignation_rules' => 0,
];

$pdo->beginTransaction();

try {
    $inventorySeeds = [
        [
            'item_code' => 'ITM-DMY-ROUTER-001',
            'item_name' => 'Core Router - Dummy',
            'category_name' => 'Network Equipment',
            'unit_label' => 'pcs',
            'min_stock' => 2,
            'current_stock' => 8,
            'unit_cost' => 18500,
            'active_status' => 1,
            'movement_qty' => 8,
        ],
        [
            'item_code' => 'ITM-DMY-ONU-002',
            'item_name' => 'GPON ONU Device - Dummy',
            'category_name' => 'CPE',
            'unit_label' => 'pcs',
            'min_stock' => 10,
            'current_stock' => 60,
            'unit_cost' => 2300,
            'active_status' => 1,
            'movement_qty' => 60,
        ],
    ];

    $insertInventory = $pdo->prepare(
        'INSERT INTO inventory_items (
            item_code, item_name, category_name, unit_label,
            min_stock, current_stock, unit_cost, active_status,
            created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :item_code, :item_name, :category_name, :unit_label,
            :min_stock, :current_stock, :unit_cost, :active_status,
            :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    $insertMovement = $pdo->prepare(
        'INSERT INTO inventory_movements (
            inventory_item_id, movement_type, quantity, unit_cost,
            reference_label, notes, created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :inventory_item_id, :movement_type, :quantity, :unit_cost,
            :reference_label, :notes, :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    foreach ($inventorySeeds as $seed) {
        $itemId = fetch_id_by_column($pdo, 'inventory_items', 'item_code', $seed['item_code']);
        if ($itemId === null) {
            $insertInventory->execute([
                'item_code' => $seed['item_code'],
                'item_name' => $seed['item_name'],
                'category_name' => $seed['category_name'],
                'unit_label' => $seed['unit_label'],
                'min_stock' => $seed['min_stock'],
                'current_stock' => $seed['current_stock'],
                'unit_cost' => $seed['unit_cost'],
                'active_status' => $seed['active_status'],
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $itemId = (int) $pdo->lastInsertId();
            $report['inventory_items']++;
        }

        $movementRef = 'OPEN-' . $seed['item_code'];
        if (!exists_by_column($pdo, 'inventory_movements', 'reference_label', $movementRef)) {
            $insertMovement->execute([
                'inventory_item_id' => $itemId,
                'movement_type' => 'IN',
                'quantity' => $seed['movement_qty'],
                'unit_cost' => $seed['unit_cost'],
                'reference_label' => $movementRef,
                'notes' => 'Initial dummy stock movement',
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $report['inventory_movements']++;
        }
    }

    $assetsSeeds = [
        [
            'asset_tag' => 'AST-DMY-LT-001',
            'asset_name' => 'Lenovo ThinkPad Dummy',
            'type_name' => 'Laptop',
            'purchase_date' => date('Y-m-d', strtotime('-120 days')),
            'purchase_value' => 78000,
            'assigned_to_name' => $assignedName,
            'status_label' => 'assigned',
            'notes' => 'Dummy asset for testing assignment flow',
        ],
        [
            'asset_tag' => 'AST-DMY-UPS-002',
            'asset_name' => 'Network UPS Dummy',
            'type_name' => 'Power',
            'purchase_date' => date('Y-m-d', strtotime('-80 days')),
            'purchase_value' => 24000,
            'assigned_to_name' => 'NOC Room',
            'status_label' => 'active',
            'notes' => 'Dummy backup power asset',
        ],
    ];

    $insertAsset = $pdo->prepare(
        'INSERT INTO assets_items (
            asset_tag, asset_name, type_name, purchase_date, purchase_value,
            assigned_to_name, status_label, notes, created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :asset_tag, :asset_name, :type_name, :purchase_date, :purchase_value,
            :assigned_to_name, :status_label, :notes, :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    foreach ($assetsSeeds as $seed) {
        if (!exists_by_column($pdo, 'assets_items', 'asset_tag', $seed['asset_tag'])) {
            $insertAsset->execute([
                'asset_tag' => $seed['asset_tag'],
                'asset_name' => $seed['asset_name'],
                'type_name' => $seed['type_name'],
                'purchase_date' => $seed['purchase_date'],
                'purchase_value' => $seed['purchase_value'],
                'assigned_to_name' => $seed['assigned_to_name'],
                'status_label' => $seed['status_label'],
                'notes' => $seed['notes'],
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $report['assets_items']++;
        }
    }

    $incomeSeeds = [
        [
            'invoice_no' => 'INV-DMY-INC-001',
            'client_name' => 'Demo Client One',
            'package_name' => 'Business 100 Mbps',
            'income_type' => 'Monthly Subscription',
            'amount' => 5000,
            'paid_amount' => 5000,
            'due_date' => $today,
            'status_label' => 'paid',
            'payment_method' => 'Bank Transfer',
            'notes' => 'Dummy fully paid monthly income',
        ],
        [
            'invoice_no' => 'INV-DMY-INC-002',
            'client_name' => 'Demo Client Two',
            'package_name' => 'Home 40 Mbps',
            'income_type' => 'Installation Charge',
            'amount' => 3000,
            'paid_amount' => 1500,
            'due_date' => $nextMonth,
            'status_label' => 'partial',
            'payment_method' => 'Cash',
            'notes' => 'Dummy partial payment income',
        ],
    ];

    $insertIncome = $pdo->prepare(
        'INSERT INTO income_entries (
            invoice_no, client_name, package_name, income_type, amount, paid_amount,
            due_date, status_label, payment_method, notes,
            created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :invoice_no, :client_name, :package_name, :income_type, :amount, :paid_amount,
            :due_date, :status_label, :payment_method, :notes,
            :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    foreach ($incomeSeeds as $seed) {
        if (!exists_by_column($pdo, 'income_entries', 'invoice_no', $seed['invoice_no'])) {
            $insertIncome->execute([
                'invoice_no' => $seed['invoice_no'],
                'client_name' => $seed['client_name'],
                'package_name' => $seed['package_name'],
                'income_type' => $seed['income_type'],
                'amount' => $seed['amount'],
                'paid_amount' => $seed['paid_amount'],
                'due_date' => $seed['due_date'],
                'status_label' => $seed['status_label'],
                'payment_method' => $seed['payment_method'],
                'notes' => $seed['notes'],
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $report['income_entries']++;
        }
    }

    $purchaseSeeds = [
        [
            'po_number' => 'PO-DMY-001',
            'order_date' => $today,
            'vendor_name' => 'FiberTech Supplies Ltd',
            'category_name' => 'Networking',
            'requested_by_name' => $assignedName,
            'delivery_date' => $nextMonth,
            'status_label' => 'Pending',
            'notes' => 'Dummy purchase order for OLT accessories',
            'items' => [
                ['item_name' => 'SFP Module', 'quantity' => 10, 'unit_price' => 1800],
                ['item_name' => 'Patch Cord', 'quantity' => 50, 'unit_price' => 120],
            ],
        ],
        [
            'po_number' => 'PO-DMY-002',
            'order_date' => date('Y-m-d', strtotime('-5 days')),
            'vendor_name' => 'PowerGrid Equipments',
            'category_name' => 'Electrical',
            'requested_by_name' => $assignedName,
            'delivery_date' => date('Y-m-d', strtotime('+10 days')),
            'status_label' => 'Approved',
            'notes' => 'Dummy purchase order for UPS batteries',
            'items' => [
                ['item_name' => 'UPS Battery 12V', 'quantity' => 8, 'unit_price' => 3200],
            ],
        ],
    ];

    $insertPurchase = $pdo->prepare(
        'INSERT INTO purchase_orders (
            po_number, order_date, vendor_name, category_name, requested_by_name,
            delivery_date, status_label, notes, total_amount,
            created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :po_number, :order_date, :vendor_name, :category_name, :requested_by_name,
            :delivery_date, :status_label, :notes, :total_amount,
            :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    $insertPurchaseItem = $pdo->prepare(
        'INSERT INTO purchase_order_items (
            purchase_order_id, item_name, quantity, unit_price, line_total
         ) VALUES (
            :purchase_order_id, :item_name, :quantity, :unit_price, :line_total
         )'
    );

    foreach ($purchaseSeeds as $seed) {
        $orderId = fetch_id_by_column($pdo, 'purchase_orders', 'po_number', $seed['po_number']);
        if ($orderId === null) {
            $totalAmount = 0.0;
            foreach ($seed['items'] as $item) {
                $totalAmount += ((float) $item['quantity']) * ((float) $item['unit_price']);
            }

            $insertPurchase->execute([
                'po_number' => $seed['po_number'],
                'order_date' => $seed['order_date'],
                'vendor_name' => $seed['vendor_name'],
                'category_name' => $seed['category_name'],
                'requested_by_name' => $seed['requested_by_name'],
                'delivery_date' => $seed['delivery_date'],
                'status_label' => $seed['status_label'],
                'notes' => $seed['notes'],
                'total_amount' => $totalAmount,
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $report['purchase_orders']++;

            foreach ($seed['items'] as $item) {
                $lineTotal = ((float) $item['quantity']) * ((float) $item['unit_price']);
                $insertPurchaseItem->execute([
                    'purchase_order_id' => $orderId,
                    'item_name' => $item['item_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
                $report['purchase_order_items']++;
            }
        }
    }

    $payheadSeeds = [
        [
            'payhead_code' => 'PH_DMY_BASIC_ADJ_001',
            'payhead_name' => 'Dummy Basic Adjustment',
            'payhead_type' => 'Earning',
            'payhead_category' => 'Salary Component',
            'calculation_type' => 'Fixed',
            'default_value' => 1000,
            'percentage_base' => 'Basic',
            'percentage_rate' => 0,
            'formula_expression' => '',
            'slab_definition' => '',
            'taxable' => 1,
            'pf_applicable' => 1,
            'esi_applicable' => 0,
            'affect_attendance' => 0,
            'pro_rata' => 1,
            'is_recurring' => 1,
            'visible_on_payslip' => 1,
            'status_label' => 'Active',
            'priority_order' => 10,
            'max_limit' => 5000,
            'gl_code' => 'GL-4100',
            'effective_from' => date('Y-m-d', strtotime('-1 month')),
            'effective_to' => null,
            'description_text' => 'Dummy earning payhead for testing payroll computation',
        ],
        [
            'payhead_code' => 'PH_DMY_FINE_002',
            'payhead_name' => 'Dummy Attendance Fine',
            'payhead_type' => 'Deduction',
            'payhead_category' => 'Disciplinary',
            'calculation_type' => 'Percentage',
            'default_value' => 0,
            'percentage_base' => 'Gross',
            'percentage_rate' => 2.5,
            'formula_expression' => '',
            'slab_definition' => '',
            'taxable' => 0,
            'pf_applicable' => 0,
            'esi_applicable' => 0,
            'affect_attendance' => 1,
            'pro_rata' => 0,
            'is_recurring' => 0,
            'visible_on_payslip' => 1,
            'status_label' => 'Active',
            'priority_order' => 20,
            'max_limit' => 3000,
            'gl_code' => 'GL-5200',
            'effective_from' => date('Y-m-d', strtotime('-2 months')),
            'effective_to' => null,
            'description_text' => 'Dummy deduction payhead for lateness/absence testing',
        ],
    ];

    $insertPayhead = $pdo->prepare(
        'INSERT INTO hr_payheads (
            payhead_code, payhead_name, payhead_type, payhead_category, calculation_type,
            default_value, percentage_base, percentage_rate, formula_expression, slab_definition,
            taxable, pf_applicable, esi_applicable, affect_attendance, pro_rata,
            is_recurring, visible_on_payslip, status_label, priority_order, max_limit,
            gl_code, effective_from, effective_to, description_text, created_by, updated_by
         ) VALUES (
            :payhead_code, :payhead_name, :payhead_type, :payhead_category, :calculation_type,
            :default_value, :percentage_base, :percentage_rate, :formula_expression, :slab_definition,
            :taxable, :pf_applicable, :esi_applicable, :affect_attendance, :pro_rata,
            :is_recurring, :visible_on_payslip, :status_label, :priority_order, :max_limit,
            :gl_code, :effective_from, :effective_to, :description_text, :created_by, :updated_by
         )'
    );

    foreach ($payheadSeeds as $seed) {
        if (!exists_by_column($pdo, 'hr_payheads', 'payhead_code', $seed['payhead_code'])) {
            $insertPayhead->execute([
                'payhead_code' => $seed['payhead_code'],
                'payhead_name' => $seed['payhead_name'],
                'payhead_type' => $seed['payhead_type'],
                'payhead_category' => $seed['payhead_category'],
                'calculation_type' => $seed['calculation_type'],
                'default_value' => $seed['default_value'],
                'percentage_base' => $seed['percentage_base'],
                'percentage_rate' => $seed['percentage_rate'],
                'formula_expression' => $seed['formula_expression'] === '' ? null : $seed['formula_expression'],
                'slab_definition' => $seed['slab_definition'] === '' ? null : $seed['slab_definition'],
                'taxable' => $seed['taxable'],
                'pf_applicable' => $seed['pf_applicable'],
                'esi_applicable' => $seed['esi_applicable'],
                'affect_attendance' => $seed['affect_attendance'],
                'pro_rata' => $seed['pro_rata'],
                'is_recurring' => $seed['is_recurring'],
                'visible_on_payslip' => $seed['visible_on_payslip'],
                'status_label' => $seed['status_label'],
                'priority_order' => $seed['priority_order'],
                'max_limit' => $seed['max_limit'],
                'gl_code' => $seed['gl_code'],
                'effective_from' => $seed['effective_from'],
                'effective_to' => $seed['effective_to'],
                'description_text' => $seed['description_text'],
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);
            $report['hr_payheads']++;
        }
    }

    $ruleSeeds = [
        [
            'rule_name' => 'Dummy Standard Resignation Rule',
            'department_name' => 'Operations / Field',
            'employee_type' => 'Permanent',
            'min_tenure_months' => 6,
            'notice_period_days' => 30,
            'buyout_allowed' => 1,
            'buyout_multiplier' => 1.5,
            'final_settlement_days' => 15,
            'exit_interview_required' => 1,
            'approvals_required' => json_encode(['Line Manager', 'HR Manager', 'Accounts Manager'], JSON_UNESCAPED_SLASHES),
            'status_label' => 'Active',
            'description_text' => 'Dummy default rule for regular resignation process',
        ],
        [
            'rule_name' => 'Dummy Contract End Rule',
            'department_name' => 'Procurement / Store',
            'employee_type' => 'Contract',
            'min_tenure_months' => 3,
            'notice_period_days' => 15,
            'buyout_allowed' => 0,
            'buyout_multiplier' => 1.0,
            'final_settlement_days' => 10,
            'exit_interview_required' => 0,
            'approvals_required' => json_encode(['HR Staff', 'Department Head'], JSON_UNESCAPED_SLASHES),
            'status_label' => 'Active',
            'description_text' => 'Dummy rule for fixed-term contracts',
        ],
    ];

    $insertRule = $pdo->prepare(
        'INSERT INTO hr_resignation_rules (
            rule_name, department_name, employee_type, min_tenure_months, notice_period_days,
            buyout_allowed, buyout_multiplier, final_settlement_days, exit_interview_required,
            approvals_required, status_label, description_text, created_by, updated_by
         ) VALUES (
            :rule_name, :department_name, :employee_type, :min_tenure_months, :notice_period_days,
            :buyout_allowed, :buyout_multiplier, :final_settlement_days, :exit_interview_required,
            :approvals_required, :status_label, :description_text, :created_by, :updated_by
         )'
    );

    foreach ($ruleSeeds as $seed) {
        if (!exists_by_column($pdo, 'hr_resignation_rules', 'rule_name', $seed['rule_name'])) {
            $insertRule->execute([
                'rule_name' => $seed['rule_name'],
                'department_name' => $seed['department_name'],
                'employee_type' => $seed['employee_type'],
                'min_tenure_months' => $seed['min_tenure_months'],
                'notice_period_days' => $seed['notice_period_days'],
                'buyout_allowed' => $seed['buyout_allowed'],
                'buyout_multiplier' => $seed['buyout_multiplier'],
                'final_settlement_days' => $seed['final_settlement_days'],
                'exit_interview_required' => $seed['exit_interview_required'],
                'approvals_required' => $seed['approvals_required'],
                'status_label' => $seed['status_label'],
                'description_text' => $seed['description_text'],
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);
            $report['hr_resignation_rules']++;
        }
    }

    $pdo->commit();

    echo "Dummy seed completed successfully.\n";
    foreach ($report as $table => $count) {
        echo sprintf("%-24s : %d\n", $table, $count);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Dummy seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
