<?php
$page_title = 'Create PO';
$page = 'pos';

$conn = getConnection();

// ---------- Fetch the three sector locations ----------
$sectorNames = ['ASb Fashion', 'ASb Glamour', 'Glamour Gate'];
$sectorLocations = [];
$placeholders = implode(',', array_fill(0, count($sectorNames), '?'));
$stmt = $conn->prepare("SELECT location_id, location_name FROM store_locations WHERE location_name IN ($placeholders)");
$stmt->bind_param(str_repeat('s', count($sectorNames)), ...$sectorNames);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $sectorLocations[$row['location_name']] = $row['location_id'];
}
$stmt->close();

// If any sector missing, fallback to all locations (or create them)
if (count($sectorLocations) < 3) {
    // Insert missing sectors if not exist
    foreach ($sectorNames as $name) {
        if (!isset($sectorLocations[$name])) {
            $conn->query("INSERT IGNORE INTO store_locations (location_name) VALUES ('$name')");
            $id = $conn->insert_id;
            if ($id) $sectorLocations[$name] = $id;
        }
    }
    // Re-fetch to be sure
    $res = $conn->query("SELECT location_id, location_name FROM store_locations WHERE location_name IN ('" . implode("','", $sectorNames) . "')");
    while ($row = $res->fetch_assoc()) {
        $sectorLocations[$row['location_name']] = $row['location_id'];
    }
}

// ---------- Generate PO number ----------
$last_po = $conn->query("SELECT po_number FROM po_header ORDER BY po_id DESC LIMIT 1")->fetch_assoc();
if ($last_po) {
    $num = intval(substr($last_po['po_number'], -4)) + 1;
    $po_number = 'PO-' . date('Ymd') . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
} else {
    $po_number = 'PO-' . date('Ymd') . '-0001';
}

// ---------- Fetch all reference data for dropdowns ----------
function fetchOptions($table, $idField, $nameField) {
    global $conn;
    $res = $conn->query("SELECT $idField, $nameField FROM $table ORDER BY $nameField");
    $opts = [];
    while ($row = $res->fetch_assoc()) {
        $opts[] = $row;
    }
    return $opts;
}
$departments = fetchOptions('departments', 'department_id', 'department_name');
$categories  = fetchOptions('categories', 'category_id', 'category_name');
$colors      = fetchOptions('colors', 'color_id', 'color_name');
$sizes       = fetchOptions('sizes', 'size_id', 'size_name');
// Sub‑departments depend on department – we'll load via AJAX later

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    /* General styles */
    .item-entry { border:1px solid #ddd; border-radius:8px; padding:15px; margin-bottom:15px; background:#fafafa; }
    .item-row { display:grid; grid-template-columns:2fr 1fr 1fr 1fr 0.5fr; gap:10px; align-items:end; }
    .allocation-row { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:10px; border-top:1px dashed #ccc; padding-top:10px; }
    .allocation-row .form-group { margin:0; }
    .item-row .form-group { margin-bottom:0; }
    .fade-in { animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
    .filter-bar { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:15px; align-items:end; }
    .filter-bar .form-group { margin:0; min-width:150px; flex:1; }
    .filter-bar .form-group select, .filter-bar .form-group input { width:100%; }
    #itemResults { max-height:300px; overflow-y:auto; border:1px solid #ddd; border-radius:5px; padding:5px; }
    .item-result-item { cursor:pointer; padding:8px 12px; border-bottom:1px solid #eee; }
    .item-result-item:hover { background:#e9f5ff; }
    .item-result-item .item-name { font-weight:bold; }
    .item-result-item .item-meta { font-size:12px; color:#666; }
    .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:20px; }
    .btn-print { background:#2c3e50; color:white; }
    .btn-print:hover { background:#1a252f; }
    /* Responsive */
    @media (max-width:768px) {
        .item-row { grid-template-columns:1fr; }
        .allocation-row { grid-template-columns:1fr; }
        .filter-bar .form-group { min-width:100%; }
    }
</style>

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
    <h2>➕ Create Purchase Order</h2>
    <div>
        <a href="?page=pos" class="btn btn-secondary">← Back</a>
        <button type="button" class="btn btn-print" onclick="printPO()">🖨️ Print PO</button>
    </div>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<form method="POST" id="poForm" onsubmit="return validateForm()">
    <input type="hidden" name="action" value="create_po">
    
    <!-- PO Header -->
    <div class="card">
        <div class="card-header"><h5>📋 PO Details</h5></div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:20px;">
                <div class="form-group">
                    <label>PO Number *</label>
                    <input type="text" name="po_number" class="form-control" value="<?php echo htmlspecialchars($po_number); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Supplier *</label>
                    <div style="display:flex; gap:10px;">
                        <input type="text" id="supplierSearch" class="form-control" placeholder="Type supplier name..." autocomplete="off">
                        <input type="hidden" name="supplier_id" id="supplierId" value="">
                        <button type="button" class="btn btn-info" onclick="searchSupplier()">🔍</button>
                    </div>
                    <div id="supplierResults" style="max-height:200px; overflow-y:auto; border:1px solid #ddd; border-radius:5px; display:none; margin-top:5px;"></div>
                    <small id="selectedSupplier" style="color:#27ae60; font-weight:bold;"></small>
                </div>
                <div class="form-group">
                    <label>Purchase Date *</label>
                    <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Expected Delivery</label>
                    <input type="date" name="expected_delivery_date" class="form-control">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:15px;">
                <div class="form-group">
                    <label>Attention</label>
                    <input type="text" name="attention" class="form-control" placeholder="Attention to...">
                </div>
                <div class="form-group">
                    <label>Remarks</label>
                    <input type="text" name="remarks" class="form-control" placeholder="Additional remarks">
                </div>
            </div>
        </div>
    </div>

    <!-- PO Items -->
    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; flex-wrap:wrap;">
            <h5>📦 PO Items <span id="itemCount" style="font-size:14px; color:#7f8c8d; font-weight:normal;">(0 items)</span></h5>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn btn-success btn-sm" onclick="openItemSelector()">➕ Add Item</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateItemModal()">🆕 Create New Item</button>
            </div>
        </div>
        <div class="card-body" id="itemsContainer">
            <!-- Items will be added here -->
            <div style="text-align:center; padding:20px; color:#7f8c8d;">Select a supplier and add items.</div>
        </div>
    </div>

    <!-- Summary -->
    <div class="card">
        <div class="card-header"><h5>💰 Summary</h5></div>
        <div class="card-body">
            <div class="summary-grid">
                <div><label style="color:#7f8c8d;font-size:14px;">Total Items</label><h4 id="totalItems">0</h4></div>
                <div><label style="color:#7f8c8d;font-size:14px;">Total Qty</label><h4 id="totalQuantity">0</h4></div>
                <div><label style="color:#7f8c8d;font-size:14px;">Total Cost</label><h4 id="totalCost">LKR 0.00</h4></div>
                <div><label style="color:#7f8c8d;font-size:14px;">Total Sell</label><h4 id="totalSell">LKR 0.00</h4></div>
            </div>
        </div>
    </div>

    <!-- Submit -->
    <div style="display:flex; gap:10px; margin-top:20px; flex-wrap:wrap;">
        <button type="submit" class="btn btn-success btn-lg">💾 Create PO</button>
        <a href="?page=pos" class="btn btn-secondary btn-lg">❌ Cancel</a>
    </div>
</form>

<!-- ====== Modal: Item Selector (Search/Filter) ====== -->
<div id="itemModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:25px; border-radius:15px; max-width:900px; width:95%; max-height:90vh; overflow-y:auto;">
        <h4>Select Item</h4>
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="form-group">
                <label>Department</label>
                <select id="filterDepartment" class="form-control" onchange="loadSubDepartments(this.value)">
                    <option value="">All</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo $d['department_id']; ?>"><?php echo htmlspecialchars($d['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Sub Department</label>
                <select id="filterSubDepartment" class="form-control">
                    <option value="">All</option>
                </select>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select id="filterCategory" class="form-control">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo $c['category_id']; ?>"><?php echo htmlspecialchars($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Color</label>
                <select id="filterColor" class="form-control">
                    <option value="">All</option>
                    <?php foreach ($colors as $col): ?>
                        <option value="<?php echo $col['color_id']; ?>"><?php echo htmlspecialchars($col['color_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Size</label>
                <select id="filterSize" class="form-control">
                    <option value="">All</option>
                    <?php foreach ($sizes as $s): ?>
                        <option value="<?php echo $s['size_id']; ?>"><?php echo htmlspecialchars($s['size_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:2;">
                <label>Search</label>
                <input type="text" id="itemSearchInput" class="form-control" placeholder="Item name or code...">
            </div>
            <div class="form-group" style="flex:0 0 auto;">
                <button class="btn btn-primary" onclick="searchItems()">🔍</button>
            </div>
        </div>
        <!-- Results -->
        <div id="itemResults" style="max-height:350px; overflow-y:auto; border:1px solid #ddd; border-radius:5px;"></div>
        <div id="itemPagination" style="margin-top:15px; display:flex; gap:5px; justify-content:center; flex-wrap:wrap;"></div>
        <button class="btn btn-secondary" onclick="closeItemSelector()" style="margin-top:15px;">Close</button>
    </div>
</div>

<!-- ====== Modal: Create New Item ====== -->
<div id="createItemModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:25px; border-radius:15px; max-width:600px; width:95%; max-height:90vh; overflow-y:auto;">
        <h4>🆕 Create New Item for Supplier</h4>
        <form id="createItemForm">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Item Name *</label>
                    <input type="text" id="newItemName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Department *</label>
                    <select id="newDept" class="form-control" onchange="loadSubDepts(this.value, 'newSubDept')" required>
                        <option value="">Select</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['department_id']; ?>"><?php echo htmlspecialchars($d['department_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sub Department</label>
                    <select id="newSubDept" class="form-control">
                        <option value="">Select</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select id="newCat" class="form-control">
                        <option value="">Select</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo $c['category_id']; ?>"><?php echo htmlspecialchars($c['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <select id="newColor" class="form-control">
                        <option value="">Select</option>
                        <?php foreach ($colors as $col): ?>
                            <option value="<?php echo $col['color_id']; ?>"><?php echo htmlspecialchars($col['color_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Size</label>
                    <select id="newSize" class="form-control">
                        <option value="">Select</option>
                        <?php foreach ($sizes as $s): ?>
                            <option value="<?php echo $s['size_id']; ?>"><?php echo htmlspecialchars($s['size_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cost Price (LKR) *</label>
                    <input type="number" step="0.01" id="newCost" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Selling Price (LKR) *</label>
                    <input type="number" step="0.01" id="newSell" class="form-control" required>
                </div>
            </div>
            <div style="margin-top:15px; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeCreateItemModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Create & Add</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ======= GLOBAL VARIABLES =======
    const locations = <?php echo json_encode($sectorLocations); ?>;
    let selectedSupplierId = 0;
    let itemCount = 0;
    let currentItemPage = 1;
    let itemSearchTerm = '';
    // For pagination and filters
    let filterDept = '', filterSub = '', filterCat = '', filterColor = '', filterSize = '', filterSearch = '';

    // ======= SUPPLIER SEARCH =======
    function searchSupplier(page = 1) {
        const search = document.getElementById('supplierSearch').value.trim();
        fetch(`?page=api&action=searchSuppliers&search=${encodeURIComponent(search)}&page_num=${page}`)
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('supplierResults');
                if (data.data.length === 0) {
                    container.innerHTML = '<div style="padding:10px; color:#7f8c8d;">No suppliers found.</div>';
                    container.style.display = 'block';
                    return;
                }
                let html = '<table style="width:100%;">';
                data.data.forEach(s => {
                    html += `<tr onclick="selectSupplier(${s.supplier_id}, '${s.supplier_name}')" style="cursor:pointer; border-bottom:1px solid #eee;">
                                <td style="padding:8px;"><strong>${s.supplier_name}</strong><br><small>${s.contact_number || ''} ${s.email || ''}</small></td>
                            </tr>`;
                });
                html += '</table>';
                if (data.totalPages > 1) {
                    html += '<div style="padding:10px; display:flex; gap:5px; justify-content:center;">';
                    for (let i = 1; i <= data.totalPages; i++) {
                        html += `<button class="btn btn-sm ${i === page ? 'btn-primary' : 'btn-secondary'}" onclick="searchSupplier(${i})">${i}</button>`;
                    }
                    html += '</div>';
                }
                container.innerHTML = html;
                container.style.display = 'block';
            })
            .catch(err => console.error(err));
    }

    function selectSupplier(id, name) {
        selectedSupplierId = id;
        document.getElementById('supplierId').value = id;
        document.getElementById('selectedSupplier').textContent = '✅ ' + name;
        document.getElementById('supplierResults').style.display = 'none';
        document.getElementById('supplierSearch').value = name;
        // Clear existing items because supplier changed
        document.getElementById('itemsContainer').innerHTML = '<div style="text-align:center; padding:20px; color:#7f8c8d;">Select a supplier and add items.</div>';
        itemCount = 0;
        updateItemCount();
        updateSummary();
    }

    document.getElementById('supplierSearch').addEventListener('input', function() {
        if (this.value.length > 0) {
            searchSupplier(1);
        } else {
            document.getElementById('supplierResults').style.display = 'none';
        }
    });

    document.addEventListener('click', function(e) {
        const results = document.getElementById('supplierResults');
        const search = document.getElementById('supplierSearch');
        if (results && !results.contains(e.target) && e.target !== search) {
            results.style.display = 'none';
        }
    });

    // ======= SUB‑DEPARTMENT LOADING =======
    function loadSubDepartments(deptId, targetSelectId = 'filterSubDepartment') {
        if (!deptId) {
            document.getElementById(targetSelectId).innerHTML = '<option value="">All</option>';
            return;
        }
        fetch(`?page=api&action=getSubDepartments&department_id=${deptId}`)
            .then(res => res.json())
            .then(data => {
                const sel = document.getElementById(targetSelectId);
                sel.innerHTML = '<option value="">All</option>';
                data.forEach(sub => {
                    sel.innerHTML += `<option value="${sub.sub_department_id}">${sub.sub_department_name}</option>`;
                });
            })
            .catch(err => console.error(err));
    }
    // For create modal
    function loadSubDepts(deptId, targetId) {
        loadSubDepartments(deptId, targetId);
    }

    // ======= ITEM SELECTOR (Modal) =======
    function openItemSelector() {
        if (!selectedSupplierId) {
            alert('Please select a supplier first!');
            return;
        }
        document.getElementById('itemModal').style.display = 'flex';
        // Reset filters
        document.getElementById('filterDepartment').value = '';
        document.getElementById('filterSubDepartment').innerHTML = '<option value="">All</option>';
        document.getElementById('filterCategory').value = '';
        document.getElementById('filterColor').value = '';
        document.getElementById('filterSize').value = '';
        document.getElementById('itemSearchInput').value = '';
        filterDept = filterSub = filterCat = filterColor = filterSize = filterSearch = '';
        currentItemPage = 1;
        searchItems();
    }

    function closeItemSelector() {
        document.getElementById('itemModal').style.display = 'none';
    }

    function searchItems(page = 1) {
        filterDept = document.getElementById('filterDepartment').value;
        filterSub = document.getElementById('filterSubDepartment').value;
        filterCat = document.getElementById('filterCategory').value;
        filterColor = document.getElementById('filterColor').value;
        filterSize = document.getElementById('filterSize').value;
        filterSearch = document.getElementById('itemSearchInput').value.trim();
        currentItemPage = page;

        const params = new URLSearchParams({
            action: 'searchItems',
            supplier_id: selectedSupplierId,
            department_id: filterDept,
            sub_department_id: filterSub,
            category_id: filterCat,
            color_id: filterColor,
            size_id: filterSize,
            search: filterSearch,
            page_num: currentItemPage
        });

        fetch(`?page=api&${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('itemResults');
                if (data.data.length === 0) {
                    container.innerHTML = '<div style="padding:20px; color:#7f8c8d;">No items found. You can create a new item using the "Create New Item" button.</div>';
                    document.getElementById('itemPagination').innerHTML = '';
                    return;
                }
                let html = '';
                data.data.forEach(item => {
                    html += `<div class="item-result-item" onclick="addItemFromSelector(${item.item_id}, '${item.item_name.replace(/'/g, "\\'")}', ${item.cost_price}, ${item.selling_price})">
                                <div class="item-name">${item.item_name}</div>
                                <div class="item-meta">Code: ${item.item_code} | Dept: ${item.department_name || 'N/A'} | Cat: ${item.category_name || 'N/A'} | Color: ${item.color_name || 'N/A'} | Size: ${item.size_name || 'N/A'}</div>
                            </div>`;
                });
                container.innerHTML = html;

                // Pagination
                const pagContainer = document.getElementById('itemPagination');
                if (data.totalPages > 1) {
                    let phtml = '';
                    for (let i = 1; i <= data.totalPages; i++) {
                        phtml += `<button class="btn btn-sm ${i === currentItemPage ? 'btn-primary' : 'btn-secondary'}" onclick="searchItems(${i})">${i}</button>`;
                    }
                    pagContainer.innerHTML = phtml;
                } else {
                    pagContainer.innerHTML = '';
                }
            })
            .catch(err => console.error(err));
    }

    // Trigger search on filter change
    document.querySelectorAll('#filterDepartment, #filterSubDepartment, #filterCategory, #filterColor, #filterSize, #itemSearchInput').forEach(el => {
        el.addEventListener('change', () => { currentItemPage = 1; searchItems(); });
        if (el.tagName === 'INPUT') el.addEventListener('keyup', (e) => { if(e.key === 'Enter') { currentItemPage = 1; searchItems(); } });
    });

    function addItemFromSelector(itemId, itemName, cost, sell) {
        closeItemSelector();
        addItemWithData({ item_id: itemId, item_name: itemName, cost_price: cost, selling_price: sell });
    }

    // ======= ADD ITEM ROW =======
    function addItemWithData(item) {
        // Remove placeholder
        const container = document.getElementById('itemsContainer');
        const placeholder = container.querySelector('div[style*="text-align:center"]');
        if (placeholder) placeholder.remove();

        itemCount++;
        const div = document.createElement('div');
        div.className = 'item-entry fade-in';
        div.dataset.index = itemCount;

        // Build allocation inputs for the three sectors
        const sectorNames = Object.keys(locations); // e.g., ['ASb Fashion', 'ASb Glamour', 'Glamour Gate']
        let allocHtml = '';
        sectorNames.forEach(name => {
            const locId = locations[name];
            allocHtml += `<div class="form-group">
                            <label style="font-size:12px; color:#7f8c8d;">${name}</label>
                            <input type="number" name="items[${itemCount}][allocations][${locId}]" class="form-control alloc-input" value="0" min="0" style="padding:5px 10px;" onchange="updateSummary()">
                        </div>`;
        });

        div.innerHTML = `
            <div class="item-row">
                <div class="form-group"><label>Item</label>
                    <input type="text" class="form-control" value="${item.item_name}" readonly style="background:#f8f9fa;">
                    <input type="hidden" name="items[${itemCount}][item_id]" value="${item.item_id}">
                </div>
                <div class="form-group"><label>Cost (LKR) *</label><input type="number" step="0.01" name="items[${itemCount}][cost_price]" class="form-control cost-input" required value="${item.cost_price}" onchange="updateSummary()"></div>
                <div class="form-group"><label>Sell (LKR) *</label><input type="number" step="0.01" name="items[${itemCount}][selling_price]" class="form-control sell-input" required value="${item.selling_price}" onchange="updateSummary()"></div>
                <div class="form-group" style="display:flex; align-items:end;"><button type="button" class="btn btn-danger" onclick="removeItem(this)" style="width:100%;">✕</button></div>
            </div>
            <div class="allocation-row">
                ${allocHtml}
            </div>
        `;
        container.appendChild(div);
        updateItemCount();
        updateSummary();
    }

    function removeItem(btn) {
        if (itemCount > 1) {
            btn.closest('.item-entry').remove();
            itemCount--;
            updateItemCount();
            updateSummary();
        } else {
            alert('Need at least one item.');
        }
    }

    // ======= SUMMARY UPDATES =======
    function updateItemCount() {
        document.getElementById('itemCount').textContent = `(${itemCount} items)`;
    }

    function updateSummary() {
        let totalQty = 0, totalCost = 0, totalSell = 0;
        document.querySelectorAll('.item-entry').forEach(entry => {
            // Sum the three allocation inputs
            let qty = 0;
            const allocInputs = entry.querySelectorAll('.alloc-input');
            allocInputs.forEach(inp => { qty += parseInt(inp.value) || 0; });
            let cost = parseFloat(entry.querySelector('.cost-input').value) || 0;
            let sell = parseFloat(entry.querySelector('.sell-input').value) || 0;
            totalQty += qty;
            totalCost += qty * cost;
            totalSell += qty * sell;
        });
        document.getElementById('totalItems').textContent = itemCount;
        document.getElementById('totalQuantity').textContent = totalQty;
        document.getElementById('totalCost').textContent = 'LKR ' + totalCost.toFixed(2);
        document.getElementById('totalSell').textContent = 'LKR ' + totalSell.toFixed(2);
    }

    // ======= CREATE NEW ITEM =======
    function openCreateItemModal() {
        if (!selectedSupplierId) {
            alert('Please select a supplier first!');
            return;
        }
        document.getElementById('createItemModal').style.display = 'flex';
        // Reset form
        document.getElementById('createItemForm').reset();
        document.getElementById('newSubDept').innerHTML = '<option value="">Select</option>';
    }

    function closeCreateItemModal() {
        document.getElementById('createItemModal').style.display = 'none';
    }

    document.getElementById('createItemForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('action', 'createItem');
        formData.append('supplier_id', selectedSupplierId);

        // Append other fields manually (they are already in form)
        fetch('?page=api', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Item created and linked to supplier!');
                closeCreateItemModal();
                // Add the new item to the PO immediately
                addItemWithData({
                    item_id: data.item_id,
                    item_name: data.item_name,
                    cost_price: data.cost_price,
                    selling_price: data.selling_price
                });
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Request failed: ' + err));
    });

    // ======= VALIDATE FORM =======
    function validateForm() {
        if (!selectedSupplierId) {
            alert('Please select a supplier!');
            return false;
        }
        if (document.querySelectorAll('.item-entry').length === 0) {
            alert('Add at least one item.');
            return false;
        }
        // Check all allocations have at least one non-zero qty? optional
        return true;
    }

    // ======= PRINT PO =======
    function printPO() {
        // Collect PO data and open print view
        const supplierId = selectedSupplierId;
        if (!supplierId) { alert('Please select a supplier first.'); return; }
        const poNumber = document.querySelector('input[name="po_number"]').value;
        const purchaseDate = document.querySelector('input[name="purchase_date"]').value;
        const expectedDate = document.querySelector('input[name="expected_delivery_date"]').value;
        const attention = document.querySelector('input[name="attention"]').value;
        const remarks = document.querySelector('input[name="remarks"]').value;
        // Gather items data
        const items = [];
        document.querySelectorAll('.item-entry').forEach(entry => {
            const itemId = entry.querySelector('input[name*="[item_id]"]').value;
            const itemName = entry.querySelector('input[type="text"]').value;
            const cost = entry.querySelector('.cost-input').value;
            const sell = entry.querySelector('.sell-input').value;
            const allocs = {};
            entry.querySelectorAll('.alloc-input').forEach(inp => {
                const name = inp.closest('.form-group').querySelector('label').textContent.trim();
                const qty = parseInt(inp.value) || 0;
                allocs[name] = qty;
            });
            items.push({ itemId, itemName, cost, sell, allocs });
        });

        // Open print page with data via POST or GET
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=print_po';
        form.target = '_blank';
        const data = { poNumber, purchaseDate, expectedDate, attention, remarks, supplierId, items };
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'data';
        input.value = JSON.stringify(data);
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>